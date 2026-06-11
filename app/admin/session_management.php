<?php

declare(strict_types=1);

/**
 * Admin session-management helpers.
 */

/**
 * Get the current PHP session id.
 *
 * @return string
 */
function admin_current_session_id(): string
{
    return session_id() ?: '';
}

/**
 * Check whether an IP address is blocked.
 *
 * @param PDO $pdo
 * @param string $ip
 * @return bool
 */
function admin_ip_is_blocked(PDO $pdo, string $ip): bool
{
    if ($ip === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT id
        FROM public_access_ip_blocks
        WHERE ip_address = :ip_address
          AND (expires_at IS NULL OR expires_at > NOW())
        LIMIT 1
    ");

    $stmt->execute([
        'ip_address' => $ip,
    ]);

    return (bool)$stmt->fetch();
}

/**
 * Register or update the current verified session.
 *
 * @param PDO $pdo
 * @param array $config
 * @return void
 */
function admin_track_current_session(PDO $pdo, array $config): void
{
    if (
        !function_exists('access_gate_is_verified') ||
        !access_gate_is_verified($config)
    ) {
        return;
    }

    $sessionId = admin_current_session_id();

    if ($sessionId === '') {
        return;
    }

    $email = trim((string)($_SESSION['manual_access_email'] ?? ''));

    if ($email === '') {
        return;
    }

    $ip = function_exists('access_gate_ip_address')
        ? access_gate_ip_address()
        : (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $userAgent = function_exists('access_gate_user_agent')
        ? access_gate_user_agent()
        : (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $verifiedUntil = (int)($_SESSION['manual_access_verified_until'] ?? 0);

    $stmt = $pdo->prepare("
        INSERT INTO public_access_sessions (
            session_id,
            email,
            ip_address,
            user_agent,
            verified_until,
            last_seen_at,
            created_at
        ) VALUES (
            :session_id,
            :email,
            :ip_address,
            :user_agent,
            :verified_until,
            NOW(),
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            email = VALUES(email),
            ip_address = VALUES(ip_address),
            user_agent = VALUES(user_agent),
            verified_until = VALUES(verified_until),
            last_seen_at = NOW()
    ");

    $stmt->execute([
        'session_id' => $sessionId,
        'email' => $email,
        'ip_address' => $ip,
        'user_agent' => mb_substr($userAgent, 0, 2000),
        'verified_until' => $verifiedUntil,
    ]);
}

/**
 * Revoke one session.
 *
 * @param PDO $pdo
 * @param string $sessionId
 * @param string $adminEmail
 * @param string $adminIp
 * @return void
 */
function admin_revoke_session(PDO $pdo, string $sessionId, string $adminEmail, string $adminIp): void
{
    if ($sessionId === '') {
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE public_access_sessions
        SET
            revoked_at = NOW(),
            revoked_by_email = :revoked_by_email,
            revoked_by_ip = :revoked_by_ip
        WHERE session_id = :session_id
    ");

    $stmt->execute([
        'session_id' => $sessionId,
        'revoked_by_email' => $adminEmail,
        'revoked_by_ip' => $adminIp,
    ]);
}

/**
 * Block an IP address.
 *
 * @param PDO $pdo
 * @param string $ip
 * @param string $reason
 * @param string $adminEmail
 * @param string $adminIp
 * @return void
 */
function admin_block_ip(PDO $pdo, string $ip, string $reason, string $adminEmail, string $adminIp): void
{
    if ($ip === '') {
        return;
    }

    $stmt = $pdo->prepare("
        INSERT INTO public_access_ip_blocks (
            ip_address,
            reason,
            blocked_by_email,
            blocked_by_ip,
            blocked_at
        ) VALUES (
            :ip_address,
            :reason,
            :blocked_by_email,
            :blocked_by_ip,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            reason = VALUES(reason),
            blocked_by_email = VALUES(blocked_by_email),
            blocked_by_ip = VALUES(blocked_by_ip),
            blocked_at = NOW(),
            expires_at = NULL
    ");

    $stmt->execute([
        'ip_address' => $ip,
        'reason' => mb_substr($reason, 0, 255),
        'blocked_by_email' => $adminEmail,
        'blocked_by_ip' => $adminIp,
    ]);
}

/**
 * Check whether the current session has been revoked.
 *
 * @param PDO $pdo
 * @return bool
 */
function admin_current_session_is_revoked(PDO $pdo): bool
{
    $sessionId = admin_current_session_id();

    if ($sessionId === '') {
        return false;
    }

    $stmt = $pdo->prepare("
        SELECT revoked_at
        FROM public_access_sessions
        WHERE session_id = :session_id
        LIMIT 1
    ");

    $stmt->execute([
        'session_id' => $sessionId,
    ]);

    $row = $stmt->fetch();

    return $row && !empty($row['revoked_at']);
}

/**
 * Remove one revoked session from the session registry.
 *
 * This only removes sessions that are already revoked.
 *
 * @param PDO $pdo
 * @param string $sessionId
 * @return void
 */
function admin_delete_revoked_session(PDO $pdo, string $sessionId): void
{
    if ($sessionId === '') {
        return;
    }

    $stmt = $pdo->prepare("
        DELETE FROM public_access_sessions
        WHERE session_id = :session_id
          AND revoked_at IS NOT NULL
    ");

    $stmt->execute([
        'session_id' => $sessionId,
    ]);
}
