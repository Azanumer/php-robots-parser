# PHP Robots Parser

A dependency-free robots.txt parser (RFC 9309) — parse `Allow` / `Disallow` rules, `Crawl-delay` and `Sitemap` lines, then ask the question crawlers actually ask: *may this user-agent fetch this URL?*

## Usage

```php
require 'src/RobotsParser.php';

use Azanumer\RobotsParser\RobotsParser;

// From a string, a file, or a live URL:
$robots = new RobotsParser($robotsTxt);
$robots = RobotsParser::fromFile('/path/to/robots.txt');
$robots = RobotsParser::fromUrl('https://example.com/robots.txt');

$robots->isAllowed('https://example.com/admin/', '*');        // false
$robots->isAllowed('https://example.com/blog/post', 'Googlebot'); // true
$robots->getCrawlDelay('*');   // 5.0, or null when not set
$robots->getSitemaps();        // ['https://example.com/sitemap.xml']
$robots->getRulesFor('Googlebot'); // raw rule list for that agent
```

## Rules, per RFC 9309

- **Longest match wins** — `/search/help` (Allow) beats `/search` (Disallow) for `/search/help`.
- **On a length tie, Allow wins.**
- **Most specific user-agent group wins** — a `Googlebot` group beats the `*` group for Googlebot; `*` is the fallback.
- `*` in a pattern matches any run of characters; a trailing `$` anchors to the end of the path.
- Matching is case-sensitive for paths, case-insensitive for user-agents.
- An empty `Disallow:` means "allow everything". A missing/unreachable robots.txt also means "allow everything" (per the spec, `fromUrl()` returns an empty parser in that case).

## Example

```bash
php examples/robots-example.php
```

## Requirements

PHP 7.4+ (no extensions beyond the standard library).

## License

MIT — see [LICENSE](LICENSE).
