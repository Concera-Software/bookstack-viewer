<?php

declare(strict_types=1);

/**
 * Check whether BookStack page-link rewriting is enabled.
 *
 * @param array $config
 * @return bool
 */
function bookstack_link_rewrite_enabled(array $config): bool
{
    return !empty($config['rewrite_bookstack_page_links']);
}

/**
 * Normalize a URL for matching against stored source_url values.
 *
 * Query strings and fragments are ignored because BookStack page links may
 * contain anchors or tracking parameters.
 *
 * @param string $url
 * @return string
 */
function bookstack_link_normalize_url(string $url): string
{
    $parts = parse_url(trim($url));

    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return '';
    }

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $path = (string)($parts['path'] ?? '/');

    $path = '/' . ltrim($path, '/');
    $path = rtrim($path, '/');

    if ($path === '') {
        $path = '/';
    }

    $normalized = $scheme . '://' . $host;

    if (!empty($parts['port'])) {
        $normalized .= ':' . (int)$parts['port'];
    }

    return $normalized . $path;
}

/**
 * Resolve a possibly relative BookStack link to an absolute source URL.
 *
 * @param string $href
 * @param array $page
 * @param array $config
 * @return string
 */
function bookstack_link_absolute_source_url(string $href, array $page, array $config): string
{
    if (function_exists('asset_proxy_absolute_source_url')) {
        return asset_proxy_absolute_source_url($href, $page, $config);
    }

    $href = trim($href);

    if ($href === '') {
        return '';
    }

    if (preg_match('#^(https?:)?//#i', $href)) {
        return str_starts_with($href, '//') ? 'https:' . $href : $href;
    }

    if (
        str_starts_with($href, '#') ||
        preg_match('#^(mailto|tel|javascript|data):#i', $href)
    ) {
        return '';
    }

    $baseUrl = '';

    if (!empty($page['source_url'])) {
        $baseUrl = (string)$page['source_url'];
    } elseif (!empty($page['source_base_url'])) {
        $baseUrl = rtrim((string)$page['source_base_url'], '/') . '/';
    } elseif (!empty($config['bookstack_base_url'])) {
        $baseUrl = rtrim((string)$config['bookstack_base_url'], '/') . '/';
    }

    if ($baseUrl === '') {
        return '';
    }

    $baseParts = parse_url($baseUrl);

    if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return '';
    }

    $origin = $baseParts['scheme'] . '://' . $baseParts['host'];

    if (!empty($baseParts['port'])) {
        $origin .= ':' . (int)$baseParts['port'];
    }

    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }

    $basePath = $baseParts['path'] ?? '/';
    $basePath = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';

    return $origin . $basePath . $href;
}

/**
 * Check whether a source URL belongs to one of the configured BookStack sources.
 *
 * @param string $url
 * @param array $config
 * @return bool
 */
function bookstack_link_is_allowed_source_url(string $url, array $config): bool
{
    if (function_exists('asset_proxy_is_allowed_source_url')) {
        return asset_proxy_is_allowed_source_url($url, $config);
    }

    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));

    if ($host === '') {
        return false;
    }

    foreach (($config['bookstack_sources'] ?? []) as $source) {
        if (!is_array($source) || empty($source['base_url'])) {
            continue;
        }

        $sourceHost = strtolower((string)(parse_url((string)$source['base_url'], PHP_URL_HOST) ?? ''));

        if ($sourceHost !== '' && $sourceHost === $host) {
            return true;
        }
    }

    return false;
}

/**
 * Look up the local wiki URL for an original BookStack page URL.
 *
 * @param PDO $pdo
 * @param array $config
 * @param string $sourceUrl
 * @param ?string $sourceKey
 * @return ?string
 */
function bookstack_link_find_local_url(PDO $pdo, array $config, string $sourceUrl, ?string $sourceKey = null): ?string
{
    static $cache = [];

    $normalized = bookstack_link_normalize_url($sourceUrl);

    if ($normalized === '') {
        return null;
    }

    $cacheKey = ($sourceKey ?? '') . '|' . $normalized;

    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    /*
     * Try exact source_url match first. Also try with a trailing slash because
     * links and stored source_url values may differ only by slash.
     */
    $candidates = [
        $normalized,
        rtrim($normalized, '/') . '/',
    ];

    $sql = "
        SELECT url_path
        FROM public_docs
        WHERE source_url IN (:url1, :url2)
    ";

    $params = [
        'url1' => $candidates[0],
        'url2' => $candidates[1],
    ];

    if ($sourceKey !== null && $sourceKey !== '') {
        $sql .= " AND source_key = :source_key";
        $params['source_key'] = $sourceKey;
    }

    $sql .= " LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch();

    if (!$row || empty($row['url_path'])) {
        $cache[$cacheKey] = null;
        return null;
    }

    $localUrl = canonical_url($config, (string)$row['url_path']);
    $cache[$cacheKey] = $localUrl;

    return $localUrl;
}

/**
 * Rewrite BookStack page links in synced HTML to local wiki URLs.
 *
 * @param PDO $pdo
 * @param string $html
 * @param array $page
 * @param array $config
 * @return string
 */
function rewrite_bookstack_page_links(PDO $pdo, string $html, array $page, array $config): string
{
    if (!bookstack_link_rewrite_enabled($config) || trim($html) === '') {
        return $html;
    }

    return preg_replace_callback(
        '#<a\b([^>]*?)\shref=([\'"])(.*?)\2([^>]*)>#is',
        static function (array $match) use ($pdo, $page, $config): string {
            $before = $match[1];
            $quote = $match[2];
            $href = html_entity_decode((string)$match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $after = $match[4];

            $absoluteUrl = bookstack_link_absolute_source_url($href, $page, $config);

            if ($absoluteUrl === '' || !bookstack_link_is_allowed_source_url($absoluteUrl, $config)) {
                return $match[0];
            }

            $localUrl = bookstack_link_find_local_url(
                $pdo,
                $config,
                $absoluteUrl,
                (string)($page['source_key'] ?? '')
            );

            if ($localUrl === null) {
                return $match[0];
            }

            return '<a' . $before . ' href=' . $quote . e($localUrl) . $quote . $after . '>';
        },
        $html
    ) ?? $html;
}
