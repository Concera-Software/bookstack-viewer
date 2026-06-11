<?php

declare(strict_types=1);

/**
 * Render the main HTML layout.
 *
 * @param array $config
 * @param string $title
 * @param string $description
 * @param string $body
 * @param string $canonicalPath
 * @return void
 */
function render_layout(array $config, string $title, string $description, string $body, string $canonicalPath = '/'): void
{
    $appName = $config['app_name'] ?? 'Documentation';
    $canonical = canonical_url($config, $canonicalPath);

    echo '<!doctype html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . ' - ' . e($appName) . '</title>';
    echo '<meta name="description" content="' . e($description) . '">';
    echo '<link rel="canonical" href="' . e($canonical) . '">';
    echo '<link rel="stylesheet" href="/assets/style.css">';
    echo '<script type="application/ld+json">';
    echo json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebSite',
        'name' => $appName,
        'url' => rtrim((string)($config['base_url'] ?? ''), '/'),
        'potentialAction' => [
            '@type' => 'SearchAction',
            'target' => rtrim((string)($config['base_url'] ?? ''), '/') . '/search?q={search_term_string}',
            'query-input' => 'required name=search_term_string',
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo '</script>';
    echo '</head>';

    $accessVerified = function_exists('access_gate_is_verified')
        ? access_gate_is_verified($config)
        : true;

    echo '<body class="' . ($accessVerified ? 'access-granted' : 'access-locked') . '">';
    echo '<header class="site-header">';
    echo '<a class="brand" href="/" aria-label="' . e($appName) . '">';
    echo '<img src="/assets/img/cocos-logo-knowledgebase.png" alt="' . e($appName) . ' logo">';
    echo '<span>' . e($appName) . '</span>';
    echo '</a>';

    echo '<form class="search-form" action="/search" method="get">';
    echo '<input name="q" type="search" placeholder="Search documentation" aria-label="Search documentation">';
    echo '<button type="submit">Search</button>';
    echo '</form>';
    echo '</header>';

    echo '<div class="page-shell">';
    echo '<main class="content">';
    echo $body;
    echo '</main>';
    echo '</div>';

    if (!$accessVerified && function_exists('render_access_gate_overlay')) {
        echo render_access_gate_overlay($config);
    }

    echo '</body>';
    echo '</html>';
}

/**
 * Create a URL-safe ID for headings.
 *
 * @param string $text
 * @return string
 */
function heading_slug(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = mb_strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/iu', '-', $text);
    $text = trim((string)$text, '-');

    return $text !== '' ? $text : 'section';
}

/**
 * Extract headings from page HTML.
 *
 * @param string $html
 * @return array
 */
function extract_headings(string $html): array
{
    $headings = [];
    $used = [];

    preg_match_all('/<h([1-6])([^>]*)>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $level = (int)$match[1];
        $attrs = $match[2] ?? '';
        $inner = $match[3] ?? '';
        $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($text === '') {
            continue;
        }

        $id = null;

        if (preg_match('/\sid=["\']([^"\']+)["\']/i', $attrs, $idMatch)) {
            $id = $idMatch[1];
        }

        if (!$id) {
            $base = heading_slug($text);
            $id = $base;

            if (isset($used[$base])) {
                $used[$base]++;
                $id = $base . '-' . $used[$base];
            } else {
                $used[$base] = 1;
            }
        }

        $headings[] = [
            'level' => $level,
            'text' => $text,
            'id' => $id,
        ];
    }

    return $headings;
}

/**
 * Add stable IDs to headings.
 *
 * @param string $html
 * @return string
 */
function add_heading_ids(string $html): string
{
    $used = [];

    return preg_replace_callback('/<h([1-6])([^>]*)>(.*?)<\/h\1>/is', function (array $match) use (&$used): string {
        $level = $match[1];
        $attrs = $match[2] ?? '';
        $inner = $match[3] ?? '';

        if (preg_match('/\sid=["\']([^"\']+)["\']/i', $attrs)) {
            return $match[0];
        }

        $text = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $base = heading_slug($text);
        $id = $base;

        if (isset($used[$base])) {
            $used[$base]++;
            $id = $base . '-' . $used[$base];
        } else {
            $used[$base] = 1;
        }

        return '<h' . $level . $attrs . ' id="' . e($id) . '">' . $inner . '</h' . $level . '>';
    }, $html) ?? $html;
}

/**
 * Build original BookStack page URL.
 *
 * @param array $config
 * @param array $page
 * @return ?string
 */
function bookstack_page_url(array $config, array $page): ?string
{
    if (!empty($page['source_url'])) {
        return (string)$page['source_url'];
    }

    if (empty($config['bookstack_base_url'])) {
        return null;
    }

    if (empty($page['book_slug']) || empty($page['page_slug'])) {
        return null;
    }

    return rtrim((string)$config['bookstack_base_url'], '/')
        . '/books/'
        . rawurlencode((string)$page['book_slug'])
        . '/page/'
        . rawurlencode((string)$page['page_slug']);
}

/**
 * Build the JavaScript used to collapse and expand books in the documentation tree.
 *
 * @return string
 */
function add_collapse_javascirpt(): string
{
    $html = '
<script>
(function () {
    const cookieName = "doc_tree_collapsed_books";

    function getCookie(name) {
        const parts = document.cookie.split(";").map(function (part) {
            return part.trim();
        });

        for (const part of parts) {
            if (part.indexOf(name + "=") === 0) {
                return decodeURIComponent(part.substring(name.length + 1));
            }
        }

        return "";
    }

    function setCookie(name, value, days) {
        const expires = new Date();
        expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));

        document.cookie = name + "=" + encodeURIComponent(value)
            + "; expires=" + expires.toUTCString()
            + "; path=/; SameSite=Lax";
    }

    function readCollapsedBooks() {
        const value = getCookie(cookieName);

        if (!value) {
            return [];
        }

        try {
            const parsed = JSON.parse(value);

            if (Array.isArray(parsed)) {
                return parsed;
            }
        } catch (error) {
            return [];
        }

        return [];
    }

    function writeCollapsedBooks(items) {
        setCookie(cookieName, JSON.stringify(items), 180);
    }

    function applyState(bookElement, collapsed) {
        const button = bookElement.querySelector(".tree-book-toggle");
        const icon = bookElement.querySelector(".tree-book-toggle-icon");

        bookElement.classList.toggle("collapsed", collapsed);

        if (button) {
            button.setAttribute("aria-expanded", collapsed ? "false" : "true");
        }

        if (icon) {
            icon.textContent = collapsed ? "▸" : "▾";
        }
    }

    const collapsedBooks = readCollapsedBooks();
    
    document.querySelectorAll(".doc-tree .tree-book[data-book-slug]").forEach(function (bookElement) {
        const slug = bookElement.getAttribute("data-book-slug");
        const hasActivePage = bookElement.querySelector(".tree-page.active") !== null;
    
        /*
         * If a page inside this book is currently open,
         * always expand this book, even when the cookie says it is collapsed.
         */
        if (hasActivePage) {
            const index = collapsedBooks.indexOf(slug);
    
            if (index !== -1) {
                collapsedBooks.splice(index, 1);
                writeCollapsedBooks(collapsedBooks);
            }
    
            applyState(bookElement, false);
        } else {
            applyState(bookElement, collapsedBooks.indexOf(slug) !== -1);
        }
    
        const button = bookElement.querySelector(".tree-book-toggle");

        if (!button) {
            return;
        }

        button.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();

            const isCollapsed = bookElement.classList.contains("collapsed");
            const newCollapsed = !isCollapsed;

            applyState(bookElement, newCollapsed);

            const current = readCollapsedBooks();
            const index = current.indexOf(slug);

            if (newCollapsed && index === -1) {
                current.push(slug);
            }

            if (!newCollapsed && index !== -1) {
                current.splice(index, 1);
            }

            writeCollapsedBooks(current);
        });
    });
})();
</script>';

    return $html;
}

