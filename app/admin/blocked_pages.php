<?php

declare(strict_types=1);

/**
 * Route: Admin hidden/blocked pages overview.
 *
 * Shows all soft-hidden pages and hard-blocked pages for administrators only.
 *
 * Admin access requires:
 * - The current IP address is configured as an admin IP.
 * - The current verified access-gate email is configured as an admin email.
 */

$debugIpOk = function_exists("can_manage_page_exclusions")
    ? can_manage_page_exclusions($config, null)
    : false;

$debugEmail = function_exists("current_verified_access_email")
    ? current_verified_access_email()
    : '';

$debugAdminEmails = function_exists("admin_email_addresses")
    ? admin_email_addresses($config)
    : [];

$debugEmailOk = $debugEmail !== '' && in_array($debugEmail, $debugAdminEmails, true);

header("X-Debug-Admin-Ip-Ok: " . ($debugIpOk ? "yes" : "no"));
header("X-Debug-Admin-Email: " . ($debugEmail !== "" ? $debugEmail : "none"));
header("X-Debug-Admin-Email-Ok: " . ($debugEmailOk ? "yes" : "no"));
header("X-Debug-Remote-Addr: " . ($_SERVER["REMOTE_ADDR"] ?? "none"));
header("X-Debug-Forwarded-For: " . ($_SERVER["HTTP_X_FORWARDED_FOR"] ?? "none"));

if (
    !function_exists("can_access_admin_pages") ||
    !can_access_admin_pages($config, null)
) {
    http_response_code(404);

    render_layout(
        $config,
        "Not found",
        "Page not found (Error 101).",
        '<div class="empty-state">Page not found (Error 101).</div>',
        $path
    );

    exit();
}

if ($path !== "/admin/hidden-pages") {
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
        "Page not found (Error 102).",
        '<div class="empty-state">Page not found (Error 102).</div>',
        $path
    );

    exit();
}

header("X-Robots-Tag: noindex, nofollow, noarchive", true);

$rows = [];

/*
 * Soft-hidden pages from the database.
 */
