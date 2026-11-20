<?php
// Database connection. Every PHP page does: require_once __DIR__ . '/includes/db.php';
// XAMPP defaults: user "root", empty password. On InfinityFree, use the values from your hosting panel.

const DB_HOST = 'localhost';
const DB_NAME = 'yiouyi';
const DB_USER = 'root';
const DB_PASS = '';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",   // Philippine time everywhere
                ]
            );
        } catch (PDOException $e) {
            http_response_code(500);
            exit('Database connection failed. Check includes/db.php and make sure MySQL is running.');
        }
    }
    return $pdo;
}
