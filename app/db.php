<?php

declare(strict_types=1);

/**
 * Create a PDO connection for the public documentation database.
 *
 * Supports both:
 *
 * New config format:
 *
 * 'public_db' => [
 *     'host' => '127.0.0.1',
 *     'port' => 3306,
 *     'database' => 'bookstack_public',
 *     'username' => 'user',
 *     'password' => 'pass',
 *     'charset' => 'utf8mb4',
 * ],
 *
 * And old config format:
 *
 * 'public_db' => [
 *     'dsn' => 'mysql:host=127.0.0.1;dbname=bookstack_public;charset=utf8mb4',
 *     'user' => 'user',
 *     'pass' => 'pass',
 * ],
 *
 * @param array $config
 * @return PDO
 */
function db(array $config): PDO
{
    /**
     * Some files call db($config), others may call db($config['public_db']).
     * This makes both styles work.
     */
    $db = $config['public_db'] ?? $config;

    if (!is_array($db)) {
        throw new RuntimeException('Invalid database configuration.');
    }

    /**
     * Backwards compatibility with the old dsn/user/pass format.
     */
    if (!empty($db['dsn'])) {
        $dsn = (string)$db['dsn'];
        $username = (string)($db['user'] ?? $db['username'] ?? '');
        $password = (string)($db['pass'] ?? $db['password'] ?? '');

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * New host/port/database format.
     */
    $host = (string)($db['host'] ?? '127.0.0.1');
    $port = (int)($db['port'] ?? 3306);
    $database = (string)($db['database'] ?? '');
    $username = (string)($db['username'] ?? $db['user'] ?? '');
    $password = (string)($db['password'] ?? $db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    if ($database === '') {
        throw new RuntimeException('Missing public database name in config.php.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Generic database connector.
 *
 * Used by sync-bookstack.php for multiple BookStack source databases.
 *
 * @param array $db
 * @return PDO
 */
function db_connect_from_config(array $db): PDO
{
    $host = (string)($db['host'] ?? '127.0.0.1');
    $port = (int)($db['port'] ?? 3306);
    $database = (string)($db['database'] ?? '');
    $username = (string)($db['username'] ?? $db['user'] ?? '');
    $password = (string)($db['password'] ?? $db['pass'] ?? '');
    $charset = (string)($db['charset'] ?? 'utf8mb4');

    if ($database === '') {
        throw new RuntimeException('Missing database name in database config.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
