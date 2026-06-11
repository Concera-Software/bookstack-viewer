<?php

declare(strict_types=1);

/**
 * Route: Admin session management.
 */

if ($path !== "/admin/sessions") {
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
    ? current_verified_access_email($config)
    : (string)($_SESSION["manual_access_email"] ?? "");

$adminIp = function_exists("access_gate_ip_address")
    ? access_gate_ip_address()
    : (string)($_SERVER["REMOTE_ADDR"] ?? "");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
    $action = trim((string)($_POST["action"] ?? ""));
    $returnTo = "/admin/sessions";

    if ($action === "revoke_session") {
        $sessionId = trim((string)($_POST["session_id"] ?? ""));

        if ($sessionId !== "") {
            admin_revoke_session($pdo, $sessionId, $adminEmail, $adminIp);
        }

        header("Location: " . $returnTo);
        exit();
    }

    if ($action === "block_ip") {
        $ip = trim((string)($_POST["ip_address"] ?? ""));
        $reason = trim((string)($_POST["reason"] ?? "Blocked from admin session manager"));

        if ($ip !== "" && filter_var($ip, FILTER_VALIDATE_IP)) {
            admin_block_ip($pdo, $ip, $reason, $adminEmail, $adminIp);
        }

        header("Location: " . $returnTo);
        exit();
    }

    http_response_code(400);
    echo "Invalid action";
    exit();
}

$stmt = $pdo->query("
    SELECT
        session_id,
        email,
        ip_address,
        user_agent,
        verified_until,
        last_seen_at,
        revoked_at,
        revoked_by_email,
        revoked_by_ip,
        created_at
    FROM public_access_sessions
    ORDER BY
        revoked_at IS NULL DESC,
        last_seen_at DESC
    LIMIT 250
");

$sessions = $stmt->fetchAll();

$blockedStmt = $pdo->query("
    SELECT
        ip_address,
        reason,
        blocked_by_email,
        blocked_by_ip,
        blocked_at,
        expires_at
    FROM public_access_ip_blocks
    ORDER BY blocked_at DESC
    LIMIT 250
");

$blockedIps = $blockedStmt->fetchAll();

$content = '<article class="doc-page">';
$content .= '<h1>Session management</h1>';
$content .= '<p class="lead">View active sessions, revoke access sessions, and block IP addresses.</p>';

$content .= '<h2>Logged in sessions</h2>';

if (!$sessions) {
    $content .= '<div class="empty-state">No tracked sessions found.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>Status</th>';
    $content .= '<th>Email</th>';
    $content .= '<th>IP address</th>';
    $content .= '<th>Last seen</th>';
    $content .= '<th>Expires</th>';
    $content .= '<th>User agent</th>';
    $content .= '<th>Actions</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody>';

    foreach ($sessions as $session) {
        $sessionId = (string)($session["session_id"] ?? "");
        $isCurrent = hash_equals(session_id(), $sessionId);
        $revokedAt = (string)($session["revoked_at"] ?? "");
        $isRevoked = $revokedAt !== "";
        $verifiedUntil = (int)($session["verified_until"] ?? 0);
        $isExpired = $verifiedUntil > 0 && $verifiedUntil < time();

        $status = "Active";

        if ($isRevoked) {
            $status = "Revoked";
        } elseif ($isExpired) {
            $status = "Expired";
        } elseif ($isCurrent) {
            $status = "Current";
        }

        $content .= '<tr class="' . e($isRevoked ? "is-revoked" : ($isExpired ? "is-expired" : "is-active")) . '">';
        $content .= '<td><strong>' . e($status) . '</strong></td>';
        $content .= '<td>' . e((string)$session["email"]) . '</td>';
        $content .= '<td>' . e((string)$session["ip_address"]) . '</td>';
        $content .= '<td>' . e((string)$session["last_seen_at"]) . '</td>';
        $content .= '<td>' . e($verifiedUntil > 0 ? date("Y-m-d H:i:s", $verifiedUntil) : "") . '</td>';
        $content .= '<td class="admin-user-agent">' . e((string)$session["user_agent"]) . '</td>';

        $content .= '<td>';

        if (!$isRevoked && !$isCurrent) {
            $content .= '<form method="post" class="inline-admin-form">';
            $content .= '<input type="hidden" name="action" value="revoke_session">';
            $content .= '<input type="hidden" name="session_id" value="' . e($sessionId) . '">';
            $content .= '<button type="submit" class="danger-button">Stop session</button>';
            $content .= '</form>';
        }

        $content .= '<form method="post" class="inline-admin-form">';
        $content .= '<input type="hidden" name="action" value="block_ip">';
        $content .= '<input type="hidden" name="ip_address" value="' . e((string)$session["ip_address"]) . '">';
        $content .= '<input type="hidden" name="reason" value="Blocked from session manager">';
        $content .= '<button type="submit" class="danger-button">Block IP</button>';
        $content .= '</form>';

        $content .= '</td>';
        $content .= '</tr>';
    }

    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';
}

$content .= '<h2>Blocked IP addresses</h2>';

if (!$blockedIps) {
    $content .= '<div class="empty-state">No blocked IP addresses found.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>IP address</th>';
    $content .= '<th>Reason</th>';
    $content .= '<th>Blocked at</th>';
    $content .= '<th>Blocked by</th>';
    $content .= '<th>Blocked from IP</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody>';

    foreach ($blockedIps as $block) {
        $content .= '<tr class="is-revoked">';
        $content .= '<td><strong>' . e((string)$block["ip_address"]) . '</strong></td>';
        $content .= '<td>' . e((string)($block["reason"] ?? "")) . '</td>';
        $content .= '<td>' . e((string)($block["blocked_at"] ?? "")) . '</td>';
        $content .= '<td>' . e((string)($block["blocked_by_email"] ?? "")) . '</td>';
        $content .= '<td>' . e((string)($block["blocked_by_ip"] ?? "")) . '</td>';
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
    ? render_split_documentation($treePages, $content, -900002, [])
    : $content;

render_layout(
    $config,
    "Session management",
    "Admin session management.",
    $html,
    "/admin/sessions"
);

exit();
