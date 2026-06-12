<?php

declare(strict_types=1);

if (!str_starts_with($path, "/downloads/info/")) {
    return;
}

$key = rawurldecode(substr($path, strlen("/downloads/info/")));
$download = downloads_find($config, $key);

if (!$download || empty($download['md_path']) || !is_file((string)$download['md_path'])) {
    http_response_code(404);
    render_layout($config, "Not found", "Page not found.", '<div class="empty-state">Download information not found.</div>', $path);
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