/**
 * Render book tree.
 *
 * @param array $pages
 * @param ?int $activePageId
 * @param array $activeHeadings
 *
 * @return string
 */
function render_book_tree(array $pages, ?int $activePageId = null, array $activeHeadings = []): string
{
    if (!$pages) {
        return '<aside class="doc-tree"><p>No pages found.</p></aside>';
    }

    $books = [];

    foreach ($pages as $page) {
        $bookSlug = (string)($page['book_slug'] ?? '');

        if ($bookSlug === '') {
            $bookSlug = 'documentation';
        }

        $bookName = (string)($page['book_name'] ?? '');

        if ($bookName === '') {
            $bookName = $bookSlug;
        }

        $chapterName = (string)($page['chapter_name'] ?? '');

        if ($chapterName === '') {
            $chapterName = 'Pages';
        }

        if (!isset($books[$bookSlug])) {
            $books[$bookSlug] = [
                'slug' => $bookSlug,
                'name' => $bookName,
                'chapters' => [],
                'active' => false,
            ];
        }

        if (!isset($books[$bookSlug]['chapters'][$chapterName])) {
            $books[$bookSlug]['chapters'][$chapterName] = [];
        }

        if ((int)($page['id'] ?? 0) === (int)$activePageId) {
            $books[$bookSlug]['active'] = true;
        }

        $books[$bookSlug]['chapters'][$chapterName][] = $page;
    }

    $html = '<aside class="doc-tree">';
    $html .= '<div class="tree-title"><a href="/">Documentation</a></div>';

    $html .= '<nav aria-label="Documentation navigation">';
    $html .= '<ul class="tree-root tree-books">';

    foreach ($books as $book) {
        $bookClass = $book['active'] ? 'tree-book active' : 'tree-book';
        $bookSlug = (string)$book['slug'];
        $bookName = (string)$book['name'];

        $html .= '<li class="' . e($bookClass) . '" data-book-slug="' . e($bookSlug) . '">';
        $html .= '<div class="tree-book-header">';
        $html .= '<button type="button" class="tree-book-toggle" aria-label="Collapse or expand book" aria-expanded="true">';
        $html .= '<span class="tree-book-toggle-icon">▾</span>';
        $html .= '</button>';
        $html .= '<a class="tree-book-title" href="/books/' . e($bookSlug) . '">';
        $html .= e($bookName);
        $html .= '</a>';
        $html .= '</div>';
        $html .= '<ul class="tree-book-chapters">';

        foreach ($book['chapters'] as $chapterName => $chapterPages) {
            $chapterIsActive = false;

            foreach ($chapterPages as $chapterPage) {
                if ((int)($chapterPage['id'] ?? 0) === (int)$activePageId) {
                    $chapterIsActive = true;
                    break;
                }
            }

            $chapterClass = $chapterIsActive ? 'tree-chapter active' : 'tree-chapter';

            $html .= '<li class="' . e($chapterClass) . '">';
            $html .= '<span class="tree-chapter-title">' . e((string)$chapterName) . '</span>';
            $html .= '<ul class="tree-pages">';

            foreach ($chapterPages as $page) {
                $isActive = ((int)($page['id'] ?? 0) === (int)$activePageId);

                $isHidden = false;

                if (
                    function_exists('is_page_excluded') &&
                    isset($GLOBALS['pdo'], $GLOBALS['config'])
                ) {
                    $isHidden = is_page_excluded(
                        $GLOBALS['pdo'],
                        $GLOBALS['config'],
                        (string)($page['source_key'] ?? ''),
                        (int)($page['source_page_id'] ?? 0)
                    );
                }

                $pageClass = 'tree-page';

                if ($isActive) {
                    $pageClass .= ' active';
                }

                if ($isHidden) {
                    $pageClass .= ' tree-page-hidden';
                }

                $html .= '<li class="' . e($pageClass) . '">';
                $html .= '<a href="' . e((string)$page['url_path']) . '">';
                $html .= e((string)$page['page_name']);

                if ($isHidden) {
                    $html .= ' <span class="hidden-page-label tree-hidden-page-label">Hidden</span>';
                }

                $html .= '</a>';

                if ($isActive && $activeHeadings) {
                    $html .= '<ul class="tree-headings">';

                    foreach ($activeHeadings as $heading) {
                        $level = max(1, min(6, (int)$heading['level']));

                        $html .= '<li class="tree-heading level-' . $level . '">';
                        $html .= '<a href="#' . e((string)$heading['id']) . '">' . e((string)$heading['text']) . '</a>';
                        $html .= '</li>';
                    }

                    $html .= '</ul>';
                }

                $html .= '</li>';
            }

            $html .= '</ul>';
            $html .= '</li>';
        }

        $html .= '</ul>';
        $html .= '</li>';
    }

    $html .= '</ul>';
    $html .= '</nav>';
    $html .= '</aside>';

    $html .= add_collapse_javascirpt();

    return $html;
}

