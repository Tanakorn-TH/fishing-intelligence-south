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
        ['name' => 'ปลายพระยา', 'name_en' => 'Plai Phraya', 'province' => 'จังหวัดกระบี่', 'lat' => 8.5328, 'lon' => 98.8637, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ลำทับ', 'name_en' => 'Lam Thap', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0723, 'lon' => 99.2922, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'อ่าวลึก', 'name_en' => 'Ao Luek', 'province' => 'จังหวัดกระบี่', 'lat' => 8.3780, 'lon' => 98.7212, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เกาะลันตา', 'name_en' => 'Ko Lanta', 'province' => 'จังหวัดกระบี่', 'lat' => 7.5336, 'lon' => 99.0865, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เขาพนม', 'name_en' => 'Khao Phanom', 'province' => 'จังหวัดกระบี่', 'lat' => 8.2655, 'lon' => 99.0512, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เหนือคลอง', 'name_en' => 'Nuea Khlong', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0714, 'lon' => 98.9993, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'อ่าวนาง', 'name_en' => '', 'province' => 'จังหวัดกระบี่', 'lat' => 8.0458, 'lon' => 98.8103, 'kind' => 'landmark', 'coast' => 'andaman'],
        ['name' => 'เกาะพีพี', 'name_en' => '', 'province' => 'จังหวัดกระบี่', 'lat' => 7.7443, 'lon' => 98.7811, 'kind' => 'landmark', 'coast' => 'andaman'],
        ['name' => 'ตรัง', 'name_en' => 'Trang', 'province' => 'จังหวัดตรัง', 'lat' => 7.5563, 'lon' => 99.6114, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'กันตัง', 'name_en' => 'Kantang', 'province' => 'จังหวัดตรัง', 'lat' => 7.4054, 'lon' => 99.5156, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'นาโยง', 'name_en' => 'Na Yong', 'province' => 'จังหวัดตรัง', 'lat' => 7.5617, 'lon' => 99.6948, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ปะเหลียน', 'name_en' => 'Palian', 'province' => 'จังหวัดตรัง', 'lat' => 7.1724, 'lon' => 99.6862, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ย่านตาขาว', 'name_en' => 'Yan Ta Khao', 'province' => 'จังหวัดตรัง', 'lat' => 7.3862, 'lon' => 99.6669, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'รัษฎา', 'name_en' => 'Ratsada', 'province' => 'จังหวัดตรัง', 'lat' => 7.9747, 'lon' => 99.6335, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'วังวิเศษ', 'name_en' => 'Wang Wiset', 'province' => 'จังหวัดตรัง', 'lat' => 7.7363, 'lon' => 99.3987, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'สิเกา', 'name_en' => 'Sikao', 'province' => 'จังหวัดตรัง', 'lat' => 7.5716, 'lon' => 99.3449, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'หาดสำราญ', 'name_en' => 'Hat Samran', 'province' => 'จังหวัดตรัง', 'lat' => 7.2403, 'lon' => 99.5762, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ห้วยยอด', 'name_en' => 'Huai Yot', 'province' => 'จังหวัดตรัง', 'lat' => 7.7894, 'lon' => 99.6347, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'นครศรีธรรมราช', 'name_en' => 'Nakhon Si Thammarat', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.4333, 'lon' => 99.9667, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'ขนอม', 'name_en' => 'Khanom', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 9.2081, 'lon' => 99.8614, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'จุฬาภรณ์', 'name_en' => 'Chulabhorn', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.0760, 'lon' => 99.8695, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ฉวาง', 'name_en' => 'Chawang', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.4261, 'lon' => 99.5047, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ชะอวด', 'name_en' => 'Cha-Uat', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 7.9677, 'lon' => 99.9998, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ช้างกลาง', 'name_en' => 'Chang Klang', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.3709, 'lon' => 99.5625, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ถ้ำพรรณรา', 'name_en' => 'Tham Phannara', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.4205, 'lon' => 99.3952, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ทุ่งสง', 'name_en' => 'Thung Song', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.1645, 'lon' => 99.6804, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ทุ่งใหญ่', 'name_en' => 'Thung Yai', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.3012, 'lon' => 99.3661, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ท่าศาลา', 'name_en' => 'Tha Sala', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.6673, 'lon' => 99.9308, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'นบพิตำ', 'name_en' => 'Nopphitam', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.7221, 'lon' => 99.7488, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'นาบอน', 'name_en' => 'Na Bon', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.2617, 'lon' => 99.5947, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'บางขัน', 'name_en' => 'Bang Khan', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.0269, 'lon' => 99.4737, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ปากพนัง', 'name_en' => 'Pak Phanang', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.3511, 'lon' => 100.2019, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'พรหมคีรี', 'name_en' => 'Phrom Khiri', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.5233, 'lon' => 99.8227, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'พระพรหม', 'name_en' => 'Phra Phrom', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.3391, 'lon' => 99.9009, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'พิปูน', 'name_en' => 'Phipun', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.5604, 'lon' => 99.6070, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ร่อนพิบูลย์', 'name_en' => 'Ron Phibun', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.1791, 'lon' => 99.8542, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ลานสกา', 'name_en' => 'Lan Saka', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.3710, 'lon' => 99.8056, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'หัวไทร', 'name_en' => 'Hua Sai', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.0441, 'lon' => 100.3059, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'เฉลิมพระเกียรติ', 'name_en' => 'Chaloem Phra Kiet', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.1769, 'lon' => 100.0358, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'เชียรใหญ่', 'name_en' => 'Chian Yai', 'province' => 'จังหวัดนครศรีธรรมราช', 'lat' => 8.1694, 'lon' => 100.1447, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'นราธิวาส', 'name_en' => 'Narathiwat', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.4264, 'lon' => 101.8231, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'จะแนะ', 'name_en' => 'Chanae', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.0991, 'lon' => 101.6935, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ตากใบ', 'name_en' => 'Tak Bai', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.2595, 'lon' => 102.0546, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'บาเจาะ', 'name_en' => 'Tak Bai', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.5168, 'lon' => 101.6510, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ยี่งอ', 'name_en' => 'Yi-Ngo', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.4030, 'lon' => 101.7062, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ระแงะ', 'name_en' => 'Ra-Ngae', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.2968, 'lon' => 101.7284, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'รือเสาะ', 'name_en' => 'Rueso', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.3936, 'lon' => 101.5185, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ศรีสาคร', 'name_en' => 'Si Sakhon', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.2314, 'lon' => 101.5005, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สุคิริน', 'name_en' => 'Sukhirin', 'province' => 'จังหวัดนราธิวาส', 'lat' => 5.9389, 'lon' => 101.7708, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สุไหงโก-ลก', 'name_en' => 'Su-Ngai Kolok', 'province' => 'จังหวัดนราธิวาส', 'lat' => 6.0298, 'lon' => 101.9659, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'แว้ง', 'name_en' => 'Waeng', 'province' => 'จังหวัดนราธิวาส', 'lat' => 5.9280, 'lon' => 101.8838, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ปัตตานี', 'name_en' => 'Pattani', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8681, 'lon' => 101.2501, 'kind' => 'province', 'coast' => 'gulf'],
        ['name' => 'กะพ้อ', 'name_en' => 'Kapho', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.5597, 'lon' => 101.5343, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ทุ่งยางแดง', 'name_en' => 'Thung Yang Daeng', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.6182, 'lon' => 101.4265, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ปะนาเระ', 'name_en' => 'Panare', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8619, 'lon' => 101.4910, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'มายอ', 'name_en' => 'Mayo', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.7186, 'lon' => 101.4098, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ยะรัง', 'name_en' => 'Yarang', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.7599, 'lon' => 101.2934, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ยะหริ่ง', 'name_en' => 'Yaring', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8662, 'lon' => 101.3689, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สายบุรี', 'name_en' => 'Sai Buri', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.7013, 'lon' => 101.6167, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'หนองจิก', 'name_en' => 'Nong Chik', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.8436, 'lon' => 101.1780, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'แม่ลาน', 'name_en' => 'Mae Lan', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.6611, 'lon' => 101.2396, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'โคกโพธิ์', 'name_en' => 'Khok Pho', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.7306, 'lon' => 101.0962, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ไม้แก่น', 'name_en' => 'Mai Kaen', 'province' => 'จังหวัดปัตตานี', 'lat' => 6.6098, 'lon' => 101.6669, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'พังงา', 'name_en' => 'Phangnga', 'province' => 'จังหวัดพังงา', 'lat' => 8.4509, 'lon' => 98.5298, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'กะปง', 'name_en' => 'Kapong', 'province' => 'จังหวัดพังงา', 'lat' => 8.6970, 'lon' => 98.4091, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'คุระบุรี', 'name_en' => 'Khura Buri', 'province' => 'จังหวัดพังงา', 'lat' => 9.1940, 'lon' => 98.4151, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ตะกั่วป่า', 'name_en' => 'Takua Pa', 'province' => 'จังหวัดพังงา', 'lat' => 8.8705, 'lon' => 98.3438, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ทับปุด', 'name_en' => 'Thap Put', 'province' => 'จังหวัดพังงา', 'lat' => 8.5162, 'lon' => 98.6397, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ท้ายเหมือง', 'name_en' => 'Thai Mueang', 'province' => 'จังหวัดพังงา', 'lat' => 8.3991, 'lon' => 98.2606, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'เกาะยาว', 'name_en' => 'Ko Yao', 'province' => 'จังหวัดพังงา', 'lat' => 8.1101, 'lon' => 98.5923, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'พัทลุง', 'name_en' => 'Phatthalung', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.6179, 'lon' => 100.0779, 'kind' => 'province', 'coast' => 'lake'],
        ['name' => 'กงหรา', 'name_en' => 'Kong Ra', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.4027, 'lon' => 99.9497, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ควนขนุน', 'name_en' => 'Khuan Khanun', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.7347, 'lon' => 100.0093, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ตะโหมด', 'name_en' => 'Tamot', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.3392, 'lon' => 100.1117, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'บางแก้ว', 'name_en' => 'Bang Kaeo', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.4295, 'lon' => 100.1780, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ปากพะยูน', 'name_en' => 'Pak Phayun', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.3422, 'lon' => 100.3175, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ป่าบอน', 'name_en' => 'Pa Bon', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.2694, 'lon' => 100.1704, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ศรีนครินทร์', 'name_en' => 'Srinagarindra', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.5699, 'lon' => 99.9435, 'kind' => 'district', 'coast' => 'lake'],
        ['name' => 'ศรีบรรพต', 'name_en' => 'Si Banphot', 'province' => 'จังหวัดพัทลุง', 'lat' => 7.7214, 'lon' => 99.8843, 'kind' => 'district', 'coast' => 'lake'],
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
        ['name' => 'คลองหอยโข่ง', 'name_en' => 'Khlong Hoi Khong', 'province' => 'จังหวัดสงขลา', 'lat' => 6.8986, 'lon' => 100.3889, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ควนเนียง', 'name_en' => 'Khuan Niang', 'province' => 'จังหวัดสงขลา', 'lat' => 7.1914, 'lon' => 100.3535, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'จะนะ', 'name_en' => 'Chana', 'province' => 'จังหวัดสงขลา', 'lat' => 6.9154, 'lon' => 100.7404, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'นาทวี', 'name_en' => 'Na Thawi', 'province' => 'จังหวัดสงขลา', 'lat' => 6.7419, 'lon' => 100.6914, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'บางกล่ำ', 'name_en' => 'Bang Klam', 'province' => 'จังหวัดสงขลา', 'lat' => 7.0895, 'lon' => 100.4107, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'ระโนด', 'name_en' => 'Ranot', 'province' => 'จังหวัดสงขลา', 'lat' => 7.7777, 'lon' => 100.3213, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'รัตภูมิ', 'name_en' => 'Rattaphum', 'province' => 'จังหวัดสงขลา', 'lat' => 7.1348, 'lon' => 100.2565, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สทิงพระ', 'name_en' => 'Sathing Phra', 'province' => 'จังหวัดสงขลา', 'lat' => 7.4730, 'lon' => 100.4391, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สะบ้าย้อย', 'name_en' => 'Saba Yoi', 'province' => 'จังหวัดสงขลา', 'lat' => 6.6175, 'lon' => 100.9519, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สะเดา', 'name_en' => 'Sadao', 'province' => 'จังหวัดสงขลา', 'lat' => 6.6388, 'lon' => 100.4234, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สิงหนคร', 'name_en' => 'Singhanakhon', 'province' => 'จังหวัดสงขลา', 'lat' => 7.2390, 'lon' => 100.5527, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'หาดใหญ่', 'name_en' => 'Hat Yai', 'province' => 'จังหวัดสงขลา', 'lat' => 7.0084, 'lon' => 100.4767, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'เทพา', 'name_en' => 'Thepha', 'province' => 'จังหวัดสงขลา', 'lat' => 6.8294, 'lon' => 100.9643, 'kind' => 'district', 'coast' => 'gulf'],
        ['name' => 'สตูล', 'name_en' => 'Satun', 'province' => 'จังหวัดสตูล', 'lat' => 6.6231, 'lon' => 100.0668, 'kind' => 'province', 'coast' => 'andaman'],
        ['name' => 'ควนกาหลง', 'name_en' => 'Khuan Kalong', 'province' => 'จังหวัดสตูล', 'lat' => 6.8454, 'lon' => 100.0715, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ควนโดน', 'name_en' => 'Khuan Don', 'province' => 'จังหวัดสตูล', 'lat' => 6.7876, 'lon' => 100.0778, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ทุ่งหว้า', 'name_en' => 'Thung Wa', 'province' => 'จังหวัดสตูล', 'lat' => 7.1096, 'lon' => 99.7559, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'ท่าแพ', 'name_en' => 'Tha Phae', 'province' => 'จังหวัดสตูล', 'lat' => 6.7901, 'lon' => 99.9701, 'kind' => 'district', 'coast' => 'andaman'],
        ['name' => 'มะนัง', 'name_en' => 'Manang', 'province' => 'จังหวัดสตูล', 'lat' => 6.9993, 'lon' => 99.9216, 'kind' => 'district', 'coast' => 'andaman'],
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
