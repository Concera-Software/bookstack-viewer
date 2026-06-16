<?php

declare(strict_types=1);

/**
 * Route: Admin user activity.
 *
 * Shows access/download activity from public_access_log.
 *
 * Features:
 * - admin-only access;
 * - filters by IP address, from date/time, till date/time, and URL;
 * - first page limited to 100 rows;
 * - endless scrolling loads the next 100 rows;
 * - compact two-column filter layout;
 * - no wrapping in the table;
 * - optional long fields are clipped;
 * - clicking a row expands a detail row directly underneath it.
 */

if ($path !== "/admin/activity") {
    return;
}

/**
 * Render activity rows for the full page and for endless-scroll partial loads.
 *
 * Each activity is rendered as two rows:
 * - a summary row;
 * - a hidden detail row that can be expanded by clicking the summary row.
 *
 * @param array $activities
 * @return string
 */
function admin_activity_render_rows(array $activities): string
{
    $content = '';

    foreach ($activities as $activity) {
        $success = (int)($activity['success'] ?? 0) === 1;

        $activityId = (int)($activity['id'] ?? 0);
        $rowId = 'activity-details-' . $activityId . '-' . substr(md5(json_encode($activity)), 0, 8);

        $dateTime = (string)($activity['created_at'] ?? '');
        $eventType = (string)($activity['event_type'] ?? '');
        $email = (string)($activity['email'] ?? '');
        $ip = (string)($activity['ip_address'] ?? '');
        $url = (string)($activity['url_path'] ?? '');
        $successText = $success ? 'Yes' : 'No';
        $message = (string)($activity['message'] ?? '');
        $referer = (string)($activity['referer'] ?? '');
        $userAgent = (string)($activity['user_agent'] ?? '');

        $content .= '<tr class="admin-activity-summary-row ' . e($success ? "is-active" : "is-revoked") . '" data-activity-toggle="' . e($rowId) . '">';

        $content .= '<td class="admin-activity-date"><span class="admin-cell-text">' . e($dateTime) . '</span></td>';
        $content .= '<td class="admin-activity-event"><span class="admin-cell-text"><strong>' . e($eventType) . '</strong></span></td>';
        $content .= '<td class="admin-activity-email"><span class="admin-cell-text">' . e($email) . '</span></td>';
        $content .= '<td class="admin-activity-ip"><span class="admin-cell-text">' . e($ip) . '</span></td>';
        $content .= '<td class="admin-activity-url"><span class="admin-cell-text">' . e($url) . '</span></td>';
        $content .= '<td class="admin-activity-success"><span class="admin-cell-text">' . e($successText) . '</span></td>';
        $content .= '<td class="admin-activity-message"><span class="admin-cell-text">' . e($message) . '</span></td>';
        $content .= '<td class="admin-activity-agent"><span class="admin-cell-text">' . e($userAgent) . '</span></td>';

        $content .= '</tr>';

        $content .= '<tr id="' . e($rowId) . '" class="admin-activity-detail-row" hidden>';
        $content .= '<td colspan="8">';
        $content .= '<div class="admin-activity-detail-panel">';

        $content .= '<dl class="admin-activity-detail-list">';
        $content .= '<dt>Date/time</dt><dd>' . e($dateTime) . '</dd>';
        $content .= '<dt>Event</dt><dd>' . e($eventType) . '</dd>';
        $content .= '<dt>Email</dt><dd>' . e($email) . '</dd>';
        $content .= '<dt>IP address</dt><dd>' . e($ip) . '</dd>';
        $content .= '<dt>URL</dt><dd>' . e($url) . '</dd>';
        $content .= '<dt>Success</dt><dd>' . e($successText) . '</dd>';
        $content .= '<dt>Message</dt><dd>' . e($message) . '</dd>';
        $content .= '<dt>Referer</dt><dd>' . e($referer) . '</dd>';
        $content .= '<dt>User agent</dt><dd>' . e($userAgent) . '</dd>';
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

$filterIp = trim((string)($_GET['ip'] ?? ''));
$filterEmail = trim((string)($_GET['email'] ?? ''));
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));
$filterUrl = trim((string)($_GET['url'] ?? ''));

$offset = max(0, (int)($_GET['offset'] ?? 0));
$isPartial = (string)($_GET['partial'] ?? '') === '1';
$limit = 100;

