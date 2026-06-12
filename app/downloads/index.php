<?php

declare(strict_types=1);

if ($path !== "/downloads" && !str_starts_with($path, "/downloads/category/")) {
    return;
}

if (!downloads_enabled($config)) {
    http_response_code(404);
    render_layout($config, "Not found", "Page not found.", '<div class="empty-state">Page not found.</div>', $path);
    exit();
}

$categoryFilter = '';

if (str_starts_with($path, "/downloads/category/")) {
    $categoryFilter = trim(rawurldecode(substr($path, strlen("/downloads/category/"))));
}

$downloads = downloads_scan($config);
$groups = downloads_group_by_category($downloads);

$content = '<article class="doc-page">';
$content .= '<h1>Downloads</h1>';
$content .= '<p class="lead">Available downloads. A verification code is required before downloading a file.</p>';

if ($categoryFilter !== '') {
    $content .= '<p><a href="/downloads">Show all downloads</a></p>';
}

if (!$groups) {
    $content .= '<div class="empty-state">No downloads are available.</div>';
} else {
    foreach ($groups as $group) {
        if ($categoryFilter !== '' && $group['slug'] !== $categoryFilter) {
            continue;
        }

        $content .= '<h2>' . e($group['name']) . '</h2>';
        $content .= '<div class="page-overview">';

        foreach ($group['items'] as $download) {
            $content .= '<div class="overview-link">';
            $content .= '<strong>';

            if (!empty($download['info_url'])) {
                $content .= '<a href="' . e((string)$download['info_url']) . '">' . e((string)$download['title']) . '</a>';
            } else {
                $content .= e((string)$download['title']);
            }

            $content .= '</strong>';

            $meta = [];
            $meta[] = 'File: ' . (string)$download['filename'];
            $meta[] = 'Size: ' . number_format(((int)$download['size_bytes']) / 1048576, 2) . ' MB';
            $meta[] = 'Updated: ' . date('Y-m-d H:i:s', (int)$download['updated_at']);

            $content .= '<span>' . e(implode(' • ', $meta)) . '</span>';
            $content .= '<div class="download-actions">';
            $content .= downloads_render_button($download);

            if (!empty($download['info_url'])) {
                $content .= ' <a class="button-link" href="' . e((string)$download['info_url']) . '">Info</a>';
            }

            $content .= '</div>';
            $content .= '</div>';
        }

        $content .= '</div>';
    }
}

$content .= '</article>';

$treePages = function_exists("fetch_tree_pages")
    ? fetch_tree_pages($pdo, $config, "downloads")
    : [];

$html = function_exists("render_split_documentation")
    ? render_split_documentation($treePages, $content, -800000, [])
    : $content;

render_layout(
    $config,
    "Downloads",
    "Available downloads.",
    $html,
    $path
);

exit();
