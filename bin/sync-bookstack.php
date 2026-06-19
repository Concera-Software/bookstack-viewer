#!/usr/bin/php
<?php

declare(strict_types=1);

/**
 * Sync public/manual BookStack pages from one or more BookStack databases into
 * the public documentation database.
 *
 * Important publishing rule:
 *
 * A page is synchronized only if one of the configured role IDs for that source
 * has access to the page directly or through inheritance from:
 *
 * - the page itself
 * - the parent chapter
 * - the parent book
 * - a bookshelf/shelf containing the book
 *
 * The default BookStack public role is ignored unless its ID is explicitly
 * configured in that source's public_role_id list.
 */

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$rootDir = dirname(__DIR__);

$config = require $rootDir . '/app/config.php';

require_once $rootDir . '/app/helpers.php';
require_once $rootDir . '/app/page_cache.php';

if (is_file($rootDir . '/app/db.php')) {
    require_once $rootDir . '/app/db.php';
}

/**
 * Resolve how BookStack permissions should be interpreted for this source.
 *
 * Supported modes:
 *
 * inherited:
 *   A page is synced when one of the configured role IDs has permission
 *   on the page itself, the parent chapter, the parent book, or a shelf
 *   containing the book.
 *
 * direct_page_only:
 *   A page is synced only when one of the configured role IDs has permission
 *   directly on that specific page.
 *
 * @param array $source
 * @return string
 */
function resolve_permission_mode(array $source): string
{
    $mode = strtolower(trim((string)($source['permission_mode'] ?? 'inherited')));

    $aliases = [
        'inherit' => 'inherited',
        'inherited' => 'inherited',
        'with_inheritance' => 'inherited',
        'page_chapter_book_shelf' => 'inherited',

        'direct' => 'direct_page_only',
        'direct_page' => 'direct_page_only',
        'page_only' => 'direct_page_only',
        'direct_page_only' => 'direct_page_only',
    ];

    if (!isset($aliases[$mode])) {
        throw new RuntimeException(
            'Invalid permission_mode "' . $mode . '". Use "inherited" or "direct_page_only".'
        );
    }

    return $aliases[$mode];
}

/**
 * Sync config hidden pages.
 *
 * @param PDO $public
 * @param array $config
 *
 * @return void
 */
function sync_config_hidden_pages(PDO $public, array $config): void
{
    $sources = $config['bookstack_sources'] ?? [];

    if (!is_array($sources)) {
        return;
    }

    $stmt = $public->prepare("
        INSERT INTO public_doc_exclusions (
            source_key,
            source_page_id,
            excluded,
            created_at,
            updated_at,
            created_by_ip,
            updated_by_ip
        ) VALUES (
            :source_key,
            :source_page_id,
            1,
            NOW(),
            NOW(),
            'config',
            'config'
        )
        ON DUPLICATE KEY UPDATE
            updated_at = updated_at
    ");

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $sourceKey = trim((string)($source['key'] ?? ''));

        if ($sourceKey === '') {
            continue;
        }

        $pageIds = $source['exclude_page_ids'] ?? [];

        if (!is_array($pageIds)) {
            continue;
        }

        foreach ($pageIds as $pageId) {
            $pageId = (int)$pageId;

            if ($pageId <= 0) {
                continue;
            }

            $stmt->execute([
                'source_key' => $sourceKey,
                'source_page_id' => $pageId,
            ]);
        }
    }
}

/**
 * Return source page IDs that must be excluded for this source.
 *
 * Config example:
 *
 * 'exclude_page_ids' => [
 *     123,
 *     456,
 * ]
 *
 * @param array $source
 * @return array
 */
