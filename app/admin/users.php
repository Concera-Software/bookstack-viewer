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

$formMode = trim((string)($_GET["action"] ?? ""));
$editEmail = trim((string)($_GET["edit"] ?? ""));

$showForm = $formMode === "add" || $editEmail !== "";
$editingUser = null;

if ($editEmail !== "" && function_exists("admin_user_by_email")) {
    $editingUser = admin_user_by_email($pdo, $editEmail);
}

$formEmail = (string)($editingUser["email"] ?? "");
$formIpText = (string)($editingUser["ip_addresses"] ?? "");
$formNotes = (string)($editingUser["notes"] ?? "");
$formEnabled = $editingUser ? ((int)($editingUser["is_enabled"] ?? 0) === 1) : true;

$content = '<article class="doc-page">';
$content .= '<h1>Admin users</h1>';
$content .= '<p class="lead">Manage administrator email addresses and bind each administrator to one or more IP addresses.</p>';

$content .= '<div class="empty-state">';
$content .= '<strong>Rule:</strong> When an email address exists here, this database entry overrules the same admin user defined in the app configuration.';
$content .= '</div>';

if ($error !== '') {
    $content .= '<div class="empty-state">' . e($error) . '</div>';
}

$content .= '<p>';
$content .= '<a class="button-link" href="/admin/users?action=add">Add admin user</a>';
$content .= '</p>';

if ($showForm) {
    $content .= '<h2>' . ($editingUser ? 'Edit admin user' : 'Add admin user') . '</h2>';

    if ($editEmail !== "" && !$editingUser) {
        $content .= '<div class="empty-state">Admin user not found.</div>';
    } else {
        $content .= '<form method="post" class="admin-user-form">';
        $content .= '<input type="hidden" name="action" value="save_admin_user">';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>Email address</span>';

        if ($editingUser) {
            $content .= '<input type="email" name="email" required readonly value="' . e($formEmail) . '">';
            $content .= '<small>The email address is the admin identity and cannot be changed here.</small>';
        } else {
            $content .= '<input type="email" name="email" required placeholder="admin@example.com">';
        }

        $content .= '</label>';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>Allowed IP addresses</span>';
        $content .= '<textarea name="ip_addresses" rows="5" required placeholder="One IP per line">' . e($formIpText) . '</textarea>';
        $content .= '</label>';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>Notes</span>';
        $content .= '<input type="text" name="notes" maxlength="255" value="' . e($formNotes) . '" placeholder="Optional note">';
        $content .= '</label>';

        $content .= '<label class="admin-check-field">';
        $content .= '<input type="checkbox" name="is_enabled" value="1"' . ($formEnabled ? " checked" : "") . '>';
        $content .= '<span>Enabled</span>';
        $content .= '</label>';

        $content .= '<div class="profile-form-actions">';
        $content .= '<button type="submit">Save admin user</button>';
        $content .= ' <a class="button-link secondary-button-link" href="/admin/users">Cancel</a>';
        $content .= '</div>';

        $content .= '</form>';
    }
}

$content .= '<h2>Configured admin users</h2>';

