<?php
// db.php — Pomocnik połączenia z bazą danych

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $d = $cfg['db'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $d['host'], $d['port'], $d['database'], $d['charset']
    );

    try {
        $pdo = new PDO($dsn, $d['username'], $d['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$d['charset']} COLLATE {$d['charset']}_unicode_ci, sql_mode='STRICT_ALL_TABLES'",
        ]);
    } catch (PDOException $e) {
        throw new RuntimeException('DB connection failed: ' . $e->getMessage());
    }

    return $pdo;
}

function db_config(): array {
    return require __DIR__ . '/config.php';
}