/**
 * Render the wiki-style navigation tree.
 *
 * @param array $pages
 * @param ?int $activePageId
 * @param array $activeHeadings
 * @return string
 */
function render_book_tree_old(array $pages, ?int $activePageId = null, array $activeHeadings = []): string
{
    if (!$pages) {
        return '<aside class="doc-tree"><p>No pages found.</p></aside>';
    }

    $bookName = $pages[0]['book_name'] ?: 'Book';
    $bookSlug = $pages[0]['book_slug'] ?: '';

    $html = '<aside class="doc-tree">';
    $html .= '<div class="tree-title">';
    $html .= $bookSlug !== ''
        ? '<a href="/books/' . e($bookSlug) . '">' . e($bookName) . '</a>'
        : e($bookName);
    $html .= '</div>';

    $html .= '<nav aria-label="Documentation navigation">';
    $html .= '<ul class="tree-root">';

    $currentChapter = null;
    $chapterOpen = false;

    foreach ($pages as $page) {
        $chapterName = $page['chapter_name'] ?: 'Pages';
        $isActive = ((int)$page['id'] === (int)$activePageId);

        if ($chapterName !== $currentChapter) {
            if ($chapterOpen) {
                $html .= '</ul></li>';
            }

            $currentChapter = $chapterName;
            $chapterOpen = true;

            $html .= '<li class="tree-chapter">';
            $html .= '<span class="tree-chapter-title">' . e($chapterName) . '</span>';
            $html .= '<ul class="tree-pages">';
        }

//        $html .= '<li class="tree-page' . ($isActive ? ' active' : '') . '">';
//        $html .= '<a href="' . e($page['url_path']) . '">' . e($page['page_name']) . '</a>';

$isHidden = false;

if (
    function_exists('is_page_excluded') &&
    isset($GLOBALS['pdo'], $GLOBALS['config'])
) {
    $isHidden = is_page_excluded(
        $GLOBALS['pdo'],
        $GLOBALS['config'],
        (string)($page['source_key'] ?? ''),
        (int)($page['source_page_id'] ?? 0)
    );
}

$pageClass = 'tree-page';

if ($isActive) {
    $pageClass .= ' active';
}

if ($isHidden) {
    $pageClass .= ' tree-page-hidden';
}

$html .= '<li class="' . e($pageClass) . '">';
$html .= '<a href="' . e($page['url_path']) . '">';
$html .= e($page['page_name']);

if ($isHidden) {
    $html .= ' <span class="hidden-page-label tree-hidden-page-label">Hidden</span>';
}

$html .= '</a>';


        if ($isActive && $activeHeadings) {
            $html .= '<ul class="tree-headings">';

            foreach ($activeHeadings as $heading) {
                $level = max(1, min(6, (int)$heading['level']));
                $html .= '<li class="tree-heading level-' . $level . '">';
                $html .= '<a href="#' . e($heading['id']) . '">' . e($heading['text']) . '</a>';
                $html .= '</li>';
            }

            $html .= '</ul>';
        }

        $html .= '</li>';
    }

    if ($chapterOpen) {
        $html .= '</ul></li>';
    }

    $html .= '</ul>';
    $html .= '</nav>';
    $html .= '</aside>';

    return $html;
}

