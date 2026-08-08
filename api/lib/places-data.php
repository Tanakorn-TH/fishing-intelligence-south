<?php
declare(strict_types=1);

/**
 * places-data.php — รายชื่อสถานที่ภาคใต้พร้อมพิกัด สำหรับ /api/places.php
 *
 * ⚠️ ไฟล์นี้สร้างด้วยเครื่อง อย่าแก้มือ
 *    สร้างด้วย: python scripts/build-places.py
 *
 * รายชื่อและรหัสอำเภอ: GeoThai (MIT) https://github.com/GeoThai/data
 * พิกัด: Open-Meteo Geocoding API (CC BY 4.0)
 *
 * GeoThai ไม่มีพิกัดในข้อมูล จึงต้องหาพิกัดจาก geocoder แยกต่างหาก
 * อำเภอที่ geocoder หาไม่เจอถูกตัดออก ตามกติกาที่ห้ามกรอกพิกัดประมาณเอง
 *
 * coast บอกว่าอยู่ฝั่งไหนของคาบสมุทร: andaman / gulf / lake / inland
 * มาจากตารางจังหวัดในสคริปต์สร้าง ไม่ได้คำนวณจากพิกัด
 *
 * ชุดนี้เป็น 'จุดอ้างอิงสำหรับดูสภาพอากาศ' ไม่ใช่หมายตกปลา
 * หมายจริงอยู่ในตาราง fishing_spots ซึ่งต้องได้พิกัดจากผู้ดูแลเท่านั้น
 */

/**
 * @return list<array{name:string, name_en:string, province:string, lat:float, lon:float, kind:string, coast:string}>
 */
