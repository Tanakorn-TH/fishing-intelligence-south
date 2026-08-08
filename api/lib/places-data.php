<?php
declare(strict_types=1);

/**
 * places-data.php — รายชื่อสถานที่ภาคใต้พร้อมพิกัด สำหรับ /api/places.php
 *
 * ⚠️ ไฟล์นี้สร้างด้วยเครื่อง อย่าแก้มือ
 *    สร้างด้วย: python scripts/build-places.py
 *    พิกัดมาจาก Open-Meteo Geocoding API
 *    (https://open-meteo.com/en/docs/geocoding-api · CC BY 4.0)
 *
 * ทุกพิกัดมาจากแหล่งข้อมูลจริง ไม่มีค่าที่ประมาณเอง
 * สถานที่ที่ค้นไม่เจอถูกตัดออกโดยตั้งใจ ตามกติกาที่ห้ามกรอกพิกัดประมาณเอง
 *
 * coast บอกว่าอยู่ฝั่งไหนของคาบสมุทร: andaman / gulf / lake / inland
 * สองฝั่งมีคลื่นลมและฤดูมรสุมคนละแบบ คนวางแผนออกเรือต้องรู้ก่อนเลือกจุด
 * ค่านี้มาจากตารางจังหวัดในสคริปต์สร้าง ไม่ได้คำนวณจากพิกัด
 *
 * ชุดนี้เป็น 'จุดอ้างอิงสำหรับดูสภาพอากาศ' ไม่ใช่หมายตกปลา
 * หมายจริงอยู่ในตาราง fishing_spots ซึ่งต้องได้พิกัดจากผู้ดูแลเท่านั้น
 */

/**
 * @return list<array{name:string, province:string, lat:float, lon:float, kind:string, coast:string}>
 */