/**
 * Render split-screen documentation layout.
 *
 * The page content is rendered before the navigation tree in the HTML source.
 * CSS places the tree visually on the left and the content on the right.
 *
 * @param array $treePages
 * @param string $contentHtml
 * @param ?int $activePageId
 * @param array $activeHeadings
 * @return string
 */
function render_split_documentation(
    array $treePages,
    string $contentHtml,
    ?int $activePageId = null,
    array $activeHeadings = []
): string {
    return
        '<div class="doc-split doc-split-content-first" id="docSplit">' .
            '<section class="doc-view">' .
                $contentHtml .
            '</section>' .
            '<div class="doc-resizer" id="docResizer" role="separator" aria-orientation="vertical" title="Drag to resize navigation"></div>' .
            render_book_tree($treePages, $activePageId, $activeHeadings) .
        '</div>' .
        '<script>
            (function () {
                const split = document.getElementById("docSplit");
                const resizer = document.getElementById("docResizer");

                if (!split || !resizer) {
                    return;
                }

                const storageKey = "bookstack_public_nav_width";

                function maxWidth() {
                    return Math.floor(window.innerWidth * 0.25);
                }

                function minWidth() {
                    return Math.min(240, maxWidth());
                }

                function applyWidth(width) {
                    const min = minWidth();
                    const max = maxWidth();
                    const safeWidth = Math.max(min, Math.min(max, width));

                    split.style.setProperty("--doc-nav-width", safeWidth + "px");
                    localStorage.setItem(storageKey, String(safeWidth));
                }

                const saved = parseInt(localStorage.getItem(storageKey) || "", 10);

                if (!Number.isNaN(saved)) {
                    applyWidth(saved);
                } else {
                    applyWidth(maxWidth());
                }

                let dragging = false;

                resizer.addEventListener("pointerdown", function (event) {
                    dragging = true;
                    document.body.classList.add("resizing-doc-nav");
                    resizer.setPointerCapture(event.pointerId);
                    event.preventDefault();
                });

                window.addEventListener("pointermove", function (event) {
                    if (!dragging) {
                        return;
                    }

                    /*
                     * Because the tree is visually on the left, event.clientX
                     * can still be used as the requested navigation width.
                     */
                    applyWidth(event.clientX);
                });

                window.addEventListener("pointerup", function () {
                    dragging = false;
                    document.body.classList.remove("resizing-doc-nav");
                });

                window.addEventListener("resize", function () {
                    const current = parseInt(
                        getComputedStyle(split).getPropertyValue("--doc-nav-width"),
                        10
                    );

                    if (!Number.isNaN(current)) {
                        applyWidth(current);
                    }
                });
            })();
        </script>';
}

