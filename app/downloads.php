<?php

declare(strict_types=1);

/**
 * Download section helpers.
 *
 * Rules:
 * - Only ZIP files are downloadable.
 * - ZIP files inside a ".hidden" folder are ignored.
 * - Optional Markdown info files use the same basename as the ZIP file.
 * - Markdown info files may use .md, .MD, .Md, or .mD.
 * - Download info pages use SEO-friendly URLs.
 * - Actual downloads require a verified access-gate session and a download code.
 */

/**
 * Return a safe local return path.
 *
 * @param string $value
 * @param string $fallback
 * @return string
 */
function downloads_safe_return_to(string $value, string $fallback = '/downloads'): string
{
    $value = trim($value);

    if ($value === '' || $value[0] !== '/' || str_starts_with($value, '//')) {
        return $fallback;
    }

    return $value;
}

/**
 * Check whether the current visitor may view download lists and download info pages.
 *
 * Uses the same access gate as normal documentation pages.
 *
 * @param array $config
 * @return bool
 */
function downloads_access_is_verified(array $config): bool
{
    if (!function_exists('access_gate_is_verified')) {
        return true;
    }

    return access_gate_is_verified($config);
}

/**
 * Check whether downloads are enabled.
 *
 * @param array $config
 * @return bool
 */
function downloads_enabled(array $config): bool
{
    return !empty($config['downloads_enabled']);
}

/**
 * Return the absolute download root folder.
 *
 * @param array $config
 * @return string
 */
function downloads_root(array $config): string
{
    return rtrim((string)($config['downloads_path'] ?? (__DIR__ . '/../repository')), '/');
}

/**
 * Convert a file/category name to a URL-safe slug.
 *
 * @param string $value
 * @return string
 */
function download_slug(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    $value = trim($value, '-');

    return $value !== '' ? $value : 'download';
}

/**
 * Convert a filename to a readable title.
 *
 * @param string $filename
 * @return string
 */
function download_title_from_filename(string $filename): string
{
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['-', '_'], ' ', $name);

    return trim(ucwords($name));
}

/**
 * Return true when path looks safe.
 *
 * @param string $relative
 * @return bool
 */
function download_relative_path_is_safe(string $relative): bool
{
    if ($relative === '') {
        return false;
    }

    if (str_contains($relative, "\0")) {
        return false;
    }

    if (str_contains($relative, '..')) {
        return false;
    }

    return (bool)preg_match('/^[a-zA-Z0-9_\-\/\. ]+$/', $relative);
}

/**
 * Find an optional Markdown info file for a ZIP basename.
 *
 * @param string $rootReal
 * @param string $dir
 * @param string $basename
 * @return ?string
 */
function downloads_find_markdown_info_file(string $rootReal, string $dir, string $basename): ?string
{
    $mdCandidates = [
        $basename . '.md',
        $basename . '.MD',
        $basename . '.Md',
        $basename . '.mD',
    ];

    foreach ($mdCandidates as $mdFilename) {
        $mdRelative = ($dir !== '' && $dir !== '/')
            ? $dir . '/' . $mdFilename
            : $mdFilename;

        $candidatePath = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mdRelative);

        if (is_file($candidatePath)) {
            return $candidatePath;
        }
    }

    return null;
}

/**
 * Scan downloads.
 *
 * Returns:
 * [
 *   [
 *     category => string,
 *     category_slug => string,
 *     title => string,
 *     filename => string,
 *     relative_path => string,
 *     download_key => string,
 *     download_url => string,
 *     info_slug => string,
 *     info_url => ?string,
 *     md_path => ?string,
 *     size_bytes => int,
 *     updated_at => int,
 *   ]
 * ]
 *
 * @param array $config
 * @return array
 */
