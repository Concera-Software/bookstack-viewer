<?php

declare(strict_types=1);

if (!preg_match('#^/downloads/info/([^/]+)/([^/]+)$#', $path, $match)) {
    return;
}

$categorySlug = rawurldecode($match[1]);
$infoSlug = rawurldecode($match[2]);

$download = downloads_find_by_info_slug($config, $categorySlug, $infoSlug);


if (!downloads_access_is_verified($config)) {
    $content = '<article class="doc-page">';
    $content .= '<h1>Download information</h1>';
    $content .= '<p class="lead">Please request access to view this download information.</p>';
    $content .= '</article>';

    $treePages = function_exists("fetch_tree_pages")
        ? fetch_tree_pages($pdo, $config, "downloads")
        : [];

    $html = function_exists("render_split_documentation")
        ? render_split_documentation($treePages, $content, -800000, [])
        : $content;

    render_layout(
        $config,
        "Download information",
        "Download information.",
        $html,
        $path
    );

    exit();
}

if (!$download || empty($download['md_path']) || !is_file((string)$download['md_path'])) {
    http_response_code(404);

    render_layout(
        $config,
        "Not found",
        "Page not found.",
        '<div class="empty-state">Download information not found.</div>',
        $path
    );

    exit();
}

$markdown = (string)file_get_contents((string)$download['md_path']);
$infoHtml = downloads_markdown_to_html($markdown);

$content = '<article class="doc-page">';
$content .= '<div class="doc-body">';
$content .= $infoHtml;
$content .= '</div>';

$content .= '<hr>';
$content .= '<h2>Download</h2>';
$content .= '<p><strong>' . e((string)$download['filename']) . '</strong></p>';

/*
 * The download button may be shown on the info page.
 * If the visitor is not verified, the normal access-gate overlay will prevent use.
 * After login, the user returns to this same info page.
 */
$content .= downloads_render_button($download);

$content .= '</article>';

$treePages = function_exists("fetch_tree_pages")
    ? fetch_tree_pages($pdo, $config, "downloads")
    : [];

$html = function_exists("render_split_documentation")
    ? render_split_documentation($treePages, $content, -800000, [])
    : $content;

render_layout(
    $config,
    (string)$download['title'],
    "Download information.",
    $html,
    $path
);

exit();
