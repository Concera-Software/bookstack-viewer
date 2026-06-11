<?php

declare(strict_types=1);

/**
 * Route: Admin user management.
 */

if ($path !== "/admin/users") {
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

$adminEmail = function_exists("current_verified_access_email")
    ? current_verified_access_email()
    : (string)($_SESSION["manual_access_email"] ?? "");

$adminIp = function_exists("access_gate_ip_address")
    ? access_gate_ip_address()
    : (string)($_SERVER["REMOTE_ADDR"] ?? "");

$error = '';

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));

    if ($action === "save_admin_user") {
        $email = trim((string)($_POST["email"] ?? ""));
        $ipText = (string)($_POST["ip_addresses"] ?? "");
        $notes = trim((string)($_POST["notes"] ?? ""));
        $enabled = isset($_POST["is_enabled"]);

        $ips = admin_user_normalize_ip_list($ipText);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } elseif (!$ips) {
            $error = "Please enter at least one valid IP address.";
        } else {
            admin_user_save(
                $pdo,
                $email,
                $ips,
                $enabled,
                $notes,
                $adminEmail,
                $adminIp
            );

            header("Location: /admin/users");
            exit();
        }
    }

    if ($action === "delete_admin_user") {
        $email = trim((string)($_POST["email"] ?? ""));

        if ($email !== '') {
            admin_user_delete($pdo, $email);
        }

        header("Location: /admin/users");
        exit();
    }
}

$users = function_exists("admin_users_all")
    ? admin_users_all($pdo)
    : [];

$content = '<article class="doc-page">';
$content .= '<h1>Admin users</h1>';
$content .= '<p class="lead">Manage administrator email addresses and bind each administrator to one or more IP addresses.</p>';

$content .= '<div class="empty-state">';
$content .= '<strong>Rule:</strong> When an email address exists here, this database entry overrules the same admin user in app/config.php.';
$content .= '</div>';

if ($error !== '') {
    $content .= '<div class="empty-state">' . e($error) . '</div>';
}

$content .= '<h2>Add or update admin user</h2>';
$content .= '<form method="post" class="admin-user-form">';
$content .= '<input type="hidden" name="action" value="save_admin_user">';

$content .= '<label class="admin-form-field">';
$content .= '<span>Email address</span>';
$content .= '<input type="email" name="email" required placeholder="admin@example.com">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>Allowed IP addresses</span>';
$content .= '<textarea name="ip_addresses" rows="5" required placeholder="One IP per line"></textarea>';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>Notes</span>';
$content .= '<input type="text" name="notes" maxlength="255" placeholder="Optional note">';
$content .= '</label>';

$content .= '<label class="admin-check-field">';
$content .= '<input type="checkbox" name="is_enabled" value="1" checked>';
$content .= '<span>Enabled</span>';
$content .= '</label>';

$content .= '<button type="submit">Save admin user</button>';
$content .= '</form>';

$content .= '<h2>Configured admin users</h2>';

if (!$users) {
    $content .= '<div class="empty-state">No database admin users found. The system is using app/config.php fallback admins.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>Status</th>';
    $content .= '<th>Email</th>';
    $content .= '<th>Allowed IP addresses</th>';
    $content .= '<th>Notes</th>';
    $content .= '<th>Updated</th>';
    $content .= '<th>Actions</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody>';

    foreach ($users as $user) {
        $email = (string)($user["email"] ?? "");
        $enabled = (int)($user["is_enabled"] ?? 0) === 1;
        $ipText = (string)($user["ip_addresses"] ?? "");
        $notes = (string)($user["notes"] ?? "");
        $updatedAt = (string)($user["updated_at"] ?? "");

        $content .= '<tr class="' . e($enabled ? "is-active" : "is-revoked") . '">';
        $content .= '<td><strong>' . e($enabled ? "Enabled" : "Disabled") . '</strong></td>';
        $content .= '<td>' . e($email) . '</td>';
        $content .= '<td><pre class="admin-ip-list">' . e($ipText) . '</pre></td>';
        $content .= '<td>' . e($notes) . '</td>';
        $content .= '<td>' . e($updatedAt) . '</td>';

        $content .= '<td>';

        $content .= '<details class="admin-inline-editor">';
        $content .= '<summary>Edit</summary>';
        $content .= '<form method="post" class="inline-admin-form admin-inline-form">';
        $content .= '<input type="hidden" name="action" value="save_admin_user">';
        $content .= '<input type="hidden" name="email" value="' . e($email) . '">';
        $content .= '<textarea name="ip_addresses" rows="5" required>' . e($ipText) . '</textarea>';
        $content .= '<input type="text" name="notes" maxlength="255" value="' . e($notes) . '">';
        $content .= '<label><input type="checkbox" name="is_enabled" value="1"' . ($enabled ? " checked" : "") . '> Enabled</label>';
        $content .= '<button type="submit">Save</button>';
        $content .= '</form>';
        $content .= '</details>';

        $content .= '<form method="post" class="inline-admin-form">';
        $content .= '<input type="hidden" name="action" value="delete_admin_user">';
        $content .= '<input type="hidden" name="email" value="' . e($email) . '">';
        $content .= '<button type="submit" class="danger-button">Delete</button>';
        $content .= '</form>';

        $content .= '</td>';
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
    ? render_split_documentation($treePages, $content, -900003, [])
    : $content;

render_layout(
    $config,
    "Admin users",
    "Manage admin users.",
    $html,
    "/admin/users"
);

exit();
