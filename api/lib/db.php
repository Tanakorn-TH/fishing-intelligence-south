<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function fis_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // phpMyAdmin บนเซิร์ฟเวอร์แสดง extension ที่ตัวมันใช้ว่ามีแค่ mysqli
    // ถ้า pdo_mysql ไม่ได้ติดตั้งจริง ให้ล้มพร้อมข้อความที่บอกวิธีแก้ ไม่ใช่ fatal error ดิบ ๆ
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException(
            'ไม่พบ PHP extension pdo_mysql — ติดตั้งด้วย: sudo apt install php8.4-mysql'
        );
    }

    $config = fis_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $config['DB_HOST'],
        $config['DB_PORT'],
        $config['DB_NAME']
    );

    $pdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        // ใช้ prepared statement จริงของ MySQL ไม่ใช่การประกอบสตริงฝั่ง PHP
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
