<?php

declare(strict_types=1);

/**
 * Route: Admin session management.
 *
 * Shows access sessions and blocked IP addresses.
 *
 * Features:
 * - admin-only access;
 * - filters by IP address and email;
 * - prevents blocking your own current IP address;
 * - allows removing an IP address from the blocklist;
 * - uses the same expandable table layout as user activity and download audit;
 * - clicking a summary row expands a detail row directly underneath it.
 */

if ($path !== "/admin/sessions") {
    return;
}

/**
 * Render session rows.
 *
 * Each session is rendered as:
 * - one summary row;
 * - one hidden detail row.
 *
 * @param array $sessions
 * @param string $currentSessionId
 * @param string $adminIp
 * @return string
 */
function admin_sessions_render_rows(array $sessions, string $currentSessionId, string $adminIp): string
{
    $content = '';

    foreach ($sessions as $session) {
        $sessionId = (string)($session["session_id"] ?? "");
        $isCurrent = hash_equals($currentSessionId, $sessionId);

        $revokedAt = (string)($session["revoked_at"] ?? "");
        $isRevoked = $revokedAt !== "";

        $verifiedUntil = (int)($session["verified_until"] ?? 0);
        $isExpired = $verifiedUntil > 0 && $verifiedUntil < time();

        $status = "Active";
        $rowClass = "is-active";

        if ($isRevoked) {
            $status = "Revoked";
            $rowClass = "is-revoked";
        } elseif ($isExpired) {
            $status = "Expired";
            $rowClass = "is-expired";
        } elseif ($isCurrent) {
            $status = "Current";
            $rowClass = "is-active";
        }

        $email = (string)($session["email"] ?? "");
        $ip = (string)($session["ip_address"] ?? "");
        $userAgent = (string)($session["user_agent"] ?? "");
        $lastSeenAt = (string)($session["last_seen_at"] ?? "");
        $createdAt = (string)($session["created_at"] ?? "");
        $revokedByEmail = (string)($session["revoked_by_email"] ?? "");
        $revokedByIp = (string)($session["revoked_by_ip"] ?? "");
        $expiresAt = $verifiedUntil > 0 ? date("Y-m-d H:i:s", $verifiedUntil) : "";

        $rowId = "session-details-" . substr(md5($sessionId . json_encode($session)), 0, 12);

        $content .= '<tr class="admin-activity-summary-row ' . e($rowClass) . '" data-session-toggle="' . e($rowId) . '">';
        $content .= '<td class="admin-activity-event"><span class="admin-cell-text"><strong>' . e($status) . '</strong></span></td>';
        $content .= '<td class="admin-activity-email"><span class="admin-cell-text">' . e($email) . '</span></td>';
        $content .= '<td class="admin-activity-ip"><span class="admin-cell-text">' . e($ip) . '</span></td>';
        $content .= '<td class="admin-activity-date"><span class="admin-cell-text">' . e($lastSeenAt) . '</span></td>';
        $content .= '<td class="admin-activity-date"><span class="admin-cell-text">' . e($expiresAt) . '</span></td>';
        $content .= '<td class="admin-activity-agent"><span class="admin-cell-text">' . e($userAgent) . '</span></td>';

        $content .= '<td class="admin-activity-message">';

        if (!$isRevoked && !$isCurrent) {
            $content .= '<form method="post" class="inline-admin-form">';
            $content .= '<input type="hidden" name="action" value="revoke_session">';
            $content .= '<input type="hidden" name="session_id" value="' . e($sessionId) . '">';
            $content .= '<button type="submit" class="danger-button">Log off</button>';
            $content .= '</form>';
        }

        if ($isRevoked) {
            $content .= '<form method="post" class="inline-admin-form">';
            $content .= '<input type="hidden" name="action" value="delete_revoked_session">';
            $content .= '<input type="hidden" name="session_id" value="' . e($sessionId) . '">';
            $content .= '<button type="submit" class="danger-button">Remove</button>';
            $content .= '</form>';
        }

        if ($ip !== '' && $ip !== $adminIp) {
            $content .= '<form method="post" class="inline-admin-form">';
            $content .= '<input type="hidden" name="action" value="block_ip">';
            $content .= '<input type="hidden" name="ip_address" value="' . e($ip) . '">';
            $content .= '<input type="hidden" name="reason" value="Blocked from session manager">';
            $content .= '<button type="submit" class="danger-button">Block IP</button>';
            $content .= '</form>';
        } 
//        elseif ($ip !== '') {
//            $content .= '<span class="card-meta">Own IP protected</span>';
//        }

        $content .= '</td>';
        $content .= '</tr>';

        $content .= '<tr id="' . e($rowId) . '" class="admin-activity-detail-row" hidden>';
        $content .= '<td colspan="7">';
        $content .= '<div class="admin-activity-detail-panel">';
        $content .= '<dl class="admin-activity-detail-list">';
        $content .= '<dt>Session ID</dt><dd>' . e($sessionId) . '</dd>';
        $content .= '<dt>Status</dt><dd>' . e($status) . '</dd>';
        $content .= '<dt>Email</dt><dd>' . e($email) . '</dd>';
        $content .= '<dt>IP address</dt><dd>' . e($ip) . '</dd>';
        $content .= '<dt>Created</dt><dd>' . e($createdAt) . '</dd>';
        $content .= '<dt>Last seen</dt><dd>' . e($lastSeenAt) . '</dd>';
        $content .= '<dt>Expires</dt><dd>' . e($expiresAt) . '</dd>';
        $content .= '<dt>Revoked at</dt><dd>' . e($revokedAt) . '</dd>';
        $content .= '<dt>Revoked by</dt><dd>' . e($revokedByEmail) . '</dd>';
        $content .= '<dt>Revoked from IP</dt><dd>' . e($revokedByIp) . '</dd>';
        $content .= '<dt>User agent</dt><dd>' . e($userAgent) . '</dd>';
        $content .= '</dl>';
        $content .= '</div>';
        $content .= '</td>';
        $content .= '</tr>';
    }

    return $content;
}

