<?php
declare(strict_types=1);

function get_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $cfg = require __DIR__ . '/config.php';
        $dsn = "mysql:host={$cfg['db_host']};dbname={$cfg['db_name']};charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        migrate_bot_columns($pdo);
    }
    return $pdo;
}

/** Databases created before computer players existed lack these columns. Idempotent. */
function migrate_bot_columns(PDO $pdo): void {
    if (!$pdo->query("SHOW COLUMNS FROM players LIKE 'is_bot'")->fetch()) {
        $pdo->exec("ALTER TABLE players ADD COLUMN is_bot TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN bot_difficulty VARCHAR(10) NULL");
    }
    if (!$pdo->query("SHOW COLUMNS FROM rooms LIKE 'bot_next_at'")->fetch()) {
        $pdo->exec("ALTER TABLE rooms ADD COLUMN bot_next_at DATETIME NULL");
    }
}
