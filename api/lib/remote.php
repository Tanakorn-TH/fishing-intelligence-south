<?php
declare(strict_types=1);

/**
 * ตัวเรียกข้อมูล JSON จากบริการภายนอก
 *
 * แยกออกมาเป็นไฟล์กลางเพราะทุก endpoint ที่ออกไปข้างนอกต้องอยู่ใต้กติกาเดียวกัน
 * คือ timeout สั้น ไม่ตาม redirect และไม่ปล่อยข้อความจากปลายทางออกไปหาผู้ใช้
 * ถ้าปล่อยให้แต่ละไฟล์เขียนเอง สักวันจะมีไฟล์หนึ่งลืมตั้ง timeout แล้วทั้งเว็บค้างตามปลายทาง
 */

/**
 * แยก "แหล่งข้อมูลภายนอกล้ม" ออกจาก "โค้ดเราพัง" ให้ชัด
 * ผู้เรียกจะได้ตัดสินใจตอบ 502 แทนที่จะถูก fis_handle จับไปตอบ 500
 * ซึ่งจะชี้นิ้วผิดเวลามีคนมาไล่ปัญหา
 */
class FisRemoteException extends RuntimeException
{
}

/**
 * เพดาน timeout ของทั้งระบบ
 * ผู้ใช้เปิดหน้าเว็บกลางทะเลด้วยสัญญาณมือถือ รอเกินนี้คือแพ้อยู่ดี
 * สู้ตอบว่าดึงข้อมูลไม่ได้ตรง ๆ ยังดีกว่าปล่อยให้ค้าง
 */
const FIS_REMOTE_MAX_TIMEOUT = 8;

/**
 * @param string $url        URL ที่ประกอบขึ้นภายในระบบเท่านั้น ห้ามรับจากผู้ใช้ตรง ๆ
 * @param int    $timeoutSeconds ถูกบีบให้ไม่เกิน FIS_REMOTE_MAX_TIMEOUT เสมอ
 * @return array<string, mixed>
 * @throws FisRemoteException เมื่อปลายทางช้า ล้ม หรือส่งอะไรที่ไม่ใช่ JSON กลับมา
 */
function fis_remote_get_json(string $url, int $timeoutSeconds = FIS_REMOTE_MAX_TIMEOUT): array
{
    $timeout = max(1, min(FIS_REMOTE_MAX_TIMEOUT, $timeoutSeconds));

    if (function_exists('curl_init')) {
        return fis_remote_decode(fis_remote_via_curl($url, $timeout), $url);
    }

    return fis_remote_decode(fis_remote_via_stream($url, $timeout), $url);
}

/**
 * @return array{status:int, body:string}
 */
function fis_remote_via_curl(string $url, int $timeout): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new FisRemoteException('curl_init ล้มเหลว');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        // กันกรณี DNS หรือ TCP ค้าง ให้ยอมแพ้ก่อนถึงเพดานรวม จะได้เหลือเวลาให้เรียกอย่างอื่นต่อ
        CURLOPT_CONNECTTIMEOUT => max(1, (int) ceil($timeout / 2)),
        // ปลายทางที่เราเรียกไม่มี redirect อยู่แล้ว การตามไปจึงมีแต่ความเสี่ยงถูกพาไปที่อื่น
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'fishing-intelligence-south/1.0 (+PHP)',
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0 || !is_string($body)) {
        throw new FisRemoteException("curl error {$errno}: {$error}");
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * ทางสำรองสำหรับโฮสต์ที่ปิด ext-curl ไว้ (เจอได้บ่อยใน shared hosting)
 *
 * @return array{status:int, body:string}
 */
function fis_remote_via_stream(string $url, int $timeout): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            // อ่าน body ของ 4xx/5xx ให้ได้ด้วย จะได้บันทึกลง log ว่าปลายทางบ่นอะไร
            'ignore_errors' => true,
            'follow_location' => 0,
            'header' => "Accept: application/json\r\nUser-Agent: fishing-intelligence-south/1.0 (+PHP)\r\n",
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
    }

    if (!is_string($body)) {
        throw new FisRemoteException('file_get_contents ล้มเหลวหรือหมดเวลา');
    }

    return ['status' => $status, 'body' => $body];
}

/**
 * @param array{status:int, body:string} $response
 * @return array<string, mixed>
 */
function fis_remote_decode(array $response, string $url): array
{
    $status = $response['status'];
    if ($status < 200 || $status >= 300) {
        // ตัด body ให้สั้นก่อนลง log เพราะปลายทางบางเจ้าตอบ HTML ยาวเป็นหน้า
        throw new FisRemoteException(
            'ปลายทางตอบ HTTP ' . $status . ' (' . fis_remote_host($url) . '): '
            . substr(trim($response['body']), 0, 200)
        );
    }

    $decoded = json_decode($response['body'], true);
    if (!is_array($decoded)) {
        throw new FisRemoteException('ปลายทาง (' . fis_remote_host($url) . ') ไม่ได้ตอบเป็น JSON object');
    }

    return $decoded;
}

/** เอาเฉพาะ host ลง log พอให้รู้ว่าใครล้ม โดยไม่ลาก query string ที่มีพิกัดผู้ใช้ไปด้วย */
function fis_remote_host(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? $host : 'unknown-host';
}
