<?php
declare(strict_types=1);

/**
 * แคชไฟล์อย่างง่ายสำหรับข้อมูลที่ดึงมาจากภายนอก
 *
 * เหตุผลที่ต้องมี: Open-Meteo ให้ใช้ฟรีก็จริง แต่ข้อมูลพยากรณ์เปลี่ยนเป็นรายชั่วโมง
 * การยิงใหม่ทุกครั้งที่มีคนเปิดหน้าจึงเสียเปล่า ทั้งช้าสำหรับผู้ใช้และเสี่ยงถูกจำกัดอัตราการเรียก
 *
 * เหตุผลที่เลือกเป็นไฟล์: โฮสต์ที่ใช้เป็น shared hosting ไม่มี APCu ไม่มี Redis
 * และโปรเจคนี้ห้ามมี composer ไฟล์จึงเป็นทางเดียวที่พึ่งได้
 *
 * ทุกฟังก์ชันในไฟล์นี้ "ห้ามทำให้คำขอล้ม" แคชเสียแปลว่าช้าลง ไม่ใช่เว็บพัง
 * จึงกลืน error ไว้เองแล้วบันทึกลง error_log แทน
 */

/**
 * หาที่เก็บแคชที่เขียนได้จริง
 *
 * ลำดับความสำคัญ: ค่าที่ผู้ดูแลตั้งเอง > โฟลเดอร์ชั่วคราวของระบบ > ในโปรเจค
 * เอาโฟลเดอร์ชั่วคราวมาก่อนในโปรเจคเพราะมันอยู่นอก document root โดยธรรมชาติ
 * จึงไม่มีทางถูกเปิดอ่านผ่านเว็บตั้งแต่แรก ส่วนตัวเลือกสุดท้ายในโปรเจคเป็นตาข่ายรองรับ
 * เผื่อโฮสต์ล็อก open_basedir ไว้จนแตะโฟลเดอร์ชั่วคราวไม่ได้
 *
 * @return string|null null แปลว่าเขียนแคชไม่ได้เลย ผู้เรียกต้องทำงานต่อได้โดยไม่มีแคช
 */
function fis_cache_dir(): ?string
{
    static $resolved = false;
    static $dir = null;

    if ($resolved) {
        return $dir;
    }
    $resolved = true;

    $candidates = [];

    $override = getenv('FIS_CACHE_DIR');
    if (is_string($override) && $override !== '') {
        $candidates[] = rtrim($override, "/\\");
    }

    $tmp = sys_get_temp_dir();
    if (is_string($tmp) && $tmp !== '') {
        $candidates[] = rtrim($tmp, "/\\") . DIRECTORY_SEPARATOR . 'fis-cache';
    }

    $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cache';

    // ทุกฟังก์ชันไฟล์ในลูปนี้ใส่ @ ไว้ทั้งหมด ไม่ใช่เพื่อความสวย
    // แต่เพราะโฮสต์ที่ตั้ง open_basedir ไว้จะโยน warning ที่มี path เต็มออกมา
    // ถ้า display_errors เปิดอยู่ warning นั้นจะไปโผล่กลางคำตอบ JSON ทั้งพัง JSON และรั่ว path
    foreach ($candidates as $candidate) {
        if (!@is_dir($candidate)) {
            // 0700 เพราะบน shared hosting มีผู้ใช้อื่นอยู่บนเครื่องเดียวกัน
            if (!@mkdir($candidate, 0700, true) && !@is_dir($candidate)) {
                continue;
            }
        }
        if (!@is_writable($candidate)) {
            continue;
        }

        fis_cache_seal($candidate);
        $dir = $candidate;
        return $dir;
    }

    error_log('[fishing-api/cache] ไม่พบตำแหน่งที่เขียนแคชได้ จะทำงานต่อโดยไม่ใช้แคช');
    return null;
}

/**
 * ปิดไม่ให้ไดเรกทอรีแคชถูกเปิดอ่านผ่านเว็บ
 *
 * จำเป็นเพราะตัวเลือกสำรองอยู่ในโปรเจค ซึ่งอาจตกอยู่ใต้ document root
 * ใส่ทั้ง .htaccess (กันการอ่านไฟล์) และ index.html เปล่า (กันการไล่ดูรายชื่อไฟล์
 * เผื่อเจอเซิร์ฟเวอร์ที่ไม่อ่าน .htaccess เช่นตัวที่ AllowOverride None)
 */
function fis_cache_seal(string $dir): void
{
    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!@file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# ไฟล์แคชภายใน ไม่ใช่ของสาธารณะ\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }

    $index = $dir . DIRECTORY_SEPARATOR . 'index.html';
    if (!@file_exists($index)) {
        @file_put_contents($index, '');
    }
}

/** ชื่อไฟล์มาจาก hash เสมอ กันไม่ให้คีย์ที่มีอักขระแปลก ๆ หลุดไปเป็น path */
function fis_cache_path(string $key): ?string
{
    $dir = fis_cache_dir();
    if ($dir === null) {
        return null;
    }
    return $dir . DIRECTORY_SEPARATOR . 'fis-' . sha1($key) . '.json';
}

/**
 * อ่านค่าจากแคชถ้ายังไม่หมดอายุ
 *
 * @return array<string, mixed>|null null = ไม่มีของ หรือของหมดอายุแล้ว
 */
function fis_cache_get(string $key, int $ttlSeconds): ?array
{
    $path = fis_cache_path($key);
    if ($path === null || !@is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $entry = json_decode($raw, true);
    if (!is_array($entry) || !isset($entry['stored_at']) || !is_array($entry['payload'] ?? null)) {
        // ไฟล์เสีย (เช่นถูกเขียนค้างไว้) ทิ้งแล้วดึงใหม่ ดีกว่าคืนของครึ่ง ๆ กลาง ๆ ให้ผู้ใช้
        @unlink($path);
        return null;
    }

    // เทียบเวลาที่บันทึกไว้ในไฟล์ ไม่ใช่ filemtime เพราะการ deploy หรือ rsync ทำให้ mtime เพี้ยนได้
    if ((time() - (int) $entry['stored_at']) >= $ttlSeconds) {
        return null;
    }

    return $entry['payload'];
}

/**
 * เขียนแคช คืน false เมื่อเขียนไม่ได้ ซึ่งไม่ถือว่าเป็นความผิดพลาดร้ายแรง
 *
 * @param array<string, mixed> $payload
 */
function fis_cache_put(string $key, array $payload): bool
{
    $path = fis_cache_path($key);
    if ($path === null) {
        return false;
    }

    $encoded = json_encode(
        ['stored_at' => time(), 'payload' => $payload],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    if (!is_string($encoded)) {
        return false;
    }

    // เขียนลงไฟล์ชั่วคราวก่อนแล้วค่อย rename เพื่อไม่ให้คำขออีกเส้นอ่านเจอไฟล์ที่เขียนค้างอยู่
    $tmp = $path . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $encoded, LOCK_EX) === false) {
        error_log('[fishing-api/cache] เขียนไฟล์แคชไม่สำเร็จ');
        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        error_log('[fishing-api/cache] ย้ายไฟล์แคชเข้าที่ไม่สำเร็จ');
        return false;
    }

    @chmod($path, 0600);
    return true;
}
