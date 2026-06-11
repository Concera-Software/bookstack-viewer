<?php

/**
 * Route: Admin hidden/blocked pages overview.
 *
 * Shows all soft-hidden pages and hard-blocked pages for admin IPs only.
 */
if ($path === "/admin/hidden-pages") {
    if (!can_manage_page_exclusions($config, null)) {
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
            "chapter_name" => (string)($row["chapter_name"] ?? ""),
            "page_name" => (string)($row["page_name"] ?? ""),
            "url_path" => (string)($row["url_path"] ?? ""),
            "updated_at" => (string)($row["hidden_updated_at"] ?? $row["updated_at"] ?? ""),
            "updated_by_ip" => (string)($row["updated_by_ip"] ?? ""),
        ];
    }

    /*
     * Hard-blocked pages from config bookstack_sources[].exclude_page_ids.
     *
     * These are normally hidden for everyone, including admin IPs, but we list
     * them here for administrative visibility.
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
                    "chapter_name" => (string)($row["chapter_name"] ?? ""),
                    "page_name" => (string)($row["page_name"] ?? ""),
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
                    "chapter_name" => "",
                    "page_name" => "Source page #" . (int)$sourcePageId,
                    "url_path" => "",
                    "updated_at" => "",
                    "updated_by_ip" => "",
                ];
            }
        }
    }

    $html = '<section class="search-page admin-hidden-pages">';
    $html .= '<h1>Hidden and blocked pages</h1>';
    $html .= '<p class="lead">This admin-only overview lists pages hidden from normal visitors or blocked by configuration.</p>';

    if (!$rows) {
        $html .= '<div class="empty-state">No hidden or blocked pages found.</div>';
    } else {
        $html .= '<table class="admin-hidden-table">';
        $html .= '<thead>';
        $html .= '<tr>';
        $html .= '<th>Status</th>';
        $html .= '<th>Source</th>';
        $html .= '<th>Page ID</th>';
        $html .= '<th>Book</th>';
        $html .= '<th>Chapter</th>';
        $html .= '<th>Page</th>';
        $html .= '<th>Updated</th>';
        $html .= '<th>Changed by IP</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($rows as $row) {
            $html .= '<tr class="' . e($row["type"] === "Hard blocked" ? "is-hard-blocked" : "is-soft-hidden") . '">';
            $html .= '<td><strong>' . e($row["type"]) . '</strong></td>';
            $html .= '<td>' . e($row["source_key"]) . '</td>';
            $html .= '<td>#' . e((string)$row["source_page_id"]) . '</td>';
            $html .= '<td>' . e($row["book_name"]) . '</td>';
            $html .= '<td>' . e($row["chapter_name"]) . '</td>';

            $html .= '<td>';

            if ($row["url_path"] !== "") {
                $html .= '<a href="' . e($row["url_path"]) . '">' . e($row["page_name"]) . '</a>';
            } else {
                $html .= e($row["page_name"]);
            }

            $html .= '</td>';

            $html .= '<td>' . e($row["updated_at"]) . '</td>';
            $html .= '<td>' . e($row["updated_by_ip"]) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
    }

    $html .= '</section>';

    render_layout(
        $config,
        "Hidden and blocked pages",
        "Admin overview of hidden and blocked documentation pages.",
        $html,
        "/admin/hidden-pages"
    );

    exit();
}
