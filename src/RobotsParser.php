<?php
/**
 * RobotsParser — a dependency-free robots.txt parser (RFC 9309).
 *
 * Parses Allow / Disallow rules (with * and $ wildcards), Crawl-delay and
 * Sitemap lines, then answers the question crawlers actually ask:
 * "may this user-agent fetch this URL?"
 *
 * Longest-matching rule wins; on a tie, Allow beats Disallow. The most
 * specific user-agent group wins; the `*` group is the fallback.
 *
 * Usage:
 *
 *     $robots = RobotsParser::fromUrl('https://example.com/robots.txt');
 *     $robots->isAllowed('https://example.com/private/page', 'MyBot');
 *     $robots->getCrawlDelay('MyBot'); // seconds, or null
 *     $robots->getSitemaps();          // list of sitemap URLs
 */

declare(strict_types=1);

namespace Azanumer\RobotsParser;

class RobotsParser
{
    /**
     * Parsed groups: each group is [ 'agents' => [...], 'rules' => [ ['allow' => bool, 'pattern' => string], ... ], 'crawl_delay' => float|null ].
     *
     * @var array<int, array{agents: string[], rules: array<int, array{allow: bool, pattern: string}>, crawl_delay: float|null}>
     */
    private array $groups = [];

    /** @var string[] */
    private array $sitemaps = [];

    /**
     * Parse robots.txt content.
     */
    public function __construct(string $content = '')
    {
        if ('' !== $content) {
            $this->parse($content);
        }
    }

