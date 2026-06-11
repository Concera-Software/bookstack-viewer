<?php

declare(strict_types=1);

/**
 * Page visibility helpers.
 *
 * Pages are never removed from public_docs by the hide/show system.
 * They are only marked as hidden or visible in public_doc_exclusions.
 *
 * @return string
 */
function client_ip_address(): string
{
    return trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
}

/**
 * Is page hard excluded.
 *
 * @param array $config
 * @param string $sourceKey
 * @param int $sourcePageId
 *
 * @return bool
 */
function is_page_hard_excluded(array $config, string $sourceKey, int $sourcePageId): bool
{
    if ($sourceKey === '' || $sourcePageId <= 0) {
        return false;
    }

    return in_array($sourcePageId, config_excluded_page_ids($config, $sourceKey), true);
}

/**
 * Source config by key.
 *
 * @param array $config
 * @param string $sourceKey
 *
 * @return ?array
 */
function source_config_by_key(array $config, string $sourceKey): ?array
{
    $sources = $config['bookstack_sources'] ?? [];

    if (!is_array($sources)) {
        return null;
    }

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        if ((string)($source['key'] ?? '') === $sourceKey) {
            return $source;
        }
    }

    return null;
}

/**
 * Global admin IPs.
 *
 * Supports both:
 * - admin_ips
 * - exclude_admin_ips, for backward compatibility
 *
 * @param array $config
 * @return array
 */
function global_visibility_admin_ips(array $config): array
{
    $ips = [];

    if (!empty($config['admin_ips']) && is_array($config['admin_ips'])) {
        $ips = array_merge($ips, $config['admin_ips']);
    }

    if (!empty($config['exclude_admin_ips']) && is_array($config['exclude_admin_ips'])) {
        $ips = array_merge($ips, $config['exclude_admin_ips']);
    }

    return array_values(array_unique(array_filter(array_map('strval', $ips))));
}

/**
 * Source-specific admin IPs.
 *
 * Supports both:
 * - admin_ips
 * - exclude_admin_ips, for backward compatibility
 *
 * @param array $config
 * @param string $sourceKey
 * @return array
 */
function source_visibility_admin_ips(array $config, string $sourceKey): array
{
    $source = source_config_by_key($config, $sourceKey);

    if (!$source) {
        return [];
    }

    $ips = [];

    if (!empty($source['admin_ips']) && is_array($source['admin_ips'])) {
        $ips = array_merge($ips, $source['admin_ips']);
    }

    if (!empty($source['exclude_admin_ips']) && is_array($source['exclude_admin_ips'])) {
        $ips = array_merge($ips, $source['exclude_admin_ips']);
    }

    return array_values(array_unique(array_filter(array_map('strval', $ips))));
}

/**
 * Check if the current IP may manage hidden/visible pages.
 *
 * If source key is supplied, global admins and source-specific admins are allowed.
 * If no source key is supplied, global admins or any source-specific admin is allowed.
 *
 * @param array $config
 * @param ?string $sourceKey
 * @return bool
 */
function can_manage_page_exclusions(array $config, ?string $sourceKey = null): bool
{
    $clientIp = client_ip_address();

    if ($clientIp === '') {
        return false;
    }

    if (in_array($clientIp, global_visibility_admin_ips($config), true)) {
        return true;
    }

    if ($sourceKey !== null && $sourceKey !== '') {
        return in_array($clientIp, source_visibility_admin_ips($config, $sourceKey), true);
    }

    $sources = $config['bookstack_sources'] ?? [];

    if (!is_array($sources)) {
        return false;
    }

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $key = (string)($source['key'] ?? '');

        if ($key !== '' && in_array($clientIp, source_visibility_admin_ips($config, $key), true)) {
            return true;
        }
    }

    return false;
}

/**
 * Return configured administrator email addresses.
 *
 * @param array $config
 * @return array
 */
