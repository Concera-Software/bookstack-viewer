<?php

declare(strict_types=1);

/**
 * Route: Admin overview.
 */

if ($path !== "/admin") {
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

$modules = [
    [
        "name" => "Hidden and blocked pages",
        "description" => "View soft-hidden pages and hard-blocked pages.",
        "url" => "/admin/hidden-pages",
        "meta" => "Page visibility administration",
    ],
    [
        "name" => "Session management",
        "description" => "View logged-in sessions, stop sessions, and block IP addresses.",
        "url" => "/admin/sessions",
        "meta" => "Access and session administration",
    ],
    [
        "name" => "Admin users",
        "description" => "Manage administrator email addresses and bind them to one or more IP addresses.",
        "url" => "/admin/users",
        "meta" => "Administrator access control",
    ],
    [
        "name" => "Public users",
        "description" => "Manage registered public users.",
        "url" => "/admin/public-users",
        "meta" => "Public user management",
    ],

[
    "name" => "Download audit",
    "description" => "Read-only overview of all requested and executed downloads.",
    "url" => "/admin/downloads",
    "meta" => "Download audit log",
],
[
    "name" => "User activity",
    "description" => "Filter access activity by IP, date, date/time range, and URL.",
    "url" => "/admin/activity",
    "meta" => "Access activity log",
],

];

$content = '<article class="doc-page">';
$content .= '<h1>Admin</h1>';
$content .= '<p class="lead">Admin-only tools for managing public documentation access and visibility.</p>';

$content .= '<div class="page-overview">';

foreach ($modules as $module) {
    $content .= '<a class="overview-link" href="' . e($module["url"]) . '">';
    $content .= '<strong>' . e($module["name"]) . '</strong>';
    $content .= '<span>' . e($module["description"]) . '</span>';
    $content .= '<span class="card-meta">' . e($module["meta"]) . '</span>';
    $content .= '</a>';
}

$content .= '</div>';
$content .= '</article>';

$treePages = function_exists("fetch_tree_pages")
    ? fetch_tree_pages($pdo, $config, "admin")
    : [];

$html = function_exists("render_split_documentation")
    ? render_split_documentation($treePages, $content, -900000, [])
    : $content;

render_layout(
    $config,
    "Admin",
    "Admin modules.",
    $html,
    "/admin"
);

exit();
