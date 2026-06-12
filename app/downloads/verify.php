<?php

declare(strict_types=1);

if ($path !== "/downloads/verify") {
    return;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    echo "Method not allowed";
    exit();
}

$key = trim((string)($_POST["file"] ?? ""));
$code = trim((string)($_POST["code"] ?? ""));
$download = downloads_find($config, $key);

if (!$download) {
    http_response_code(404);
    render_layout($config, "Not found", "Download not found.", '<div class="empty-state">Download not found.</div>', "/downloads");
    exit();
}

if (!downloads_verify_code($pdo, $config, $download, $code)) {
    http_response_code(403);
    render_layout(
        $config,
        "Invalid code",
        "Invalid download code.",
        '<article class="doc-page"><h1>Invalid code</h1><p>The code is invalid or expired.</p>' . downloads_render_button($download) . '</article>',
        "/downloads"
    );
    exit();
}

$root = downloads_root($config);
$rootReal = realpath($root);
$filePath = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string)$download['relative_path']);
$fileReal = realpath($filePath);

if (
    $rootReal === false ||
    $fileReal === false ||
    !str_starts_with($fileReal, $rootReal . DIRECTORY_SEPARATOR) ||
    mb_strtolower(pathinfo($fileReal, PATHINFO_EXTENSION)) !== 'zip'
) {
    http_response_code(404);
    echo "Download not found";
    exit();
}

if (function_exists("access_gate_log")) {
    access_gate_log(
        $pdo,
        downloads_current_email($config),
        "download_file",
        true,
        "Downloaded " . (string)$download['download_key'],
        "/downloads/info/" . rawurlencode((string)$download['download_key'])
    );
}

header("Content-Type: application/zip");
header("Content-Length: " . filesize($fileReal));
header('Content-Disposition: attachment; filename="' . basename($fileReal) . '"');
header("X-Content-Type-Options: nosniff");

readfile($fileReal);
exit();
