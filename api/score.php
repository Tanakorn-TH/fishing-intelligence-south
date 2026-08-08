<?php
declare(strict_types=1);

/**
 * GET /api/score.php?lat=6.87&lon=101.25&date=2026-08-08
 *
 * Fishing Score — คะแนนความน่าออกทะเลของวันนั้น แยกตามประเภทงานตกปลา
 *
 * ⚠️ อ่าน docs/fishing-score.md ก่อนแก้เรื่องสูตร
 * ตรรกะการคิดคะแนนอยู่ใน lib/scoring.php เพราะ /api/outlook.php ใช้ชุดเดียวกัน
 *
 * สิ่งที่คะแนนนี้เป็น: เครื่องมือช่วยตัดสินใจว่าวันนี้น่าออกไหมและเหมาะกับงานแบบไหน
 * สิ่งที่คะแนนนี้ไม่ใช่: คำพยากรณ์ว่าจะได้ปลา และไม่ได้มาจากสถิติการจับปลาจริง
 */

require_once __DIR__ . '/lib/scoring.php';

fis_handle(function (): void {
    fis_require_get();

    $lat = fis_conditions_coord('lat', -90.0, 90.0);
    $lon = fis_conditions_coord('lon', -180.0, 180.0);

    $tz = new DateTimeZone(FIS_SCORE_TZ);
    // ใช้ตัวตรวจวันที่ชุดเดียวกับ tides เพราะคะแนนต้องพึ่งข้อมูลน้ำ
    // ขอบเขตจึงถูกจำกัดด้วยระยะพยากรณ์ของแบบจำลองน้ำอยู่ดี
    $date = fis_tides_date($tz);

    try {
        $weather = fis_weather_payload($lat, $lon);
        $tides = fis_tides_payload($lat, $lon, $date);
    } catch (FisTidesNoDataException $e) {
        fis_tides_fail_no_data($date, $tz);
        return;
    } catch (FisRemoteException $e) {
        if (fis_tides_looks_like_no_data($e->getMessage())) {
            fis_tides_fail_no_data($date, $tz);
            return;
        }
        error_log('[fishing-api/score] ' . $e->getMessage());
        fis_fail(
            'ขณะนี้ดึงข้อมูลสภาพอากาศหรือระดับน้ำไม่ได้ จึงยังคิดคะแนนให้ไม่ได้ กรุณาลองใหม่อีกครั้งในอีกสักครู่',
            502,
            'upstream_unavailable'
        );
        return;
    }

    $dayStart = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $date . ' 00:00:00',
        new DateTimeZone(FIS_SOLUNAR_TZ)
    );
    $solunar = fis_solunar_data($lat, $lon, $date, $dayStart);

    // เวลาที่ใช้ประเมิน: ถ้าเป็นวันนี้ใช้ตอนนี้ ถ้าเป็นวันอื่นใช้เที่ยงวัน
    // เพราะ "ตอนนี้" ของวันอื่นไม่มีความหมาย และเที่ยงวันเป็นตัวแทนกลาง ๆ ของทั้งวัน
    $now = new DateTimeImmutable('now', $tz);
    $isToday = $now->format('Y-m-d') === $date;
    $at = $isToday ? $now : new DateTimeImmutable($date . ' 12:00:00', $tz);

    $factors = fis_score_factors($weather, $tides, $solunar, $at, $tz);
    $styles = fis_score_all_styles($factors);
    $overall = fis_score_overall($styles);
    $safety = fis_score_safety($weather['data']['current'] ?? []);

    fis_json([
        'data' => [
            'date' => $date,
            'evaluated_at' => $at->format('Y-m-d\TH:i:sP'),
            'evaluated_scope' => $isToday ? 'now' : 'midday',
            'overall' => $overall,
            'best_style' => $styles === [] ? null : $styles[0]['key'],
            'styles' => $styles,
            'safety' => $safety,
            'factors' => $factors,
            'notice' => FIS_SCORE_NOTICE,
        ],
        'meta' => [
            'source' => 'คำนวณในระบบจากข้อมูล Open-Meteo และการคำนวณดาราศาสตร์ของเรา',
            'formula_version' => FIS_SCORE_FORMULA_VERSION,
            'formula_doc' => 'docs/fishing-score.md',
            'method' => 'ถ่วงน้ำหนักปัจจัยที่ทีมเลือกเอง ไม่ได้ปรับจากสถิติการจับปลาจริง',
            'overall_method' => 'ค่าเฉลี่ยของ 3 งานที่ได้คะแนนสูงสุดในวันนั้น',
            'fetched_at' => (new DateTimeImmutable('now', $tz))->format('c'),
            'cached' => ($weather['cached'] ?? false) && ($tides['cached'] ?? false),
        ],
    ]);
});
