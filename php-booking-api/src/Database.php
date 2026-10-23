<?php
declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    /** DSN examples: "sqlite::memory:", "mysql:host=db;dbname=booking;charset=utf8mb4" */
    public static function connect(string $dsn, ?string $user = null, ?string $pass = null): PDO
    {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    /** Portable schema (works on SQLite and MySQL). */
    public static function migrate(PDO $pdo): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $isMysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            id $pk,
            name VARCHAR(100) NOT NULL UNIQUE,
            capacity INT NOT NULL
        )");
        $pdo->exec("CREATE TABLE IF NOT EXISTS bookings (
            id $pk,
            room_id INT NOT NULL,
            guest VARCHAR(100) NOT NULL,
            starts_at VARCHAR(19) NOT NULL,
            ends_at VARCHAR(19) NOT NULL,
            FOREIGN KEY (room_id) REFERENCES rooms(id)
        )");
        // Index only needed for the overlap query; ignore "already exists" on re-run.
        try {
            $pdo->exec('CREATE INDEX idx_bookings_room_time ON bookings(room_id, starts_at, ends_at)');
        } catch (\PDOException) {
        }
    }
}