function admin_email_addresses(array $config): array
{
    $emails = $config['admin_emails'] ?? [];

    if (!is_array($emails)) {
        return [];
    }

    $clean = [];

    foreach ($emails as $email) {
        $email = mb_strtolower(trim((string)$email));

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $clean[] = $email;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Return the currently verified access-gate email address.
 *
 * @return string
 */
function current_verified_access_email(): string
{
    return mb_strtolower(trim((string)($_SESSION['manual_access_email'] ?? '')));
}

/**
 * Check whether the current visitor may access admin pages.
 *
 * Admin access requires both:
 * - allowed admin IP
 * - verified access-gate email listed in admin_emails
 *
 * @param array $config
 * @param ?string $sourceKey
 * @return bool
 */
function can_access_admin_pages(array $config, ?string $sourceKey = null): bool
{
    if (!can_manage_page_exclusions($config, $sourceKey)) {
        return false;
    }

    $email = current_verified_access_email();

    if ($email === '') {
        return false;
    }

    return in_array($email, admin_email_addresses($config), true);
}

/**
 * Source-configured hidden page IDs.
 *
 * These are treated as default hidden pages. A DB record with excluded=0
 * may override this so that admin show/hide settings survive syncs.
 *
 * @param array $config
 * @param string $sourceKey
 * @return array
 */
function config_excluded_page_ids(array $config, string $sourceKey): array
{
    $source = source_config_by_key($config, $sourceKey);

    if (!$source) {
        return [];
    }

    $ids = $source['exclude_page_ids'] ?? [];

    if (!is_array($ids)) {
        return [];
    }

    $clean = [];

    foreach ($ids as $id) {
        $id = (int)$id;

        if ($id > 0) {
            $clean[] = $id;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Return true if a page is hidden for normal visitors.
 *
 * DB setting wins over config. This allows an admin to show a page even when
 * it originally came from exclude_page_ids, and the setting survives sync.
 *
 * @param PDO $pdo
 * @param array $config
 * @param string $sourceKey
 * @param int $sourcePageId
 * @return bool
 */
function is_page_excluded(PDO $pdo, array $config, string $sourceKey, int $sourcePageId): bool
{
    if ($sourceKey === '' || $sourcePageId <= 0) {
        return false;
    }

    /*
     * Hard exclusion from config.
     *
     * These pages are hidden for everyone, including admin IPs.
     * The database cannot override this.
     */
    if (in_array($sourcePageId, config_excluded_page_ids($config, $sourceKey), true)) {
        return true;
    }

    /*
     * Soft exclusion from database.
     *
     * These pages may still be visible to admin IPs.
     */
    $stmt = $pdo->prepare("
        SELECT excluded
        FROM public_doc_exclusions
        WHERE source_key = :source_key
          AND source_page_id = :source_page_id
        LIMIT 1
    ");

    $stmt->execute([
        'source_key' => $sourceKey,
        'source_page_id' => $sourcePageId,
    ]);

    $value = $stmt->fetchColumn();

    return (int)$value === 1;
}

/**
 * Mark a page hidden or visible.
 *
 * @param PDO $pdo
 * @param string $sourceKey
 * @param int $sourcePageId
 * @param bool $excluded
 * @return void
 */
function set_page_excluded(PDO $pdo, string $sourceKey, int $sourcePageId, bool $excluded): void
{
    if ($sourceKey === '' || $sourcePageId <= 0) {
        throw new InvalidArgumentException('Invalid source/page combination.');
    }

    $ip = client_ip_address();

    $stmt = $pdo->prepare("
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
            :excluded,
            NOW(),
            NOW(),
            :created_by_ip,
            :updated_by_ip
        )
        ON DUPLICATE KEY UPDATE
            excluded = VALUES(excluded),
            updated_at = NOW(),
            updated_by_ip = VALUES(updated_by_ip)
    ");

    $stmt->execute([
        'source_key' => $sourceKey,
        'source_page_id' => $sourcePageId,
        'excluded' => $excluded ? 1 : 0,
        'created_by_ip' => $ip,
        'updated_by_ip' => $ip,
    ]);
}

/**
 * Check if a public_docs row is visible to the current visitor.
 *
 * Admin IPs may see hidden pages for their source.
 *
 * @param PDO $pdo
 * @param array $config
 * @param array $page
 * @return bool
 */
function page_visible_to_current_ip(PDO $pdo, array $config, array $page): bool
{
    $sourceKey = (string)($page['source_key'] ?? '');
    $sourcePageId = (int)($page['source_page_id'] ?? 0);

    if ($sourceKey === '' || $sourcePageId <= 0) {
        return true;
    }

    /*
     * Hard excluded pages are hidden for everyone.
     */
    if (is_page_hard_excluded($config, $sourceKey, $sourcePageId)) {
        return false;
    }

    /*
     * Soft hidden pages are hidden for normal visitors,
     * but visible for allowed admin IPs.
     */
    if (!is_page_excluded($pdo, $config, $sourceKey, $sourcePageId)) {
        return true;
    }

    return can_access_admin_pages($config, $sourceKey);
}


/**
 * Filter a list of public_docs rows for the current visitor.
 *
 * @param PDO $pdo
 * @param array $config
 * @param array $pages
 * @return array
 */
function filter_pages_for_current_ip(PDO $pdo, array $config, array $pages): array
{
    $visible = [];

    foreach ($pages as $page) {
        if (page_visible_to_current_ip($pdo, $config, $page)) {
            $visible[] = $page;
        }
    }

    return $visible;
}

/**
 * Render the bottom-page hide/show admin control.
 *
 * @param PDO $pdo
 * @param array $config
 * @param array $page
 * @return string
 */
function render_page_visibility_toggle(PDO $pdo, array $config, array $page): string
{
    $sourceKey = (string)($page['source_key'] ?? '');
    $sourcePageId = (int)($page['source_page_id'] ?? 0);

    if ($sourceKey === '' || $sourcePageId <= 0) {
        return '';
    }


    if (is_page_hard_excluded($config, $sourceKey, $sourcePageId)) {
        return '';
    }

    if (!can_access_admin_pages($config, $sourceKey)) {
        return '';
    }

    $isHidden = is_page_excluded($pdo, $config, $sourceKey, $sourcePageId);

    $action = $isHidden ? 'show' : 'hide';
    $buttonLabel = $isHidden ? 'Publish page #' . $sourcePageId . ' for everybody (show)' : 'Hide page #' . $sourcePageId . ' from the public (hide)';
    $stateText = $isHidden
        ? 'This page #' . $sourcePageId . ' is currently hidden for normal visitors and search results.'
        : 'This page #' . $sourcePageId . ' is currently public for normal visitors.';

    $returnTo = (string)($_SERVER['REQUEST_URI'] ?? '/');

    if ($returnTo === '' || $returnTo[0] !== '/') {
        $returnTo = '/';
    }

$stateClass = $isHidden ? 'is-hidden' : 'is-visible';

$html = '<form method="post" action="/admin/toggle-page-visibility" class="doc-visibility-form doc-visibility-form-split ' . $stateClass . '">';
$html .= '<input type="hidden" name="source_key" value="' . e($sourceKey) . '">';
$html .= '<input type="hidden" name="source_page_id" value="' . e((string)$sourcePageId) . '">';
$html .= '<input type="hidden" name="action" value="' . e($action) . '">';
$html .= '<input type="hidden" name="return_to" value="' . e($returnTo) . '">';

$html .= '<span class="doc-visibility-note">';
$html .= e($stateText);
$html .= '</span>';

$html .= '<button type="submit" class="doc-visibility-button">';
$html .= e($buttonLabel);
$html .= '</button>';

    $html .= '</form>';

    return $html;
}
