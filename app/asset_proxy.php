<?php

declare(strict_types=1);

/**
 * Asset proxy for images and documents referenced by synced BookStack pages.
 *
 * The proxy only accepts source URLs from configured BookStack source domains.
 * This prevents the endpoint from becoming an unrestricted public proxy.
 */

/**
 * Return the configured asset proxy path.
 *
 * @param array $config
 * @return string
 */
function asset_proxy_path(array $config): string
{
    $path = (string)($config['asset_proxy_path'] ?? '/asset-proxy');

    if ($path === '' || $path[0] !== '/') {
        return '/asset-proxy';
    }

    return rtrim($path, '/') ?: '/asset-proxy';
}

/**
 * Check whether the asset proxy is enabled.
 *
 * @param array $config
 * @return bool
 */
function asset_proxy_enabled(array $config): bool
{
    return !empty($config['asset_proxy_enabled']);
}

/**
 * Return the local asset cache directory.
 *
 * @param array $config
 * @return string
 */
function asset_proxy_cache_dir(array $config): string
{
    return rtrim(
        (string)($config['asset_proxy_cache_dir'] ?? (__DIR__ . '/../var/asset-cache')),
        '/'
    );
}

/**
 * Encode a value as URL-safe base64.
 *
 * @param string $value
 * @return string
 */
function asset_proxy_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Decode a URL-safe base64 value.
 *
 * @param string $value
 * @return string
 */
function asset_proxy_base64url_decode(string $value): string
{
    $padding = strlen($value) % 4;

    if ($padding > 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);

    return $decoded === false ? '' : $decoded;
}

/**
 * Return all allowed source hosts based on bookstack_sources and legacy config.
 *
 * @param array $config
 * @return array
 */
function asset_proxy_allowed_hosts(array $config): array
{
    $hosts = [];

    foreach (($config['bookstack_sources'] ?? []) as $source) {
        if (!is_array($source) || empty($source['base_url'])) {
            continue;
        }

        $host = parse_url((string)$source['base_url'], PHP_URL_HOST);

        if ($host) {
            $hosts[] = strtolower($host);
        }
    }

    if (!empty($config['bookstack_base_url'])) {
        $host = parse_url((string)$config['bookstack_base_url'], PHP_URL_HOST);

        if ($host) {
            $hosts[] = strtolower($host);
        }
    }

    return array_values(array_unique($hosts));
}

/**
 * Check whether a source URL is allowed to be proxied.
 *
 * @param string $url
 * @param array $config
 * @return bool
 */
function asset_proxy_is_allowed_source_url(string $url, array $config): bool
{
    $parts = parse_url($url);

    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }

    $scheme = strtolower((string)$parts['scheme']);

    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }

    $host = strtolower((string)$parts['host']);

    return in_array($host, asset_proxy_allowed_hosts($config), true);
}

/**
 * Return the file extension from a URL path.
 *
 * @param string $url
 * @return string
 */
function asset_proxy_url_extension(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);

    if (!is_string($path) || $path === '') {
        return '';
    }

    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

/**
 * Check whether a URL looks like an image URL.
 *
 * @param string $url
 * @param array $config
 * @return bool
 */
function asset_proxy_is_image_url(string $url, array $config): bool
{
    $extension = asset_proxy_url_extension($url);
    $allowed = $config['asset_proxy_image_extensions'] ?? [];

    if (!is_array($allowed)) {
        $allowed = [];
    }

    return $extension !== '' && in_array($extension, array_map('strtolower', $allowed), true);
}

/**
 * Check whether a URL looks like a document URL.
 *
 * @param string $url
 * @param array $config
 * @return bool
 */
function asset_proxy_is_document_url(string $url, array $config): bool
{
    $extension = asset_proxy_url_extension($url);
    $allowed = $config['asset_proxy_document_extensions'] ?? [];

    if (!is_array($allowed)) {
        $allowed = [];
    }

    return $extension !== '' && in_array($extension, array_map('strtolower', $allowed), true);
}

/**
 * Build an absolute source URL from a possibly relative BookStack asset URL.
 *
 * @param string $url
 * @param array $page
 * @param array $config
 * @return string
 */
function asset_proxy_absolute_source_url(string $url, array $page, array $config): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    if (preg_match('#^(https?:)?//#i', $url)) {
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }

        return $url;
    }

    if (
        str_starts_with($url, '#') ||
        preg_match('#^(mailto|tel|javascript|data):#i', $url)
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
        $origin .= ':' . $baseParts['port'];
    }

    if (str_starts_with($url, '/')) {
        return $origin . $url;
    }

    $basePath = $baseParts['path'] ?? '/';
    $basePath = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';

    return $origin . $basePath . $url;
}

/**
 * Build a local public proxy URL for an original source URL.
 *
 * @param string $sourceUrl
 * @param array $config
 * @return string
 */
function asset_proxy_public_url(string $sourceUrl, array $config): string
{
    return asset_proxy_path($config) . '?u=' . rawurlencode(asset_proxy_base64url_encode($sourceUrl));
}