function resolve_excluded_page_ids(array $source): array
{
    $excludePageIds = $source['exclude_page_ids'] ?? [];

    if (!is_array($excludePageIds)) {
        return [];
    }

    $clean = [];

    foreach ($excludePageIds as $pageId) {
        $pageId = (int)$pageId;

        if ($pageId > 0) {
            $clean[] = $pageId;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Connect to a MySQL/MariaDB database using a config array.
 *
 * @param array $db
 * @return PDO
 */
function sync_db_connect(array $db): PDO
{
    $host = (string)($db['host'] ?? '127.0.0.1');
    $port = (int)($db['port'] ?? 3306);
    $database = (string)($db['database'] ?? '');
    $username = (string)($db['username'] ?? $db['user'] ?? '');
    $password = (string)($db['password'] ?? $db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    if ($database === '') {
        throw new RuntimeException('Database name is missing.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Connect to the public documentation database.
 *
 * @param array $config
 * @return PDO
 */
function connect_public_database(array $config): PDO
{
    if (empty($config['public_db']) || !is_array($config['public_db'])) {
        throw new RuntimeException('Missing public_db configuration.');
    }

    return sync_db_connect($config['public_db']);
}

/**
 * Check whether a table exists in the current database.
 *
 * @param PDO $pdo
 * @param string $table
 * @return bool
 */
function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
    ");

    $stmt->execute([
        'table_name' => $table,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Check whether a column exists in a table in the current database.
 *
 * @param PDO $pdo
 * @param string $table
 * @param string $column
 * @return bool
 */
function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = :table_name
          AND column_name = :column_name
    ");

    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Detect the BookStack schema type.
 *
 * @param PDO $pdo
 * @return string
 */
function detect_bookstack_schema(PDO $pdo): string
{
    if (
        table_exists($pdo, 'entities') &&
        table_exists($pdo, 'entity_page_data') &&
        table_exists($pdo, 'joint_permissions') &&
        table_exists($pdo, 'roles')
    ) {
        return 'entities_v26';
    }

    if (
        table_exists($pdo, 'pages') &&
        table_exists($pdo, 'books') &&
        table_exists($pdo, 'joint_permissions') &&
        table_exists($pdo, 'roles')
    ) {
        return 'legacy_pages';
    }

    throw new RuntimeException('Unsupported BookStack schema. Could not detect known BookStack tables.');
}

/**
 * Dynamically fetch the default BookStack public role ID.
 *
 * This is only used when no public_role_id is configured for a source.
 *
 * @param PDO $pdo
 * @return int
 */
function fetch_public_role_id(PDO $pdo): int
{
    if (!table_exists($pdo, 'roles')) {
        throw new RuntimeException('Could not find roles table.');
    }

    if (column_exists($pdo, 'roles', 'system_name')) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM roles
            WHERE system_name = 'public'
            ORDER BY id ASC
            LIMIT 1
        ");

        $stmt->execute();

        $roleId = $stmt->fetchColumn();

        if ($roleId) {
            return (int)$roleId;
        }
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM roles
        WHERE LOWER(display_name) = 'public'
        ORDER BY id ASC
        LIMIT 1
    ");

    $stmt->execute();

    $roleId = $stmt->fetchColumn();

    if (!$roleId) {
        throw new RuntimeException('Could not find BookStack public role.');
    }

    return (int)$roleId;
}

/**
 * Verify that a configured BookStack role ID exists.
 *
 * @param PDO $pdo
 * @param int $roleId
 * @return bool
 */
function bookstack_role_exists(PDO $pdo, int $roleId): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM roles
        WHERE id = :role_id
    ");

    $stmt->execute([
        'role_id' => $roleId,
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Return one or more configured role IDs for a BookStack source.
 *
 * Supported config examples:
 *
 * 'public_role_id' => 31
 * 'public_role_id' => [31, 32]
 *
 * If no role is configured, the default BookStack public role is detected.
 *
 * @param PDO $pdo
 * @param array $source
 * @return array
 */
function resolve_public_role_ids(PDO $pdo, array $source): array
{
    $configured = $source['public_role_id'] ?? null;

    if ($configured === null || $configured === '' || $configured === 0 || $configured === '0') {
        return [fetch_public_role_id($pdo)];
    }

    if (!is_array($configured)) {
        $configured = [$configured];
    }

    $roleIds = [];

    foreach ($configured as $roleId) {
        $roleId = (int)$roleId;

        if ($roleId <= 0) {
            continue;
        }

        if (!bookstack_role_exists($pdo, $roleId)) {
            throw new RuntimeException(
                'Configured public_role_id ' . $roleId .
                ' does not exist for source ' . (string)($source['key'] ?? 'unknown')
            );
        }

        $roleIds[] = $roleId;
    }

    $roleIds = array_values(array_unique($roleIds));

    if (!$roleIds) {
        return [fetch_public_role_id($pdo)];
    }

    return $roleIds;
}

/**
 * Resolve allowed permission statuses for a source.
 *
 * Examples:
 *
 * 'public_permission_statuses' => [1]
 * 'public_permission_statuses' => [1, 3]
 *
 * @param array $source
 * @return array
 */
function resolve_public_permission_statuses(array $source): array
{
    $statuses = $source['public_permission_statuses'] ?? [1];

    if (!is_array($statuses)) {
        $statuses = [$statuses];
    }

    $clean = [];

    foreach ($statuses as $status) {
        $status = (int)$status;

        if ($status >= 0 && $status <= 9) {
            $clean[] = $status;
        }
    }

    $clean = array_values(array_unique($clean));

    return $clean ?: [1];
}

/**
 * Build named placeholders for role IDs.
 *
 * @param array $roleIds
 * @param array $params
 * @return string
 */
function build_role_placeholders(array $roleIds, array &$params): string
{
    $placeholders = [];

    foreach ($roleIds as $index => $roleId) {
        $paramName = 'role_id_' . $index;
        $placeholders[] = ':' . $paramName;
        $params[$paramName] = (int)$roleId;
    }

    return implode(', ', $placeholders);
}

/**
 * Build named placeholders for permission statuses.
 *
 * @param array $statuses
 * @param array $params
 * @return string
 */
function build_status_placeholders(array $statuses, array &$params): string
{
    $placeholders = [];

    foreach ($statuses as $index => $status) {
        $paramName = 'status_' . $index;
        $placeholders[] = ':' . $paramName;
        $params[$paramName] = (int)$status;
    }

    return implode(', ', $placeholders);
}

/**
 * Fetch public/manual pages from BookStack v26+ entity schema.
 *
 * Permission modes:
 *
 * inherited:
 *   The configured role IDs may be linked to page, chapter, book, or shelf.
 *
 * direct_page_only:
 *   The configured role IDs must be linked directly to the page.
 *
 * @param PDO $pdo
 * @param array $publicRoleIds
 * @param array $permissionStatuses
 * @param string $permissionMode
 * @return array
 */
function fetch_public_pages_entities_v26_inherited(
    PDO $pdo,
    array $publicRoleIds,
    array $permissionStatuses = [1],
    string $permissionMode = 'inherited'
): array {
    $params = [];

    $publicRoleIds = array_values(array_unique(array_map('intval', $publicRoleIds)));

    if (!$publicRoleIds) {
        throw new RuntimeException('No public role IDs supplied.');
    }

    $permissionStatuses = array_values(array_unique(array_map('intval', $permissionStatuses)));

    if (!$permissionStatuses) {
        $permissionStatuses = [1];
    }

    $permissionMode = resolve_permission_mode([
        'permission_mode' => $permissionMode,
    ]);

    $roleSql = build_role_placeholders($publicRoleIds, $params);
    $statusSql = build_status_placeholders($permissionStatuses, $params);

    $shelfJoin = '';
    $permissionEntityCondition = "
                (
                    jp.entity_type = 'page'
                    AND jp.entity_id = pg.id
                )
    ";

    if ($permissionMode === 'inherited') {
        $hasShelves = table_exists($pdo, 'bookshelves_books');

        $shelfJoin = $hasShelves
            ? "
        LEFT JOIN bookshelves_books bb
            ON bb.book_id = pg.book_id
        "
            : "";

        $shelfPermissionCondition = $hasShelves
            ? "
                OR (
                    jp.entity_type = 'bookshelf'
                    AND jp.entity_id = bb.bookshelf_id
                )
        "
            : "";

        $permissionEntityCondition = "
                (
                    jp.entity_type = 'page'
                    AND jp.entity_id = pg.id
                )
                OR (
                    jp.entity_type = 'chapter'
                    AND jp.entity_id = pg.chapter_id
                )
                OR (
                    jp.entity_type = 'book'
                    AND jp.entity_id = pg.book_id
                )
                {$shelfPermissionCondition}
        ";
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            pg.id AS source_page_id,
            pg.name AS page_name,
            pg.slug AS page_slug,
            pg.priority AS page_priority,

            bk.id AS book_id,
            bk.name AS book_name,
            bk.slug AS book_slug,
            bk.priority AS book_priority,

            ch.id AS chapter_id,
            ch.name AS chapter_name,
            ch.slug AS chapter_slug,
            ch.priority AS chapter_priority,

            pg_data.html,
            pg_data.text,
            pg_data.markdown,
            pg_data.editor,

            pg.created_at,
            pg.updated_at
        FROM entities pg

        INNER JOIN entity_page_data pg_data
            ON pg_data.page_id = pg.id
           AND pg_data.draft = 0
           AND pg_data.template = 0

        LEFT JOIN entities bk
            ON bk.id = pg.book_id
           AND bk.type = 'book'
           AND bk.deleted_at IS NULL

        LEFT JOIN entities ch
            ON ch.id = pg.chapter_id
           AND ch.type = 'chapter'
           AND ch.deleted_at IS NULL

        {$shelfJoin}

        INNER JOIN joint_permissions jp
            ON jp.role_id IN ({$roleSql})
           AND jp.status IN ({$statusSql})
           AND (
                {$permissionEntityCondition}
           )

        WHERE
            pg.type = 'page'
            AND pg.deleted_at IS NULL

        ORDER BY
            bk.priority ASC,
            bk.name ASC,
            ch.priority ASC,
            ch.name ASC,
            pg.priority ASC,
            pg.name ASC
    ");

    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Fetch pages that have direct page-level view permission in entity_permissions.
 *
 * This matches queries like:
 *
 * SELECT *
 * FROM entity_permissions
 * WHERE entity_type = 'page'
 *   AND role_id IN (...)
 *   AND view = 1
 *
 * @param PDO $pdo
 * @param array $publicRoleIds
 * @param array $permissionStatuses
 * @return array
 */
function fetch_public_pages_entities_v26_direct_page_only(
    PDO $pdo,
    array $publicRoleIds,
    array $permissionStatuses = [1]
): array {
    if (!table_exists($pdo, 'entity_permissions')) {
        throw new RuntimeException(
            'permission_mode direct_page_only requires the entity_permissions table.'
        );
    }

    $params = [];

    $publicRoleIds = array_values(array_unique(array_map('intval', $publicRoleIds)));

    if (!$publicRoleIds) {
        throw new RuntimeException('No public role IDs supplied.');
    }

    /*
     * For entity_permissions, the permission column is `view`.
     * Normally this should be [1].
     */
    $permissionStatuses = array_values(array_unique(array_map('intval', $permissionStatuses)));

    if (!$permissionStatuses) {
        $permissionStatuses = [1];
    }

    $roleSql = build_role_placeholders($publicRoleIds, $params);
    $viewSql = build_status_placeholders($permissionStatuses, $params);

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            pg.id AS source_page_id,
            pg.name AS page_name,
            pg.slug AS page_slug,
            pg.priority AS page_priority,

            bk.id AS book_id,
            bk.name AS book_name,
            bk.slug AS book_slug,
            bk.priority AS book_priority,

            ch.id AS chapter_id,
            ch.name AS chapter_name,
            ch.slug AS chapter_slug,
            ch.priority AS chapter_priority,

            pg_data.html,
            pg_data.text,
            pg_data.markdown,
            pg_data.editor,

            pg.created_at,
            pg.updated_at
        FROM entity_permissions ep

        INNER JOIN entities pg
            ON pg.id = ep.entity_id
           AND pg.type = ep.entity_type
           AND pg.type = 'page'
           AND pg.deleted_at IS NULL

        INNER JOIN entity_page_data pg_data
            ON pg_data.page_id = pg.id
           AND pg_data.draft = 0
           AND pg_data.template = 0

        LEFT JOIN entities bk
            ON bk.id = pg.book_id
           AND bk.type = 'book'
           AND bk.deleted_at IS NULL

        LEFT JOIN entities ch
            ON ch.id = pg.chapter_id
           AND ch.type = 'chapter'
           AND ch.deleted_at IS NULL

        WHERE ep.entity_type = 'page'
          AND ep.role_id IN ({$roleSql})
          AND ep.`view` IN ({$viewSql})

        ORDER BY
            bk.priority ASC,
            bk.name ASC,
            ch.priority ASC,
            ch.name ASC,
            pg.priority ASC,
            pg.name ASC
    ");

    $stmt->execute($params);

    return $stmt->fetchAll();
}

/**
 * Fetch public/manual pages from BookStack v26+ entity schema.
 *
 * Modes:
 *
 * inherited:
 *   Uses joint_permissions.
 *   Accepts permission on page, chapter, book, or bookshelf.
 *
 * direct_page_only:
 *   Uses entity_permissions.
 *   Accepts only direct page-level view permission.
 *
 * @param PDO $pdo
 * @param array $publicRoleIds
 * @param array $permissionStatuses
 * @param string $permissionMode
 * @return array
 */
function fetch_public_pages_entities_v26(
    PDO $pdo,
    array $publicRoleIds,
    array $permissionStatuses = [1],
    string $permissionMode = 'inherited'
): array {
    $permissionMode = resolve_permission_mode([
        'permission_mode' => $permissionMode,
    ]);

    if ($permissionMode === 'direct_page_only') {
        return fetch_public_pages_entities_v26_direct_page_only(
            $pdo,
            $publicRoleIds,
            $permissionStatuses
        );
    }

    return fetch_public_pages_entities_v26_inherited(
        $pdo,
        $publicRoleIds,
        $permissionStatuses
    );
}


/**
 * Fetch public/manual pages from older BookStack pages/books/chapters schema.
 *
 * Permission modes:
 *
 * inherited:
 *   The configured role IDs may be linked to page, chapter, book, or shelf.
 *
 * direct_page_only:
 *   The configured role IDs must be linked directly to the page.
 *
 * @param PDO $pdo
 * @param array $publicRoleIds
 * @param array $permissionStatuses
 * @param string $permissionMode
 * @return array
 */
function fetch_public_pages_legacy(
    PDO $pdo,
    array $publicRoleIds,
    array $permissionStatuses = [1],
    string $permissionMode = 'inherited'
): array {
    $params = [];

    $publicRoleIds = array_values(array_unique(array_map('intval', $publicRoleIds)));

    if (!$publicRoleIds) {
        throw new RuntimeException('No public role IDs supplied.');
    }

    $permissionStatuses = array_values(array_unique(array_map('intval', $permissionStatuses)));

    if (!$permissionStatuses) {
        $permissionStatuses = [1];
    }

    $permissionMode = resolve_permission_mode([
        'permission_mode' => $permissionMode,
    ]);

    $roleSql = build_role_placeholders($publicRoleIds, $params);
    $statusSql = build_status_placeholders($permissionStatuses, $params);

    $hasChapters = table_exists($pdo, 'chapters');
    $hasShelves = table_exists($pdo, 'bookshelves_books');

    $chapterJoin = $hasChapters
        ? 'LEFT JOIN chapters ch ON ch.id = pg.chapter_id'
        : "LEFT JOIN (SELECT NULL AS id, NULL AS name, NULL AS slug, NULL AS priority) ch ON 1 = 0";

    $chapterDeletedCondition = $hasChapters && column_exists($pdo, 'chapters', 'deleted_at')
        ? 'AND ch.deleted_at IS NULL'
        : '';

    $bookDeletedCondition = column_exists($pdo, 'books', 'deleted_at')
        ? 'AND bk.deleted_at IS NULL'
        : '';

    $pageDeletedCondition = column_exists($pdo, 'pages', 'deleted_at')
        ? 'AND pg.deleted_at IS NULL'
        : '';

    $pageDraftCondition = column_exists($pdo, 'pages', 'draft')
        ? 'AND pg.draft = 0'
        : '';

    $pageTemplateSelect = column_exists($pdo, 'pages', 'template')
        ? 'pg.template AS template'
        : '0 AS template';

    $htmlColumn = column_exists($pdo, 'pages', 'html')
        ? 'pg.html AS html'
        : "'' AS html";

    $textColumn = column_exists($pdo, 'pages', 'text')
        ? 'pg.text AS text'
        : "'' AS text";

    $markdownColumn = column_exists($pdo, 'pages', 'markdown')
        ? 'pg.markdown AS markdown'
        : "'' AS markdown";

    $editorColumn = column_exists($pdo, 'pages', 'editor')
        ? 'pg.editor AS editor'
        : "'' AS editor";

    $pagePriorityColumn = column_exists($pdo, 'pages', 'priority')
        ? 'pg.priority AS page_priority'
        : '0 AS page_priority';

    $bookPriorityColumn = column_exists($pdo, 'books', 'priority')
        ? 'bk.priority AS book_priority'
        : '0 AS book_priority';

    $chapterPriorityColumn = $hasChapters && column_exists($pdo, 'chapters', 'priority')
        ? 'ch.priority AS chapter_priority'
        : '0 AS chapter_priority';

    $shelfJoin = '';
    $permissionEntityCondition = "
                (
                    jp.entity_type = 'page'
                    AND jp.entity_id = pg.id
                )
    ";

    if ($permissionMode === 'inherited') {
        $shelfJoin = $hasShelves
            ? "
        LEFT JOIN bookshelves_books bb
            ON bb.book_id = pg.book_id
        "
            : "";

        $shelfPermissionCondition = $hasShelves
            ? "
                OR (
                    jp.entity_type = 'bookshelf'
                    AND jp.entity_id = bb.bookshelf_id
                )
        "
            : "";

        $permissionEntityCondition = "
                (
                    jp.entity_type = 'page'
                    AND jp.entity_id = pg.id
                )
                OR (
                    jp.entity_type = 'chapter'
                    AND jp.entity_id = pg.chapter_id
                )
                OR (
                    jp.entity_type = 'book'
                    AND jp.entity_id = pg.book_id
                )
                {$shelfPermissionCondition}
        ";
    }

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            pg.id AS source_page_id,
            pg.name AS page_name,
            pg.slug AS page_slug,
            {$pagePriorityColumn},

            bk.id AS book_id,
            bk.name AS book_name,
            bk.slug AS book_slug,
            {$bookPriorityColumn},

            ch.id AS chapter_id,
            ch.name AS chapter_name,
            ch.slug AS chapter_slug,
            {$chapterPriorityColumn},

            {$htmlColumn},
            {$textColumn},
            {$markdownColumn},
            {$editorColumn},
            {$pageTemplateSelect},

            pg.created_at,
            pg.updated_at
        FROM pages pg

        LEFT JOIN books bk
            ON bk.id = pg.book_id
            {$bookDeletedCondition}

        {$chapterJoin}
            {$chapterDeletedCondition}

        {$shelfJoin}

        INNER JOIN joint_permissions jp
            ON jp.role_id IN ({$roleSql})
           AND jp.status IN ({$statusSql})
           AND (
                {$permissionEntityCondition}
           )

        WHERE 1 = 1
            {$pageDeletedCondition}
            {$pageDraftCondition}

        ORDER BY
            book_priority ASC,
            book_name ASC,
            chapter_priority ASC,
            chapter_name ASC,
            page_priority ASC,
            page_name ASC
    ");

    $stmt->execute($params);

    $pages = $stmt->fetchAll();

    return array_values(array_filter($pages, static function (array $page): bool {
        return (int)($page['template'] ?? 0) === 0;
    }));
}

/**
 * Fetch public/manual pages from older BookStack pages/books/chapters schema.
 *
 * Legacy support is kept conservative:
 * - Direct page permission is supported.
 * - Chapter and book inherited permission are supported if those tables/columns exist.
 * - Bookshelf support is not guaranteed in legacy schema but is attempted if
 *   entities + bookshelves_books also exist.
 *
 * @param PDO $pdo
 * @param array $publicRoleIds
 * @param array $permissionStatuses
 * @return array
 */
function fetch_public_pages_legacy_old(PDO $pdo, array $publicRoleIds, array $permissionStatuses = [1]): array
{
    $params = [];

    $publicRoleIds = array_values(array_unique(array_map('intval', $publicRoleIds)));

    if (!$publicRoleIds) {
        throw new RuntimeException('No public role IDs supplied.');
    }

    $permissionStatuses = array_values(array_unique(array_map('intval', $permissionStatuses)));

    if (!$permissionStatuses) {
        $permissionStatuses = [1];
    }

    $roleSql = build_role_placeholders($publicRoleIds, $params);
    $statusSql = build_status_placeholders($permissionStatuses, $params);

    $hasChapters = table_exists($pdo, 'chapters');
    $hasShelves = table_exists($pdo, 'bookshelves_books');

    $chapterJoin = $hasChapters
        ? 'LEFT JOIN chapters ch ON ch.id = pg.chapter_id'
        : "LEFT JOIN (SELECT NULL AS id, NULL AS name, NULL AS slug, NULL AS priority) ch ON 1 = 0";

    $chapterDeletedCondition = $hasChapters && column_exists($pdo, 'chapters', 'deleted_at')
        ? 'AND ch.deleted_at IS NULL'
        : '';

    $bookDeletedCondition = column_exists($pdo, 'books', 'deleted_at')
        ? 'AND bk.deleted_at IS NULL'
        : '';

    $pageDeletedCondition = column_exists($pdo, 'pages', 'deleted_at')
        ? 'AND pg.deleted_at IS NULL'
        : '';

    $pageDraftCondition = column_exists($pdo, 'pages', 'draft')
        ? 'AND pg.draft = 0'
        : '';

    $pageTemplateSelect = column_exists($pdo, 'pages', 'template')
        ? 'pg.template AS template'
        : '0 AS template';

    $htmlColumn = column_exists($pdo, 'pages', 'html')
        ? 'pg.html AS html'
        : "'' AS html";

    $textColumn = column_exists($pdo, 'pages', 'text')
        ? 'pg.text AS text'
        : "'' AS text";

    $markdownColumn = column_exists($pdo, 'pages', 'markdown')
        ? 'pg.markdown AS markdown'
        : "'' AS markdown";

    $editorColumn = column_exists($pdo, 'pages', 'editor')
        ? 'pg.editor AS editor'
        : "'' AS editor";

    $pagePriorityColumn = column_exists($pdo, 'pages', 'priority')
        ? 'pg.priority AS page_priority'
        : '0 AS page_priority';

    $bookPriorityColumn = column_exists($pdo, 'books', 'priority')
        ? 'bk.priority AS book_priority'
        : '0 AS book_priority';

    $chapterPriorityColumn = $hasChapters && column_exists($pdo, 'chapters', 'priority')
        ? 'ch.priority AS chapter_priority'
        : '0 AS chapter_priority';

    $shelfJoin = $hasShelves
        ? "
        LEFT JOIN bookshelves_books bb
            ON bb.book_id = pg.book_id
        "
        : "";

    $shelfPermissionCondition = $hasShelves
        ? "
                OR (
                    jp.entity_type = 'bookshelf'
                    AND jp.entity_id = bb.bookshelf_id
                )
        "
        : "";

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            pg.id AS source_page_id,
            pg.name AS page_name,
            pg.slug AS page_slug,
            {$pagePriorityColumn},

            bk.id AS book_id,
            bk.name AS book_name,
            bk.slug AS book_slug,
            {$bookPriorityColumn},

            ch.id AS chapter_id,
            ch.name AS chapter_name,
            ch.slug AS chapter_slug,
            {$chapterPriorityColumn},

            {$htmlColumn},
            {$textColumn},
            {$markdownColumn},
            {$editorColumn},
            {$pageTemplateSelect},

            pg.created_at,
            pg.updated_at
        FROM pages pg

        LEFT JOIN books bk
            ON bk.id = pg.book_id
            {$bookDeletedCondition}

        {$chapterJoin}
            {$chapterDeletedCondition}

        {$shelfJoin}

        INNER JOIN joint_permissions jp
            ON jp.role_id IN ({$roleSql})
           AND jp.status IN ({$statusSql})
           AND (
                (
                    jp.entity_type = 'page'
                    AND jp.entity_id = pg.id
                )
                OR (
                    jp.entity_type = 'chapter'
                    AND jp.entity_id = pg.chapter_id
                )
                OR (
                    jp.entity_type = 'book'
                    AND jp.entity_id = pg.book_id
                )
                {$shelfPermissionCondition}
           )

        WHERE 1 = 1
            {$pageDeletedCondition}
            {$pageDraftCondition}

        ORDER BY
            book_priority ASC,
            book_name ASC,
            chapter_priority ASC,
            chapter_name ASC,
            page_priority ASC,
            page_name ASC
    ");

    $stmt->execute($params);

    $pages = $stmt->fetchAll();

    return array_values(array_filter($pages, static function (array $page): bool {
        return (int)($page['template'] ?? 0) === 0;
    }));
}

/**
 * Build the original BookStack source URL for a page.
 *
 * @param array $source
 * @param array $page
 * @return ?string
 */
function bookstack_original_page_url(array $source, array $page): ?string
{
    $baseUrl = rtrim((string)($source['base_url'] ?? ''), '/');

    if ($baseUrl === '') {
        return null;
    }

    if (empty($page['book_slug']) || empty($page['page_slug'])) {
        return null;
    }

    return $baseUrl
        . '/books/'
        . rawurlencode((string)$page['book_slug'])
        . '/page/'
        . rawurlencode((string)$page['page_slug']);
}

/**
 * Sanitize a source key for generated URL conflict suffixes.
 *
 * @param string $sourceKey
 * @return string
 */
function sanitize_source_key_for_url(string $sourceKey): string
{
    $sourceKey = preg_replace('/[^a-z0-9-]+/i', '-', $sourceKey);
    $sourceKey = strtolower(trim((string)$sourceKey, '-'));

    return $sourceKey !== '' ? $sourceKey : 'source';
}

/**
 * Build the public URL path.
 *
 * Normal:
 * /books/{book-slug}/page/{page-slug}
 *
 * Collision:
 * /books/{book-slug}/page/{page-slug}-{source-key}
 *
 * @param array $source
 * @param array $page
 * @param array $usedPaths
 * @return string
 */
function build_public_url_path(array $source, array $page, array &$usedPaths): string
{
    $bookSlug = trim((string)($page['book_slug'] ?? 'documentation'));
    $pageSlug = trim((string)($page['page_slug'] ?? ''));

    if ($bookSlug === '') {
        $bookSlug = 'documentation';
    }

    if ($pageSlug === '') {
        $pageSlug = 'page-' . (int)($page['source_page_id'] ?? 0);
    }

    $basePath = '/books/' . $bookSlug . '/page/' . $pageSlug;
    $path = $basePath;

    if (isset($usedPaths[$path])) {
        $sourceKey = sanitize_source_key_for_url((string)($source['key'] ?? 'source'));
        $path = $basePath . '-' . $sourceKey;
    }

    $counter = 2;

    while (isset($usedPaths[$path])) {
        $sourceKey = sanitize_source_key_for_url((string)($source['key'] ?? 'source'));
        $path = $basePath . '-' . $sourceKey . '-' . $counter;
        $counter++;
    }

    $usedPaths[$path] = true;

    return $path;
}

/**
 * Generate a safe plain text excerpt.
 *
 * @param string $text
 * @param int $length
 * @return string
 */
function sync_excerpt(string $text, int $length = 240): string
{
    if (function_exists('excerpt')) {
        return excerpt($text, $length);
    }

    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');

    if (mb_strlen($text) <= $length) {
        return $text;
    }

    return mb_substr($text, 0, $length - 1) . '…';
}

/**
 * Convert HTML to plain text.
 *
 * @param string $html
 * @return string
 */
function sync_plain_text(string $html): string
{
    if (function_exists('plain_text')) {
        return plain_text($html);
    }

    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Apply ownership and safe permissions to a generated public file.
 *
 * @param string $path
 * @param array $config
 * @return void
 */
function apply_generated_file_permissions(string $path, array $config): void
{
    if (!is_file($path)) {
        throw new RuntimeException('Generated file does not exist: ' . $path);
    }

    $owner = (string)($config['generated_file_owner'] ?? '');
    $group = (string)($config['generated_file_group'] ?? '');

    if ($owner !== '') {
        if (!@chown($path, $owner)) {
            throw new RuntimeException('Could not change owner of ' . $path . ' to ' . $owner);
        }
    }

    if ($group !== '') {
        if (!@chgrp($path, $group)) {
            throw new RuntimeException('Could not change group of ' . $path . ' to ' . $group);
        }
    }

    if (!@chmod($path, 0644)) {
        throw new RuntimeException('Could not change permissions of ' . $path . ' to 0644');
    }
}

/**
 * Generate sitemap.
 *
 * @param PDO $public
 * @param array $config
 *
 * @return int
 */
function generate_sitemap(PDO $public, array $config): int
{
    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');

    if ($baseUrl === '') {
        throw new RuntimeException('Missing config value: base_url');
    }

    $sitemapPath = dirname(__DIR__) . '/sitemap.pages.xml';

    /*
     * Build hard-exclusion conditions from bookstack_sources[].exclude_page_ids.
     * These are pages that must never be indexed.
     */
    $hardExcludeConditions = [];
    $hardExcludeParams = [];

    $sources = $config['bookstack_sources'] ?? [];

    if (is_array($sources)) {
        $excludeIndex = 0;

        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            $sourceKey = trim((string)($source['key'] ?? ''));

            if ($sourceKey === '') {
                continue;
            }

            $excludePageIds = $source['exclude_page_ids'] ?? [];

            if (!is_array($excludePageIds)) {
                continue;
            }

            foreach ($excludePageIds as $sourcePageId) {
                $sourcePageId = (int)$sourcePageId;

                if ($sourcePageId <= 0) {
                    continue;
                }

                $sourceParam = 'hard_source_' . $excludeIndex;
                $pageParam = 'hard_page_' . $excludeIndex;

                $hardExcludeConditions[] = "(d.source_key = :{$sourceParam} AND d.source_page_id = :{$pageParam})";
                $hardExcludeParams[$sourceParam] = $sourceKey;
                $hardExcludeParams[$pageParam] = $sourcePageId;

                $excludeIndex++;
            }
        }
    }

    $hardExcludeSql = '';

    if ($hardExcludeConditions) {
        $hardExcludeSql = ' AND NOT (' . implode(' OR ', $hardExcludeConditions) . ')';
    }

    /*
     * Soft-hidden pages are stored in public_doc_exclusions.
     * They must also be excluded from the sitemap.
     */
    $visibleWhereSql = "
        x.id IS NULL
        {$hardExcludeSql}
    ";

    $urls = [];

    $urls[] = [
        'loc' => $baseUrl . '/',
        'lastmod' => null,
    ];

    /*
     * Add book URLs only when the book has at least one visible page.
     */
    $bookSql = "
        SELECT
            d.book_slug,
            MAX(d.updated_at) AS updated_at
        FROM public_docs d
        LEFT JOIN public_doc_exclusions x
            ON x.source_key = d.source_key
           AND x.source_page_id = d.source_page_id
           AND x.excluded = 1
        WHERE d.book_slug IS NOT NULL
          AND d.book_slug != ''
          AND {$visibleWhereSql}
        GROUP BY d.book_slug
        ORDER BY d.book_slug ASC
    ";

    $bookStmt = $public->prepare($bookSql);
    $bookStmt->execute($hardExcludeParams);

    foreach ($bookStmt as $book) {
        $urls[] = [
            'loc' => $baseUrl . '/books/' . rawurlencode((string)$book['book_slug']),
            'lastmod' => $book['updated_at'] ?? null,
        ];
    }

    /*
     * Add only visible page URLs.
     */
    $pageSql = "
        SELECT
            d.url_path,
            d.updated_at
        FROM public_docs d
        LEFT JOIN public_doc_exclusions x
            ON x.source_key = d.source_key
           AND x.source_page_id = d.source_page_id
           AND x.excluded = 1
        WHERE d.url_path IS NOT NULL
          AND d.url_path != ''
          AND {$visibleWhereSql}
        ORDER BY d.updated_at DESC
    ";

    $pageStmt = $public->prepare($pageSql);
    $pageStmt->execute($hardExcludeParams);

    foreach ($pageStmt as $page) {
        $urls[] = [
            'loc' => $baseUrl . '/' . ltrim((string)$page['url_path'], '/'),
            'lastmod' => $page['updated_at'] ?? null,
        ];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

    foreach ($urls as $url) {
        $xml .= '  <url>' . PHP_EOL;
        $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_COMPAT, 'UTF-8') . '</loc>' . PHP_EOL;

        if (!empty($url['lastmod'])) {
            $timestamp = strtotime((string)$url['lastmod']);

            if ($timestamp !== false) {
                $xml .= '    <lastmod>' . date('c', $timestamp) . '</lastmod>' . PHP_EOL;
            }
        }

        $xml .= '  </url>' . PHP_EOL;
    }

    $xml .= '</urlset>' . PHP_EOL;

    if (file_put_contents($sitemapPath, $xml) === false) {
        throw new RuntimeException('Could not write sitemap.pages.xml to: ' . $sitemapPath);
    }

    apply_generated_file_permissions($sitemapPath, $config);

    return count($urls);
}


/**
 * Prepare the insert statement for public_docs.
 *
 * @param PDO $public
 * @return PDOStatement
 */
function prepare_public_docs_insert(PDO $public): PDOStatement
{
    return $public->prepare("
        INSERT INTO public_docs (
            source_key,
            source_page_id,
            source_base_url,

            book_id,
            chapter_id,

            book_name,
            book_slug,

            chapter_name,
            chapter_slug,

            page_name,
            page_slug,

            url_path,
            source_url,

            html,
            text_content,

            description,
            tags,

            created_at,
            updated_at,
            source_updated_at
        ) VALUES (
            :source_key,
            :source_page_id,
            :source_base_url,

            :book_id,
            :chapter_id,

            :book_name,
            :book_slug,

            :chapter_name,
            :chapter_slug,

            :page_name,
            :page_slug,

            :url_path,
            :source_url,

            :html,
            :text_content,

            :description,
            :tags,

            :created_at,
            :updated_at,
            :source_updated_at
        )
    ");
}

/**
 * Fetch public/manual pages for one configured source.
 *
 * @param PDO $sourcePdo
 * @param string $schema
 * @param array $publicRoleIds
 * @param array $source
 * @return array
 */
function fetch_public_pages_for_source(PDO $sourcePdo, string $schema, array $publicRoleIds, array $source): array
{
    $permissionStatuses = resolve_public_permission_statuses($source);
    $permissionMode = resolve_permission_mode($source);

    echo 'Public role IDs: ' . implode(', ', $publicRoleIds) . PHP_EOL;
    echo 'Permission statuses: ' . implode(', ', $permissionStatuses) . PHP_EOL;
    echo 'Permission mode: ' . $permissionMode . PHP_EOL;

    if ($schema === 'entities_v26') {
        return fetch_public_pages_entities_v26(
            $sourcePdo,
            $publicRoleIds,
            $permissionStatuses,
            $permissionMode
        );
    }

    if ($schema === 'legacy_pages') {
        return fetch_public_pages_legacy(
            $sourcePdo,
            $publicRoleIds,
            $permissionStatuses
        );
    }

    throw new RuntimeException('Unsupported schema type: ' . $schema);
}


/**
 * Fetch public/manual pages for one configured source.
 *
 * @param PDO $sourcePdo
 * @param string $schema
 * @param array $publicRoleIds
 * @param array $source
 * @return array
 */
function fetch_public_pages_for_source_old(PDO $sourcePdo, string $schema, array $publicRoleIds, array $source): array
{
    $permissionStatuses = resolve_public_permission_statuses($source);

    echo 'Public role IDs: ' . implode(', ', $publicRoleIds) . PHP_EOL;
    echo 'Permission statuses: ' . implode(', ', $permissionStatuses) . PHP_EOL;

    if ($schema === 'entities_v26') {
        return fetch_public_pages_entities_v26(
            $sourcePdo,
            $publicRoleIds,
            $permissionStatuses
        );
    }

    if ($schema === 'legacy_pages') {
        return fetch_public_pages_legacy(
            $sourcePdo,
            $publicRoleIds,
            $permissionStatuses
        );
    }

    throw new RuntimeException('Unsupported schema type: ' . $schema);
}

/**
 * Optional debug helper for permission counts.
 *
 * @param PDO $pdo
 * @param array $roleIds
 * @return void
 */
function debug_permission_counts(PDO $pdo, array $roleIds): void
{
    if (!$roleIds) {
        return;
    }

    $params = [];
    $roleSql = build_role_placeholders($roleIds, $params);

    $stmt = $pdo->prepare("
        SELECT
            role_id,
            entity_type,
            status,
            COUNT(*) AS records
        FROM joint_permissions
        WHERE role_id IN ({$roleSql})
        GROUP BY role_id, entity_type, status
        ORDER BY role_id, entity_type, status
    ");

    $stmt->execute($params);

    echo 'Permission counts:' . PHP_EOL;

    foreach ($stmt->fetchAll() as $row) {
        echo '  role '
            . (string)$row['role_id']
            . ' '
            . (string)$row['entity_type']
            . ' status '
            . (string)$row['status']
            . ' = '
            . (string)$row['records']
            . PHP_EOL;
    }
}

/**
 * Main sync process.
 *
 * @param array $config
 * @return void
 */
function run_sync(array $config): void
{
    $sources = $config['bookstack_sources'] ?? [];

    if (!is_array($sources) || count($sources) === 0) {
        throw new RuntimeException('No BookStack sources configured. Add bookstack_sources to app/config.php.');
    }

    $public = connect_public_database($config);

    $public->beginTransaction();
    sync_config_hidden_pages($public, $config);

    try {
        /**
         * DELETE is used instead of TRUNCATE because TRUNCATE causes an implicit
         * commit in MySQL/MariaDB.
         */
        $public->exec('DELETE FROM public_docs');

        $insert = prepare_public_docs_insert($public);

        $usedPaths = [];
        $totalPages = 0;
        $sourceCount = 0;

        foreach ($sources as $source) {
            if (!is_array($source)) {
                throw new RuntimeException('Invalid source configuration.');
            }
        
            $sourceKey = trim((string)($source['key'] ?? 'unknown-source'));
        
            /**
             * Source enable/disable flag.
             *
             * Backward compatible:
             * - missing enabled flag means enabled
             * - enabled = true means sync
             * - enabled = false means skip
             */
            $sourceEnabled = array_key_exists('enabled', $source)
                ? (bool)$source['enabled']
                : true;
        
            if (!$sourceEnabled) {
                echo 'Skipping disabled source: ' . $sourceKey . PHP_EOL;
                continue;
            }
        
            if ($sourceKey === '' || $sourceKey === 'unknown-source') {
                throw new RuntimeException('BookStack source is missing key.');
            }

            if ($sourceKey === '') {
                throw new RuntimeException('BookStack source is missing key.');
            }

            if (empty($source['db']) || !is_array($source['db'])) {
                throw new RuntimeException('BookStack source ' . $sourceKey . ' is missing db configuration.');
            }

            $sourceCount++;

            echo 'Syncing source: ' . $sourceKey . PHP_EOL;

            $sourcePdo = sync_db_connect($source['db']);

            $schema = trim((string)($source['schema'] ?? 'auto'));

            if ($schema === '' || $schema === 'auto') {
                $schema = detect_bookstack_schema($sourcePdo);
            }

            echo 'Detected schema for ' . $sourceKey . ': ' . $schema . PHP_EOL;

            $publicRoleIds = resolve_public_role_ids($sourcePdo, $source);

            echo 'Public role IDs for ' . $sourceKey . ': ' . implode(', ', $publicRoleIds);

            if (!empty($source['public_role_id'])) {
                echo ' configured';
            } else {
                echo ' detected';
            }

            echo PHP_EOL;

            if (!empty($source['debug_permissions'])) {
                debug_permission_counts($sourcePdo, $publicRoleIds);
            }

            $pages = fetch_public_pages_for_source($sourcePdo, $schema, $publicRoleIds, $source);

            echo 'Public/manual pages from ' . $sourceKey . ': ' . count($pages) . PHP_EOL;

            foreach ($pages as $page) {
                $urlPath = build_public_url_path($source, $page, $usedPaths);

    /**
     * Print every synced page so we can verify exactly what is published.
     */
$sourcePageId = (int)($page['source_page_id'] ?? 0);
$excludedPageIds = resolve_excluded_page_ids($source);
$isConfigExcluded = $sourcePageId > 0 && in_array($sourcePageId, $excludedPageIds, true);

echo '  - Page ID '
    . ($sourcePageId > 0 ? (string)$sourcePageId : '?')
    . ($isConfigExcluded ? ' [EXCLUDED IN CONFIG]' : '')
    . ' | '
    . (string)($page['page_name'] ?? 'Untitled page')
    . ' | '
    . $urlPath
    . PHP_EOL;


                $html = (string)($page['html'] ?? '');
                $text = trim((string)($page['text'] ?? ''));

                if ($text === '') {
                    $text = sync_plain_text($html);
                }

                $sourceUrl = bookstack_original_page_url($source, $page);

                $insert->execute([
                    'source_key' => $sourceKey,
                    'source_page_id' => (int)($page['source_page_id'] ?? 0),
                    'source_base_url' => rtrim((string)($source['base_url'] ?? ''), '/'),

                    'book_id' => isset($page['book_id']) ? (int)$page['book_id'] : null,
                    'chapter_id' => isset($page['chapter_id']) ? (int)$page['chapter_id'] : null,

                    'book_name' => $page['book_name'] ?? null,
                    'book_slug' => $page['book_slug'] ?? null,

                    'chapter_name' => $page['chapter_name'] ?? null,
                    'chapter_slug' => $page['chapter_slug'] ?? null,

                    'page_name' => (string)($page['page_name'] ?? 'Untitled page'),
                    'page_slug' => (string)($page['page_slug'] ?? ''),

                    'url_path' => $urlPath,
                    'source_url' => $sourceUrl,

                    'html' => $html,
                    'text_content' => $text,

                    'description' => sync_excerpt($text, 240),
                    'tags' => '',

                    'created_at' => $page['created_at'] ?? null,
                    'updated_at' => $page['updated_at'] ?? null,
                    'source_updated_at' => $page['updated_at'] ?? null,
                ]);

                $totalPages++;
            }
        }

        $public->commit();

        $sitemapUrlCount = generate_sitemap($public, $config);

        echo 'Synced sources: ' . $sourceCount . PHP_EOL;
        echo 'Synced total public/manual pages: ' . $totalPages . PHP_EOL;
        echo 'Generated sitemap URLs: ' . $sitemapUrlCount . PHP_EOL;
        echo 'Generated sitemap: ' . dirname(__DIR__) . '/sitemap.pages.xml' . PHP_EOL;
    } catch (Throwable $e) {
        if ($public->inTransaction()) {
            $public->rollBack();
        }

        fwrite(STDERR, 'Sync failed: ' . $e->getMessage() . PHP_EOL);

        throw $e;
    }
}

run_sync($config);

if (function_exists('page_cache_clear')) {
    $deletedCachedPages = page_cache_clear($config);
    echo "Cleared {$deletedCachedPages} cached page(s)." . PHP_EOL;
}