/**
 * Render split-screen documentation layout.
 *
 * @param array $treePages
 * @param string $contentHtml
 * @param ?int $activePageId
 * @param array $activeHeadings
 * @return string
 */
function render_split_documentation_old(
    array $treePages,
    string $contentHtml,
    ?int $activePageId = null,
    array $activeHeadings = []
): string {
    return
        '<div class="doc-split" id="docSplit">' .
            render_book_tree($treePages, $activePageId, $activeHeadings) .
            '<div class="doc-resizer" id="docResizer" role="separator" aria-orientation="vertical" title="Drag to resize navigation"></div>' .
            '<section class="doc-view">' .
                $contentHtml .
            '</section>' .
        '</div>' .
        '<script>
            (function () {
                const split = document.getElementById("docSplit");
                const resizer = document.getElementById("docResizer");

                if (!split || !resizer) {
                    return;
                }

                const storageKey = "bookstack_public_nav_width";

                function maxWidth() {
                    return Math.floor(window.innerWidth * 0.25);
                }

                function minWidth() {
                    return Math.min(240, maxWidth());
                }

                function applyWidth(width) {
                    const min = minWidth();
                    const max = maxWidth();
                    const safeWidth = Math.max(min, Math.min(max, width));

                    split.style.setProperty("--doc-nav-width", safeWidth + "px");
                    localStorage.setItem(storageKey, String(safeWidth));
                }

                const saved = parseInt(localStorage.getItem(storageKey) || "", 10);

                if (!Number.isNaN(saved)) {
                    applyWidth(saved);
                } else {
                    applyWidth(maxWidth());
                }

                let dragging = false;

                resizer.addEventListener("pointerdown", function (event) {
                    dragging = true;
                    document.body.classList.add("resizing-doc-nav");
                    resizer.setPointerCapture(event.pointerId);
                    event.preventDefault();
                });

                window.addEventListener("pointermove", function (event) {
                    if (!dragging) {
                        return;
                    }

                    applyWidth(event.clientX);
                });

                window.addEventListener("pointerup", function () {
                    dragging = false;
                    document.body.classList.remove("resizing-doc-nav");
                });

                window.addEventListener("resize", function () {
                    const current = parseInt(
                        getComputedStyle(split).getPropertyValue("--doc-nav-width"),
                        10
                    );

                    if (!Number.isNaN(current)) {
                        applyWidth(current);
                    }
                });
            })();
        </script>';
}

/**
 * Render the access-gate overlay.
 */
//function render_access_gate_overlay(): string
/**
 * Render access gate overlay.
 *
 * @param array $config
 *
 * @return string
 */
