<?php

declare(strict_types=1);

/**
 * CoCoS Manual access gate.
 *
 * This file provides:
 * - email-code access
 * - magic login link access
 * - SMTP email sending
 * - HTML + text email templates
 * - access logging
 * - login overlay rendering
 */


/**
 * Cookie name used to remember the last email address.
 *
 * @return string
 */
function access_gate_remember_email_cookie_name(): string
{
    return 'manual_access_remembered_email';
}

/**
 * Return secure cookie options for the access gate.
 *
 * @param array $config
 * @param int $maxAge
 * @return array
 */
function access_gate_cookie_options(array $config, int $maxAge): array
{
    return [
        'expires' => time() + $maxAge,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/**
 * Store the last used email address in a cookie.
 *
 * The cookie only stores the email address. It does not grant access.
 *
 * @param string $email
 * @param array $config
 * @return void
 */
function access_gate_remember_email(string $email, array $config): void
{
    $email = trim(mb_strtolower($email));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $days = max(1, (int)($config['access_gate_remember_email_days'] ?? 180));
    $maxAge = $days * 86400;

    setcookie(
        access_gate_remember_email_cookie_name(),
        $email,
        access_gate_cookie_options($config, $maxAge)
    );

    /**
     * Make the value available during the same request too.
     */
    $_COOKIE[access_gate_remember_email_cookie_name()] = $email;
}

/**
 * Read the remembered email address from the cookie.
 *
 * @param array $config
 * @return ?string
 */
function access_gate_remembered_email(array $config): ?string
{
    $email = trim((string)($_COOKIE[access_gate_remember_email_cookie_name()] ?? ''));

    if ($email === '') {
        return null;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $validation = access_gate_validate_email($email, $config);

    if (!$validation['ok']) {
        return null;
    }

    return (string)$validation['email'];
}

/**
 * Clear the remembered email cookie.
 *
 * You probably do not want to call this on normal logout,
 * because the goal is to remember the address for next time.
 *
 * @param array $config
 * @return void
 */
function access_gate_forget_remembered_email(array $config): void
{
    setcookie(
        access_gate_remember_email_cookie_name(),
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );

    unset($_COOKIE[access_gate_remember_email_cookie_name()]);
}

/**
 * Start the access-gate session.
 *
 * @param array $config
 * @return void
 */
function access_gate_start_session(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionDays = max(1, (int)($config['access_gate_session_days'] ?? 7));
    $lifetime = $sessionDays * 86400;

    session_cache_limiter('');

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}


/**
 * Start the access-gate session.
 *
 * @param array $config
 * @return void
 */
function access_gate_start_session_depricated1(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $sessionDays = max(1, (int)($config['access_gate_session_days'] ?? 7));
    $lifetime = $sessionDays * 86400;

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Check if the access gate is enabled.
 *
 * @param array $config
 * @return bool
 */
function access_gate_enabled(array $config): bool
{
    return (bool)($config['access_gate_enabled'] ?? false);
}

/**
 * Check if the current session is verified.
 *
 * @param array $config
 * @return bool
 */
function access_gate_is_verified(array $config): bool
{
    if (!access_gate_enabled($config)) {
        return true;
    }

    $verifiedUntil = (int)($_SESSION['manual_access_verified_until'] ?? 0);

    if ($verifiedUntil <= 0) {
        return false;
    }

    if ($verifiedUntil < time()) {
        unset(
            $_SESSION['manual_access_email'],
            $_SESSION['manual_access_verified_until'],
            $_SESSION['manual_access_pending_email']
        );

        return false;
    }

    return true;
}

/**
 * Return the current verified email address, if available.
 *
 * @return ?string
 */
function access_gate_verified_email(): ?string
{
    $email = trim((string)($_SESSION['manual_access_email'] ?? ''));

    return $email !== '' ? $email : null;
}

/**
 * Send a JSON response and stop execution.
 *
 * @param array $payload
 * @param int $statusCode
 * @return void
 */
function access_gate_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

/**
 * Get the current request IP address.
 *
 * @return ?string
 */
function access_gate_ip_address(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    if (!$ip) {
        return null;
    }

    return mb_substr((string)$ip, 0, 64);
}

/**
 * Get the current request user agent.
 *
 * @return ?string
 */
function access_gate_user_agent(): ?string
{
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    if (!$userAgent) {
        return null;
    }

    return mb_substr((string)$userAgent, 0, 255);
}

/**
 * Get the current request referer.
 *
 * @return ?string
 */
function access_gate_referer(): ?string
{
    $referer = $_SERVER['HTTP_REFERER'] ?? null;

    if (!$referer) {
        return null;
    }

    return mb_substr((string)$referer, 0, 768);
}

/**
 * Get the current URL path.
 *
 * @return ?string
 */
function access_gate_current_path(): ?string
{
    $uri = $_SERVER['REQUEST_URI'] ?? null;

    if (!$uri) {
        return null;
    }

    return mb_substr((string)$uri, 0, 768);
}

/**
 * Write an access event to public_access_log.
 *
 * This function must never break the website if logging fails.
 *
 * @param PDO $pdo
 * @param ?string $email
 * @param string $eventType
 * @param bool $success
 * @param ?string $message
 * @param ?string $urlPath
 * @return void
 */
function access_gate_log(
    PDO $pdo,
    ?string $email,
    string $eventType,
    bool $success = true,
    ?string $message = null,
    ?string $urlPath = null
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO public_access_log (
                email,
                event_type,
                url_path,
                ip_address,
                user_agent,
                referer,
                success,
                message,
                created_at
            ) VALUES (
                :email,
                :event_type,
                :url_path,
                :ip_address,
                :user_agent,
                :referer,
                :success,
                :message,
                NOW()
            )
        ");

        $stmt->execute([
            'email' => $email,
            'event_type' => mb_substr($eventType, 0, 64),
            'url_path' => $urlPath !== null ? mb_substr($urlPath, 0, 768) : access_gate_current_path(),
            'ip_address' => access_gate_ip_address(),
            'user_agent' => access_gate_user_agent(),
            'referer' => access_gate_referer(),
            'success' => $success ? 1 : 0,
            'message' => $message !== null ? mb_substr($message, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Access gate log failed: ' . $e->getMessage());
    }
}

/**
 * Validate an email address and optional allowed domains.
 *
 * @param string $email
 * @param array $config
 * @return array
 */
function access_gate_validate_email(string $email, array $config): array
{
    $email = trim(mb_strtolower($email));

    if ($email === '') {
        return [
            'ok' => false,
            'email' => '',
            'message' => 'Please enter your email address.',
        ];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return [
            'ok' => false,
            'email' => $email,
            'message' => 'Please enter a valid email address.',
        ];
    }

    $allowedDomains = $config['access_gate_allowed_domains'] ?? [];

    if (is_array($allowedDomains) && count($allowedDomains) > 0) {
        $domain = mb_strtolower((string)substr(strrchr($email, '@') ?: '', 1));

        $allowedDomains = array_map(
            static fn ($item): string => mb_strtolower(trim((string)$item)),
            $allowedDomains
        );

        if (!in_array($domain, $allowedDomains, true)) {
            return [
                'ok' => false,
                'email' => $email,
                'message' => 'This email domain is not allowed.',
            ];
        }
    }

    return [
        'ok' => true,
        'email' => $email,
        'message' => null,
    ];
}

/**
 * Request a new email access code.
 *
 * @param PDO $pdo
 * @param array $config
 * @return array
 */
function access_gate_request_code(PDO $pdo, array $config): array
{
    if (!access_gate_enabled($config)) {
        return [
            'ok' => true,
            'message' => 'Access gate is disabled.',
        ];
    }

    $emailInput = (string)($_POST['email'] ?? '');
    $validation = access_gate_validate_email($emailInput, $config);

    if (!$validation['ok']) {
        access_gate_log(
            $pdo,
            $validation['email'] ?: null,
            'code_request_failed',
            false,
            $validation['message']
        );

        return [
            'ok' => false,
            'message' => $validation['message'],
        ];
    }

    $email = (string)$validation['email'];
    $ttl = max(1, (int)($config['access_gate_code_ttl_minutes'] ?? 10));

    /**
     * Manual login code.
     */
    $code = (string)random_int(100000, 999999);
    $codeHash = hash('sha256', $code);

    /**
     * Magic login token.
     *
     * The raw token is emailed to the user.
     * Only the SHA-256 hash is stored in the database.
     */
    $magicToken = bin2hex(random_bytes(32));
    $magicTokenHash = hash('sha256', $magicToken);

    $ip = access_gate_ip_address();
    $userAgent = access_gate_user_agent();

    try {
        /**
         * Clean up expired codes for this email.
         */
        $cleanup = $pdo->prepare("
            DELETE FROM public_access_codes
            WHERE email = :email
              AND expires_at <= NOW()
        ");

        $cleanup->execute([
            'email' => $email,
        ]);

        /**
         * Store the new code and magic token.
         */
        $insert = $pdo->prepare("
            INSERT INTO public_access_codes (
                email,
                code_hash,
                token_hash,
                ip_address,
                user_agent,
                attempts,
                used_at,
                expires_at,
                created_at
            ) VALUES (
                :email,
                :code_hash,
                :token_hash,
                :ip_address,
                :user_agent,
                0,
                NULL,
                DATE_ADD(NOW(), INTERVAL {$ttl} MINUTE),
                NOW()
            )
        ");

        $insert->execute([
            'email' => $email,
            'code_hash' => $codeHash,
            'token_hash' => $magicTokenHash,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);

        if (!access_gate_send_email_code($email, $code, $config, $magicToken)) {
            access_gate_log(
                $pdo,
                $email,
                'code_request_failed',
                false,
                'Could not send access email'
            );

            return [
                'ok' => false,
                'message' => 'The access email could not be sent. Please try again later.',
            ];
        }

	$_SESSION['manual_access_pending_email'] = $email;
	access_gate_remember_email($email, $config);

        access_gate_log(
            $pdo,
            $email,
            'code_requested',
            true,
            'Access code requested'
        );

        return [
            'ok' => true,
            'message' => 'We sent an access code and direct login link to your email address.',
            'email' => $email,
        ];
    } catch (Throwable $e) {
        error_log('Access code request failed: ' . $e->getMessage());

        access_gate_log(
            $pdo,
            $email,
            'code_request_failed',
            false,
            'Internal error while requesting access code'
        );

        return [
            'ok' => false,
            'message' => 'The access code could not be created. Please try again later.',
        ];
    }
}

/**
 * Verify a 6-digit code.
 *
 * @param PDO $pdo
 * @param array $config
 * @return array
 */
function access_gate_verify_code(PDO $pdo, array $config): array
{
    if (!access_gate_enabled($config)) {
        return [
            'ok' => true,
            'message' => 'Access gate is disabled.',
        ];
    }

    $emailInput = (string)($_POST['email'] ?? ($_SESSION['manual_access_pending_email'] ?? ''));
    $code = trim((string)($_POST['code'] ?? ''));

    $validation = access_gate_validate_email($emailInput, $config);

    if (!$validation['ok']) {
        access_gate_log(
            $pdo,
            null,
            'code_verify_failed',
            false,
            $validation['message']
        );

        return [
            'ok' => false,
            'message' => $validation['message'],
        ];
    }

    $email = (string)$validation['email'];

    if (!preg_match('/^[0-9]{6}$/', $code)) {
        access_gate_log(
            $pdo,
            $email,
            'code_verify_failed',
            false,
            'Invalid code format'
        );

        return [
            'ok' => false,
            'message' => 'Please enter the 6-digit code.',
        ];
    }

    $codeHash = hash('sha256', $code);

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM public_access_codes
            WHERE email = :email
              AND used_at IS NULL
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $stmt->execute([
            'email' => $email,
        ]);

        $row = $stmt->fetch();

        if (!$row) {
            access_gate_log(
                $pdo,
                $email,
                'code_verify_failed',
                false,
                'No valid code found'
            );

            return [
                'ok' => false,
                'message' => 'The code is invalid or expired. Please request a new code.',
            ];
        }

        $attempts = (int)($row['attempts'] ?? 0);

        if ($attempts >= 5) {
            access_gate_log(
                $pdo,
                $email,
                'code_verify_failed',
                false,
                'Too many attempts'
            );

            return [
                'ok' => false,
                'message' => 'Too many attempts. Please request a new code.',
            ];
        }

        if (!hash_equals((string)$row['code_hash'], $codeHash)) {
            $update = $pdo->prepare("
                UPDATE public_access_codes
                SET attempts = attempts + 1
                WHERE id = :id
            ");

            $update->execute([
                'id' => $row['id'],
            ]);

            access_gate_log(
                $pdo,
                $email,
                'code_verify_failed',
                false,
                'Wrong access code'
            );

            return [
                'ok' => false,
                'message' => 'The code is incorrect.',
            ];
        }

        access_gate_mark_code_used($pdo, (int)$row['id']);
        access_gate_grant_session($email, $config);

        access_gate_log(
            $pdo,
            $email,
            'code_verified',
            true,
            'Access granted by code'
        );

$returnTo = (string)($_POST['return_to'] ?? '/');

if ($returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
    $returnTo = '/';
}

return [
    'ok' => true,
    'message' => 'Access granted.',
    'redirect' => $returnTo,
];

    } catch (Throwable $e) {
        error_log('Access code verification failed: ' . $e->getMessage());

        access_gate_log(
            $pdo,
            $email,
            'code_verify_failed',
            false,
            'Internal verification error'
        );

        return [
            'ok' => false,
            'message' => 'The code could not be verified. Please try again later.',
        ];
    }
}

/**
 * Verify a magic login token from the email link.
 *
 * @param PDO $pdo
 * @param array $config
 * @return bool
 */
function access_gate_verify_magic_token(PDO $pdo, array $config): bool
{
    if (!access_gate_enabled($config)) {
        return true;
    }

    $token = trim((string)($_GET['token'] ?? ''));

    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        access_gate_log(
            $pdo,
            null,
            'magic_login_failed',
            false,
            'Invalid magic token format'
        );

        return false;
    }

    $tokenHash = hash('sha256', $token);

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM public_access_codes
            WHERE token_hash = :token_hash
              AND used_at IS NULL
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $stmt->execute([
            'token_hash' => $tokenHash,
        ]);

        $row = $stmt->fetch();

        if (!$row) {
            access_gate_log(
                $pdo,
                null,
                'magic_login_failed',
                false,
                'Invalid, expired, or already used magic token'
            );

            return false;
        }

        $email = (string)$row['email'];

        access_gate_mark_code_used($pdo, (int)$row['id']);
        access_gate_grant_session($email, $config);

        access_gate_log(
            $pdo,
            $email,
            'magic_login_verified',
            true,
            'Access granted by magic login link'
        );

        return true;
    } catch (Throwable $e) {
        error_log('Magic login verification failed: ' . $e->getMessage());

        access_gate_log(
            $pdo,
            null,
            'magic_login_failed',
            false,
            'Internal magic login error'
        );

        return false;
    }
}

/**
 * Mark an access code/token row as used.
 *
 * @param PDO $pdo
 * @param int $id
 * @return void
 */
function access_gate_mark_code_used(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare("
        UPDATE public_access_codes
        SET used_at = NOW()
        WHERE id = :id
    ");

    $stmt->execute([
        'id' => $id,
    ]);
}

/**
 * Grant browser/session access.
 *
 * @param string $email
 * @param array $config
 * @return void
 */
function access_gate_grant_session(string $email, array $config): void
{
    $days = max(1, (int)($config['access_gate_session_days'] ?? 7));

    $_SESSION['manual_access_email'] = $email;
    $_SESSION['manual_access_verified_until'] = time() + ($days * 86400);

    access_gate_remember_email($email, $config);

    unset($_SESSION['manual_access_pending_email']);
}

/**
 * Send the one-time code and magic login link by SMTP smarthost.
 *
 * @param string $email
 * @param string $code
 * @param array $config
 * @param ?string $magicToken
 * @return bool
 */
function access_gate_send_email_code(string $email, string $code, array $config, ?string $magicToken = null): bool
{
    $from = (string)($config['access_gate_mail_from'] ?? 'no-reply@example.com');
    $fromName = (string)($config['access_gate_mail_from_name'] ?? 'CoCoS Manual');

    $baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
    $appName = (string)($config['app_name'] ?? 'CoCoS Manual');

    $subject = 'Your access code for ' . $appName;
    $magicLink = null;
    $bcc = trim((string)($config['access_gate_mail_bcc'] ?? ''));

    if ($magicToken !== null && $baseUrl !== '') {
        $magicLink = $baseUrl . '/access/magic-login?token=' . rawurlencode($magicToken);
    }

    $ttlMinutes = max(1, (int)($config['access_gate_code_ttl_minutes'] ?? 10));
    $sessionDays = max(1, (int)($config['access_gate_session_days'] ?? 7));

    $textBody = access_gate_build_text_email(
        $appName,
        $code,
        $magicLink,
        $ttlMinutes,
        $sessionDays
    );

    $htmlBody = access_gate_build_html_email(
        $appName,
        $code,
        $magicLink,
        $ttlMinutes,
        $sessionDays
    );

    return smtp_send_mail_html(
        $config,
        $from,
        $fromName,
        $email,
        $subject,
        $textBody,
        $htmlBody,
	$bcc
    );
}

/**
 * Build plain-text fallback email.
 *
 * @param string $appName
 * @param string $code
 * @param ?string $magicLink
 * @param int $ttlMinutes
 * @param int $sessionDays
 * @return string
 */
function access_gate_build_text_email(
    string $appName,
    string $code,
    ?string $magicLink,
    int $ttlMinutes,
    int $sessionDays
): string {
    $body = "Access to {$appName}\n\n";
    $body .= "Your access code is: {$code}\n\n";

    if ($magicLink !== null) {
        $body .= "Direct login link:\n";
        $body .= $magicLink . "\n\n";
    }

    $body .= "This code and link expire in {$ttlMinutes} minutes.\n";
    $body .= "After verification, access remains active for {$sessionDays} days on this browser.\n\n";
    $body .= "If you did not request this code, you can ignore this email.\n";

    return $body;
}

/**
 * Build nicely formatted HTML email.
 *
 * @param string $appName
 * @param string $code
 * @param ?string $magicLink
 * @param int $ttlMinutes
 * @param int $sessionDays
 * @return string
 */
function access_gate_build_html_email(
    string $appName,
    string $code,
    ?string $magicLink,
    int $ttlMinutes,
    int $sessionDays
): string {
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $safeMagicLink = $magicLink !== null
        ? htmlspecialchars($magicLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : null;

    $buttonHtml = '';

    if ($safeMagicLink !== null) {
        $buttonHtml = '
            <table role="presentation" cellspacing="0" cellpadding="0" style="margin: 18px 0 0;">
                <tr>
                    <td align="center" style="border-radius: 6px;">
                        <a href="' . $safeMagicLink . '" style="
                            display: inline-block;
			    margin-top: 8px;
                            padding: 14px 22px;
                            color: #0F3F5F;
                            text-decoration: none;
                            border-radius: 6px;
                            font-weight: 700;
                            font-size: 12px;
                            line-height: 1.2;
			    background: #FFFFF;
                            font-family: Segoe UI, Roboto, Helvetica, Arial, sans-serif;
                        ">Click here to login directly</a>
                    </td>
                </tr>
            </table>';
    }

    $linkFallback = '';

    if ($safeMagicLink !== null) {
        $linkFallback = '
            <p style="margin: 24px 0 0; font-size: 13px; line-height: 1.5;">
                If the button does not work, copy and paste this link into your browser:
            </p>
            <p style="
                margin: 8px 0 0;
                padding: 12px;
                border-radius: 6px;
                color: #344054;
                font-size: 12px;
                line-height: 1.5;
                word-break: break-all;
                overflow-wrap: anywhere;
            ">' . $safeMagicLink . '<br></p>';
    }

    return '<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <title>' . $safeAppName . ' access code</title>
</head>
<body style="margin: 0; padding: 0; background: #f4f6f8; font-family: Segoe UI, Roboto, Helvetica, Arial, sans-serif; color: #1f2933;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background: #f4f6f8; padding: 32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 620px; background: #ffffff; border: 1px solid #d9e2ec; border-radius: 10px; overflow: hidden;">
                    <tr>
                        <td style="background: #0f3f5f; padding: 22px 28px;">
                            <div style="color: #ffffff; font-size: 20px; font-weight: 700; letter-spacing: -0.02em;">
                                ' . $safeAppName . '
                            </div>
                            <div style="color: #b8d7e8; margin-top: 4px; font-size: 14px;">
                                Secure documentation access
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 30px 28px;">
                            <h1 style="margin: 0 0 12px; color: #111827; font-size: 24px; line-height: 1.2;">
                                Your access code
                            </h1>

                            <p style="margin: 0 0 24px; color: #667085; font-size: 15px; line-height: 1.6;">
                                Use the code below to continue, or <a href="' . $safeMagicLink . '">click here to login directly</a>.
                            </p>

                            <div style="
                                display: inline-block;
                                padding: 16px 22px;
                                background: #f8fafc;
                                border: 1px solid #c6d0dc;
                                border-radius: 6px;
                                color: #111827;
                                font-size: 30px;
                                line-height: 1;
                                font-weight: 800;
                                letter-spacing: 6px;
                                font-family: Consolas, Menlo, Monaco, monospace;
                            "><center>' . $safeCode . '</center></div>

                            <div style="
                                margin: 26px 0 18px;
                                padding: 14px 16px;
                                background: #e7f2f8;
                                border-left: 4px solid #2476a8;
                                border-radius: 6px;
                                color: #344054;
                                font-size: 14px;
                                line-height: 1.5;
                            ">
                                This code and link expire in <strong>' . $ttlMinutes . ' minutes</strong>.
                                After verification, access remains active for <strong>' . $sessionDays . ' days</strong> on this browser.
                            </div>
                            ' . $linkFallback . '

                            <p style="margin: 24px 0 0; color: #667085; font-size: 13px; line-height: 1.5;">
                                If you did not request this code, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding: 16px 28px; background: #f8fafc; border-top: 1px solid #d9e2ec; color: #667085; font-size: 12px;">
                            This is an automated message from ' . $safeAppName . '.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
}


/**
 * Send a multipart plain-text + HTML email via SMTP smarthost.
 *
 * @param array $config
 * @param string $from
 * @param string $fromName
 * @param string $to
 * @param string $subject
 * @param string $textBody
 * @param string $htmlBody
 * @return bool
 */
function smtp_send_mail_html(
    array $config,
    string $from,
    string $fromName,
    string $to,
    string $subject,
    string $textBody,
    string $htmlBody
): bool {
    $smtp = $config['smtp'] ?? [];

    $host = (string)($smtp['host'] ?? '');
    $port = (int)($smtp['port'] ?? 587);
    $encryption = strtolower((string)($smtp['encryption'] ?? 'tls'));
    $username = (string)($smtp['username'] ?? '');
    $password = (string)($smtp['password'] ?? '');
    $timeout = (int)($smtp['timeout'] ?? 20);

    if ($host === '') {
        error_log('SMTP host is not configured.');
        return false;
    }

    $remote = $host . ':' . $port;

    $context = stream_context_create([
        'ssl' => [
            'peer_name' => $host,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
        ],
    ]);

    if ($encryption === 'ssl') {
        $remote = 'ssl://' . $remote;
    }

    $socket = @stream_socket_client(
        $remote,
        $errno,
        $errstr,
        $timeout,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$socket) {
        error_log('SMTP connection failed: ' . $errstr . ' (' . $errno . ')');
        return false;
    }

    stream_set_timeout($socket, $timeout);

    try {
        smtp_expect($socket, [220], $config);

        smtp_command($socket, 'EHLO ' . smtp_local_hostname(), [250], $config);

        if ($encryption === 'tls') {
            smtp_command($socket, 'STARTTLS', [220], $config);

            $cryptoOk = @stream_socket_enable_crypto(
                $socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($cryptoOk !== true) {
                throw new RuntimeException('SMTP STARTTLS negotiation failed.');
            }

            smtp_command($socket, 'EHLO ' . smtp_local_hostname(), [250], $config);
        }

        if ($username !== '' || $password !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334], $config);
            smtp_command($socket, base64_encode($username), [334], $config, true);
            smtp_command($socket, base64_encode($password), [235], $config, true);
        }

        smtp_command($socket, 'MAIL FROM:<' . smtp_clean_email($from) . '>', [250], $config);
        smtp_command($socket, 'RCPT TO:<' . smtp_clean_email($to) . '>', [250, 251], $config);
        smtp_command($socket, 'DATA', [354], $config);

        $message = smtp_build_html_message(
            $from,
            $fromName,
            $to,
            $subject,
            $textBody,
            $htmlBody
        );

        fwrite($socket, $message . "\r\n.\r\n");
        smtp_expect($socket, [250], $config);

        smtp_command($socket, 'QUIT', [221], $config);

        fclose($socket);

        return true;
    } catch (Throwable $e) {
        error_log('SMTP HTML send failed: ' . $e->getMessage());

        @fwrite($socket, "QUIT\r\n");
        @fclose($socket);

        return false;
    }
}

/**
 * Build a multipart/alternative email message.
 *
 * @param string $from
 * @param string $fromName
 * @param string $to
 * @param string $subject
 * @param string $textBody
 * @param string $htmlBody
 * @return string
 */
function smtp_build_html_message(
    string $from,
    string $fromName,
    string $to,
    string $subject,
    string $textBody,
    string $htmlBody
): string {
    $encodedFromName = mb_encode_mimeheader($fromName, 'UTF-8');
    $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8');

    $boundary = 'cocos_manual_' . bin2hex(random_bytes(16));

    $textBody = smtp_normalize_body($textBody);
    $htmlBody = smtp_normalize_body($htmlBody);

    $headers = [];
    $headers[] = 'Date: ' . date('r');
    $headers[] = 'From: ' . $encodedFromName . ' <' . smtp_clean_email($from) . '>';
    $headers[] = 'To: <' . smtp_clean_email($to) . '>';
    $headers[] = 'Subject: ' . $encodedSubject;
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'Auto-Submitted: auto-generated';
    $headers[] = 'X-Mailer: CoCoS Manual Access Gate';

    $body = '';
    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";

    $body .= '--' . $boundary . "\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";

    $body .= '--' . $boundary . "--\r\n";

    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

/**
 * Read a response from the SMTP server.
 *
 * @param mixed $socket
 * @param array $config
 * @return array
 */
function smtp_read_response($socket, array $config): array
{
    $lines = [];

    while (($line = fgets($socket, 515)) !== false) {
        $line = rtrim($line, "\r\n");
        $lines[] = $line;

        smtp_debug($config, 'S: ' . $line);

        if (preg_match('/^[0-9]{3} /', $line)) {
            break;
        }
    }

    $lastLine = end($lines) ?: '';
    $code = (int)substr($lastLine, 0, 3);

    return [
        'code' => $code,
        'lines' => $lines,
    ];
}

/**
 * Expect one of the given SMTP response codes.
 *
 * @param mixed $socket
 * @param array $expectedCodes
 * @param array $config
 * @return array
 */
function smtp_expect($socket, array $expectedCodes, array $config): array
{
    $response = smtp_read_response($socket, $config);

    if (!in_array((int)$response['code'], $expectedCodes, true)) {
        throw new RuntimeException(
            'Unexpected SMTP response ' .
            (string)$response['code'] .
            ': ' .
            implode(' | ', $response['lines'])
        );
    }

    return $response;
}

/**
 * Send an SMTP command and expect response code.
 *
 * @param mixed $socket
 * @param string $command
 * @param array $expectedCodes
 * @param array $config
 * @param bool $hideCommand
 * @return array
 */
function smtp_command(
    $socket,
    string $command,
    array $expectedCodes,
    array $config,
    bool $hideCommand = false
): array {
    smtp_debug($config, 'C: ' . ($hideCommand ? '[hidden]' : $command));

    fwrite($socket, $command . "\r\n");

    return smtp_expect($socket, $expectedCodes, $config);
}

/**
 * Normalize and dot-stuff SMTP DATA body.
 *
 * @param string $body
 * @return string
 */
function smtp_normalize_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = preg_replace('/^\./m', '..', $body);

    return str_replace("\n", "\r\n", $body);
}

/**
 * Clean an email address for SMTP commands.
 *
 * @param string $email
 * @return string
 */
function smtp_clean_email(string $email): string
{
    $email = trim($email);
    $email = str_replace(["\r", "\n", '<', '>'], '', $email);

    return $email;
}

/**
 * Return a safe local hostname for EHLO.
 *
 * @return string
 */
function smtp_local_hostname(): string
{
    $hostname = gethostname();

    if (!$hostname) {
        return 'localhost';
    }

    $hostname = preg_replace('/[^a-zA-Z0-9.-]/', '', $hostname);

    return $hostname ?: 'localhost';
}

/**
 * Optional SMTP debug logging.
 *
 * @param array $config
 * @param string $line
 * @return void
 */
function smtp_debug(array $config, string $line): void
{
    $smtp = $config['smtp'] ?? [];

    if (empty($smtp['debug'])) {
        return;
    }

    $logFile = (string)($smtp['debug_log'] ?? '');

    if ($logFile === '') {
        error_log('[SMTP] ' . $line);
        return;
    }

    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL,
        FILE_APPEND
    );
}