$where = [];
$params = [];

if ($filterIp !== '') {
    $where[] = 'ip_address = :ip_address';
    $params['ip_address'] = $filterIp;
}

if ($filterEmail !== '') {
    $where[] = 'email LIKE :email';
    $params['email'] = '%' . $filterEmail . '%';
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

$sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);

foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}

$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$activities = $stmt->fetchAll();

if ($isPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    echo admin_activity_render_rows($activities);
    exit();
}

$content = '<article class="doc-page">';
$content .= '<h1>User activity</h1>';
$content .= '<p class="lead">Filter and inspect access-gate and download activity.</p>';

$content .= '<form method="get" action="/admin/activity" class="admin-activity-filter">';
$content .= '<div class="admin-activity-filter-grid">';

$content .= '<label class="admin-form-field">';
$content .= '<span>IP address</span>';
$content .= '<input type="text" name="ip" value="' . e($filterIp) . '" placeholder="IP address">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>Email</span>';
$content .= '<input type="text" name="email" value="' . e($filterEmail) . '" placeholder="user@example.com">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>URL contains</span>';
$content .= '<input type="text" name="url" value="' . e($filterUrl) . '" placeholder="/downloads, /books, ...">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>From</span>';
$content .= '<input type="datetime-local" name="from" value="' . e($filterFrom) . '">';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>Till</span>';
$content .= '<input type="datetime-local" name="to" value="' . e($filterTo) . '">';
$content .= '</label>';

$content .= '</div>';

$content .= '<div class="admin-activity-filter-actions">';
$content .= '<button type="submit">Apply</button>';
$content .= '<a class="button-link" href="/admin/activity">Reset</a>';
$content .= '</div>';

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
    $content .= '<tbody id="adminActivityRows">';
    $content .= admin_activity_render_rows($activities);
    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';
    $content .= '<div id="adminActivityLoadStatus" class="admin-activity-load-status"></div>';
}

$queryForJs = $_GET;
unset($queryForJs['offset'], $queryForJs['partial']);

$baseQuery = http_build_query($queryForJs);
$baseQueryJson = json_encode($baseQuery, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$initialOffset = $offset + $limit;
$hasMore = count($activities) === $limit ? 'true' : 'false';

$content .= <<<HTML
<script>
(function () {
    const tbody = document.getElementById("adminActivityRows");
    const status = document.getElementById("adminActivityLoadStatus");

    if (!tbody || !status) {
        return;
    }

    let offset = {$initialOffset};
    let loading = false;
    let hasMore = {$hasMore};
    const limit = {$limit};
    const baseQuery = {$baseQueryJson};

    function bindActivityRowToggle(root) {
        root.querySelectorAll("[data-activity-toggle]").forEach(function (row) {
            if (row.dataset.boundActivityToggle === "1") {
                return;
            }

            row.dataset.boundActivityToggle = "1";

            row.addEventListener("click", function () {
                const targetId = row.getAttribute("data-activity-toggle");
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

    bindActivityRowToggle(document);

    async function loadMore() {
        if (loading || !hasMore) {
            return;
        }

        const nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;

        if (!nearBottom) {
            return;
        }

        loading = true;
        status.textContent = "Loading more activity...";

        const params = new URLSearchParams(baseQuery);
        params.set("offset", String(offset));
        params.set("partial", "1");

        try {
            const response = await fetch("/admin/activity?" + params.toString(), {
                headers: {
                    "X-Requested-With": "fetch"
                }
            });

            if (!response.ok) {
                throw new Error("Could not load activity");
            }

            const html = await response.text();

            if (html.trim() === "") {
                hasMore = false;
                status.textContent = "No more activity.";
                return;
            }

            tbody.insertAdjacentHTML("beforeend", html);
            bindActivityRowToggle(tbody);

            const loadedRows = (html.match(/class="admin-activity-summary-row/g) || []).length;
            offset += loadedRows;

            if (loadedRows < limit) {
                hasMore = false;
                status.textContent = "No more activity.";
            } else {
                status.textContent = "";
            }
        } catch (error) {
            status.textContent = "Could not load more activity.";
        } finally {
            loading = false;
        }
    }

    window.addEventListener("scroll", loadMore, { passive: true });
})();
</script>
HTML;

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