/**
 * Render blocked IP rows.
 *
 * Each blocked IP is rendered as:
 * - one summary row;
 * - one hidden detail row.
 *
 * @param array $blockedIps
 * @param string $adminIp
 * @return string
 */
function admin_blocked_ips_render_rows(array $blockedIps, string $adminIp): string
{
    $content = '';

    foreach ($blockedIps as $block) {
        $ip = (string)($block["ip_address"] ?? "");
        $reason = (string)($block["reason"] ?? "");
        $blockedAt = (string)($block["blocked_at"] ?? "");
        $blockedByEmail = (string)($block["blocked_by_email"] ?? "");
        $blockedByIp = (string)($block["blocked_by_ip"] ?? "");
        $expiresAt = (string)($block["expires_at"] ?? "");

        $rowId = "blocked-ip-details-" . substr(md5($ip . json_encode($block)), 0, 12);

        $content .= '<tr class="admin-activity-summary-row is-revoked" data-session-toggle="' . e($rowId) . '">';
        $content .= '<td class="admin-activity-ip"><span class="admin-cell-text"><strong>' . e($ip) . '</strong></span></td>';
        $content .= '<td class="admin-activity-message"><span class="admin-cell-text">' . e($reason) . '</span></td>';
        $content .= '<td class="admin-activity-date"><span class="admin-cell-text">' . e($blockedAt) . '</span></td>';
        $content .= '<td class="admin-activity-email"><span class="admin-cell-text">' . e($blockedByEmail) . '</span></td>';

        $content .= '<td class="admin-activity-message">';

        if ($ip !== '' && $ip !== $adminIp) {
            $content .= '<form method="post" class="inline-admin-form">';
            $content .= '<input type="hidden" name="action" value="unblock_ip">';
            $content .= '<input type="hidden" name="ip_address" value="' . e($ip) . '">';
            $content .= '<button type="submit">Remove block</button>';
            $content .= '</form>';
        } elseif ($ip !== '') {
            $content .= '<span class="card-meta">Own IP protected</span>';
        }

        $content .= '</td>';
        $content .= '</tr>';

        $content .= '<tr id="' . e($rowId) . '" class="admin-activity-detail-row" hidden>';
        $content .= '<td colspan="5">';
        $content .= '<div class="admin-activity-detail-panel">';
        $content .= '<dl class="admin-activity-detail-list">';
        $content .= '<dt>IP address</dt><dd>' . e($ip) . '</dd>';
        $content .= '<dt>Reason</dt><dd>' . e($reason) . '</dd>';
        $content .= '<dt>Blocked at</dt><dd>' . e($blockedAt) . '</dd>';
        $content .= '<dt>Blocked by</dt><dd>' . e($blockedByEmail) . '</dd>';
        $content .= '<dt>Blocked from IP</dt><dd>' . e($blockedByIp) . '</dd>';
        $content .= '<dt>Expires at</dt><dd>' . e($expiresAt) . '</dd>';
        $content .= '</dl>';
        $content .= '</div>';
        $content .= '</td>';
        $content .= '</tr>';
    }

    return $content;
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

    if ($action === "delete_revoked_session") {
        $sessionId = trim((string)($_POST["session_id"] ?? ""));

        if ($sessionId !== "") {
            admin_delete_revoked_session($pdo, $sessionId);
        }

        header("Location: " . $returnTo);
        exit();
    }

    if ($action === "block_ip") {
        $ip = trim((string)($_POST["ip_address"] ?? ""));
        $reason = trim((string)($_POST["reason"] ?? "Blocked from admin session manager"));

        /*
         * Prevent blocking your own current IP address.
         * This avoids locking yourself out of the admin environment.
         */
        if (
            $ip !== "" &&
            filter_var($ip, FILTER_VALIDATE_IP) &&
            $ip !== $adminIp
        ) {
            admin_block_ip($pdo, $ip, $reason, $adminEmail, $adminIp);
        }

        header("Location: " . $returnTo);
        exit();
    }

    if ($action === "unblock_ip") {
        $ip = trim((string)($_POST["ip_address"] ?? ""));

        if (
            $ip !== "" &&
            filter_var($ip, FILTER_VALIDATE_IP) &&
            function_exists("admin_unblock_ip")
        ) {
            admin_unblock_ip($pdo, $ip);
        }

        header("Location: " . $returnTo);
        exit();
    }

    http_response_code(400);
    echo "Invalid action";
    exit();
}

