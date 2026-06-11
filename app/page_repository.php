<?php

declare(strict_types=1);

/**
 * Page repository helpers for the public documentation frontend.
 *
 * These functions keep database lookups for books, pages and the
 * documentation tree outside index.php, so index.php can remain focused on
 * routing and rendering.
 */

/**
 * Fetch all public pages for a book.
 *
 * @param PDO $pdo
 * @param string $bookSlug
 * @return array
 */
function fetch_book_pages(PDO $pdo, string $bookSlug): array
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            source_key,
            source_page_id,
            page_name,
            page_slug,
            book_id,
            book_name,
            book_slug,
            chapter_id,
            chapter_name,
            chapter_slug,
            url_path,
            html,
            text_content,
            description,
            updated_at
        FROM public_docs
        WHERE book_slug = :book_slug
        ORDER BY
            CASE WHEN chapter_name IS NULL OR chapter_name = '' THEN 0 ELSE 1 END ASC,
            chapter_name ASC,
            page_name ASC
    ");

    $stmt->execute([
        "book_slug" => $bookSlug,
    ]);

    $pages = $stmt->fetchAll();

    if (function_exists("filter_pages_for_current_ip")) {
        return filter_pages_for_current_ip(
            $pdo,
            $GLOBALS["config"] ?? [],
            $pages
        );
    }

    return $pages;
}

/**
 * Fetch all pages for the global documentation tree.
 *
 * @param PDO $pdo
 * @return array
 */
function fetch_all_tree_pages(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            id,
            source_key,
            source_page_id,
            page_name,
            page_slug,
            book_id,
            book_name,
            book_slug,
            chapter_id,
            chapter_name,
            chapter_slug,
            url_path,
            html,
            text_content,
            description,
            updated_at
        FROM public_docs
        WHERE book_slug IS NOT NULL
          AND book_slug != ''
        ORDER BY
            book_name ASC,
            book_slug ASC,
            CASE WHEN chapter_name IS NULL OR chapter_name = '' THEN 0 ELSE 1 END ASC,
            chapter_name ASC,
            page_name ASC
    ");

    $pages = $stmt->fetchAll();

    if (function_exists("filter_pages_for_current_ip")) {
        return filter_pages_for_current_ip(
            $pdo,
            $GLOBALS["config"] ?? [],
            $pages
        );
    }

    return $pages;
}

/**
 * Return synthetic admin pages for the documentation tree.
 *
 * @param array $config
 * @return array
 */
function admin_tree_pages(array $config): array
{
    if (
        !function_exists('can_access_admin_pages') ||
        !can_access_admin_pages($config, null)
    ) {
        return [];
    }

    return [
        [
            'id' => -900001,
            'source_key' => 'admin',
            'source_page_id' => 0,
            'page_name' => 'Hidden and blocked pages',
            'page_slug' => 'hidden-pages',
            'book_id' => -900001,
            'book_name' => 'Admin',
            'book_slug' => 'admin',
            'book_url' => '/admin/hidden-pages',
            'chapter_id' => -900001,
            'chapter_name' => 'Administration',
            'chapter_slug' => 'administration',
            'url_path' => '/admin/hidden-pages',
            'html' => '',
            'text_content' => '',
            'description' => 'Admin overview of hidden and blocked documentation pages.',
            'updated_at' => '',
        ],
[
    'id' => -900002,
    'source_key' => 'admin',
    'source_page_id' => 0,
    'page_name' => 'Session management',
    'page_slug' => 'sessions',
    'book_id' => -900001,
    'book_name' => 'Admin',
    'book_slug' => 'admin',
    'book_url' => '/admin/hidden-pages',
    'chapter_id' => -900001,
    'chapter_name' => 'Administration',
    'chapter_slug' => 'administration',
    'url_path' => '/admin/sessions',
    'html' => '',
    'text_content' => '',
    'description' => 'Admin session management.',
    'updated_at' => '',
],

    ];
}

/**
 * Return the pages that should be shown in the left documentation tree.
 *
 * @param PDO $pdo
 * @param array $config
 * @param string $currentBookSlug
 * @return array
 */
function fetch_tree_pages(
    PDO $pdo,
    array $config,
    string $currentBookSlug
): array {

if (!empty($config["doc_tree_show_all_books"])) {
    $pages = fetch_all_tree_pages($pdo);
} else {
    $pages = fetch_book_pages($pdo, $currentBookSlug);
}

return array_merge($pages, admin_tree_pages($config));

}

/**
 * Redirect requests to the configured base_url when the current host/scheme
 * does not match the canonical site URL.
 *
 * The full request path and query string are preserved.
 *
 * @param array $config Application configuration.
 * @return void
 */
function redirect_to_base_url_if_needed(array $config): void
{
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

    if ($baseUrl === '') {
        return;
    }

    $baseParts = parse_url($baseUrl);

    if (
        empty($baseParts['scheme']) ||
        empty($baseParts['host'])
    ) {
        return;
    }

    $targetScheme = strtolower((string)$baseParts['scheme']);
    $targetHost = strtolower((string)$baseParts['host']);
    $targetPort = isset($baseParts['port']) ? (int)$baseParts['port'] : null;

    $currentHostHeader = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $currentHost = $currentHostHeader;
    $currentPort = null;

    if (str_contains($currentHostHeader, ':')) {
        [$currentHost, $port] = explode(':', $currentHostHeader, 2);
        $currentPort = (int)$port;
    }

    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
    );

    $currentScheme = $isHttps ? 'https' : 'http';

    $schemeDiffers = $currentScheme !== $targetScheme;
    $hostDiffers = $currentHost !== $targetHost;
    $portDiffers = $targetPort !== null && $currentPort !== $targetPort;

    if (!$schemeDiffers && !$hostDiffers && !$portDiffers) {
        return;
    }

    $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '/');

    if ($requestUri === '' || $requestUri[0] !== '/') {
        $requestUri = '/';
    }

    $redirectUrl = $baseUrl . $requestUri;

    header('Location: ' . $redirectUrl, true, 301);
    exit();
}