    /**
     * Fetch and parse a robots.txt from a URL. Returns an empty parser
     * (allow everything) when the file cannot be fetched — a missing
     * robots.txt means "no restrictions" per the spec.
     */
    public static function fromUrl(string $url, int $timeoutSeconds = 5): self
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => $timeoutSeconds,
                'ignore_errors' => true,
                'header'        => "User-Agent: RobotsParser/1.0\r\n",
            ],
        ]);

        $content = @file_get_contents($url, false, $context);
        if (false === $content) {
            return new self();
        }

        // A 4xx/5xx robots.txt is treated as "no file" by most crawlers.
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\S+\s+([45]\d\d)#', $header, $m)) {
                    return new self();
                }
            }
        }

        return new self($content);
    }

    /**
     * Parse a robots.txt file from disk.
     *
     * @throws \InvalidArgumentException When the file cannot be read.
     */
    public static function fromFile(string $path): self
    {
        $content = @file_get_contents($path);
        if (false === $content) {
            throw new \InvalidArgumentException("Cannot read robots.txt: {$path}");
        }
        return new self($content);
    }

    /**
     * Parse robots.txt content into groups.
     */
    public function parse(string $content): void
    {
        $this->groups   = [];
        $this->sitemaps = [];

        $current = null; // index into $this->groups, or null

        foreach (preg_split("/\r\n|\r|\n/", $content) as $rawLine) {
            // Strip comments and surrounding whitespace.
            $line = trim(preg_replace('/#.*$/', '', $rawLine));
            if ('' === $line) {
                continue;
            }

            $colon = strpos($line, ':');
            if (false === $colon) {
                continue; // Not a field: value line — ignore.
            }

            $field = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            switch ($field) {
                case 'user-agent':
                    $agent = strtolower($value);
                    if ('' === $agent) {
                        break;
                    }
                    // A User-agent line starts a new group — unless the current
                    // group has no rules yet, in which case it just adds an
                    // agent to that group (multiple agents per group).
                    if (null === $current
                        || ! empty($this->groups[$current]['rules'])
                        || null !== $this->groups[$current]['crawl_delay']
                    ) {
                        $this->groups[] = ['agents' => [], 'rules' => [], 'crawl_delay' => null];
                        $current        = count($this->groups) - 1;
                    }
                    $this->groups[$current]['agents'][] = $agent;
                    break;

                case 'allow':
                case 'disallow':
                    if (null === $current) {
                        break; // Rule outside any group — ignore.
                    }
                    // An empty Disallow means "allow everything"; store it
                    // as a no-op rule so it never blocks.
                    if ('' === $value) {
                        break;
                    }
                    $this->groups[$current]['rules'][] = [
                        'allow'   => 'allow' === $field,
                        'pattern' => $value,
                    ];
                    break;

                case 'crawl-delay':
                    if (null !== $current && is_numeric($value)) {
                        $this->groups[$current]['crawl_delay'] = (float) $value;
                    }
                    break;

                case 'sitemap':
                    if ('' !== $value) {
                        $this->sitemaps[] = $value;
                    }
                    break;
            }
        }
    }

    /**
     * Is $url fetchable by $userAgent?
     *
     * @param string $url       Full URL or path (path + query is used).
     * @param string $userAgent Crawler name, e.g. 'Googlebot'. Default '*'.
     */
    public function isAllowed(string $url, string $userAgent = '*'): bool
    {
        $group = $this->findGroup($userAgent);
        if (null === $group) {
            return true; // No matching group — no restrictions.
        }

        $path = $this->urlPath($url);

        $bestLength = -1;
        $bestAllow  = true;

        foreach ($group['rules'] as $rule) {
            if (! $this->patternMatches($rule['pattern'], $path)) {
                continue;
            }
            // Longest matching pattern wins; on a length tie, Allow wins.
            $length = strlen($rule['pattern']);
            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $bestAllow  = $rule['allow'];
            }
        }

        return $bestAllow;
    }

    /**
     * Crawl-delay in seconds for a user-agent, or null when not set.
     */
    public function getCrawlDelay(string $userAgent = '*'): ?float
    {
        $group = $this->findGroup($userAgent);
        return null === $group ? null : $group['crawl_delay'];
    }

    /**
     * All Sitemap URLs declared in the file.
     *
     * @return string[]
     */
    public function getSitemaps(): array
    {
        return $this->sitemaps;
    }

    /**
     * The raw rule list for a user-agent (empty when no group matches).
     *
     * @return array<int, array{allow: bool, pattern: string}>
     */
    public function getRulesFor(string $userAgent = '*'): array
    {
        $group = $this->findGroup($userAgent);
        return null === $group ? [] : $group['rules'];
    }

    /**
     * Find the most specific group for a user-agent: the longest agent
     * token that appears in the crawler's name (case-insensitive);
     * the `*` group is the fallback. Returns the group array or null.
     */
    private function findGroup(string $userAgent): ?array
    {
        $ua       = strtolower($userAgent);
        $fallback = null;
        $best     = null;
        $bestLen  = -1;

        foreach ($this->groups as $group) {
            foreach ($group['agents'] as $agent) {
                if ('*' === $agent) {
                    $fallback = $group;
                    continue;
                }
                // The agent token matches when it appears anywhere in the
                // crawler's user-agent string (e.g. "Googlebot" matches
                // "Mozilla/5.0 (compatible; Googlebot/2.1; ...)").
                if (false !== stripos($ua, $agent) && strlen($agent) > $bestLen) {
                    $best    = $group;
                    $bestLen = strlen($agent);
                }
            }
        }

        return $best ?? $fallback;
    }

    /**
     * Extract path + query from a URL (or use the string as-is when it is
     * already a path).
     */
    private function urlPath(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }

    /**
     * Does a robots.txt pattern match a path? `*` matches any run of
     * characters, a trailing `$` anchors to the end. Matching is
     * case-sensitive, per RFC 9309.
     */
    private function patternMatches(string $pattern, string $path): bool
    {
        $anchored = '' !== $pattern && '$' === substr($pattern, -1);
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        // Escape everything, then turn the wildcards back into regex.
        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\\*', '.*', $regex);

        $regex = '/^' . $regex . ($anchored ? '$' : '') . '/';
        return 1 === preg_match($regex, $path);
    }
}
