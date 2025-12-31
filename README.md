**SitemapOnTheFly** is a MediaWiki extension that allows generation of sitemaps on the fly, when visiting some arbitrary page (`/sitemap.xml ` for example). It handles both the index - that is, a list of all sitemaps available - and individual sitemap pages showing the actual pages.

## Usage
Whilst this extension handles generating the actual sitemaps, some extra work is required in respect of pointing your webserver to the actual file. 

- First, load the extension - `wfLoadExtension('SitemapOnTheFly')`
- The extension has one configuration variable, `$wgSitemapNamespaces` which is an array of namespaces which should be shown in the sitemap; you may either pass numerical values `[ 0, 6 ]`, or MediaWiki constants `[ NS_MAIN, NS_FILE ]`, it doesn't matter. Custom namespaces will obviously have to be numerical.
- Create a `sitemap.php` file, this will need to load MediaWiki's `WebStart.php`, and will need to conditionally load either the index, or the individual sitemap pages depending on the URL:

You can see an example of Telepedia's production setup for this, [here](https://gitlab.com/telepedia/saltstack/-/blob/main/salt/mediawiki/files/sitemap.php?ref_type=heads). Point NGINX or Apache at it, and you should be grand.
If you go down a different path, note that you should generally ensure you call `define( 'MW_NO_SESSION', 1 );` to disable MediaWiki sessions and save a bit of bandwith - sessions are not relevant for sitemaps.
