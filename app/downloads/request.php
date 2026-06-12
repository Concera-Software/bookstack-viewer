<?php

declare(strict_types=1);

if ($path !== "/downloads/request") {
    return;
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
    http_response_code(405);
    echo "Method not allowed";
    exit();
}

$key = trim((string)($_POST["file"] ?? ($_GET["file"] ?? "")));
$download = downloads_find($config, $key);

if (!$download) {
    http_response_code(404);
    render_layout($config, "Not found", "Download not found.", '<div class="empty-state">Download not found.</div>', "/downloads");
    exit();
}

$email = downloads_current_email($config);

if ($email === '') {
    http_response_code(403);
    render_layout($config, "Access required", "Please log in first.", '<div class="empty-state">Please log in before requesting a download.</div>', "/downloads");
    exit();
}

$sent = downloads_send_code($pdo, $config, $download);

$content = '<article class="doc-page">';
$content .= '<h1>Verify download</h1>';

if (!$sent) {
    $content .= '<div class="empty-state">The verification code could not be sent. Please try again later.</div>';
} else {
    $content .= '<p class="lead">A verification code has been sent to <strong>' . e($email) . '</strong>.</p>';
    $content .= '<form method="post" action="/downloads/verify" class="admin-user-form">';
    $content .= '<input type="hidden" name="file" value="' . e((string)$download['download_key']) . '">';
    $content .= '<label class="admin-form-field"><span>Verification code</span><input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label>';
    $content .= '<button type="submit">Verify and download</button>';
    $content .= '</form>';
}

$content .= '</article>';

render_layout(
    $config,
    "Verify download",
    "Verify download.",
    $content,
    "/downloads"
);

exit();