function downloads_scan(array $config): array
{
    $root = downloads_root($config);

    if (!is_dir($root)) {
        return [];
    }

    $rootReal = realpath($root);

    if ($rootReal === false) {
        return [];
    }

    $items = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $rootReal,
            FilesystemIterator::SKIP_DOTS
        )
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        /*
         * Only ZIP files are downloadable.
         * This is case-insensitive, so .zip and .ZIP both work.
         */
        if (mb_strtolower($file->getExtension()) !== 'zip') {
            continue;
        }

        $fullPath = $file->getRealPath();

        if ($fullPath === false || !str_starts_with($fullPath, $rootReal . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($fullPath, strlen($rootReal) + 1));

        /*
         * Never list or serve downloads from hidden folders.
         *
         * Any folder named ".hidden" anywhere below the downloads root is ignored.
         *
         * Examples:
         * - downloads/.hidden/file.zip
         * - downloads/tools/.hidden/file.zip
         * - downloads/tools/.hidden/archive/file.zip
         */
        $relativeParts = explode('/', $relative);

        if (in_array('.hidden', $relativeParts, true)) {
            continue;
        }

        if (!download_relative_path_is_safe($relative)) {
            continue;
        }

        $dir = trim(str_replace('\\', '/', dirname($relative)), '.');

        if ($dir === '' || $dir === '/') {
            $category = 'Downloads';
            $categorySlug = 'downloads';
        } else {
            $parts = explode('/', $dir);
            $category = trim((string)end($parts));
            $categorySlug = download_slug($dir);
        }

        $filename = basename($relative);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $mdPath = downloads_find_markdown_info_file($rootReal, $dir, $basename);
        $hasInfo = $mdPath !== null;

        /*
         * Internal key remains the real relative ZIP path.
         * This is used for verification and file download handling.
         */
        $key = $relative;

        /*
         * Public SEO URL uses safe slugs, not the real filename.
         *
         * Example:
         * /downloads/info/virtual-machines/cocos-v5-0-26-b24359-3-hyper-v
         */
        $infoSlug = download_slug($basename);

        $items[] = [
            'category' => $category,
            'category_slug' => $categorySlug,
            'title' => download_title_from_filename($filename),
            'filename' => $filename,
            'relative_path' => $relative,
            'download_key' => $key,
            'download_url' => '/downloads/request?file=' . rawurlencode($key),
            'info_slug' => $infoSlug,
            'info_url' => $hasInfo
                ? '/downloads/info/' . rawurlencode($categorySlug) . '/' . rawurlencode($infoSlug)
                : null,
            'md_path' => $hasInfo ? $mdPath : null,
            'size_bytes' => (int)$file->getSize(),
            'updated_at' => (int)$file->getMTime(),
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return [$a['category'], $a['title']] <=> [$b['category'], $b['title']];
    });

    return $items;
}

/**
 * Group downloads by category.
 *
 * @param array $downloads
 * @return array
 */
function downloads_group_by_category(array $downloads): array
{
    $groups = [];

    foreach ($downloads as $download) {
        $slug = (string)$download['category_slug'];

        if (!isset($groups[$slug])) {
            $groups[$slug] = [
                'name' => (string)$download['category'],
                'slug' => $slug,
                'items' => [],
            ];
        }

        $groups[$slug]['items'][] = $download;
    }

    return $groups;
}

/**
 * Find a download by relative file key.
 *
 * @param array $config
 * @param string $key
 * @return ?array
 */
function downloads_find(array $config, string $key): ?array
{
    if (in_array('.hidden', explode('/', str_replace('\\', '/', $key)), true)) {
        return null;
    }

    foreach (downloads_scan($config) as $download) {
        if (hash_equals((string)$download['download_key'], $key)) {
            return $download;
        }
    }

    return null;
}

/**
 * Find a download by public info URL slugs.
 *
 * @param array $config
 * @param string $categorySlug
 * @param string $infoSlug
 * @return ?array
 */
function downloads_find_by_info_slug(array $config, string $categorySlug, string $infoSlug): ?array
{
    $categorySlug = trim($categorySlug);
    $infoSlug = trim($infoSlug);

    if ($categorySlug === '' || $infoSlug === '') {
        return null;
    }

    foreach (downloads_scan($config) as $download) {
        if (
            (string)($download['category_slug'] ?? '') === $categorySlug &&
            (string)($download['info_slug'] ?? '') === $infoSlug
        ) {
            return $download;
        }
    }

    return null;
}

/**
 * Basic markdown renderer for download info files.
 *
 * This keeps the feature dependency-free. For advanced markdown, replace this
 * with a real CommonMark parser later.
 *
 * @param string $markdown
 * @return string
 */
