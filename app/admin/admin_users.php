<?php

declare(strict_types=1);

/**
 * Admin-user helper functions.
 *
 * Admin users from the database overrule app/config.php for the same email.
 */

/**
 * Normalize an email address.
 *
 * @param string $email
 * @return string
 */
function admin_user_normalize_email(string $email): string
{
    return mb_strtolower(trim($email));
}

/**
 * Normalize a list of IP addresses from textarea input.
 *
 * @param string $text
 * @return array
 */
function admin_user_normalize_ip_list(string $text): array
{
    $items = preg_split('/[\r\n,; ]+/', $text) ?: [];
    $clean = [];

    foreach ($items as $item) {
        $ip = trim((string)$item);

        if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) {
            $clean[] = $ip;
        }
    }

    return array_values(array_unique($clean));
}

/**
 * Return all database admin users.
 *
 * @param PDO $pdo
 * @return array
 */
function admin_users_all(PDO $pdo): array
{
    $stmt = $pdo->query("
        SELECT
            id,
            email,
            ip_addresses,
            is_enabled,
            notes,
            created_at,
            updated_at,
            updated_by_email,
            updated_by_ip
        FROM public_admin_users
        ORDER BY email ASC
    ");

    return $stmt->fetchAll();
}

/**
 * Return a DB admin user by email.
 *
 * @param PDO $pdo
 * @param string $email
 * @return ?array
 */
function admin_user_by_email(PDO $pdo, string $email): ?array
{
    $email = admin_user_normalize_email($email);

    if ($email === '') {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT
            id,
            email,
            ip_addresses,
            is_enabled,
            notes,
            created_at,
            updated_at,
            updated_by_email,
            updated_by_ip
        FROM public_admin_users
        WHERE email = :email
        LIMIT 1
    ");

    $stmt->execute([
        'email' => $email,
    ]);

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Return the allowed IPs for a DB admin user.
 *
 * @param array $user
 * @return array
 */
function admin_user_ip_addresses(array $user): array
{
    return admin_user_normalize_ip_list((string)($user['ip_addresses'] ?? ''));
}

/**
 * Return true when the current verified user has a database admin-user record.
 *
 * If a DB record exists for the email, it overrules app/config.php.
 *
 * @param PDO $pdo
 * @param string $email
 * @param string $clientIp
 * @return ?bool Returns true/false when DB user exists, null when DB user does not exist.
 */
function admin_user_db_access_decision(PDO $pdo, string $email, string $clientIp): ?bool
{
    $user = admin_user_by_email($pdo, $email);

    if (!$user) {
        return null;
    }

    if ((int)($user['is_enabled'] ?? 0) !== 1) {
        return false;
    }

    $ips = admin_user_ip_addresses($user);

    if (!$ips) {
        return false;
    }

    return in_array($clientIp, $ips, true);
}

/**
 * Save or update a DB admin user.
 *
 * @param PDO $pdo
 * @param string $email
 * @param array $ips
 * @param bool $enabled
 * @param string $notes
 * @param string $updatedByEmail
 * @param string $updatedByIp
 * @return void
 */
function admin_user_save(
    PDO $pdo,
    string $email,
    array $ips,
    bool $enabled,
    string $notes,
    string $updatedByEmail,
    string $updatedByIp
): void {
    $email = admin_user_normalize_email($email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $ipText = implode("\n", array_values(array_unique($ips)));

    $stmt = $pdo->prepare("
        INSERT INTO public_admin_users (
            email,
            ip_addresses,
            is_enabled,
            notes,
            created_at,
            updated_at,
            updated_by_email,
            updated_by_ip
        ) VALUES (
            :email,
            :ip_addresses,
            :is_enabled,
            :notes,
            NOW(),
            NOW(),
            :updated_by_email,
            :updated_by_ip
        )
        ON DUPLICATE KEY UPDATE
            ip_addresses = VALUES(ip_addresses),
            is_enabled = VALUES(is_enabled),
            notes = VALUES(notes),
            updated_at = NOW(),
            updated_by_email = VALUES(updated_by_email),
            updated_by_ip = VALUES(updated_by_ip)
    ");

    $stmt->execute([
        'email' => $email,
        'ip_addresses' => $ipText,
        'is_enabled' => $enabled ? 1 : 0,
        'notes' => mb_substr($notes, 0, 255),
        'updated_by_email' => $updatedByEmail,
        'updated_by_ip' => $updatedByIp,
    ]);
}

/**
 * Delete a DB admin user.
 *
 * @param PDO $pdo
 * @param string $email
 * @return void
 */
function admin_user_delete(PDO $pdo, string $email): void
{
    $email = admin_user_normalize_email($email);

    if ($email === '') {
        return;
    }

    $stmt = $pdo->prepare("
        DELETE FROM public_admin_users
        WHERE email = :email
    ");

    $stmt->execute([
        'email' => $email,
    ]);
}
