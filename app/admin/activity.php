<?php

declare(strict_types=1);

/**
 * Route: Admin user activity.
 */

if ($path !== "/admin/activity") {
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

$filterIp = trim((string)($_GET['ip'] ?? ''));
$filterDate = trim((string)($_GET['date'] ?? ''));
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));
$filterUrl = trim((string)($_GET['url'] ?? ''));

$where = [];
$params = [];

if ($filterIp !== '') {
    $where[] = 'ip_address = :ip_address';
    $params['ip_address'] = $filterIp;
}

if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $where[] = 'DATE(created_at) = :filter_date';
    $params['filter_date'] = $filterDate;
}

if ($filterFrom !== '') {
    $from = str_replace('T', ' ', $filterFrom);

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $from)) {
        $where[] = 'created_at >= :from_date';
        $params['from_date'] = $from . ':00';
    }
}

if ($filterTo !== '') {
    $to = str_replace('T', ' ', $filterTo);

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $to)) {
        $where[] = 'created_at <= :to_date';
        $params['to_date'] = $to . ':59';
    }
}

if ($filterUrl !== '') {
    $where[] = 'url_path LIKE :url_path';
    $params['url_path'] = '%' . $filterUrl . '%';
}

$sql = "
    SELECT
        id,
        email,
        event_type,
        url_path,
        ip_address,
        user_agent,
        referer,
        success,
        message,
        created_at
    FROM public_access_log
";

if ($where) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY created_at DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

$content = '<article class="doc-page">';
$content .= '<h1>User activity</h1>';
$content .= '<p class="lead">Filter and inspect access-gate and download activity.</p>';

$content .= '<form method="get" action="/admin/activity" class="admin-user-form">';
$content .= '<label class="admin-form-field"><span>IP address</span><input type="text" name="ip" value="' . e($filterIp) . '"></label>';
$content .= '<label class="admin-form-field"><span>Date</span><input type="date" name="date" value="' . e($filterDate) . '"></label>';
$content .= '<label class="admin-form-field"><span>From date/time</span><input type="datetime-local" name="from" value="' . e($filterFrom) . '"></label>';
$content .= '<label class="admin-form-field"><span>To date/time</span><input type="datetime-local" name="to" value="' . e($filterTo) . '"></label>';
$content .= '<label class="admin-form-field"><span>URL contains</span><input type="text" name="url" value="' . e($filterUrl) . '"></label>';
$content .= '<button type="submit">Apply filter</button>';
$content .= ' <a class="button-link" href="/admin/activity">Reset</a>';
$content .= '</form>';

if (!$activities) {
    $content .= '<div class="empty-state">No activity found for the selected filter.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table admin-activity-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>Date/time</th>';
    $content .= '<th>Event</th>';
    $content .= '<th>Email</th>';
    $content .= '<th>IP address</th>';
    $content .= '<th>URL</th>';
    $content .= '<th>Success</th>';
    $content .= '<th>Message</th>';
    $content .= '<th>User agent</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody>';

    foreach ($activities as $activity) {
        $success = (int)($activity['success'] ?? 0) === 1;
//
//        $content .= '<tr class="' . e($success ? "is-active" : "is-revoked") . '">';
//        $content .= '<td>' . str_replace(" ","&nbsp;",e((string)($activity['created_at'] ?? ''))) . '</td>';
//        $content .= '<td><strong>' . e((string)($activity['event_type'] ?? '')) . '</strong></td>';
//        $content .= '<td>' . e((string)($activity['email'] ?? '')) . '</td>';
//        $content .= '<td>' . e((string)($activity['ip_address'] ?? '')) . '</td>';
//        $content .= '<td class="admin-user-agent">' . e((string)($activity['url_path'] ?? '')) . '</td>';
//        $content .= '<td>' . e((string)($activity['url_path'] ?? '')) . '</td>';
//        $content .= '<td>' . e($success ? 'Yes' : 'No') . '</td>';
//        $content .= '<td>' . e((string)($activity['message'] ?? '')) . '</td>';
//        $content .= '<td class="admin-user-agent">' . e((string)($activity['user_agent'] ?? '')) . '</td>';
//	$content .= '<td>' . e((string)($activity['user_agent'] ?? '')) . '</td>';
//        $content .= '</tr>';



$dateTime = (string)($activity['created_at'] ?? '');
$eventType = (string)($activity['event_type'] ?? '');
$email = (string)($activity['email'] ?? '');
$ip = (string)($activity['ip_address'] ?? '');
$url = (string)($activity['url_path'] ?? '');
$successText = $success ? 'Yes' : 'No';
$message = (string)($activity['message'] ?? '');
$userAgent = (string)($activity['user_agent'] ?? '');
$referer = (string)($activity['referer'] ?? '');

$tooltip = implode("\n", [
    "Date/time: " . $dateTime,
    "Event: " . $eventType,
    "Email: " . $email,
    "IP: " . $ip,
    "URL: " . $url,
    "Success: " . $successText,
    "Message: " . $message,
    "Referer: " . $referer,
    "User agent: " . $userAgent,
]);

$content .= '<tr class="' . e($success ? "is-active" : "is-revoked") . '">';

$content .= '<td class="admin-activity-fixed">';
$content .= '<span class="admin-cell-text">' . e($dateTime) . '</span>';
$content .= '<span class="admin-row-tooltip">' . nl2br(e($tooltip)) . '</span>';
$content .= '</td>';

$content .= '<td class="admin-activity-truncate"><span class="admin-cell-text"><strong>' . e($eventType) . '</strong></span></td>';
$content .= '<td class="admin-activity-fixed"><span class="admin-cell-text">' . e($email) . '</span></td>';
$content .= '<td class="admin-activity-fixed"><span class="admin-cell-text">' . e($ip) . '</span></td>';
$content .= '<td class="admin-activity-truncate"><span class="admin-cell-text">' . e($url) . '</span></td>';
$content .= '<td class="admin-activity-fixed"><span class="admin-cell-text">' . e($successText) . '</span></td>';
$content .= '<td class="admin-activity-truncate"><span class="admin-cell-text">' . e($message) . '</span></td>';
$content .= '<td class="admin-activity-truncate"><span class="admin-cell-text">' . e($userAgent) . '</span></td>';

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
    ? render_split_documentation($treePages, $content, -900005, [])
    : $content;

render_layout(
    $config,
    "User activity",
    "User activity.",
    $html,
    "/admin/activity"
);

exit();
