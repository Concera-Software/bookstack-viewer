<?php

declare(strict_types=1);

/**
 * Return whether page cache is enabled.
 *
 * @param array $config
 * @return bool
 */
function page_cache_enabled(array $config): bool
{
    return !empty($config['page_cache_enabled']);
}

/**
 * Return page cache directory.
 *
 * @param array $config
 * @return string
 */
function page_cache_dir(array $config): string
{
    return rtrim(
        (string)($config['page_cache_dir'] ?? (__DIR__ . '/../var/page-cache')),
        '/'
    );
}

/**
 * Check whether the current request may be cached.
 *
 * @param array $config
 * @param string $path
 * @return bool
 */
function page_cache_is_cacheable_request(array $config, string $path): bool
{
    if (!page_cache_enabled($config)) {
        header('X-Page-Cache: DISABLED');
        return false;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('X-Page-Cache: SKIP-METHOD');
        return false;
    }

    /*
     * Do not cache pages with query strings.
     */
    if (!empty($_GET)) {
        header('X-Page-Cache: SKIP-QUERY');
        return false;
    }

    if (
        str_starts_with($path, '/access/') ||
        str_starts_with($path, '/admin/') ||
        str_starts_with($path, '/asset-proxy') ||
        $path === '/search' ||
        $path === '/robots.txt' ||
        $path === '/sitemap.xml'
    ) {
        header('X-Page-Cache: SKIP-ROUTE');
        return false;
    }

    /*
     * Do not cache pages shown to verified users because they may contain
     * user/access/admin specific elements.
     */
    if (!empty($_SESSION['manual_access_email'])) {
        header('X-Page-Cache: SKIP-SESSION');
        return false;
    }

    return true;
}

/**
 * Return the cache file path for a page path.
 *
 * @param array $config
 * @param string $path
 * @return string
 */
function page_cache_file(array $config, string $path): string
{
    return page_cache_dir($config) . '/' . hash('sha256', $path) . '.html';
}

/**
 * Try to serve a cached page.
 *
 * @param array $config
 * @param string $path
 * @return bool
 */
function page_cache_try_serve(array $config, string $path): bool
{
    if (!page_cache_is_cacheable_request($config, $path)) {
        return false;
    }

    $file = page_cache_file($config, $path);

    if (!is_file($file)) {
        header('X-Page-Cache: MISS');
        return false;
    }

    $cacheDays = max(1, (int)($config['page_cache_days'] ?? 7));
    $maxAge = $cacheDays * 86400;

    if ((time() - filemtime($file)) > $maxAge) {
        @unlink($file);
        header('X-Page-Cache: STALE');
        return false;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: public, max-age=300');
    header('X-Page-Cache: HIT');

//    readfile($file);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        readfile($file);
    }

    return true;
}

/**
 * Start output buffering and write the generated page to cache.
 *
 * @param array $config
 * @param string $path
 * @return void
 */
function page_cache_start(array $config, string $path): void
{
    if (!page_cache_is_cacheable_request($config, $path)) {
        return;
    }

    $cacheDir = page_cache_dir($config);

    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
        header('X-Page-Cache: WRITE-DIR-FAILED');
        return;
    }

    if (!is_writable($cacheDir)) {
        header('X-Page-Cache: WRITE-DIR-NOT-WRITABLE');
        return;
    }

    $file = page_cache_file($config, $path);

    ob_start(static function (string $html) use ($file): string {
      $statusCode = http_response_code();
      $lowerHtml = strtolower($html);

      if (
        ($statusCode === 200 || $statusCode === false) &&
        str_contains($lowerHtml, '<!doctype html>') &&
        !str_contains($lowerHtml, 'accessoverlay') &&
        !str_contains($lowerHtml, 'access-locked')
      ) {
        $result = @file_put_contents($file, $html, LOCK_EX);

        if ($result === false && !headers_sent()) {
            header('X-Page-Cache-Write: FAILED');
        } elseif (!headers_sent()) {
            header('X-Page-Cache-Write: OK');
        }
      } elseif (!headers_sent()) {
        header('X-Page-Cache-Write: SKIP-LOCKED');
      }

      return $html;
    });

}

/**
 * Clear all cached HTML pages.
 *
 * @param array $config
 * @return int
 */
function page_cache_clear(array $config): int
{
    $cacheDir = page_cache_dir($config);

    if (!is_dir($cacheDir)) {
        return 0;
    }

    $deleted = 0;

    foreach (new DirectoryIterator($cacheDir) as $file) {
        if ($file->isDot() || !$file->isFile()) {
            continue;
        }

        if (strtolower($file->getExtension()) !== 'html') {
            continue;
        }

        if (@unlink($file->getPathname())) {
            $deleted++;
        }
    }

    return $deleted;
}
