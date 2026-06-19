<?php

declare(strict_types=1);

/**
 * Public user helpers.
 *
 * Public users are different from admin users.
 * This table stores every visitor email address that ever requested
 * an access/login code or a download code.
 */

function public_user_normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

function public_user_ensure(
    PDO $pdo,
    string $email,
    string $source,
    ?string $ipAddress = null,
    ?string $userAgent = null
): void {
    $email = public_user_normalize_email($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $source = mb_substr(trim($source), 0, 64);

    $loginRequestedAtSql = $source === 'login_code_requested'
        ? 'NOW()'
        : 'NULL';

    $downloadRequestedAtSql = $source === 'download_code_requested'
        ? 'NOW()'
        : 'NULL';

    $stmt = $pdo->prepare("
        INSERT INTO public_users (
            email,
            first_login_code_requested_at,
            last_login_code_requested_at,
            first_download_code_requested_at,
            last_download_code_requested_at,
            last_seen_at,
            last_ip_address,
            last_user_agent,
            created_at,
            updated_at
        ) VALUES (
            :email,
            {$loginRequestedAtSql},
            {$loginRequestedAtSql},
            {$downloadRequestedAtSql},
            {$downloadRequestedAtSql},
            NOW(),
            :last_ip_address,
            :last_user_agent,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            last_login_code_requested_at = CASE
                WHEN :source_login = 1 THEN NOW()
                ELSE last_login_code_requested_at
            END,
            first_login_code_requested_at = CASE
                WHEN :source_login_first = 1
                 AND first_login_code_requested_at IS NULL THEN NOW()
                ELSE first_login_code_requested_at
            END,
            last_download_code_requested_at = CASE
                WHEN :source_download = 1 THEN NOW()
                ELSE last_download_code_requested_at
            END,
            first_download_code_requested_at = CASE
                WHEN :source_download_first = 1
                 AND first_download_code_requested_at IS NULL THEN NOW()
                ELSE first_download_code_requested_at
            END,
            last_seen_at = NOW(),
            last_ip_address = :last_ip_address_update,
            last_user_agent = :last_user_agent_update,
            updated_at = NOW()
    ");

    $isLogin = $source === 'login_code_requested' ? 1 : 0;
    $isDownload = $source === 'download_code_requested' ? 1 : 0;

    $stmt->execute([
        'email' => $email,
        'last_ip_address' => $ipAddress !== null ? mb_substr($ipAddress, 0, 64) : null,
        'last_user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,

        'source_login' => $isLogin,
        'source_login_first' => $isLogin,
        'source_download' => $isDownload,
        'source_download_first' => $isDownload,

        'last_ip_address_update' => $ipAddress !== null ? mb_substr($ipAddress, 0, 64) : null,
        'last_user_agent_update' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
    ]);
}

function public_user_get_by_email(PDO $pdo, string $email): ?array
{
    $email = public_user_normalize_email($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT *
        FROM public_users
        WHERE email = :email
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function public_user_is_enabled(PDO $pdo, string $email): bool
{
    $email = public_user_normalize_email($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT is_enabled
        FROM public_users
        WHERE email = :email
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
    ]);

    $row = $stmt->fetch();

    /*
     * New users are allowed.
     *
     * If the email address does not exist yet, it should be allowed to request
     * a first code. public_user_ensure() will create the public user record.
     */
    if (!$row) {
        return true;
    }

    return (int)($row['is_enabled'] ?? 0) === 1;
}

function public_user_update_profile(
    PDO $pdo,
    string $email,
    string $firstName,
    string $lastName,
    string $phone
): void {
    $email = public_user_normalize_email($email);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE public_users
        SET
            first_name = :first_name,
            last_name = :last_name,
            phone = :phone,
            profile_updated_at = NOW(),
            updated_at = NOW()
        WHERE email = :email
    ");

    $stmt->execute([
        'email' => $email,
        'first_name' => mb_substr(trim($firstName), 0, 120),
        'last_name' => mb_substr(trim($lastName), 0, 120),
        'phone' => mb_substr(trim($phone), 0, 80),
    ]);
}