/**
 * Rewrite one URL value to the local proxy if it is allowed.
 *
 * @param string $url
 * @param array $page
 * @param array $config
 * @param bool $isImageContext
 * @return string
 */
function asset_proxy_rewrite_url(string $url, array $page, array $config, bool $isImageContext = false): string
{
    if (!asset_proxy_enabled($config)) {
        return $url;
    }

    $absoluteUrl = asset_proxy_absolute_source_url($url, $page, $config);

    if ($absoluteUrl === '') {
        return $url;
    }

    if (!asset_proxy_is_allowed_source_url($absoluteUrl, $config)) {
        return $url;
    }

    if ($isImageContext) {
        return asset_proxy_public_url($absoluteUrl, $config);
    }

    if (asset_proxy_is_document_url($absoluteUrl, $config)) {
        return asset_proxy_public_url($absoluteUrl, $config);
    }

    return $url;
}

/**
 * Rewrite srcset URLs.
 *
 * @param string $srcset
 * @param array $page
 * @param array $config
 * @return string
 */
function asset_proxy_rewrite_srcset(string $srcset, array $page, array $config): string
{
    $parts = array_map('trim', explode(',', $srcset));
    $rewritten = [];

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $segments = preg_split('/\s+/', $part);
        $url = $segments[0] ?? '';

        if ($url === '') {
            $rewritten[] = $part;
            continue;
        }

        $segments[0] = asset_proxy_rewrite_url($url, $page, $config, true);
        $rewritten[] = implode(' ', $segments);
    }

    return implode(', ', $rewritten);
}

/**
 * Rewrite image and document references in synced BookStack HTML.
 *
 * @param string $html
 * @param array $page
 * @param array $config
 * @return string
 */
function asset_proxy_rewrite_html(string $html, array $page, array $config): string
{
    if (!asset_proxy_enabled($config) || trim($html) === '') {
        return $html;
    }

    /*
     * Rewrite img/src and source/srcset image references.
     */
    $html = preg_replace_callback(
        '#<(img|source)\b([^>]*?)\s(src|srcset)=([\'"])(.*?)\4([^>]*)>#is',
        static function (array $match) use ($page, $config): string {
            $tag = $match[1];
            $before = $match[2];
            $attribute = strtolower($match[3]);
            $quote = $match[4];
            $value = $match[5];
            $after = $match[6];

            if ($attribute === 'srcset') {
                $newValue = asset_proxy_rewrite_srcset($value, $page, $config);
            } else {
                $newValue = asset_proxy_rewrite_url($value, $page, $config, true);
            }

            return '<' . $tag . $before . ' ' . $attribute . '=' . $quote . e($newValue) . $quote . $after . '>';
        },
        $html
    ) ?? $html;

    /*
     * Rewrite document links. Normal links to other pages are left untouched.
     */
    $html = preg_replace_callback(
        '#<a\b([^>]*?)\shref=([\'"])(.*?)\2([^>]*)>#is',
        static function (array $match) use ($page, $config): string {
            $before = $match[1];
            $quote = $match[2];
            $value = $match[3];
            $after = $match[4];

            $newValue = asset_proxy_rewrite_url($value, $page, $config, false);

            return '<a' . $before . ' href=' . $quote . e($newValue) . $quote . $after . '>';
        },
        $html
    ) ?? $html;

    return $html;
}

/**
 * Return cache file paths for a source URL.
 *
 * @param string $sourceUrl
 * @param array $config
 * @return array
 */
function asset_proxy_cache_paths(string $sourceUrl, array $config): array
{
    $cacheDir = asset_proxy_cache_dir($config);
    $hash = hash('sha256', $sourceUrl);

    return [
        'hash' => $hash,
        'body' => $cacheDir . '/' . $hash . '.bin',
        'meta' => $cacheDir . '/' . $hash . '.json',
    ];
}

/**
 * Guess a safe content type from file extension.
 *
 * @param string $url
 * @return string
 */
function asset_proxy_guess_content_type(string $url): string
{
    $extension = asset_proxy_url_extension($url);

    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
        default => 'application/octet-stream',
    };
}

/**
 * Check whether a cached file is still fresh.
 *
 * @param string $bodyPath
 * @param array $config
 * @return bool
 */
function asset_proxy_cache_is_fresh(string $bodyPath, array $config): bool
{
    if (!is_file($bodyPath)) {
        return false;
    }

    $cacheDays = max(1, (int)($config['asset_proxy_cache_days'] ?? 30));
    $maxAge = $cacheDays * 86400;

    return (time() - filemtime($bodyPath)) < $maxAge;
}

/**
 * Remove cached files older than the configured cache age.
 *
 * @param array $config
 * @return void
 */
function asset_proxy_cleanup(array $config): void
{
    $cacheDir = asset_proxy_cache_dir($config);

    if (!is_dir($cacheDir)) {
        return;
    }

    $cacheDays = max(1, (int)($config['asset_proxy_cache_days'] ?? 30));
    $maxAge = $cacheDays * 86400;
    $now = time();

    foreach (new DirectoryIterator($cacheDir) as $file) {
        if ($file->isDot() || !$file->isFile()) {
            continue;
        }

        if (($now - $file->getMTime()) <= $maxAge) {
            continue;
        }

        @unlink($file->getPathname());
    }
}

