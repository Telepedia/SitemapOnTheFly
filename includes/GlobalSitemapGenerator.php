<?php

namespace Telepedia\Extensions\SitemapOnTheFly;

use MediaWiki\MediaWikiServices;
use Telepedia\ConfigCentre\Wiki;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\SelectQueryBuilder;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * Generates a single sitemap containing the homepage of every wiki which is not marked closed.
 * Each entry has a link to the wiki, and its robots.txt file where Google et al can find the sitemap for
 * that wiki
 */
class GlobalSitemapGenerator {

	/**
	 * @var int
	 */
	private int $maximumPerSitemap = 50000;

	/**
	 * Theoretically should never be used, but we mirror the default in cc_wikis incase something
	 * got messed up
	 * @var string
	 */
	private const PLACEHOLDER_URL = 'https://placeholder.telepedia.net';

	/**
	 * @var IConnectionProvider
	 */
	private IConnectionProvider $dbProvider;

	public function __construct() {
		$this->dbProvider = MediaWikiServices::getInstance()->getConnectionProvider();
	}

	/**
	 * Return an instance of this class
	 * @return GlobalSitemapGenerator
	 */
	public static function newInstance(): GlobalSitemapGenerator {
		return new GlobalSitemapGenerator();
	}

	/**
	 * Generate a sitemap containing the URL of every wiki that is not marked closed
	 *
	 * @param int $partNumber The part number (no-op rn but a sitemap can only handle 50k then needs pagination)
	 * @return string XML content of the sitemap
	 */
	public function generateSitemap( int $partNumber = 1 ): string {
		$start = microtime( true );

		$dbr = $this->dbProvider->getReplicaDatabase( 'virtual-configcentre' );

		$res = $dbr->newSelectQueryBuilder()
			->select( 'wiki_url' )
			->from( 'cc_wikis' )
			->where( [
				$dbr->bitAnd( 'wiki_flags', Wiki::FLAG_CLOSED ) . ' = 0',
				$dbr->expr( 'wiki_url', '!=', self::PLACEHOLDER_URL ),
			] )
			->orderBy( 'id', SelectQueryBuilder::SORT_ASC )
			->limit( $this->maximumPerSitemap )
			->offset( ( max( 1, $partNumber ) - 1 ) * $this->maximumPerSitemap )
			->caller( __METHOD__ )
			->fetchResultSet();

		$wikiLinks = [];
		foreach ( $res as $row ) {
			$wikiLinks[] = rtrim( $row->wiki_url, '/' ) . '/';
		}

		return $this->buildSitemapXml( $wikiLinks, $start );
	}

	/**
	 * Helper function to wrap the wiki URLs in the XML.
	 * @param array $wikiLinks
	 * @param float $startTime
	 * @return string
	 */
	private function buildSitemapXml( array $wikiLinks, float $startTime ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $wikiLinks as $link ) {
			$xml .= "  <url>\n";
			$xml .= "    <loc>" . htmlspecialchars( $link ) . "</loc>\n";
			$xml .= "  </url>\n";
		}

		$xml .= '</urlset>';

		$timeMs = round( ( microtime( true ) - $startTime ) * 1000, 2 );
		$xml .= "<!-- Generation Time: {$timeMs} ms -->";
		$date = ConvertibleTimestamp::now( TS_ISO_8601 );
		$xml .= "<!-- Generation Date: {$date} -->";

		return $xml;
	}
}
