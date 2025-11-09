<?php

namespace Telepedia\Extensions\SitemapOnTheFly;

use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\Utils\MWTimestamp;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Timestamp\ConvertibleTimestamp;

class SitemapGenerator {

	/**
	 * @var int
	 */
	private $maximumPerIndex = 50000;

	/**
	 * @var array
	 */
	private array $namespacesToGenerateSitemapFor = [];

	/**
	 * @var IConnectionProvider
	 */
	private IConnectionProvider $dbProvider;

	/**
	 * @var string|mixed
	 */
	private string $baseUrl;

	/**
	 * @var string
	 */
	private string $scriptPath;

	public function __construct() {
		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig('SitemapOnTheFly');
		$this->namespacesToGenerateSitemapFor = $config->get('SitemapNamespaces');
		$this->dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
		$this->baseUrl = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::Server );
		$this->scriptPath = MediaWikiServices::getInstance()->getMainConfig()->get( MainConfigNames::ScriptPath );
	}

	/**
	 * Return an instance of this class
	 * @return SitemapGenerator
	 */
	public static function newInstance(): SitemapGenerator {
		return new SitemapGenerator();
	}


	/**
	 * Generate the index for a sitemap
	 * @return string XML content of the sitemap index
	 */
	public function generateIndex(): string {
		$start = microtime(true);

		$sitemapLinks = [];
		$dbr = $this->dbProvider->getReplicaDatabase();

		// if we have a language variant, we need to use it in the URL
		$wikiUrlBase = rtrim($this->baseUrl, '/') . $this->scriptPath;

		foreach ( $this->namespacesToGenerateSitemapFor as $namespaceId ) {
			$pageCount = $dbr->newSelectQueryBuilder()
				->select( 'COUNT(*)' )
				->from( 'page' )
				->where( [
					'page_namespace' => $namespaceId,
					'page_is_redirect' => 0
				] )
				->caller( __METHOD__ )
				->fetchField();

			if ( $pageCount == 0 ) {
				// nothing to do
				continue;
			}

			// Only need 1 sitemap page for this wiki
			if ( $pageCount <= $this->maximumPerIndex ) {
				// get the latest time a page in this namespace was updated
				$maxTouched = $dbr->newSelectQueryBuilder()
					->select( 'MAX(page_touched)' )
					->from( 'page' )
					->where( [
						'page_namespace' => $namespaceId,
						'page_is_redirect' => 0
					] )
					->caller( __METHOD__ )
					->fetchField();

				$sitemapLinks[] = [
					'loc' => $wikiUrlBase . '/sitemap-NS-' . $namespaceId . '.xml',
					'lastmod' => MWTimestamp::convert( TS_ISO_8601, $maxTouched),
				];

			} else {
				// over 50k, must split into multiple files
				// not sure this is the most appropriate way to do it
				$numFiles = (int)ceil( $pageCount / $this->maximumPerIndex );

				for ( $part = 1; $part <= $numFiles; $part++ ) {
					$offset = ( $part - 1 ) * $this->maximumPerIndex;
					$maxTouchedInChunk = $dbr->newSelectQueryBuilder()
						->select('MAX(t.page_touched)')
						->from(
							$dbr->newSelectQueryBuilder()
								->select('page_touched')
								->from('page')
								->where(
									[
										'page_namespace' => $namespaceId,
										'page_is_redirect' => 0
									]
								)
								->orderBy('page_id')
								->limit($this->maximumPerIndex)
								->offset($offset)
								->caller(__METHOD__),
							't'
						)
						->caller(__METHOD__)
						->fetchField();

					$sitemapLinks[] = [
						'loc' => $wikiUrlBase . '/sitemap-NS-' . $namespaceId . '-part-' . $part . '.xml',
						'lastmod' => MWTimestamp::convert( TS_ISO_8601, $maxTouchedInChunk),
					];
				}
			}
		}

		return $this->buildIndexXML( $sitemapLinks, $start );
	}

	/**
	 * Helper function to wrap the links in the sitemapindex XML.
	 * @param array $sitemapLinks
	 * @return string
	 */
	private function buildIndexXml( array $sitemapLinks, float $startTime ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ($sitemapLinks as $link) {
			$xml .= "  <sitemap>\n";
			$xml .= "    <loc>" . htmlspecialchars($link['loc']) . "</loc>\n";
			$xml .= "    <lastmod>" . $link['lastmod'] . "</lastmod>\n";
			$xml .= "  </sitemap>\n";
		}

		$xml .= '</sitemapindex>';

		$timeMs = round((microtime(true) - $startTime) * 1000, 2);
		$xml .= "<!-- Generation Time: {$timeMs} ms -->";
		$date = ConvertibleTimestamp::now(TS_ISO_8601 );
		$xml .= "<!-- Generation Date: {$date} -->";

		return $xml;
	}

	/**
	 * Helper function to get the data for a specific individual sitemap page
	 *
	 * @param int $namespaceId The namespace to generate.
	 * @param int $partNumber The part number (for pagination).
	 * @return string
	 */
	public function generateSitemapPage( int $namespaceId, int $partNumber = 1 ): string {
		$pageLinks = [];
		$dbr = $this->dbProvider->getReplicaDatabase();

		// Calculate the offset for the database query
		$offset = ($partNumber - 1) * $this->maximumPerIndex;

		$res = $dbr->newSelectQueryBuilder()
			->select([
				'page_title',
				'page_touched',
				'page_namespace'
			])
			->from('page')
			->where(
				[
					'page_namespace' => $namespaceId,
					'page_is_redirect' => 0
				]
			)
			->orderBy('page_id')
			->limit($this->maximumPerIndex)
			->offset($offset)
			->caller(__METHOD__)
			->fetchResultSet();

		foreach ( $res as $row ) {
			$title = Title::newFromRow($row);
			if ( $title ) {
				$pageLinks[] = [
					'loc' => $title->getFullURL(),
					'lastmod' => MWTimestamp::convert( TS_ISO_8601, $row->page_touched)
				];
			}
		}

		return $this->buildSitemapXml( $pageLinks );
	}

	/**
	 * Helper function to generate the sitemap for a particular sitemap page
	 *
	 * @param array $pageLinks
	 * @return string
	 */
	private function buildSitemapXml(array $pageLinks): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ($pageLinks as $link) {
			$xml .= "  <url>\n";
			$xml .= "    <loc>" . htmlspecialchars($link['loc']) . "</loc>\n";
			$xml .= "    <lastmod>" . $link['lastmod'] . "</lastmod>\n";
			$xml .= "	 <priority>1.0</priority>\n";
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';
		return $xml;
	}
}