/**
 * Download an original file into the cache.
 *
 * @param string $sourceUrl
 * @param array $config
 * @return array
 */
function asset_proxy_download_to_cache(string $sourceUrl, array $config): array
{
    $paths = asset_proxy_cache_paths($sourceUrl, $config);
    $cacheDir = asset_proxy_cache_dir($config);

    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0750, true) && !is_dir($cacheDir)) {
        throw new RuntimeException('Could not create asset cache directory.');
    }

    $maxBytes = max(1024, (int)($config['asset_proxy_max_bytes'] ?? 52428800));
    $tmpPath = $paths['body'] . '.tmp';

    $handle = fopen($tmpPath, 'wb');

    if (!$handle) {
        throw new RuntimeException('Could not create temporary cache file.');
    }

    $downloadedBytes = 0;
    $contentType = asset_proxy_guess_content_type($sourceUrl);

    $curl = curl_init($sourceUrl);

    if (!$curl) {
        fclose($handle);
        @unlink($tmpPath);
        throw new RuntimeException('Could not initialize download.');
    }

    curl_setopt_array($curl, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => 'CoCoS BookStack Viewer Asset Proxy',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$contentType): int {
            if (stripos($header, 'Content-Type:') === 0) {
                $value = trim(substr($header, strlen('Content-Type:')));
                $value = explode(';', $value)[0] ?? '';

                if ($value !== '') {
                    $contentType = strtolower($value);
                }
            }

            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use ($handle, &$downloadedBytes, $maxBytes): int {
            $length = strlen($data);
            $downloadedBytes += $length;

            if ($downloadedBytes > $maxBytes) {
                return 0;
            }

            return fwrite($handle, $data);
        },
    ]);

    $success = curl_exec($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);

    curl_close($curl);
    fclose($handle);

    if (!$success || $httpCode < 200 || $httpCode >= 300) {
        @unlink($tmpPath);
        throw new RuntimeException('Could not download original asset. ' . $curlError);
    }

    rename($tmpPath, $paths['body']);

    $meta = [
        'source_url' => $sourceUrl,
        'content_type' => $contentType,
        'bytes' => filesize($paths['body']) ?: 0,
        'cached_at' => date('c'),
    ];

    file_put_contents($paths['meta'], json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $meta;
}

/**
 * Read cached asset metadata.
 *
 * @param string $metaPath
 * @param string $sourceUrl
 * @return array
 */
function asset_proxy_read_meta(string $metaPath, string $sourceUrl): array
{
    if (!is_file($metaPath)) {
        return [
            'source_url' => $sourceUrl,
            'content_type' => asset_proxy_guess_content_type($sourceUrl),
        ];
    }

    $json = file_get_contents($metaPath);
    $meta = is_string($json) ? json_decode($json, true) : null;

    if (!is_array($meta)) {
        return [
            'source_url' => $sourceUrl,
            'content_type' => asset_proxy_guess_content_type($sourceUrl),
        ];
    }

    return $meta;
}

/**
 * Serve the proxied asset request.
 *
 * @param array $config
 * @return void
 */
function asset_proxy_handle_request(array $config): void
{
    if (!asset_proxy_enabled($config)) {
        http_response_code(404);
        echo 'Asset proxy disabled.';
        return;
    }

    $encodedUrl = (string)($_GET['u'] ?? '');
    $sourceUrl = asset_proxy_base64url_decode($encodedUrl);

    if ($sourceUrl === '' || !asset_proxy_is_allowed_source_url($sourceUrl, $config)) {
        http_response_code(400);
        echo 'Invalid asset URL.';
        return;
    }

    if (
        !asset_proxy_is_image_url($sourceUrl, $config) &&
        !asset_proxy_is_document_url($sourceUrl, $config)
    ) {
        http_response_code(400);
        echo 'Asset type not allowed.';
        return;
    }

    /*
     * Cleanup is intentionally lightweight and runs with a small probability
     * so normal asset requests do not constantly scan the cache directory.
     */
    if (random_int(1, 100) === 1) {
        asset_proxy_cleanup($config);
    }

    $paths = asset_proxy_cache_paths($sourceUrl, $config);

    try {
        if (!asset_proxy_cache_is_fresh($paths['body'], $config)) {
            @unlink($paths['body']);
            @unlink($paths['meta']);

            $meta = asset_proxy_download_to_cache($sourceUrl, $config);
        } else {
            $meta = asset_proxy_read_meta($paths['meta'], $sourceUrl);
        }
    } catch (Throwable $e) {
        http_response_code(502);
        echo 'Could not load asset.';
        return;
    }

    if (!is_file($paths['body'])) {
        http_response_code(404);
        echo 'Asset not found.';
        return;
    }

    $contentType = (string)($meta['content_type'] ?? asset_proxy_guess_content_type($sourceUrl));
    $fileSize = filesize($paths['body']);

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . (string)$fileSize);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');

    readfile($paths['body']);
}