$filterIp = trim((string)($_GET["ip"] ?? ""));
$filterEmail = trim((string)($_GET["email"] ?? ""));

$where = [];
$params = [];

if ($filterIp !== "") {
    $where[] = "ip_address = :ip_address";
    $params["ip_address"] = $filterIp;
}

if ($filterEmail !== "") {
    $where[] = "email LIKE :email";
    $params["email"] = "%" . $filterEmail . "%";
}

$sql = "
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
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= "
    ORDER BY
        revoked_at IS NULL DESC,
        last_seen_at DESC
    LIMIT 250
";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue(":" . $key, $value);
}

$stmt->execute();
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
$content .= '<p class="lead">Filter and inspect access sessions, revoke sessions, block IP addresses, and remove IP addresses from the blocklist.</p>';

$content .= '<form method="get" action="/admin/sessions" class="admin-activity-filter">';
$content .= '<div class="admin-activity-filter-grid">';

$content .= '<label class="admin-form-field">';
$content .= '<span>IP address</span>';
$content .= '<input type="text" name="ip" value="' . e($filterIp) . '" placeholder="IP address">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>Email</span>';
$content .= '<input type="text" name="email" value="' . e($filterEmail) . '" placeholder="user@example.com">';
$content .= '</label>';

$content .= '</div>';

$content .= '<div class="admin-activity-filter-actions">';
$content .= '<button type="submit">Apply</button>';
$content .= '<a class="button-link" href="/admin/sessions">Reset</a>';
$content .= '</div>';

$content .= '</form>';

$content .= '<h2>Logged in sessions</h2>';

if (!$sessions) {
    $content .= '<div class="empty-state">No tracked sessions found for the selected filter.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table admin-activity-table admin-session-audit-table">';
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
    $content .= '<tbody id="adminSessionRows">';
    $content .= admin_sessions_render_rows($sessions, session_id(), $adminIp);
    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';
}

$content .= '<h2>Blocked IP addresses</h2>';

if (!$blockedIps) {
    $content .= '<div class="empty-state">No blocked IP addresses found.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table admin-activity-table admin-session-block-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>IP address</th>';
    $content .= '<th>Reason</th>';
    $content .= '<th>Blocked at</th>';
    $content .= '<th>Blocked by</th>';
    $content .= '<th>Actions</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody id="adminBlockedIpRows">';
    $content .= admin_blocked_ips_render_rows($blockedIps, $adminIp);
    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';
}

$content .= <<<HTML
<script>
(function () {
    function bindSessionRowToggle(root) {
        root.querySelectorAll("[data-session-toggle]").forEach(function (row) {
            if (row.dataset.boundSessionToggle === "1") {
                return;
            }

            row.dataset.boundSessionToggle = "1";

            row.addEventListener("click", function (event) {
                if (event.target.closest("button, a, input, form")) {
                    return;
                }

                const targetId = row.getAttribute("data-session-toggle");
                const detailRow = document.getElementById(targetId);

                if (!detailRow) {
                    return;
                }

                const isHidden = detailRow.hasAttribute("hidden");

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

    bindSessionRowToggle(document);
})();
</script>
HTML;

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