$stmt = $pdo->query("
    SELECT
        d.id,
        d.source_key,
        d.source_page_id,
        d.book_name,
        d.book_slug,
        d.chapter_name,
        d.page_name,
        d.page_slug,
        d.url_path,
        d.updated_at,
        e.excluded,
        e.updated_at AS hidden_updated_at,
        e.updated_by_ip
    FROM public_doc_exclusions e
    INNER JOIN public_docs d
        ON d.source_key = e.source_key
       AND d.source_page_id = e.source_page_id
    WHERE e.excluded = 1
    ORDER BY
        d.book_name ASC,
        d.chapter_name ASC,
        d.page_name ASC
");

foreach ($stmt->fetchAll() as $row) {
    $rows[] = [
        "type" => "Soft hidden",
        "source_key" => (string)($row["source_key"] ?? ""),
        "source_page_id" => (int)($row["source_page_id"] ?? 0),
        "book_name" => (string)($row["book_name"] ?? ""),
        "book_slug" => (string)($row["book_slug"] ?? ""),
        "chapter_name" => (string)($row["chapter_name"] ?? ""),
        "page_name" => (string)($row["page_name"] ?? ""),
        "page_slug" => (string)($row["page_slug"] ?? ""),
        "url_path" => (string)($row["url_path"] ?? ""),
        "updated_at" => (string)($row["hidden_updated_at"] ?? $row["updated_at"] ?? ""),
        "updated_by_ip" => (string)($row["updated_by_ip"] ?? ""),
    ];
}

/*
 * Hard-blocked pages from config bookstack_sources[].exclude_page_ids.
 *
 * These are normally hidden for everyone, including admin IPs, but this page
 * lists them for administrative visibility.
 */
foreach (($config["bookstack_sources"] ?? []) as $source) {
    if (!is_array($source)) {
        continue;
    }

    $sourceKey = (string)($source["key"] ?? "");

    if ($sourceKey === "") {
        continue;
    }

    foreach (config_excluded_page_ids($config, $sourceKey) as $sourcePageId) {
        $stmt = $pdo->prepare("
            SELECT
                id,
                source_key,
                source_page_id,
                book_name,
                book_slug,
                chapter_name,
                page_name,
                page_slug,
                url_path,
                updated_at
            FROM public_docs
            WHERE source_key = :source_key
              AND source_page_id = :source_page_id
            LIMIT 1
        ");

        $stmt->execute([
            "source_key" => $sourceKey,
            "source_page_id" => $sourcePageId,
        ]);

        $row = $stmt->fetch();

        if ($row) {
            $rows[] = [
                "type" => "Hard blocked",
                "source_key" => (string)($row["source_key"] ?? ""),
                "source_page_id" => (int)($row["source_page_id"] ?? 0),
                "book_name" => (string)($row["book_name"] ?? ""),
                "book_slug" => (string)($row["book_slug"] ?? ""),
                "chapter_name" => (string)($row["chapter_name"] ?? ""),
                "page_name" => (string)($row["page_name"] ?? ""),
                "page_slug" => (string)($row["page_slug"] ?? ""),
                "url_path" => (string)($row["url_path"] ?? ""),
                "updated_at" => (string)($row["updated_at"] ?? ""),
                "updated_by_ip" => "",
            ];
        } else {
            $rows[] = [
                "type" => "Hard blocked",
                "source_key" => $sourceKey,
                "source_page_id" => (int)$sourcePageId,
                "book_name" => "",
                "book_slug" => "",
                "chapter_name" => "",
                "page_name" => "Source page #" . (int)$sourcePageId,
                "page_slug" => "",
                "url_path" => "",
                "updated_at" => "",
                "updated_by_ip" => "",
            ];
        }
    }
}

$content = '<article class="doc-page">';
$content .= '<h1>Hidden and blocked pages</h1>';
$content .= '<p class="lead">This admin-only overview lists pages hidden from normal visitors or blocked by configuration.</p>';

if (!$rows) {
    $content .= '<div class="empty-state">No hidden or blocked pages found.</div>';
} else {
    $content .= '<div class="page-overview">';

    foreach ($rows as $row) {
        $isHardBlocked = $row["type"] === "Hard blocked";

        $classes = [
            "overview-link",
            $isHardBlocked ? "overview-link-hard-blocked" : "overview-link-hidden",
        ];

        $href = (string)($row["url_path"] ?? "");

        /*
         * Hard-blocked pages are intentionally not directly opened because the
         * normal page route hides them for everyone. They are listed here only
         * for administrative visibility.
         */
        if ($href === "" || $isHardBlocked) {
            $href = "#";
            $classes[] = "overview-link-disabled";
        }

        $content .= '<a class="' . e(implode(" ", $classes)) . '" href="' . e($href) . '">';

        $content .= '<strong>';
        $content .= e((string)$row["page_name"]);

        if ($isHardBlocked) {
            $content .= ' <span class="hidden-page-label">Hard blocked</span>';
        } else {
            $content .= ' <span class="hidden-page-label">Soft hidden</span>';
        }

        $content .= '</strong>';

        $meta = [];

        if (!empty($row["book_name"])) {
            $meta[] = "Book: " . (string)$row["book_name"];
        }

        if (!empty($row["chapter_name"])) {
            $meta[] = "Chapter: " . (string)$row["chapter_name"];
        }

        if (!empty($row["source_key"])) {
            $meta[] = "Source: " . (string)$row["source_key"];
        }

        if (!empty($row["source_page_id"])) {
            $meta[] = "Source page ID: #" . (string)$row["source_page_id"];
        }

        if (!empty($row["updated_at"])) {
            $meta[] = "Changed: " . (string)$row["updated_at"];
        }

        if (!empty($row["updated_by_ip"])) {
            $meta[] = "Changed by IP: " . (string)$row["updated_by_ip"];
        }

        if ($meta) {
            $content .= '<span>' . e(implode(" • ", $meta)) . '</span>';
        }

        $content .= '</a>';
    }

    $content .= '</div>';
}

$content .= '</article>';

$treePages = [];

if (function_exists("fetch_tree_pages")) {
    $treePages = fetch_tree_pages($pdo, $config, "admin");
}

$html = function_exists("render_split_documentation")
    ? render_split_documentation($treePages, $content, -900001, [])
    : $content;

render_layout(
    $config,
    "Hidden and blocked pages",
    "Admin overview of hidden and blocked documentation pages.",
    $html,
    "/admin/hidden-pages"
);

exit();
