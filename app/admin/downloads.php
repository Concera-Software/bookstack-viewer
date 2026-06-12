<?php

declare(strict_types=1);

/**
 * Route: Admin download audit.
 */

if ($path !== "/admin/downloads") {
    return;
}

if (
    !function_exists("can_access_admin_pages") ||
    !can_access_admin_pages($config, null)
) {
    http_response_code(404);

    render_layout(
        $config,
        "Not found",
        "Page not found.",
        '<div class="empty-state">Page not found.</div>',
        $path
    );

    exit();
}

header("X-Robots-Tag: noindex, nofollow, noarchive", true);

$stmt = $pdo->query("
    SELECT
        id,
        email,
        download_key,
        download_title,
        filename,
        category,
        ip_address,
        status,
        code_sent_at,
        verified_at,
        downloaded_at,
        failed_at,
        failure_reason,
        created_at
    FROM public_download_requests
    ORDER BY created_at DESC
    LIMIT 500
");

$downloads = $stmt->fetchAll();

$content = '<article class="doc-page">';
$content .= '<h1>Download audit</h1>';
$content .= '<p class="lead">Read-only list of requested and executed downloads.</p>';

if (!$downloads) {
    $content .= '<div class="empty-state">No download requests found.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>Status</th>';
    $content .= '<th>Created</th>';
    $content .= '<th>Email</th>';
    $content .= '<th>IP address</th>';
    $content .= '<th>Category</th>';
    $content .= '<th>File</th>';
    $content .= '<th>Verified</th>';
    $content .= '<th>Downloaded</th>';
    $content .= '<th>Failure</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody>';

    foreach ($downloads as $download) {
        $status = (string)($download['status'] ?? '');

        $rowClass = 'is-active';

        if ($status === 'failed') {
            $rowClass = 'is-revoked';
        } elseif ($status !== 'downloaded') {
            $rowClass = 'is-expired';
        }

        $content .= '<tr class="' . e($rowClass) . '">';
        $content .= '<td><strong>' . e($status) . '</strong></td>';
        $content .= '<td>' . e((string)($download['created_at'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($download['email'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($download['ip_address'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($download['category'] ?? '')) . '</td>';
        $content .= '<td>';
        $content .= '<strong>' . e((string)($download['filename'] ?? '')) . '</strong>';
        $content .= '<br><span class="admin-user-agent">' . e((string)($download['download_key'] ?? '')) . '</span>';
        $content .= '</td>';
        $content .= '<td>' . e((string)($download['verified_at'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($download['downloaded_at'] ?? '')) . '</td>';
        $content .= '<td>' . e((string)($download['failure_reason'] ?? '')) . '</td>';
        $content .= '</tr>';
    }

    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';
}

$content .= '</article>';

$treePages = function_exists("fetch_tree_pages")
    ? fetch_tree_pages($pdo, $config, "admin")
    : [];

$html = function_exists("render_split_documentation")
    ? render_split_documentation($treePages, $content, -900004, [])
    : $content;

render_layout(
    $config,
    "Download audit",
    "Download audit.",
    $html,
    "/admin/downloads"
);

exit();
