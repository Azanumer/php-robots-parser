<?php
/**
 * Example: parse a robots.txt and check a few URLs.
 *
 * Run: php examples/robots-example.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/RobotsParser.php';

use Azanumer\RobotsParser\RobotsParser;

$robotsTxt = <<<'TXT'
# Example robots.txt
User-agent: *
Disallow: /admin/
Disallow: /search
Allow: /search/help
Disallow: /*?sort=
Crawl-delay: 5

User-agent: Googlebot
Disallow: /no-google/
Allow: /no-google/public

Sitemap: https://example.com/sitemap.xml
TXT;

$robots = new RobotsParser($robotsTxt);

$checks = [
    ['https://example.com/', '*'],
    ['https://example.com/admin/dashboard', '*'],
    ['https://example.com/search', '*'],
    ['https://example.com/search/help', '*'],
    ['https://example.com/products?sort=price', '*'],
    ['https://example.com/no-google/secret', 'Googlebot'],
    ['https://example.com/no-google/public', 'Googlebot'],
    ['https://example.com/no-google/secret', 'Bingbot'],
];

foreach ($checks as [$url, $agent]) {
    $verdict = $robots->isAllowed($url, $agent) ? 'ALLOWED ' : 'BLOCKED ';
    printf("%s %-10s %s\n", $verdict, $agent, $url);
}

printf("\nCrawl-delay for *: %s seconds\n", var_export($robots->getCrawlDelay('*'), true));
printf("Sitemaps: %s\n", implode(', ', $robots->getSitemaps()));

// Live fetch example (needs network — wrapped so the example still runs offline).
$live = RobotsParser::fromUrl('https://www.google.com/robots.txt');
printf("\nGoogle robots.txt sitemaps: %s\n", implode(', ', $live->getSitemaps()) ?: '(none listed)');
