<?php

declare(strict_types=1);

/**
 * Route: Admin public user management.
 *
 * Public users are visitors/users that requested login codes or download codes.
 * They are separate from admin users.
 */

if ($path !== "/admin/public-users") {
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

$error = '';
$message = '';

/**
 * Normalize public user email address.
 */
function admin_public_user_normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

/**
 * Return all public users.
 */
function admin_public_users_all(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            id,
            email,
            first_name,
            last_name,
            phone,
            role_key,
            is_enabled,
            first_login_code_requested_at,
            last_login_code_requested_at,
            first_download_code_requested_at,
            last_download_code_requested_at,
            last_seen_at,
            last_ip_address,
            last_user_agent,
            profile_updated_at,
            created_at,
            updated_at
        FROM public_users
        ORDER BY last_seen_at DESC, email ASC
    ");

    return $stmt->fetchAll();
}

/**
 * Return a public user by email.
 */
function admin_public_user_by_email(PDO $pdo, string $email): ?array
{
    $email = admin_public_user_normalize_email($email);

    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            email,
            first_name,
            last_name,
            phone,
            role_key,
            is_enabled,
            first_login_code_requested_at,
            last_login_code_requested_at,
            first_download_code_requested_at,
            last_download_code_requested_at,
            last_seen_at,
            last_ip_address,
            last_user_agent,
            profile_updated_at,
            created_at,
            updated_at
        FROM public_users
        WHERE email = :email
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Save a public user.
 *
 * Email is the identity key. When editing an existing user, the email is readonly
 * in the form and should not be changed.
 */
function admin_public_user_save(
    PDO $pdo,
    string $email,
    string $firstName,
    string $lastName,
    string $phone,
    string $roleKey,
    bool $enabled
): void {
    $email = admin_public_user_normalize_email($email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }

    $roleKey = mb_strtolower(trim($roleKey));
    $roleKey = preg_replace('/[^a-z0-9_\-]+/', '-', $roleKey) ?? 'user';
    $roleKey = trim($roleKey, '-');

    if ($roleKey === '') {
        $roleKey = 'user';
    }

    $stmt = $pdo->prepare("
        INSERT INTO public_users (
            email,
            first_name,
            last_name,
            phone,
            role_key,
            is_enabled,
            profile_updated_at,
            created_at,
            updated_at
        ) VALUES (
            :email,
            :first_name,
            :last_name,
            :phone,
            :role_key,
            :is_enabled,
            NOW(),
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            first_name = VALUES(first_name),
            last_name = VALUES(last_name),
            phone = VALUES(phone),
            role_key = VALUES(role_key),
            is_enabled = VALUES(is_enabled),
            profile_updated_at = NOW(),
            updated_at = NOW()
    ");

    $stmt->execute([
        'email' => $email,
        'first_name' => mb_substr(trim($firstName), 0, 120),
        'last_name' => mb_substr(trim($lastName), 0, 120),
        'phone' => mb_substr(trim($phone), 0, 80),
        'role_key' => mb_substr($roleKey, 0, 64),
        'is_enabled' => $enabled ? 1 : 0,
    ]);
}

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));

    if ($action === "save_public_user") {
        $email = (string)($_POST["email"] ?? "");
        $firstName = (string)($_POST["first_name"] ?? "");
        $lastName = (string)($_POST["last_name"] ?? "");
        $phone = (string)($_POST["phone"] ?? "");
        $roleKey = (string)($_POST["role_key"] ?? "user");
        $enabled = isset($_POST["is_enabled"]);

        try {
            admin_public_user_save(
                $pdo,
                $email,
                $firstName,
                $lastName,
                $phone,
                $roleKey,
                $enabled
            );

            header("Location: /admin/public-users");
            exit();
        } catch (Throwable $e) {
            error_log('Public user save failed: ' . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

$users = admin_public_users_all($pdo);

$formMode = trim((string)($_GET["action"] ?? ""));
$editEmail = trim((string)($_GET["edit"] ?? ""));

$showForm = $formMode === "add" || $editEmail !== "";
$editingUser = null;

if ($editEmail !== "") {
    $editingUser = admin_public_user_by_email($pdo, $editEmail);
}

$formEmail = (string)($editingUser["email"] ?? "");
$formFirstName = (string)($editingUser["first_name"] ?? "");
$formLastName = (string)($editingUser["last_name"] ?? "");
$formPhone = (string)($editingUser["phone"] ?? "");
$formRoleKey = (string)($editingUser["role_key"] ?? "user");
$formEnabled = $editingUser ? ((int)($editingUser["is_enabled"] ?? 0) === 1) : true;

$content = '<article class="doc-page">';
$content .= '<h1>Public users</h1>';
$content .= '<p class="lead">Manage public users that requested login codes or download codes.</p>';

$content .= '<div class="empty-state">';
$content .= '<strong>Note:</strong> Public users are separate from admin users. Email addresses are treated as identity keys and should not be changed.';
$content .= '</div>';

if ($error !== '') {
    $content .= '<div class="empty-state">' . e($error) . '</div>';
}

$content .= '<p>';
$content .= '<a class="button-link" href="/admin/public-users?action=add">Add public user</a>';
$content .= '</p>';

if ($showForm) {
    $content .= '<h2>' . ($editingUser ? 'Edit public user' : 'Add public user') . '</h2>';

    if ($editEmail !== "" && !$editingUser) {
        $content .= '<div class="empty-state">Public user not found.</div>';
    } else {
        $content .= '<form method="post" class="admin-user-form">';
        $content .= '<input type="hidden" name="action" value="save_public_user">';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>Email address</span>';

        if ($editingUser) {
            $content .= '<input type="email" name="email" required readonly value="' . e($formEmail) . '">';
            $content .= '<small>The email address is the public user identity and cannot be changed here.</small>';
        } else {
            $content .= '<input type="email" name="email" required placeholder="user@example.com">';
        }

        $content .= '</label>';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>First name</span>';
        $content .= '<input type="text" name="first_name" maxlength="120" value="' . e($formFirstName) . '" autocomplete="given-name">';
        $content .= '</label>';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>Last name</span>';
        $content .= '<input type="text" name="last_name" maxlength="120" value="' . e($formLastName) . '" autocomplete="family-name">';
        $content .= '</label>';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>Phone number</span>';
        $content .= '<input type="tel" name="phone" maxlength="80" value="' . e($formPhone) . '" autocomplete="tel">';
        $content .= '</label>';

        $content .= '<label class="admin-form-field">';
        $content .= '<span>Role key</span>';
        $content .= '<input type="text" name="role_key" maxlength="64" value="' . e($formRoleKey) . '" placeholder="user">';
        $content .= '<small>Reserved for future roles or extra credentials. Use <strong>user</strong> for now.</small>';
        $content .= '</label>';

        $content .= '<label class="admin-check-field">';
        $content .= '<input type="checkbox" name="is_enabled" value="1"' . ($formEnabled ? " checked" : "") . '>';
        $content .= '<span>Enabled</span>';
        $content .= '</label>';

        $content .= '<div class="profile-form-actions">';
        $content .= '<button type="submit">Save public user</button>';
        $content .= ' <a class="button-link secondary-button-link" href="/admin/public-users">Cancel</a>';
        $content .= '</div>';

        $content .= '</form>';
    }
}

$content .= '<h2>Known public users</h2>';

if (!$users) {
    $content .= '<div class="empty-state">No public users found yet.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table admin-activity-table admin-public-users-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>Status</th>';
    $content .= '<th>Email</th>';
    $content .= '<th>Name</th>';
    $content .= '<th>Phone</th>';
    $content .= '<th>Role</th>';
    $content .= '<th>Last seen</th>';
    $content .= '<th>Actions</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody>';

    foreach ($users as $user) {
        $id = (int)($user["id"] ?? 0);
        $email = (string)($user["email"] ?? "");
        $firstName = (string)($user["first_name"] ?? "");
        $lastName = (string)($user["last_name"] ?? "");
        $phone = (string)($user["phone"] ?? "");
        $roleKey = (string)($user["role_key"] ?? "user");
        $enabled = (int)($user["is_enabled"] ?? 0) === 1;

        $firstLoginCodeRequestedAt = (string)($user["first_login_code_requested_at"] ?? "");
        $lastLoginCodeRequestedAt = (string)($user["last_login_code_requested_at"] ?? "");
        $firstDownloadCodeRequestedAt = (string)($user["first_download_code_requested_at"] ?? "");
        $lastDownloadCodeRequestedAt = (string)($user["last_download_code_requested_at"] ?? "");
        $lastSeenAt = (string)($user["last_seen_at"] ?? "");
        $lastIpAddress = (string)($user["last_ip_address"] ?? "");
        $lastUserAgent = (string)($user["last_user_agent"] ?? "");
        $profileUpdatedAt = (string)($user["profile_updated_at"] ?? "");
        $createdAt = (string)($user["created_at"] ?? "");
        $updatedAt = (string)($user["updated_at"] ?? "");

        $fullName = trim($firstName . ' ' . $lastName);
        $rowId = 'public-user-detail-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $email);
        $rowClass = $enabled ? "is-active" : "is-revoked";

        $content .= '<tr class="admin-activity-summary-row ' . e($rowClass) . '" data-public-user-toggle="' . e($rowId) . '">';
        $content .= '<td><span class="admin-cell-text"><strong>' . e($enabled ? "Enabled" : "Disabled") . '</strong></span></td>';
        $content .= '<td><span class="admin-cell-text">' . e($email) . '</span></td>';
        $content .= '<td><span class="admin-cell-text">' . e($fullName !== '' ? $fullName : '-') . '</span></td>';
        $content .= '<td><span class="admin-cell-text">' . e($phone !== '' ? $phone : '-') . '</span></td>';
        $content .= '<td><span class="admin-cell-text">' . e($roleKey !== '' ? $roleKey : 'user') . '</span></td>';
        $content .= '<td><span class="admin-cell-text">' . e($lastSeenAt !== '' ? $lastSeenAt : '-') . '</span></td>';

        $content .= '<td>';
        $content .= '<a class="button-link small-button-link" href="/admin/public-users?edit=' . e(rawurlencode($email)) . '">Edit</a>';
        $content .= '</td>';

        $content .= '</tr>';

        $content .= '<tr id="' . e($rowId) . '" class="admin-activity-detail-row" hidden>';
        $content .= '<td colspan="7">';
        $content .= '<div class="admin-activity-detail-panel">';
        $content .= '<dl class="admin-activity-detail-list">';

        $content .= '<dt>ID</dt>';
        $content .= '<dd>' . e((string)$id) . '</dd>';

        $content .= '<dt>Email</dt>';
        $content .= '<dd>' . e($email) . '</dd>';

        $content .= '<dt>Status</dt>';
        $content .= '<dd>' . e($enabled ? "Enabled" : "Disabled") . '</dd>';

        $content .= '<dt>First name</dt>';
        $content .= '<dd>' . e($firstName !== '' ? $firstName : '-') . '</dd>';

        $content .= '<dt>Last name</dt>';
        $content .= '<dd>' . e($lastName !== '' ? $lastName : '-') . '</dd>';

        $content .= '<dt>Phone</dt>';
        $content .= '<dd>' . e($phone !== '' ? $phone : '-') . '</dd>';

        $content .= '<dt>Role key</dt>';
        $content .= '<dd>' . e($roleKey !== '' ? $roleKey : 'user') . '</dd>';

        $content .= '<dt>First login code requested</dt>';
        $content .= '<dd>' . e($firstLoginCodeRequestedAt !== '' ? $firstLoginCodeRequestedAt : '-') . '</dd>';

        $content .= '<dt>Last login code requested</dt>';
        $content .= '<dd>' . e($lastLoginCodeRequestedAt !== '' ? $lastLoginCodeRequestedAt : '-') . '</dd>';

        $content .= '<dt>First download code requested</dt>';
        $content .= '<dd>' . e($firstDownloadCodeRequestedAt !== '' ? $firstDownloadCodeRequestedAt : '-') . '</dd>';

        $content .= '<dt>Last download code requested</dt>';
        $content .= '<dd>' . e($lastDownloadCodeRequestedAt !== '' ? $lastDownloadCodeRequestedAt : '-') . '</dd>';

        $content .= '<dt>Last seen</dt>';
        $content .= '<dd>' . e($lastSeenAt !== '' ? $lastSeenAt : '-') . '</dd>';

        $content .= '<dt>Last IP address</dt>';
        $content .= '<dd>' . e($lastIpAddress !== '' ? $lastIpAddress : '-') . '</dd>';

        $content .= '<dt>Last user agent</dt>';
        $content .= '<dd>' . e($lastUserAgent !== '' ? $lastUserAgent : '-') . '</dd>';

        $content .= '<dt>Profile updated</dt>';
        $content .= '<dd>' . e($profileUpdatedAt !== '' ? $profileUpdatedAt : '-') . '</dd>';

        $content .= '<dt>Created</dt>';
        $content .= '<dd>' . e($createdAt !== '' ? $createdAt : '-') . '</dd>';

        $content .= '<dt>Updated</dt>';
        $content .= '<dd>' . e($updatedAt !== '' ? $updatedAt : '-') . '</dd>';

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
    function bindPublicUserRowToggle(root) {
        root.querySelectorAll("[data-public-user-toggle]").forEach(function (row) {
            if (row.dataset.boundPublicUserToggle === "1") {
                return;
            }

            row.dataset.boundPublicUserToggle = "1";

            row.addEventListener("click", function (event) {
                if (event.target.closest("button, a, input, textarea, form, summary")) {
                    return;
                }

                var targetId = row.getAttribute("data-public-user-toggle");
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

    bindPublicUserRowToggle(document);
})();
</script>
HTML;
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
    "Public users",
    "Manage public users.",
    $html,
    "/admin/public-users"
);

exit();