function downloads_markdown_to_html(string $markdown): string
{
    $lines = preg_split('/\R/', $markdown) ?: [];
    $html = '';
    $listOpen = false;

    foreach ($lines as $line) {
        $raw = rtrim((string)$line);
        $safe = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (trim($raw) === '') {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            continue;
        }

        if (preg_match('/^###\s+(.+)$/', $raw, $m)) {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }

            $html .= '<h3>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h3>';
            continue;
        }

        if (preg_match('/^##\s+(.+)$/', $raw, $m)) {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }

            $html .= '<h2>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h2>';
            continue;
        }

        if (preg_match('/^#\s+(.+)$/', $raw, $m)) {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }

            $html .= '<h1>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>';
            continue;
        }

        if (preg_match('/^\-\s+(.+)$/', $raw, $m)) {
            if (!$listOpen) {
                $html .= '<ul>';
                $listOpen = true;
            }

            $html .= '<li>' . htmlspecialchars($m[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
            continue;
        }

        if ($listOpen) {
            $html .= '</ul>';
            $listOpen = false;
        }

        $safe = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $safe) ?? $safe;
        $safe = preg_replace('/\[(.+?)\]\((https?:\/\/[^)]+)\)/', '<a href="$2" rel="nofollow">$1</a>', $safe) ?? $safe;

        $html .= '<p>' . $safe . '</p>';
    }

    if ($listOpen) {
        $html .= '</ul>';
    }

    return $html;
}

/**
 * Return the currently verified email or empty string.
 *
 * @param array $config
 * @return string
 */
function downloads_current_email(array $config): string
{
    if (
        function_exists('access_gate_is_verified') &&
        !access_gate_is_verified($config)
    ) {
        return '';
    }

    return mb_strtolower(trim((string)($_SESSION['manual_access_email'] ?? '')));
}

/**
 * Create a permanent download-request audit row.
 *
 * This table is never cleaned up by the download-code cleanup.
 *
 * @param PDO $pdo
 * @param array $download
 * @param ?string $email
 * @param string $status
 * @param ?string $failureReason
 * @return int
 */