function fis_places_dataset(): array
{
    static $places = [
        ['name' => 'กระบี่', 'name_en' => 'Krabi', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0726, 'lon' => 98.9105, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'คลองท่อม', 'name_en' => 'Khlong Thom', 'province' => 'จังหวัดกระบี่', 'lat' => 7.9375, 'lon' => 99.1446, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'อ่าวลึก', 'name_en' => 'Ao Luek', 'province' => 'จังหวัดกระบี่', 'lat' => 8.3780, 'lon' => 98.7212, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เกาะลันตา', 'name_en' => 'Ko Lanta', 'province' => 'จังหวัดกระบี่', 'lat' => 7.5336, 'lon' => 99.0865, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เหนือคลอง', 'name_en' => 'Nuea Khlong', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0714, 'lon' => 98.9993, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'อ่าวนาง', 'name_en' => '', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0458, 'lon' => 98.8103, 'kind' => 'landmark', 'coast' => 'andaman'],
        ['name' => 'ตรัง', 'name_en' => 'Trang', 'province' => 'จังหวัดตรัง', 'lat' => 7.5563, 'lon' => 99.6114, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'กันตัง', 'name_en' => 'Kantang', 'province' => 'จังหวัดตรัง', 'lat' => 7.4054, 'lon' => 99.5156, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ปะเหลียน', 'name_en' => 'Palian', 'province' => 'จังหวัดตรัง', 'lat' => 7.1724, 'lon' => 99.6862, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ย่านตาขาว', 'name_en' => 'Yan Ta Khao', 'province' => 'จังหวัดตรัง', 'lat' => 7.3862, 'lon' => 99.6669, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'สิเกา', 'name_en' => 'Sikao', 'province' => 'จังหวัดตรัง', 'lat' => 7.5716, 'lon' => 99.3449, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'หาดสำราญ', 'name_en' => 'Hat Samran', 'province' => 'จังหวัดตรัง', 'lat' => 7.2403, 'lon' => 99.5762, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'นครศรีธรรมราช', 'name_en' => 'Nakhon Si Thammarat', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.4333, 'lon' => 99.9667, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'ขนอม', 'name_en' => 'Khanom', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 9.2081, 'lon' => 99.8614, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ท่าศาลา', 'name_en' => 'Tha Sala', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.6673, 'lon' => 99.9308, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ปากพนัง', 'name_en' => 'Pak Phanang', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.3511, 'lon' => 100.2019, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'หัวไทร', 'name_en' => 'Hua Sai', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.0441, 'lon' => 100.3059, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'นราธิวาส', 'name_en' => 'Narathiwat', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.4264, 'lon' => 101.8231, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'ตากใบ', 'name_en' => 'Tak Bai', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.2595, 'lon' => 102.0546, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'บาเจาะ', 'name_en' => 'Tak Bai', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.5168, 'lon' => 101.6510, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ยี่งอ', 'name_en' => 'Yi-Ngo', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.4030, 'lon' => 101.7062, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ปัตตานี', 'name_en' => 'Pattani', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8681, 'lon' => 101.2501, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'ปะนาเระ', 'name_en' => 'Panare', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8619, 'lon' => 101.4910, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ยะรัง', 'name_en' => 'Yarang', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.7599, 'lon' => 101.2934, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ยะหริ่ง', 'name_en' => 'Yaring', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8662, 'lon' => 101.3689, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สายบุรี', 'name_en' => 'Sai Buri', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.7013, 'lon' => 101.6167, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'หนองจิก', 'name_en' => 'Nong Chik', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8436, 'lon' => 101.1780, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ไม้แก่น', 'name_en' => 'Mai Kaen', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.6098, 'lon' => 101.6669, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'พังงา', 'name_en' => 'Phangnga', 'province' => 'จังหวัดพังงา', 'lat' => 8.4509, 'lon' => 98.5298, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'คุระบุรี', 'name_en' => 'Khura Buri', 'province' => 'จังหวัดพังงา', 'lat' => 9.1940, 'lon' => 98.4151, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ตะกั่วป่า', 'name_en' => 'Takua Pa', 'province' => 'จังหวัดพังงา', 'lat' => 8.8705, 'lon' => 98.3438, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ทับปุด', 'name_en' => 'Thap Put', 'province' => 'จังหวัดพังงา', 'lat' => 8.5162, 'lon' => 98.6397, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ท้ายเหมือง', 'name_en' => 'Thai Mueang', 'province' => 'จังหวัดพังงา', 'lat' => 8.3991, 'lon' => 98.2606, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เกาะยาว', 'name_en' => 'Ko Yao', 'province' => 'จังหวัดพังงา', 'lat' => 8.1101, 'lon' => 98.5923, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'พัทลุง', 'name_en' => 'Phatthalung', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.6179, 'lon' => 100.0779, 'kind' => 'province', 'coast' => 'lake'],
        ['name' => 'ควนขนุน', 'name_en' => 'Khuan Khanun', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.7347, 'lon' => 100.0093, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'บางแก้ว', 'name_en' => 'Bang Kaeo', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.4295, 'lon' => 100.1780, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ปากพะยูน', 'name_en' => 'Pak Phayun', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.3422, 'lon' => 100.3175, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'เขาชัยสน', 'name_en' => 'Khao Chaison', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.4612, 'lon' => 100.1337, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ภูเก็ต', 'name_en' => 'Phuket', 'province' => 'จังหวัดภูเก็ต', 'lat' => 7.8906, 'lon' => 98.3981, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'ถลาง', 'name_en' => 'Thalang', 'province' => 'จังหวัดภูเก็ต', 'lat' => 8.0317, 'lon' => 98.3341, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เมืองภูเก็ต', 'name_en' => 'Mueang Phuket', 'province' => 'จังหวัดภูเก็ต', 'lat' => 7.8634, 'lon' => 98.3644, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ยะลา', 'name_en' => 'Yala', 'province' => 'จังหวัดยะลา', 'lat' => 6.5400, 'lon' => 101.2813, 'kind' => 'province', 'coast' => 'inland'],
        ['name' => 'ธารโต', 'name_en' => 'Than To', 'province' => 'จังหวัดยะลา', 'lat' => 6.1674, 'lon' => 101.1801, 'kind' => 'district', 'coast' => 'inland'],
        ['name' => 'บันนังสตา', 'name_en' => 'Bannang Sata', 'province' => 'จังหวัดยะลา', 'lat' => 6.2664, 'lon' => 101.2646, 'kind' => 'district', 'coast' => 'inland'],
        ['name' => 'ยะหา', 'name_en' => 'Yaha', 'province' => 'จังหวัดยะลา', 'lat' => 6.4797, 'lon' => 101.1320, 'kind' => 'district', 'coast' => 'inland'],
        ['name' => 'รามัน', 'name_en' => 'Raman', 'province' => 'จังหวัดยะลา', 'lat' => 6.4786, 'lon' => 101.4241, 'kind' => 'district', 'coast' => 'inland'],
        ['name' => 'สงขลา', 'name_en' => 'Songkhla', 'province' => 'จังหวัดสงขลา', 'lat' => 7.1988, 'lon' => 100.5951, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'กระแสสินธุ์', 'name_en' => 'Krasae Sin', 'province' => 'จังหวัดสงขลา', 'lat' => 7.6155, 'lon' => 100.3284, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'จะนะ', 'name_en' => 'Chana', 'province' => 'จังหวัดสงขลา', 'lat' => 6.9154, 'lon' => 100.7404, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ระโนด', 'name_en' => 'Ranot', 'province' => 'จังหวัดสงขลา', 'lat' => 7.7777, 'lon' => 100.3213, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สทิงพระ', 'name_en' => 'Sathing Phra', 'province' => 'จังหวัดสงขลา', 'lat' => 7.4730, 'lon' => 100.4391, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สิงหนคร', 'name_en' => 'Singhanakhon', 'province' => 'จังหวัดสงขลา', 'lat' => 7.2390, 'lon' => 100.5527, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'เทพา', 'name_en' => 'Thepha', 'province' => 'จังหวัดสงขลา', 'lat' => 6.8294, 'lon' => 100.9643, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สตูล', 'name_en' => 'Satun', 'province' => 'จังหวัดสตูล', 'lat' => 6.6231, 'lon' => 100.0668, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'ทุ่งหว้า', 'name_en' => 'Thung Wa', 'province' => 'จังหวัดสตูล', 'lat' => 7.1096, 'lon' => 99.7559, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ท่าแพ', 'name_en' => 'Tha Phae', 'province' => 'จังหวัดสตูล', 'lat' => 6.7901, 'lon' => 99.9701, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ละงู', 'name_en' => 'La-Ngu', 'province' => 'จังหวัดสตูล', 'lat' => 6.8849, 'lon' => 99.7884, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เกาะหลีเป๊ะ', 'name_en' => '', 'province' => 'จังหวัดสตูล', 'lat' => 6.4908, 'lon' => 99.3032, 'kind' => 'landmark', 'coast' => 'andaman'],
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
