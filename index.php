<?php

declare(strict_types=1);

$config = require __DIR__ . "/app/config.php";

require __DIR__ . "/app/page_cache.php";
require __DIR__ . "/app/db.php";
require __DIR__ . "/app/helpers.php";
require __DIR__ . "/app/renderer.php";
require __DIR__ . "/app/access_gate.php";
require __DIR__ . "/app/exclusions.php";
require __DIR__ . "/app/page_repository.php";
require __DIR__ . "/app/asset_proxy.php";
require __DIR__ . "/app/link_rewriter.php";

redirect_to_base_url_if_needed($config);
access_gate_start_session($config);

$pdo = db($config, "public_db");

$GLOBALS["pdo"] = $pdo;
$GLOBALS["config"] = $config;

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$path = "/" . trim($path, "/");
$path = $path === "/" ? "/" : rtrim($path, "/");

if (
    $path === asset_proxy_path($config) ||
    str_starts_with($path, asset_proxy_path($config) . '/')
) {
    asset_proxy_handle_request($config, $path);
    exit();
}

if (
    $path === "/access/request-code" &&
    ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST"
) {
    access_gate_json_response(access_gate_request_code($pdo, $config));
}

if ($path === "/access/request-code") {
    access_gate_json_response([
        "ok" => false,
        "message" => "Invalid request method. This endpoint only accepts POST.",
    ]);
}

if (
    $path === "/access/verify-code" &&
    ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST"
) {
    access_gate_json_response(access_gate_verify_code($pdo, $config));
}

if ($path === "/access/verify-code") {
    access_gate_json_response([
        "ok" => false,
        "message" => "Invalid request method. This endpoint only accepts POST.",
    ]);
}

/**
 * Route: Admin hidden/blocked pages overview.
 *
 * Shows all soft-hidden pages and hard-blocked pages for admin IPs only.
 */
if ($path === "/app/admin/hidden-pages") {
    require __DIR__ . "/app/admin/blocked_pages.php";
}

/*
 * Page cache only after access/admin/asset routes are handled.
 */
if (page_cache_try_serve($config, $path)) {
    exit();
}

page_cache_start($config, $path);


if ($path === "/access/magic-login") {
    if (access_gate_verify_magic_token($pdo, $config)) {
        header("Location: /");
        exit();
    }

    render_layout(
        $config,
        "Access link expired",
        "The access link is invalid or expired.",
        '<section class="search-page"><h1>Access link expired</h1><p>The login link is invalid, expired, or already used. Please request a new access code.</p><p><a href="/">Return to the manual</a></p></section>',
        "/access/magic-login"
    );

    exit();
}

if ($path === "/access/logout") {
    if (function_exists("access_gate_log")) {
        access_gate_log(
            $pdo,
            $_SESSION["manual_access_email"] ?? null,
            "logout",
            true,
            "User logged out"
        );
    }

    unset(
        $_SESSION["manual_access_email"],
        $_SESSION["manual_access_verified_until"],
        $_SESSION["manual_access_pending_email"]
    );

    header("Location: /");
    exit();
}

if (
    in_array(
        $path,
        ["/admin/toggle-page-visibility", "/admin/toggle-page-exclusion"],
        true
    ) &&
    ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST"
) {
    $sourceKey = trim((string) ($_POST["source_key"] ?? ""));
    $sourcePageId = (int) ($_POST["source_page_id"] ?? 0);
    $action = trim((string) ($_POST["action"] ?? ""));

    if ($sourceKey === "" || $sourcePageId <= 0) {
        http_response_code(400);
        echo "Missing source or page ID";
        exit();
    }

    if (!can_access_admin_pages($config, $sourceKey)) {
        http_response_code(403);
        echo "Forbidden";
        exit();
    }

    if (in_array($action, ["hide", "exclude"], true)) {
        set_page_excluded($pdo, $sourceKey, $sourcePageId, true);
    } elseif (in_array($action, ["show", "include"], true)) {
        set_page_excluded($pdo, $sourceKey, $sourcePageId, false);
    } else {
        http_response_code(400);
        echo "Invalid action";
        exit();
    }

    $returnTo = (string) ($_POST["return_to"] ?? "/");

    if ($returnTo === "" || $returnTo[0] !== "/") {
        $returnTo = "/";
    }

    header("Location: " . $returnTo);
    exit();
}