if (!$users) {
    $content .= '<div class="empty-state">No database admin users found. The system is using app/config.php fallback admins.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table admin-activity-table admin-users-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>Status</th>';
    $content .= '<th>Email</th>';
    $content .= '<th>Allowed IP addresses</th>';
    $content .= '<th>Actions</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody>';

    foreach ($users as $user) {
        $id = (int)($user["id"] ?? 0);
        $email = (string)($user["email"] ?? "");
        $enabled = (int)($user["is_enabled"] ?? 0) === 1;
        $ipText = (string)($user["ip_addresses"] ?? "");
        $notes = (string)($user["notes"] ?? "");
        $createdAt = (string)($user["created_at"] ?? "");
        $updatedAt = (string)($user["updated_at"] ?? "");
        $updatedByEmail = (string)($user["updated_by_email"] ?? "");
        $updatedByIp = (string)($user["updated_by_ip"] ?? "");

        $rowId = 'admin-user-detail-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $email);
        $rowClass = $enabled ? "is-active" : "is-revoked";

        $content .= '<tr class="admin-activity-summary-row ' . e($rowClass) . '" data-admin-user-toggle="' . e($rowId) . '">';
        $content .= '<td><span class="admin-cell-text"><strong>' . e($enabled ? "Enabled" : "Disabled") . '</strong></span></td>';
        $content .= '<td><span class="admin-cell-text">' . e($email) . '</span></td>';
        $content .= '<td><pre class="admin-ip-list">' . e($ipText) . '</pre></td>';

        $content .= '<td>';
        $content .= '<a class="button-link small-button-link" href="/admin/users?edit=' . e(rawurlencode($email)) . '">Edit</a>';

        $content .= '<form method="post" class="inline-admin-form">';
        $content .= '<input type="hidden" name="action" value="delete_admin_user">';
        $content .= '<input type="hidden" name="email" value="' . e($email) . '">';
        $content .= '<button type="submit" class="danger-button">Delete</button>';
        $content .= '</form>';

        $content .= '</td>';
        $content .= '</tr>';

        $content .= '<tr id="' . e($rowId) . '" class="admin-activity-detail-row" hidden>';
        $content .= '<td colspan="4">';
        $content .= '<div class="admin-activity-detail-panel">';
        $content .= '<dl class="admin-activity-detail-list">';

        $content .= '<dt>ID</dt>';
        $content .= '<dd>' . e((string)$id) . '</dd>';

        $content .= '<dt>Email</dt>';
        $content .= '<dd>' . e($email) . '</dd>';

        $content .= '<dt>Status</dt>';
        $content .= '<dd>' . e($enabled ? "Enabled" : "Disabled") . '</dd>';

        $content .= '<dt>Allowed IP addresses</dt>';
        $content .= '<dd><pre class="admin-ip-list admin-detail-ip-list">' . e($ipText) . '</pre></dd>';

        $content .= '<dt>Notes</dt>';
        $content .= '<dd>' . e($notes !== '' ? $notes : '-') . '</dd>';

        $content .= '<dt>Created</dt>';
        $content .= '<dd>' . e($createdAt !== '' ? $createdAt : '-') . '</dd>';

        $content .= '<dt>Updated</dt>';
        $content .= '<dd>' . e($updatedAt !== '' ? $updatedAt : '-') . '</dd>';

        $content .= '<dt>Updated by email</dt>';
        $content .= '<dd>' . e($updatedByEmail !== '' ? $updatedByEmail : '-') . '</dd>';

        $content .= '<dt>Updated by IP</dt>';
        $content .= '<dd>' . e($updatedByIp !== '' ? $updatedByIp : '-') . '</dd>';

        $content .= '</dl>';
        $content .= '</div>';
        $content .= '</td>';
        $content .= '</tr>';
    }

    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';

    $content .= <<<'HTML'
<script>
(function () {
    function bindAdminUserRowToggle(root) {
        root.querySelectorAll("[data-admin-user-toggle]").forEach(function (row) {
            if (row.dataset.boundAdminUserToggle === "1") {
                return;
            }

            row.dataset.boundAdminUserToggle = "1";

            row.addEventListener("click", function (event) {
                if (event.target.closest("button, a, input, textarea, form, summary")) {
                    return;
                }

                var targetId = row.getAttribute("data-admin-user-toggle");
                var detailRow = document.getElementById(targetId);

                if (!detailRow) {
                    return;
                }

                var isHidden = detailRow.hasAttribute("hidden");

                if (isHidden) {
                    detailRow.removeAttribute("hidden");
                    row.classList.add("is-expanded");
                } else {
                    detailRow.setAttribute("hidden", "hidden");
                    row.classList.remove("is-expanded");
                }
            });
        });
    }

    bindAdminUserRowToggle(document);
})();
</script>
HTML;
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
