<?php
/*
  ___           _       _           _    __   ___
 | _ ) ___  ___| |__ __| |_ __ _ __| |__ \ \ / (_)_____ __ _____ _ _
 | _ \/ _ \/ _ \ / /(_-<  _/ _` / _| / /  \ V /| / -_) V  V / -_) '_|
 |___/\___/\___/_\_\/__/\__\__,_\__|_\_\   \_/ |_\___|\_/\_/\___|_|

--------------------------------------------------------------------------

File	      : app/downloads/request.php
Version       : 1.0.0
Creation date : 2026/06/09
Authors       : we & ai
P.o.o.        : concera, the netherlands.

--------------------------------------------------------------------------

*/

declare(strict_types=1);

/**
 * Route: Request a download verification code.
 *
 * This page:
 * - requires an active access-gate session;
 * - sends a download-specific verification code;
 * - keeps the documentation tree visible;
 * - provides a back button to the previous download page.
 */

if ($path !== "/downloads/request") {
    return;
}

/**
 * Render this page inside the normal split documentation layout,
 * so the doc-tree remains visible.
 *
 * @param array $config
 * @param PDO $pdo
 * @param string $title
 * @param string $description
 * @param string $content
 * @param string $canonicalPath
 * @return void
 */
function downloads_request_render(
    array $config,
    PDO $pdo,
    string $title,
    string $description,
    string $content,
    string $canonicalPath
): void {
    $treePages = function_exists("fetch_tree_pages")
        ? fetch_tree_pages($pdo, $config, "downloads")
        : [];

    $html = function_exists("render_split_documentation")
        ? render_split_documentation($treePages, $content, -800000, [])
        : $content;

    render_layout(
        $config,
        $title,
        $description,
        $html,
        $canonicalPath
    );

    exit();
}

$returnTo = downloads_safe_return_to(
    (string)($_POST["return_to"] ?? ($_SERVER["HTTP_REFERER"] ?? "/downloads")),
    "/downloads"
);

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);

    $content = '<article class="doc-page">';
    $content .= '<p><a class="button-link" href="' . e($returnTo) . '">← Back</a></p>';
    $content .= '<h1>Method not allowed</h1>';
    $content .= '<div class="empty-state">This page only accepts download requests from the download button.</div>';
    $content .= '</article>';

    downloads_request_render(
        $config,
        $pdo,
        "Method not allowed",
        "Method not allowed.",
        $content,
        $returnTo
    );
}

$email = downloads_current_email($config);

if ($email === "") {
    http_response_code(403);

    $content = '<article class="doc-page">';
    $content .= '<p><a class="button-link" href="' . e($returnTo) . '">← Back</a></p>';
    $content .= '<h1>Access required</h1>';
    $content .= '<div class="empty-state">Please log in before requesting a download.</div>';
    $content .= '</article>';

    downloads_request_render(
        $config,
        $pdo,
        "Access required",
        "Please log in first.",
        $content,
        $returnTo
    );
}

$key = trim((string)($_POST["file"] ?? ($_GET["file"] ?? "")));
$download = downloads_find($config, $key);

if (!$download) {
    http_response_code(404);

    $content = '<article class="doc-page">';
    $content .= '<p><a class="button-link" href="' . e($returnTo) . '">← Back</a></p>';
    $content .= '<h1>Download not found</h1>';
    $content .= '<div class="empty-state">Download not found.</div>';
    $content .= '</article>';

    downloads_request_render(
        $config,
        $pdo,
        "Not found",
        "Download not found.",
        $content,
        $returnTo
    );
}

$sent = downloads_send_code($pdo, $config, $download);

$content = '<article class="doc-page">';
$content .= '<p><a class="button-link" href="' . e($returnTo) . '">← Back</a></p>';
$content .= '<h1>Verify download</h1>';

if (!$sent) {
    $content .= '<div class="empty-state">The verification code could not be sent. Please try again later.</div>';
} else {
    $content .= '<p class="lead">A verification code has been sent to <strong>' . e($email) . '</strong>.</p>';
    $content .= '<p>Enter the code below to download <strong>' . e((string)($download["filename"] ?? "download.zip")) . '</strong>.</p>';

    $content .= '<form method="post" action="/downloads/verify" class="admin-user-form">';
    $content .= '<input type="hidden" name="file" value="' . e((string)$download["download_key"]) . '">';
    $content .= '<input type="hidden" name="return_to" value="' . e($returnTo) . '">';

    $content .= '<label class="admin-form-field">';
    $content .= '<span>Verification code</span>';
    $content .= '<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required>';
    $content .= '</label>';

    $content .= '<button type="submit">Verify and download</button>';
    $content .= '</form>';
}

$content .= '</article>';

downloads_request_render(
    $config,
    $pdo,
    "Verify download",
    "Verify download.",
    $content,
    $returnTo
);