function downloads_create_request_log(
    PDO $pdo,
    array $download,
    ?string $email,
    string $status = 'requested',
    ?string $failureReason = null
): int {
    $ip = function_exists('access_gate_ip_address')
        ? access_gate_ip_address()
        : (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $userAgent = function_exists('access_gate_user_agent')
        ? access_gate_user_agent()
        : (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $referer = function_exists('access_gate_referer')
        ? access_gate_referer()
        : (string)($_SERVER['HTTP_REFERER'] ?? '');

    $stmt = $pdo->prepare("
        INSERT INTO public_download_requests (
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
            failed_at,
            failure_reason,
            created_at
        ) VALUES (
            :email,
            :download_key,
            :download_title,
            :filename,
            :category,
            :category_slug,
            :ip_address,
            :user_agent,
            :referer,
            :status,
            CASE WHEN :status_code_sent = 1 THEN NOW() ELSE NULL END,
            CASE WHEN :status_failed = 1 THEN NOW() ELSE NULL END,
            :failure_reason,
            NOW()
        )
    ");

    $stmt->execute([
        'email' => $email,
        'download_key' => (string)($download['download_key'] ?? ''),
        'download_title' => mb_substr((string)($download['title'] ?? ''), 0, 255),
        'filename' => mb_substr((string)($download['filename'] ?? ''), 0, 255),
        'category' => mb_substr((string)($download['category'] ?? ''), 0, 255),
        'category_slug' => mb_substr((string)($download['category_slug'] ?? ''), 0, 255),
        'ip_address' => mb_substr($ip, 0, 64),
        'user_agent' => mb_substr($userAgent, 0, 512),
        'referer' => mb_substr($referer, 0, 768),
        'status' => mb_substr($status, 0, 32),
        'status_code_sent' => $status === 'code_sent' ? 1 : 0,
        'status_failed' => $status === 'failed' ? 1 : 0,
        'failure_reason' => $failureReason !== null ? mb_substr($failureReason, 0, 255) : null,
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Update permanent download-request audit status.
 *
 * @param PDO $pdo
 * @param int $requestId
 * @param string $status
 * @param ?string $failureReason
 * @return void
 */
function downloads_update_request_log(
    PDO $pdo,
    int $requestId,
    string $status,
    ?string $failureReason = null
): void {
    if ($requestId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE public_download_requests
        SET
            status = :status,
            verified_at = CASE WHEN :status_verified = 1 THEN NOW() ELSE verified_at END,
            downloaded_at = CASE WHEN :status_downloaded = 1 THEN NOW() ELSE downloaded_at END,
            failed_at = CASE WHEN :status_failed = 1 THEN NOW() ELSE failed_at END,
            failure_reason = COALESCE(:failure_reason, failure_reason)
        WHERE id = :id
    ");

    $stmt->execute([
        'id' => $requestId,
        'status' => mb_substr($status, 0, 32),
        'status_verified' => $status === 'verified' ? 1 : 0,
        'status_downloaded' => $status === 'downloaded' ? 1 : 0,
        'status_failed' => $status === 'failed' ? 1 : 0,
        'failure_reason' => $failureReason !== null ? mb_substr($failureReason, 0, 255) : null,
    ]);
}

/**
 * Send a download verification code to the current user's email address.
 *
 * @param PDO $pdo
 * @param array $config
 * @param array $download
 * @return bool
 */
function downloads_send_code(PDO $pdo, array $config, array $download): bool
{
    $email = downloads_current_email($config);

    if ($email === '') {
        return false;
    }

    if (
	function_exists('public_user_is_enabled') &&
	!public_user_is_enabled($pdo, $email)
    ) {
	if (function_exists('downloads_create_request_log')) {
		downloads_create_request_log(
			$pdo,
			$download,
			$email,
			'failed',
			'Public user is disabled'
		);
	}
		return false;
    }

    if (function_exists('public_user_ensure')) {
        public_user_ensure(
            $pdo,
            $email,
            'download_code_requested',
            function_exists('access_gate_ip_address') ? access_gate_ip_address() : (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            function_exists('access_gate_user_agent') ? access_gate_user_agent() : (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
        );
    }

    $ttl = max(1, (int)($config['download_code_ttl_minutes'] ?? 10));
    $code = (string)random_int(100000, 999999);
    $codeHash = hash('sha256', $code);
    $downloadKey = (string)$download['download_key'];

    /*
     * Permanent audit row. This stays forever.
     */
    $requestId = downloads_create_request_log(
        $pdo,
        $download,
        $email,
        'requested',
        null
    );

    /*
     * Only clean up temporary verification codes.
     * Do not clean up public_download_requests.
     */
    $cleanup = $pdo->prepare("
        DELETE FROM public_download_codes
        WHERE email = :email
          AND download_key = :download_key
          AND expires_at <= NOW()
    ");

    $cleanup->execute([
        'email' => $email,
        'download_key' => $downloadKey,
    ]);

    $stmt = $pdo->prepare("
        INSERT INTO public_download_codes (
            email,
            download_key,
            download_request_id,
            code_hash,
            ip_address,
            user_agent,
            attempts,
            used_at,
            expires_at,
            created_at
        ) VALUES (
            :email,
            :download_key,
            :download_request_id,
            :code_hash,
            :ip_address,
            :user_agent,
            0,
            NULL,
            DATE_ADD(NOW(), INTERVAL {$ttl} MINUTE),
            NOW()
        )
    ");

    $stmt->execute([
        'email' => $email,
        'download_key' => $downloadKey,
        'download_request_id' => $requestId,
        'code_hash' => $codeHash,
        'ip_address' => function_exists('access_gate_ip_address') ? access_gate_ip_address() : ($_SERVER['REMOTE_ADDR'] ?? ''),
        'user_agent' => function_exists('access_gate_user_agent') ? access_gate_user_agent() : ($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ]);

    $appName = (string)($config['app_name'] ?? 'Documentation');
    $from = (string)($config['access_gate_mail_from'] ?? ($config['smtp']['from'] ?? ''));
    $fromName = (string)($config['access_gate_mail_from_name'] ?? $appName);
    $subject = $appName . ' download code';

    $title = (string)$download['title'];

    $text = "Your download verification code is: {$code}\n\n";
    $text .= "Download: {$title}\n";
    $text .= "This code expires in {$ttl} minutes.\n";

    $safeApp = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $html = '<!doctype html><html><body style="font-family: Segoe UI, Arial, sans-serif; background:#f4f6f8; padding:32px;">';
    $html .= '<div style="max-width:620px;margin:auto;background:#fff;border:1px solid #d9e2ec;border-radius:10px;padding:28px;">';
    $html .= '<h1 style="margin-top:0;">' . $safeApp . ' download code</h1>';
    $html .= '<p>Use this code to download <strong>' . $safeTitle . '</strong>.</p>';
    $html .= '<div style="display:inline-block;padding:16px 22px;border:1px solid #c6d0dc;border-radius:6px;font-size:30px;font-weight:800;letter-spacing:6px;">' . $safeCode . '</div>';
    $html .= '<p>This code expires in <strong>' . $ttl . ' minutes</strong>.</p>';
    $html .= '</div></body></html>';

    if (!function_exists('smtp_send_mail_html')) {
        downloads_update_request_log(
            $pdo,
            $requestId,
            'failed',
            'SMTP mail function is not available'
        );

        return false;
    }

    $mailSent = smtp_send_mail_html(
        $config,
        $from,
        $fromName,
        $email,
        $subject,
        $text,
        $html
    );

    if ($mailSent) {
        downloads_update_request_log($pdo, $requestId, 'code_sent');

        return true;
    }

    downloads_update_request_log(
        $pdo,
        $requestId,
        'failed',
        'Could not send download code'
    );

    return false;
}

/**
 * Verify a download code.
 *
 * @param PDO $pdo
 * @param array $config
 * @param array $download
 * @param string $code
 * @return array{ok: bool, request_id: int}
 */
function downloads_verify_code(PDO $pdo, array $config, array $download, string $code): array
{
    $email = downloads_current_email($config);

    if ($email === '' || !preg_match('/^[0-9]{6}$/', $code)) {
        return [
            'ok' => false,
            'request_id' => 0,
        ];
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM public_download_codes
        WHERE email = :email
          AND download_key = :download_key
          AND used_at IS NULL
          AND expires_at > NOW()
        ORDER BY created_at DESC
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
        'download_key' => (string)$download['download_key'],
    ]);

    $row = $stmt->fetch();

    if (!$row) {
        return [
            'ok' => false,
            'request_id' => 0,
        ];
    }

    $requestId = (int)($row['download_request_id'] ?? 0);

    if ((int)($row['attempts'] ?? 0) >= 5) {
        if ($requestId > 0) {
            downloads_update_request_log($pdo, $requestId, 'failed', 'Too many invalid code attempts');
        }

        return [
            'ok' => false,
            'request_id' => $requestId,
        ];
    }

    if (!hash_equals((string)$row['code_hash'], hash('sha256', $code))) {
        $update = $pdo->prepare("
            UPDATE public_download_codes
            SET attempts = attempts + 1
            WHERE id = :id
        ");

        $update->execute([
            'id' => (int)$row['id'],
        ]);

        return [
            'ok' => false,
            'request_id' => $requestId,
        ];
    }

    $update = $pdo->prepare("
        UPDATE public_download_codes
        SET used_at = NOW()
        WHERE id = :id
    ");

    $update->execute([
        'id' => (int)$row['id'],
    ]);

    if ($requestId > 0) {
        downloads_update_request_log($pdo, $requestId, 'verified');
    }

    return [
        'ok' => true,
        'request_id' => $requestId,
    ];
}

/**
 * Render a download button/form.
 *
 * @param array $download
 * @return string
 */
function downloads_render_button(array $download): string
{
    $returnTo = downloads_safe_return_to((string)($_SERVER['REQUEST_URI'] ?? '/downloads'));

    return '<form method="post" action="/downloads/request" class="inline-admin-form">'
        . '<input type="hidden" name="file" value="' . e((string)$download['download_key']) . '">'
        . '<input type="hidden" name="return_to" value="' . e($returnTo) . '">'
        . '<button type="submit">Download</button>'
        . '</form>';
}