function render_access_gate_overlay(array $config = []): string
{
$rememberedEmail = '';

if (!empty($_SESSION['manual_access_pending_email'])) {
    $rememberedEmail = (string)$_SESSION['manual_access_pending_email'];
} elseif (function_exists('access_gate_remembered_email')) {
    $rememberedEmail = (string)(access_gate_remembered_email($config ?? []) ?? '');
}

$pendingEmail = htmlspecialchars(
    $rememberedEmail,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
    return '
<div class="access-overlay" id="accessOverlay">
    <div class="access-card">
        <h4 class="access-title">Access required<h4>
        <p class="access-intro">
            Enter your email address. We will send you a one-time access code and a direct login link.
        </p>

        <form id="accessEmailForm" class="access-form">
            <label for="accessEmail">Email address</label>
            <input
                type="email"
                id="accessEmail"
                name="email"
                autocomplete="email"
                value="' . $pendingEmail . '"
                required
            >

            <button type="submit">Send access code</button>
        </form>

        <form id="accessCodeForm" class="access-form access-code-form" style="' . ($pendingEmail !== '' ? '' : 'display:none;') . '">
            <label for="accessCode">Access code</label>
            <input
                type="text"
                id="accessCode"
                name="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]{6}"
                required
            >

            <input type="hidden" id="accessCodeEmail" name="email" value="' . $pendingEmail . '">

            <button type="submit">Verify code</button>
            <!-- <button type="button" id="accessResendButton" class="access-secondary-button">Resend code</button> -->
        </form>

        <p id="accessMessage" class="access-message"></p>
    </div>
</div>

<script>
(function () {
    const emailForm = document.getElementById("accessEmailForm");
    const codeForm = document.getElementById("accessCodeForm");
    const emailInput = document.getElementById("accessEmail");
    const codeInput = document.getElementById("accessCode");
    const codeEmailInput = document.getElementById("accessCodeEmail");
    const message = document.getElementById("accessMessage");
    const resendButton = document.getElementById("accessResendButton");

    let resendCooldown = 0;
    let resendTimer = null;

    function setMessage(text, isError) {
        message.textContent = text || "";
        message.className = "access-message" + (isError ? " error" : " success");
    }

    async function postForm(url, data) {
        const body = new URLSearchParams();

        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });

        const response = await fetch(url, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8"
            },
            body: body.toString(),
            credentials: "same-origin"
        });

        return await response.json();
    }

    function startResendCooldown() {
        resendCooldown = 30;
        resendButton.disabled = true;
        resendButton.textContent = "Resend code (" + resendCooldown + ")";

        if (resendTimer) {
            clearInterval(resendTimer);
        }

        resendTimer = setInterval(function () {
            resendCooldown--;

            if (resendCooldown <= 0) {
                clearInterval(resendTimer);
                resendButton.disabled = false;
                resendButton.textContent = "Resend code";
                return;
            }

            resendButton.textContent = "Resend code (" + resendCooldown + ")";
        }, 1000);
    }

    async function requestCode() {
        const email = emailInput.value.trim();

        if (!email) {
            setMessage("Please enter your email address.", true);
            return;
        }

        setMessage("Sending access email...", false);

        try {
            const result = await postForm("/access/request-code", {
                email: email,
                return_to: window.location.pathname + window.location.search
            });

            if (!result.ok) {
                setMessage(result.message || "Could not send the access email.", true);
                return;
            }

            codeEmailInput.value = result.email || email;
            codeForm.style.display = "";
            codeInput.focus();

            setMessage(result.message || "Access email sent.", false);
            //startResendCooldown();
        } catch (error) {
            setMessage("Could not contact the server. Please try again.", true);
        }
    }

    async function verifyCode() {
        const email = codeEmailInput.value.trim() || emailInput.value.trim();
        const code = codeInput.value.trim();

        if (!email || !code) {
            setMessage("Please enter the code from your email.", true);
            return;
        }

        setMessage("Verifying code...", false);

        try {
            const result = await postForm("/access/verify-code", {
                email: email,
                code: code
            });

            if (!result.ok) {
                setMessage(result.message || "The code could not be verified.", true);
                return;
            }

            setMessage(result.message || "Access granted.", false);
            window.location.href = result.redirect || window.location.href;
        } catch (error) {
            setMessage("Could not contact the server. Please try again.", true);
        }
    }

    emailForm.addEventListener("submit", function (event) {
        event.preventDefault();
        requestCode();
    });

    codeForm.addEventListener("submit", function (event) {
        event.preventDefault();
        verifyCode();
    });

    resendButton.addEventListener("click", function () {
        if (resendCooldown > 0) {
            return;
        }

        requestCode();
    });
})();
</script>';
}

