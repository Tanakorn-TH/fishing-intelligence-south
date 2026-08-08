# การนำขึ้นระบบจริง

ปลายทาง: `https://fishing.yru.ac.th` — Apache 2.4.65 + mod_fcgid + PHP 8.4.11 + MySQL 8.0.46
เซิร์ฟเวอร์อยู่วง `10.10.2.164` ต้องอยู่ในเครือข่ายมหาวิทยาลัยถึงจะเข้าถึงได้

## ข้อจำกัดของบัญชีบนเซิร์ฟเวอร์

บัญชี `fishing` เป็น **sftp-only** — ล็อกอินด้วยกุญแจ SSH ได้ แต่**รันคำสั่งบนเซิร์ฟเวอร์ไม่ได้**

```
$ ssh fishing@fishing.yru.ac.th "ls"
This service allows sftp connections only.
```

การ deploy จึงเป็นการส่งไฟล์ล้วน ๆ ผ่าน SFTP ไม่มีขั้นตอนที่ต้องสั่งงานฝั่งเซิร์ฟเวอร์
(อย่าเสียเวลาเขียนสคริปต์แบบ `tar | ssh` หรือ `ssh ... "command"` — ใช้ไม่ได้)

โครงสร้างโฟลเดอร์บนเซิร์ฟเวอร์

```
/home/fishing/web/fishing.yru.ac.th/
├── public_html/   ← document root  (index.html, styles.css, app.js, api/)
├── private/       ← อยู่นอก document root  (.env อยู่ตรงนี้)
├── logs/
└── cgi-bin/  document_errors/  stats/
```

## ตั้งค่าครั้งเดียว

### 1. ไฟล์ตั้งค่า deploy

คัดลอก `ops/deploy.env.example` ไปไว้ที่ `~/.fishing-secrets/deploy.env` แล้วเติมค่า เก็บไว้นอก repo เสมอ

### 2. กุญแจ SSH

```bash
ssh-keygen -t ed25519 -f ~/.ssh/fishing_deploy -N "" -C "fishing deploy"
```

ปกติส่งกุญแจขึ้นด้วย `ssh-copy-id` แต่ถ้ารหัส SSH ใช้ไม่ได้ (บัญชีนี้รหัส FTP กับ SSH เป็นคนละตัว)
ให้ส่งผ่าน FTPS แทน — วางไฟล์ที่ `~/.ssh/authorized_keys` แล้ว **ต้องตั้งสิทธิ์ด้วย**
เพราะ FTP อัปโหลดมาเป็น `664` ซึ่ง sshd จะปฏิเสธ (ห้าม group เขียนได้)

```
SITE CHMOD 700 /.ssh
SITE CHMOD 600 /.ssh/authorized_keys
```

คำสั่ง `MFF UNIX.mode` ใช้ไม่ได้กับเซิร์ฟเวอร์นี้ ต้องใช้ `SITE CHMOD`

### 3. deploy ครั้งแรก

```bash
bash ops/deploy.sh --setup
```

`--setup` จะทำเพิ่มจากการส่งโค้ดปกติ 2 อย่าง

- สร้าง `.env` สำหรับเซิร์ฟเวอร์จาก `.env` ในเครื่อง โดย**เปลี่ยน `DB_HOST` เป็น `localhost`**
  (บนเซิร์ฟเวอร์ PHP กับ MySQL อยู่เครื่องเดียวกัน) แล้วส่งไปไว้ที่ `private/` นอก document root
- สร้าง `.htaccess` ใน document root ที่มี `SetEnv FIS_ENV_FILE ...` ชี้ตำแหน่ง `.env` ให้ PHP รู้

## deploy ครั้งต่อ ๆ ไป

```bash
bash ops/deploy.sh
```

สคริปต์ทำตามลำดับนี้ และ**หยุดทันทีถ้าขั้นใดล้ม**

1. ทดสอบในเครื่อง — `node --check`, ตรวจ id ของ DOM, ตรวจ HTML
2. แสดงรายการไฟล์แล้วถามยืนยัน (พิมพ์ `yes`)
3. ส่งไฟล์ผ่าน SFTP
4. เรียก `/api/health.php` ตรวจว่าตอบ 200

รายการไฟล์ประกาศไว้ตายตัวในสคริปต์ ไม่ได้กวาดทั้งโฟลเดอร์ — `.git`, `.env`, `tests/`, `scripts/`,
`.github/` และไฟล์เอกสารจึงไม่มีทางหลุดขึ้น production

## ตรวจหลัง deploy

```bash
curl -s https://fishing.yru.ac.th/api/health.php
```

ควรได้ `"ok":true` และ `"tables_found":8` ตรวจเรื่องความปลอดภัยด้วย — สองอันนี้ต้อง**ไม่ใช่** 200

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://fishing.yru.ac.th/.env
```

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://fishing.yru.ac.th/api/lib/db.php
```

## แก้ปัญหา

| อาการ | สาเหตุ |
|---|---|
| `This service allows sftp connections only` | ปกติ — บัญชีนี้ไม่มี shell ใช้ SFTP เท่านั้น |
| SSH ขอรหัสผ่านทั้งที่ติดตั้งกุญแจแล้ว | สิทธิ์ไฟล์ผิด ต้อง `.ssh` = 700 และ `authorized_keys` = 600 |
| health ตอบ 503 `ไม่พบ extension pdo_mysql` | `sudo apt install php8.4-mysql` แล้วรีสตาร์ท Apache |
| health ตอบ 503 `ต่อฐานข้อมูลไม่ได้` | `.env` บนเซิร์ฟเวอร์ผิด หรือ `.htaccess` ไม่ได้ตั้ง `FIS_ENV_FILE` |
| `tables_found` ไม่ครบ 8 | ยังไม่ได้โหลด `data-model.sql` เข้าฐานข้อมูล |
| ต่อ SFTP ไม่ได้เลย | ไม่ได้อยู่ในเครือข่ายมหาวิทยาลัย |
