<?php

declare(strict_types=1);

/**
 * Download section.
 *
 * Rules:
 * - Only .zip files are downloadable.
 * - Optional markdown info files use the same basename as the ZIP file.
 * - Example:
 *   - downloads/tools/tool.zip
 *   - downloads/tools/tool.md
 */

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
    return rtrim((string)($config['downloads_path'] ?? (__DIR__ . '/../downloads')), '/');
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
 *     download_url => string,
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

        if (mb_strtolower($file->getExtension()) !== 'zip') {
            continue;
        }

        $fullPath = $file->getRealPath();

        if ($fullPath === false || !str_starts_with($fullPath, $rootReal . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($fullPath, strlen($rootReal) + 1));

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
        $mdRelative = ($dir !== '' && $dir !== '/') ? $dir . '/' . $basename . '.md' : $basename . '.md';
        $mdPath = $rootReal . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $mdRelative);
        $hasInfo = is_file($mdPath);

        $key = $relative;

        $items[] = [
            'category' => $category,
            'category_slug' => $categorySlug,
            'title' => download_title_from_filename($filename),
            'filename' => $filename,
            'relative_path' => $relative,
            'download_key' => $key,
            'download_url' => '/downloads/request?file=' . rawurlencode($key),
            'info_url' => $hasInfo ? '/downloads/info/' . rawurlencode($key) : null,
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
    foreach (downloads_scan($config) as $download) {
        if (hash_equals((string)$download['download_key'], $key)) {
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

    $ttl = max(1, (int)($config['download_code_ttl_minutes'] ?? 10));
    $code = (string)random_int(100000, 999999);
    $codeHash = hash('sha256', $code);
    $downloadKey = (string)$download['download_key'];

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
        return false;
    }

    return smtp_send_mail_html(
        $config,
        $from,
        $fromName,
        $email,
        $subject,
        $text,
        $html
    );
}

/**
 * Verify a download code.
 *
 * @param PDO $pdo
 * @param array $config
 * @param array $download
 * @param string $code
 * @return bool
 */
function downloads_verify_code(PDO $pdo, array $config, array $download, string $code): bool
{
    $email = downloads_current_email($config);

    if ($email === '' || !preg_match('/^[0-9]{6}$/', $code)) {
        return false;
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
        return false;
    }

    if ((int)($row['attempts'] ?? 0) >= 5) {
        return false;
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

        return false;
    }

    $update = $pdo->prepare("
        UPDATE public_download_codes
        SET used_at = NOW()
        WHERE id = :id
    ");

    $update->execute([
        'id' => (int)$row['id'],
    ]);

    return true;
}

/**
 * Render a download button/form.
 *
 * @param array $download
 * @return string
 */
function downloads_render_button(array $download): string
{
    return '<form method="post" action="/downloads/request" class="inline-admin-form">'
        . '<input type="hidden" name="file" value="' . e((string)$download['download_key']) . '">'
        . '<button type="submit">Download</button>'
        . '</form>';
}