function fis_places_dataset(): array
{
    static $places = [
        ['name' => 'กระบี่', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0726, 'lon' => 98.9105, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'ชุมพร', 'province' => 'จังหวัดชุมพร', 'lat' => 10.4957, 'lon' => 99.1797, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'ตรัง', 'province' => 'จังหวัดตรัง', 'lat' => 7.5563, 'lon' => 99.6114, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'นครศรีธรรมราช', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.4333, 'lon' => 99.9667, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'นราธิวาส', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.4264, 'lon' => 101.8231, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'ปัตตานี', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8681, 'lon' => 101.2501, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'พังงา', 'province' => 'จังหวัดพังงา', 'lat' => 8.4509, 'lon' => 98.5298, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'พัทลุง', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.6179, 'lon' => 100.0779, 'kind' => 'province', 'coast' => 'lake'],
        ['name' => 'ยะลา', 'province' => 'จังหวัดยะลา', 'lat' => 6.5400, 'lon' => 101.2813, 'kind' => 'province', 'coast' => 'inland'],
        ['name' => 'ระนอง', 'province' => 'จังหวัดระนอง', 'lat' => 9.9658, 'lon' => 98.6348, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'สงขลา', 'province' => 'จังหวัดสงขลา', 'lat' => 7.1988, 'lon' => 100.5951, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'สตูล', 'province' => 'จังหวัดสตูล', 'lat' => 6.6231, 'lon' => 100.0668, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'สุราษฎร์ธานี', 'province' => 'จังหวัดสุราษฎร์ธานี', 'lat' => 9.1401, 'lon' => 99.3331, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'ภูเก็ต', 'province' => 'ภูเก็ต', 'lat' => 7.8906, 'lon' => 98.3981, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'คลองท่อม', 'province' => 'จังหวัดกระบี่', 'lat' => 7.9375, 'lon' => 99.1446, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'อ่าวนาง', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0458, 'lon' => 98.8103, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'เกาะพีพีดอน', 'province' => 'จังหวัดกระบี่', 'lat' => 7.7443, 'lon' => 98.7811, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'เกาะลันตา', 'province' => 'จังหวัดกระบี่', 'lat' => 7.5336, 'lon' => 99.0865, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'ปะทิว', 'province' => 'จังหวัดชุมพร', 'lat' => 10.7091, 'lon' => 99.3182, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'กันตัง', 'province' => 'จังหวัดตรัง', 'lat' => 7.4054, 'lon' => 99.5156, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'ปะเหลียน', 'province' => 'จังหวัดตรัง', 'lat' => 7.1724, 'lon' => 99.6862, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'สิเกา', 'province' => 'จังหวัดตรัง', 'lat' => 7.5716, 'lon' => 99.3449, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'ขนอม', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 9.2081, 'lon' => 99.8614, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ท่าศาลา', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.6673, 'lon' => 99.9308, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ปากพนัง', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.3511, 'lon' => 100.2019, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'หัวไทร', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.0441, 'lon' => 100.3059, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ตากใบ', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.2595, 'lon' => 102.0546, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ปะนาเระ', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8619, 'lon' => 101.4910, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ยะหริ่ง', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8662, 'lon' => 101.3689, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'สายบุรี', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.7013, 'lon' => 101.6167, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'หนองจิก', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8436, 'lon' => 101.1780, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ไม้แก่น', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.6098, 'lon' => 101.6669, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'คุระบุรี', 'province' => 'จังหวัดพังงา', 'lat' => 9.1940, 'lon' => 98.4151, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'ตะกั่วป่า', 'province' => 'จังหวัดพังงา', 'lat' => 8.8705, 'lon' => 98.3438, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'ท้ายเหมือง', 'province' => 'จังหวัดพังงา', 'lat' => 8.3991, 'lon' => 98.2606, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'เกาะยาว', 'province' => 'จังหวัดพังงา', 'lat' => 8.1101, 'lon' => 98.5923, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'กะเปอร์', 'province' => 'จังหวัดระนอง', 'lat' => 9.5852, 'lon' => 98.5961, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'สุขสำราญ', 'province' => 'จังหวัดระนอง', 'lat' => 9.3446, 'lon' => 98.4290, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'จะนะ', 'province' => 'จังหวัดสงขลา', 'lat' => 6.9154, 'lon' => 100.7404, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ระโนด', 'province' => 'จังหวัดสงขลา', 'lat' => 7.7777, 'lon' => 100.3213, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'สทิงพระ', 'province' => 'จังหวัดสงขลา', 'lat' => 7.4730, 'lon' => 100.4391, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'สิงหนคร', 'province' => 'จังหวัดสงขลา', 'lat' => 7.2390, 'lon' => 100.5527, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'หาดใหญ่', 'province' => 'จังหวัดสงขลา', 'lat' => 7.0084, 'lon' => 100.4767, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'เทพา', 'province' => 'จังหวัดสงขลา', 'lat' => 6.8294, 'lon' => 100.9643, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ทุ่งหว้า', 'province' => 'จังหวัดสตูล', 'lat' => 7.1096, 'lon' => 99.7559, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'ละงู', 'province' => 'จังหวัดสตูล', 'lat' => 6.8849, 'lon' => 99.7884, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'เกาะหลีเป๊ะ', 'province' => 'จังหวัดสตูล', 'lat' => 6.4908, 'lon' => 99.3032, 'kind' => 'town', 'coast' => 'andaman'],
        ['name' => 'ดอนสัก', 'province' => 'จังหวัดสุราษฎร์ธานี', 'lat' => 9.3168, 'lon' => 99.6918, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ท่าชนะ', 'province' => 'จังหวัดสุราษฎร์ธานี', 'lat' => 9.5720, 'lon' => 99.1659, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'เกาะพะงัน', 'province' => 'จังหวัดสุราษฎร์ธานี', 'lat' => 9.7195, 'lon' => 99.9951, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'เกาะสมุย', 'province' => 'จังหวัดสุราษฎร์ธานี', 'lat' => 9.5357, 'lon' => 99.9357, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'เกาะเต่า', 'province' => 'จังหวัดสุราษฎร์ธานี', 'lat' => 10.0981, 'lon' => 99.8381, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ไชยา', 'province' => 'จังหวัดสุราษฎร์ธานี', 'lat' => 9.3863, 'lon' => 99.1986, 'kind' => 'town', 'coast' => 'gulf'],
        ['name' => 'ถลาง', 'province' => 'ภูเก็ต', 'lat' => 8.0317, 'lon' => 98.3341, 'kind' => 'town', 'coast' => 'andaman'],
    ];

    return $places;
}

/** ชื่อฝั่งทะเลภาษาไทยสำหรับแสดงผล */
function fis_places_coast_label(string $coast): string
{
    $labels = [
        'andaman' => 'อันดามัน',
        'gulf' => 'อ่าวไทย',
        'lake' => 'ทะเลสาบสงขลา',
        'inland' => 'ไม่ติดทะเล',
    ];

    return $labels[$coast] ?? 'ไม่ระบุฝั่ง';
}
