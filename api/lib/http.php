<?php
declare(strict_types=1);

function fis_json($payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fis_fail(string $message, int $status = 400, string $code = 'bad_request'): void
{
    fis_json(['error' => ['code' => $code, 'message' => $message]], $status);
    exit;
}

function fis_require_get(): void
{
    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
    if ($method !== 'GET') {
        header('Allow: GET');
        fis_fail('endpoint นี้รองรับเฉพาะ GET', 405, 'method_not_allowed');
    }
}

/**
 * ครอบ handler ไว้ ไม่ให้ข้อความ exception หลุดออกไปหาผู้ใช้
 * รายละเอียดจริงลง error log ของเซิร์ฟเวอร์เท่านั้น เพราะอาจมี path หรือค่าเชื่อมต่อปนอยู่
 */
function fis_handle(callable $handler): void
{
    try {
        $handler();
    } catch (Throwable $e) {
        error_log('[fishing-api] ' . get_class($e) . ': ' . $e->getMessage());
        fis_json([
            'error' => ['code' => 'server_error', 'message' => 'เกิดข้อผิดพลาดภายในระบบ'],
        ], 500);
    }
}
