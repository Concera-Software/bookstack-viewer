<?php

declare(strict_types=1);

/**
 * Route: Admin download audit.
 *
 * Shows requested and executed downloads from public_download_requests.
 *
 * Features:
 * - admin-only access;
 * - read-only;
 * - filters by IP address, email, status, filename/download key, from date/time, and till date/time;
 * - first page limited to 100 rows;
 * - endless scrolling loads the next 100 rows;
 * - compact two-column filter layout;
 * - no wrapping in the table;
 * - optional long fields are clipped;
 * - clicking a row expands a detail row directly underneath it.
 */

if ($path !== "/admin/downloads") {
    return;
}

/**
 * Render download audit rows for the full page and endless-scroll partial loads.
 *
 * Each download audit record is rendered as two rows:
 * - a summary row;
 * - a hidden detail row that can be expanded by clicking the summary row.
 *
 * @param array $downloads
 * @return string
 */
function admin_downloads_render_rows(array $downloads): string
{
    $content = '';

    foreach ($downloads as $download) {
        $id = (int)($download['id'] ?? 0);
        $rowId = 'download-details-' . $id . '-' . substr(md5(json_encode($download)), 0, 8);

        $status = (string)($download['status'] ?? '');
        $createdAt = (string)($download['created_at'] ?? '');
        $email = (string)($download['email'] ?? '');
        $ip = (string)($download['ip_address'] ?? '');
        $category = (string)($download['category'] ?? '');
        $categorySlug = (string)($download['category_slug'] ?? '');
        $downloadTitle = (string)($download['download_title'] ?? '');
        $filename = (string)($download['filename'] ?? '');
        $downloadKey = (string)($download['download_key'] ?? '');
        $codeSentAt = (string)($download['code_sent_at'] ?? '');
        $verifiedAt = (string)($download['verified_at'] ?? '');
        $downloadedAt = (string)($download['downloaded_at'] ?? '');
        $failedAt = (string)($download['failed_at'] ?? '');
        $failureReason = (string)($download['failure_reason'] ?? '');
        $userAgent = (string)($download['user_agent'] ?? '');
        $referer = (string)($download['referer'] ?? '');

        $rowClass = 'is-active';

        if ($status === 'failed') {
            $rowClass = 'is-revoked';
        } elseif ($status !== 'downloaded') {
            $rowClass = 'is-expired';
        }

        $displayFile = $filename !== '' ? $filename : $downloadTitle;

        $content .= '<tr class="admin-download-summary-row ' . e($rowClass) . '" data-download-toggle="' . e($rowId) . '">';

        $content .= '<td class="admin-download-date"><span class="admin-cell-text">' . e($createdAt) . '</span></td>';
        $content .= '<td class="admin-download-status"><span class="admin-cell-text"><strong>' . e($status) . '</strong></span></td>';
        $content .= '<td class="admin-download-email"><span class="admin-cell-text">' . e($email) . '</span></td>';
        $content .= '<td class="admin-download-ip"><span class="admin-cell-text">' . e($ip) . '</span></td>';
        $content .= '<td class="admin-download-category"><span class="admin-cell-text">' . e($category) . '</span></td>';
        $content .= '<td class="admin-download-file"><span class="admin-cell-text">' . e($displayFile) . '</span></td>';
        $content .= '<td class="admin-download-verified"><span class="admin-cell-text">' . e($verifiedAt) . '</span></td>';
        $content .= '<td class="admin-download-downloaded"><span class="admin-cell-text">' . e($downloadedAt) . '</span></td>';
        $content .= '<td class="admin-download-failure"><span class="admin-cell-text">' . e($failureReason) . '</span></td>';

        $content .= '</tr>';

        $content .= '<tr id="' . e($rowId) . '" class="admin-download-detail-row" hidden>';
        $content .= '<td colspan="9">';
        $content .= '<div class="admin-download-detail-panel">';

        $content .= '<dl class="admin-download-detail-list">';
        $content .= '<dt>ID</dt><dd>' . e((string)$id) . '</dd>';
        $content .= '<dt>Status</dt><dd>' . e($status) . '</dd>';
        $content .= '<dt>Created</dt><dd>' . e($createdAt) . '</dd>';
        $content .= '<dt>Email</dt><dd>' . e($email) . '</dd>';
        $content .= '<dt>IP address</dt><dd>' . e($ip) . '</dd>';
        $content .= '<dt>Category</dt><dd>' . e($category) . '</dd>';
        $content .= '<dt>Category slug</dt><dd>' . e($categorySlug) . '</dd>';
        $content .= '<dt>Download title</dt><dd>' . e($downloadTitle) . '</dd>';
        $content .= '<dt>Filename</dt><dd>' . e($filename) . '</dd>';
        $content .= '<dt>Download key</dt><dd>' . e($downloadKey) . '</dd>';
        $content .= '<dt>Code sent</dt><dd>' . e($codeSentAt) . '</dd>';
        $content .= '<dt>Verified</dt><dd>' . e($verifiedAt) . '</dd>';
        $content .= '<dt>Downloaded</dt><dd>' . e($downloadedAt) . '</dd>';
        $content .= '<dt>Failed</dt><dd>' . e($failedAt) . '</dd>';
        $content .= '<dt>Failure reason</dt><dd>' . e($failureReason) . '</dd>';
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
$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterFile = trim((string)($_GET['file'] ?? ''));
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo = trim((string)($_GET['to'] ?? ''));

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

if ($filterStatus !== '') {
    $where[] = 'status = :status';
    $params['status'] = $filterStatus;
}

if ($filterFile !== '') {
    $where[] = '(filename LIKE :file_filter OR download_key LIKE :file_filter OR download_title LIKE :file_filter)';
    $params['file_filter'] = '%' . $filterFile . '%';
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

$sql = "
    SELECT
        id,
        email,
        download_key,
        download_title,
        filename,
        category,
        category_slug,
        ip_address,
        user_agent,
        referer,
        status,
        code_sent_at,
        verified_at,
        downloaded_at,
        failed_at,
        failure_reason,
        created_at
    FROM public_download_requests
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
$downloads = $stmt->fetchAll();

if ($isPartial) {
    header('Content-Type: text/html; charset=UTF-8');
    echo admin_downloads_render_rows($downloads);
    exit();
}

$content = '<article class="doc-page">';
$content .= '<h1>Download audit</h1>';
$content .= '<p class="lead">Read-only list of requested and executed downloads.</p>';

$content .= '<form method="get" action="/admin/downloads" class="admin-activity-filter admin-download-filter">';
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
$content .= '<span>Status</span>';
$content .= '<select name="status">';
$content .= '<option value="">Any status</option>';

foreach (['requested', 'code_sent', 'verified', 'downloaded', 'failed'] as $statusOption) {
    $content .= '<option value="' . e($statusOption) . '"' . ($filterStatus === $statusOption ? ' selected' : '') . '>' . e($statusOption) . '</option>';
}

$content .= '</select>';
$content .= '</label>';

$content .= '<label class="admin-form-field">';
$content .= '<span>File contains</span>';
$content .= '<input type="text" name="file" value="' . e($filterFile) . '" placeholder="filename, title, path">';
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
$content .= '<a class="button-link" href="/admin/downloads">Reset</a>';
$content .= '</div>';

$content .= '</form>';

if (!$downloads) {
    $content .= '<div class="empty-state">No download requests found for the selected filter.</div>';
} else {
    $content .= '<div class="admin-table-wrap">';
    $content .= '<table class="admin-session-table admin-download-table">';
    $content .= '<thead>';
    $content .= '<tr>';
    $content .= '<th>Created</th>';
    $content .= '<th>Status</th>';
    $content .= '<th>Email</th>';
    $content .= '<th>IP address</th>';
    $content .= '<th>Category</th>';
    $content .= '<th>File</th>';
    $content .= '<th>Verified</th>';
    $content .= '<th>Downloaded</th>';
    $content .= '<th>Failure</th>';
    $content .= '</tr>';
    $content .= '</thead>';
    $content .= '<tbody id="adminDownloadRows">';
    $content .= admin_downloads_render_rows($downloads);
    $content .= '</tbody>';
    $content .= '</table>';
    $content .= '</div>';
    $content .= '<div id="adminDownloadLoadStatus" class="admin-activity-load-status"></div>';
}

$queryForJs = $_GET;
unset($queryForJs['offset'], $queryForJs['partial']);

$baseQuery = http_build_query($queryForJs);
$baseQueryJson = json_encode($baseQuery, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
$initialOffset = $offset + $limit;
$hasMore = count($downloads) === $limit ? 'true' : 'false';

$content .= <<<HTML
<script>
(function () {
    const tbody = document.getElementById("adminDownloadRows");
    const status = document.getElementById("adminDownloadLoadStatus");

    if (!tbody || !status) {
        return;
    }

    let offset = {$initialOffset};
    let loading = false;
    let hasMore = {$hasMore};
    const limit = {$limit};
    const baseQuery = {$baseQueryJson};

    function bindDownloadRowToggle(root) {
        root.querySelectorAll("[data-download-toggle]").forEach(function (row) {
            if (row.dataset.boundDownloadToggle === "1") {
                return;
            }

            row.dataset.boundDownloadToggle = "1";

            row.addEventListener("click", function () {
                const targetId = row.getAttribute("data-download-toggle");
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

    bindDownloadRowToggle(document);

    async function loadMore() {
        if (loading || !hasMore) {
            return;
        }

        const nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 300;

        if (!nearBottom) {
            return;
        }

        loading = true;
        status.textContent = "Loading more downloads...";

        const params = new URLSearchParams(baseQuery);
        params.set("offset", String(offset));
        params.set("partial", "1");

        try {
            const response = await fetch("/admin/downloads?" + params.toString(), {
                headers: {
                    "X-Requested-With": "fetch"
                }
            });

            if (!response.ok) {
                throw new Error("Could not load downloads");
            }

            const html = await response.text();

            if (html.trim() === "") {
                hasMore = false;
                status.textContent = "No more downloads.";
                return;
            }

            tbody.insertAdjacentHTML("beforeend", html);
            bindDownloadRowToggle(tbody);

            const loadedRows = (html.match(/class="admin-download-summary-row/g) || []).length;
            offset += loadedRows;

            if (loadedRows < limit) {
                hasMore = false;
                status.textContent = "No more downloads.";
            } else {
                status.textContent = "";
            }
        } catch (error) {
            status.textContent = "Could not load more downloads.";
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
    ? render_split_documentation($treePages, $content, -900004, [])
    : $content;

render_layout(
    $config,
    "Download audit",
    "Download audit.",
    $html,
    "/admin/downloads"
);

exit();
