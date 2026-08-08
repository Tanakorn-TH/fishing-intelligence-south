<?php
declare(strict_types=1);

/**
 * ค่าเชื่อมต่อฐานข้อมูล
 *
 * ลำดับการอ่าน: environment variable ก่อน ถ้าไม่มีจึงอ่านจากไฟล์ .env
 *
 * ⚠️ ไฟล์ .env ต้องอยู่ "นอก" document root เสมอ
 * เซิร์ฟเวอร์นี้เป็น nginx อยู่หน้า Apache ซึ่ง nginx มักเสิร์ฟไฟล์สแตติกเองโดยไม่ผ่าน Apache
 * แปลว่า .htaccess อาจกันไฟล์ .env ไม่ได้ ถ้าวางไว้ใน document root จะมีคนเปิด
 * https://โดเมน/.env อ่านรหัสผ่านไปได้ตรง ๆ
 *
 * ตั้ง FIS_ENV_FILE ชี้ไปยังตำแหน่งจริงของ .env เช่น /home/user/config/fishing.env
 */

function fis_env_file(): string
{
    $override = getenv('FIS_ENV_FILE');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env';
}

function fis_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $keys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'];
    $values = [];

    foreach ($keys as $key) {
        $fromEnv = getenv($key);
        if (is_string($fromEnv) && $fromEnv !== '') {
            $values[$key] = $fromEnv;
        }
    }

    $file = fis_env_file();
    if (is_readable($file)) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = trim($parts[0]);
            // environment variable ชนะเสมอ ไม่ให้ไฟล์เขียนทับ
            if (!in_array($key, $keys, true) || isset($values[$key])) {
                continue;
            }
            $values[$key] = trim($parts[1]);
        }
    }

    $missing = [];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
        if (!isset($values[$key]) || $values[$key] === '') {
            $missing[] = $key;
        }
    }
    if ($missing !== []) {
        // ไม่บอกว่าอ่านไฟล์ไหนอยู่ กันไม่ให้ path หลุดออกไปหาผู้ใช้ผ่านข้อความ error
        throw new RuntimeException('ไม่พบค่าเชื่อมต่อฐานข้อมูล: ' . implode(', ', $missing));
    }

    if (!isset($values['DB_PORT']) || $values['DB_PORT'] === '') {
        $values['DB_PORT'] = '3306';
    }

    $config = $values;
    return $config;
}