/**
 * Log verified page views.
 *
 * Do not log static assets, sitemap, robots, or access-gate endpoints.
 */
if (
    function_exists("access_gate_is_verified") &&
    function_exists("access_gate_log") &&
    access_gate_is_verified($config)
) {
    $logPath = $path ?? "/";

    $skipAccessLog = false;

    if (str_starts_with($logPath, "/assets/")) {
        $skipAccessLog = true;
    }

    if (str_starts_with($logPath, "/access/")) {
        $skipAccessLog = true;
    }

    if (
        in_array(
            $logPath,
            ["/favicon.ico", "/robots.txt", "/sitemap.xml"],
            true
        )
    ) {
        $skipAccessLog = true;
    }

    if (!$skipAccessLog) {
        access_gate_log(
            $pdo,
            $_SESSION["manual_access_email"] ?? null,
            "page_view",
            true,
            null,
            $logPath
        );
    }
}

/**
 * Route: Home page.
 */
if ($path === "/") {
    $stmt = $pdo->query("
    SELECT
        book_id,
        book_name,
        book_slug,
        COUNT(*) AS page_count,
        MAX(updated_at) AS updated_at
    FROM public_docs
    WHERE book_slug IS NOT NULL
      AND book_slug != ''
    GROUP BY
        book_id,
        book_name,
        book_slug
    ORDER BY
        book_name ASC
    ");

    $books = [];

    foreach ($stmt->fetchAll() as $book) {
        $bookPages = fetch_book_pages($pdo, (string) $book["book_slug"]);

        if (!$bookPages) {
            continue;
        }

        $book["page_count"] = count($bookPages);
        $books[] = $book;
    }

    $html = '<section class="home-hero">';
    $html .= "<h1>" . e($config["app_name"] ?? "Documentation") . "</h1>";
    $html .=
        "<p>Browse public product documentation, manuals and troubleshooting guides.</p>";
    $html .= "</section>";

    if (!$books) {
        $html .=
            '<div class="empty-state">No public documentation has been synced yet.</div>';
    } else {
        $html .= '<section class="card-grid">';

        foreach ($books as $book) {
            $html .= '<article class="book-card">';
            $html .=
                '<h2><a href="/books/' .
                e($book["book_slug"]) .
                '">' .
                e($book["book_name"]) .
                "</a></h2>";
            $html .= "<p>" . (int) $book["page_count"] . " public pages</p>";

            if (!empty($book["updated_at"])) {
                $html .=
                    '<span class="card-meta">Updated ' .
                    e($book["updated_at"]) .
                    "</span>";
            }

            $html .= "</article>";
        }

        $html .= "</section>";
    }

    render_layout(
        $config,
        "Documentation",
        "Browse public documentation.",
        $html,
        "/"
    );
    exit();
}

/**
 * Route: Search page.
 */
if ($path === "/search") {
    $q = trim((string) ($_GET["q"] ?? ""));

    $html = '<section class="search-page">';
    $html .= "<h1>Search documentation</h1>";
    $html .= '<form class="large-search" action="/search" method="get">';
    $html .=
        '<input name="q" type="search" value="' .
        e($q) .
        '" placeholder="Search documentation" aria-label="Search documentation">';
    $html .= '<button type="submit">Search</button>';
    $html .= "</form>";

    if ($q !== "") {
        $results = [];

        /**
         * First try MySQL FULLTEXT search.
         */
        try {
            $stmt = $pdo->prepare("
                SELECT
                    id,
                    source_key,
                    source_page_id,
                    page_name,
                    page_slug,
                    book_name,
                    book_slug,
                    chapter_name,
                    chapter_slug,
                    url_path,
                    text_content,
                    description,
                    updated_at,
                    MATCH(page_name, text_content, description, tags)
                        AGAINST(:fulltext_q IN NATURAL LANGUAGE MODE) AS score
                FROM public_docs
                WHERE MATCH(page_name, text_content, description, tags)
                      AGAINST(:fulltext_q_where IN NATURAL LANGUAGE MODE)
                ORDER BY
                    score DESC,
                    updated_at DESC
                LIMIT 50
            ");

            $stmt->execute([
                "fulltext_q" => $q,
                "fulltext_q_where" => $q,
            ]);

            $results = $stmt->fetchAll();
            $results = filter_pages_for_current_ip($pdo, $config, $results);
        } catch (Throwable $e) {
            /**
             * If FULLTEXT search fails for any reason, fall back to LIKE search.
             */
            $results = [];
        }

        /**
         * Fallback LIKE search.
         *
         * Important:
         * Do not reuse the same named PDO placeholder multiple times.
         * Some PDO/MySQL configurations throw HY093 when you do that.
         */
        if (!$results) {
            $like =
                "%" .
                str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $q) .
                "%";

            $stmt = $pdo->prepare("
                SELECT
                    id,
                    source_key,
                    source_page_id,
                    page_name,
                    page_slug,
                    book_name,
                    book_slug,
                    chapter_name,
                    chapter_slug,
                    url_path,
                    text_content,
                    description,
                    updated_at
                FROM public_docs
                WHERE
                    page_name LIKE :like_page_name ESCAPE '\\\\'
                    OR text_content LIKE :like_text_content ESCAPE '\\\\'
                    OR description LIKE :like_description ESCAPE '\\\\'
                    OR tags LIKE :like_tags ESCAPE '\\\\'
                ORDER BY
                    updated_at DESC,
                    page_name ASC
                LIMIT 50
            ");

            $stmt->execute([
                "like_page_name" => $like,
                "like_text_content" => $like,
                "like_description" => $like,
                "like_tags" => $like,
            ]);

            $results = $stmt->fetchAll();
        }

        $html .= "<h2>Results for “" . e($q) . "”</h2>";

        if (!$results) {
            $html .= '<div class="empty-state">No results found.</div>';
        } else {
            $html .= '<ol class="search-results">';

            foreach ($results as $result) {
                $resultText =
                    $result["description"] ?:
                    excerpt($result["text_content"] ?? "", 220);

                $html .= "<li>";
                $html .=
                    '<h3><a href="' .
                    e($result["url_path"]) .
                    '">' .
                    e($result["page_name"]) .
                    "</a></h3>";

                $html .= '<p class="breadcrumb-line">';

                if (!empty($result["book_name"])) {
                    $html .= e($result["book_name"]);
                }

                if (!empty($result["chapter_name"])) {
                    $html .= " / " . e($result["chapter_name"]);
                }

                $html .= "</p>";
                $html .= "<p>" . e(excerpt($resultText, 260)) . "</p>";
                $html .= "</li>";
            }

            $html .= "</ol>";
        }
    }

    $html .= "</section>";

    render_layout(
        $config,
        "Search",
        "Search public documentation.",
        $html,
        "/search"
    );

    exit();
}

/**
 * Route: Dynamic sitemap.
 */
if ($path === "/sitemap.xml") {
    header("Content-Type: application/xml; charset=UTF-8");

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    echo "<url><loc>" . e(canonical_url($config, "/")) . "</loc></url>";

    $bookStmt = $pdo->query("
        SELECT
            book_slug,
            MAX(updated_at) AS updated_at
        FROM public_docs
        WHERE book_slug IS NOT NULL
          AND book_slug != ''
        GROUP BY book_slug
        ORDER BY book_slug ASC
    ");

    foreach ($bookStmt as $book) {
        echo "<url>";
        echo "<loc>" .
            e(canonical_url($config, "/books/" . $book["book_slug"])) .
            "</loc>";

        if (!empty($book["updated_at"])) {
            echo "<lastmod>" .
                e(date("c", strtotime($book["updated_at"]))) .
                "</lastmod>";
        }

        echo "</url>";
    }

    $pageStmt = $pdo->query("
        SELECT url_path, updated_at
        FROM public_docs
        ORDER BY updated_at DESC
    ");

    foreach ($pageStmt as $row) {
        echo "<url>";
        echo "<loc>" . e(canonical_url($config, $row["url_path"])) . "</loc>";

        if (!empty($row["updated_at"])) {
            echo "<lastmod>" .
                e(date("c", strtotime($row["updated_at"]))) .
                "</lastmod>";
        }

        echo "</url>";
    }

    echo "</urlset>";
    exit();
}

/**
 * Route: llms.txt.
 */
if ($path === "/llms.txt") {
    header("Content-Type: text/plain; charset=UTF-8");

    echo "# " . ($config["app_name"] ?? "Documentation") . "\n\n";
    echo "This site contains public documentation exported from BookStack.\n\n";

    $bookStmt = $pdo->query("
        SELECT
            book_name,
            book_slug,
            COUNT(*) AS page_count
        FROM public_docs
        WHERE book_slug IS NOT NULL
          AND book_slug != ''
        GROUP BY
            book_id,
            book_name,
            book_slug
        ORDER BY
            book_name ASC
    ");

    echo "## Documentation books\n\n";

    foreach ($bookStmt as $book) {
        echo "- [" .
            $book["book_name"] .
            "](" .
            canonical_url($config, "/books/" . $book["book_slug"]) .
            ")";
        echo " - " . (int) $book["page_count"] . " pages\n";
    }

    echo "\n## Recently updated pages\n\n";

    $pageStmt = $pdo->query("
        SELECT page_name, url_path, description
        FROM public_docs
        ORDER BY updated_at DESC, page_name ASC
        LIMIT 100
    ");

    foreach ($pageStmt as $page) {
        echo "- [" .
            $page["page_name"] .
            "](" .
            canonical_url($config, $page["url_path"]) .
            ")";

        if (!empty($page["description"])) {
            echo " - " . str_replace(["\r", "\n"], " ", $page["description"]);
        }

        echo "\n";
    }

    exit();
}

/**
 * Route: Book overview page.
 */
if (preg_match('#^/books/([^/]+)$#', $path, $match)) {
    $bookSlug = $match[1];
    $pages = fetch_book_pages($pdo, $bookSlug);

    if (!$pages) {
        http_response_code(404);
        render_layout(
            $config,
            "Not found",
            "Book not found.",
            '<div class="empty-state">Book not found.</div>',
            $path
        );
        exit();
    }

    $bookName = $pages[0]["book_name"] ?: $bookSlug;

    $content = '<article class="doc-page">';
    $content .= "<h1>" . e($bookName) . "</h1>";
    $content .=
        '<p class="lead">Select a page from the navigation tree on the left.</p>';
    $content .= '<div class="page-overview">';

    foreach ($pages as $page) {
        $sourceKey = (string) ($page["source_key"] ?? "");
        $sourcePageId = (int) ($page["source_page_id"] ?? 0);

        $isHidden = false;

        if (
            function_exists("is_page_excluded") &&
            $sourceKey !== "" &&
            $sourcePageId > 0
        ) {
            /*
             * This marks soft-hidden pages.
             * Hard-excluded pages should already be filtered out for everyone.
             */
            $isHidden = is_page_excluded(
                $pdo,
                $config,
                $sourceKey,
                $sourcePageId
            );
        }

        $overviewClass = $isHidden
            ? "overview-link overview-link-hidden"
            : "overview-link";

        $content .=
            '<a class="' .
            e($overviewClass) .
            '" href="' .
            e($page["url_path"]) .
            '">';

        $content .= "<strong>";
        $content .= e($page["page_name"]);

//        if ($sourcePageId > 0) {
//            $content .= " <small>#" . e((string) $sourcePageId) . "</small>";
//        }

        if ($isHidden) {
            $content .= ' <span class="hidden-page-label">Hidden</span>';
        }

        $content .= "</strong>";

        if (!empty($page["description"])) {
            $content .=
                "<span>" . e(excerpt($page["description"], 160)) . "</span>";
        }

        $content .= "</a>";
    }

    $content .= "</div>";
    $content .= "</article>";

    //$html = render_split_documentation($pages, $content);
    $treePages = fetch_tree_pages($pdo, $config, $bookSlug);
    $html = render_split_documentation($treePages, $content);

    render_layout(
        $config,
        $bookName,
        "Public pages in " . $bookName . ".",
        $html,
        "/books/" . $bookSlug
    );
    exit();
}

/**
 * Route: BookStack-compatible page route:
 * /books/{book-slug}/page/{page-slug}
 */
if (preg_match('#^/books/([^/]+)/page/([^/]+)$#', $path, $match)) {
    $bookSlug = $match[1];
    $pageSlug = $match[2];

    $stmt = $pdo->prepare("
        SELECT *
        FROM public_docs
        WHERE book_slug = :book_slug
          AND page_slug = :page_slug
        LIMIT 1
    ");

    $stmt->execute([
        "book_slug" => $bookSlug,
        "page_slug" => $pageSlug,
    ]);

    $page = $stmt->fetch();

    if (!$page) {
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

    if (!page_visible_to_current_ip($pdo, $config, $page)) {
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

    $pageSourceKey = (string) ($page["source_key"] ?? "");
    $pageSourcePageId = (int) ($page["source_page_id"] ?? 0);

    $pageIsHidden = false;

    if (
        function_exists("is_page_excluded") &&
        $pageSourceKey !== "" &&
        $pageSourcePageId > 0
    ) {
        $pageIsHidden = is_page_excluded(
            $pdo,
            $config,
            $pageSourceKey,
            $pageSourcePageId
        );
    }

    if ($pageIsHidden) {
        header("X-Robots-Tag: noindex, nofollow, noarchive", true);
    }

    //$treePages = fetch_all_tree_pages($pdo, $config);
    $treePages = fetch_tree_pages($pdo, $config, (string) $page["book_slug"]);

//    $description =
//        $page["description"] ?: excerpt($page["text_content"] ?? "", 160);

    $description = seo_meta_description(
        (string)($page["description"] ?? ""),
        (string)($page["text_content"] ?? ""),
        155
    );

    $seoTitle = seo_page_title(
        (string)($page["page_name"] ?? ""),
        (string)($page["book_name"] ?? ""),
        58
    );


$pageHtml = add_heading_ids($page["html"] ?? "");

if (function_exists("rewrite_bookstack_page_links")) {
    $pageHtml = rewrite_bookstack_page_links($pdo, $pageHtml, $page, $config);
}

if (function_exists("asset_proxy_rewrite_html")) {
    $pageHtml = asset_proxy_rewrite_html($pageHtml, $page, $config);
}


$activeHeadings = extract_headings($pageHtml);

    $activeHeadings = extract_headings($pageHtml);
    $sourceUrl = bookstack_page_url($config, $page);

    $content = "";
    $content .=
        '<div id="page-top" class="doc-scroll-actions doc-scroll-actions-bottom">';
    $content .= '<article class="doc-page">';

    //    $content .= render_page_visibility_toggle($pdo, $config, $page);
    $content .= '<nav class="breadcrumb-line">';
    $content .= '<a href="/">Documentation</a>';

    if (!empty($page["book_slug"]) && !empty($page["book_name"])) {
        $content .=
            ' / <a href="/books/' .
            e($page["book_slug"]) .
            '">' .
            e($page["book_name"]) .
            "</a>";
    }

    if (!empty($page["chapter_name"])) {
        $content .= " / " . e($page["chapter_name"]);
    }

    $content .= "</nav>";

    $content .= "<h1>" . e($page["page_name"]) . "</h1>";

    $content .= '<div class="page-meta">';

    if (!empty($page["updated_at"])) {
        $content .= "<span>Updated: " . e($page["updated_at"]) . "</span>";
    }

    //    if ($sourceUrl) {
    //        $content .= '<a href="' . e($sourceUrl) . '" target="_blank" rel="noopener noreferrer">Original BookStack source</a>';
    //    }

    $content .= "</div>";

    $content .= '<div class="doc-scroll-actions doc-scroll-actions-top">';
    $content .=
        '<a href="#page-bottom" class="doc-scroll-button">Scroll down</a>';
    $content .= "</div>";

    $content .= '<script type="application/ld+json">';
    $content .= json_encode(
        [
            "@context" => "https://schema.org",
            "@type" => "TechArticle",
            "headline" => $page["page_name"],
            "description" => $description,
            "dateModified" => $page["updated_at"],
            "mainEntityOfPage" => canonical_url($config, $page["url_path"]),
            "isBasedOn" => $sourceUrl,
        ],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    $content .= "</script>";

    $content .= '<div class="doc-body">';
    $content .= $pageHtml;

    $content .= '<div class="doc-scroll-actions doc-scroll-actions-top">';
    $content .=
        '<a href="#page-top" class="doc-scroll-button">Scroll naar top van de pagina</a>';
    $content .= "</div>";

    if (
        $sourceUrl &&
        function_exists("can_access_admin_pages") &&
        can_access_admin_pages(
            $config,
            (string) ($page["source_key"] ?? "")
        )
    ) {
        $content .= '<br><br><hr><div class="doc-admin-source-bar">';
        $content .= '<span class="doc-admin-source-text">';
        $content .=
            "This document is automatically synchronized. The origional document is located at : <br>";
        $content .=
            '<a href="' .
            e($sourceUrl) .
            '" target="_blank" rel="noopener noreferrer">' .
            e($sourceUrl) .
            "</a>";
        $content .= "</span>";

        $content .=
            '<a class="doc-admin-source-button" href="' .
            e($sourceUrl) .
            '" target="_blank" rel="noopener noreferrer">';
        $content .= "Open origional document";
        $content .= "</a>";

        $content .= "</div>";
    }

    $content .= "</div>";
    $content .= render_page_visibility_toggle($pdo, $config, $page);

    $content .=
        '<div id="page-bottom" class="doc-scroll-actions doc-scroll-actions-bottom">';
    $content .= "</article>";

    $html = render_split_documentation(
        $treePages,
        $content,
        (int) $page["id"],
        $activeHeadings
    );

    render_layout(
        $config,
        $seoTitle,
        $description,
        $html,
        $page["url_path"]
    );

//    render_layout(
//        $config,
//        $page["page_name"],
//        $description,
//        $html,
//        $page["url_path"]
//    );
    exit();
}

/**
 * Backwards compatibility:
 * /pages/{slug}
 */
if (preg_match('#^/pages/([^/]+)$#', $path, $match)) {
    $pageSlug = $match[1];

    $stmt = $pdo->prepare("
        SELECT url_path
        FROM public_docs
        WHERE page_slug = :page_slug
        ORDER BY id ASC
        LIMIT 2
    ");

    $stmt->execute([
        "page_slug" => $pageSlug,
    ]);

    $matches = $stmt->fetchAll();

    if (count($matches) === 1) {
        header("Location: " . $matches[0]["url_path"], true, 301);
        exit();
    }

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

/**
 * Backwards compatibility:
 * /pages/{id}/{slug}
 *
 * Redirects to BookStack-compatible URL:
 * /books/{book-slug}/page/{page-slug}
 */

if (preg_match('#^/pages/([0-9]+)/([^/]+)$#', $path, $match)) {
    $pageId = (int) $match[1];

    $stmt = $pdo->prepare("
        SELECT url_path
        FROM public_docs
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        "id" => $pageId,
    ]);

    $page = $stmt->fetch();

    if ($page && !empty($page["url_path"])) {
        header("Location: " . $page["url_path"], true, 301);
        exit();
    }

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

http_response_code(404);
render_layout(
    $config,
    "Not found",
    "Page not found.",
    '<div class="empty-state">The requested page does not exist.</div>',
    $path
);
