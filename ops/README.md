# การนำขึ้นระบบจริง

ปลายทาง: `https://fishing.yru.ac.th` — Apache 2.4.65 + mod_fcgid + PHP 8.4.11 + MySQL 8.0.46
เข้าถึงผ่าน SSH (พอร์ต 22, OpenSSH 8.9p1) ต้องอยู่ในเครือข่ายมหาวิทยาลัย (เซิร์ฟเวอร์อยู่วง 10.10.2.x)

## ตั้งค่าครั้งเดียว

### 1. ไฟล์ตั้งค่า deploy

คัดลอก `ops/deploy.env.example` ไปไว้ที่ `~/.fishing-secrets/deploy.env` แล้วเติม `DEPLOY_USER` กับ `DEPLOY_PATH`
เก็บไว้นอก repo เสมอ

### 2. กุญแจ SSH

สร้างกุญแจแล้วส่ง public key ขึ้นเซิร์ฟเวอร์ ทำครั้งเดียวจบ ไม่ต้องพิมพ์รหัสผ่านทุกครั้งที่ deploy

```bash
ssh-keygen -t ed25519 -f ~/.ssh/fishing_deploy -C "fishing deploy"
```

```bash
ssh-copy-id -i ~/.ssh/fishing_deploy.pub ผู้ใช้@fishing.yru.ac.th
```

แล้วใส่ `DEPLOY_SSH_KEY=~/.ssh/fishing_deploy` ใน `deploy.env`

### 3. ไฟล์ `.env` บนเซิร์ฟเวอร์

**สคริปต์ deploy ไม่ส่งไฟล์นี้ให้** ตั้งครั้งเดียวเองบนเซิร์ฟเวอร์

⚠️ **ต้องวางนอก document root** — nginx เสิร์ฟไฟล์สแตติกเองโดยไม่ผ่าน Apache แปลว่า `.htaccess`
กัน `.env` ไม่ได้ ถ้าวางไว้ใน document root ใครก็เปิด `https://fishing.yru.ac.th/.env` อ่านรหัสผ่านไปได้

วางไว้เหนือ document root หนึ่งชั้น เช่น `/home/ผู้ใช้/fishing-config/.env` แล้วบอกตำแหน่งให้ PHP รู้
ผ่านตัวแปร `FIS_ENV_FILE` (ตั้งใน Apache vhost หรือ `.htaccess` ด้วย `SetEnv`)

ค่าที่ต้องมี — สังเกตว่า `DB_HOST` บนเซิร์ฟเวอร์เป็น `localhost` ไม่ใช่ชื่อโดเมน
เพราะ PHP กับ MySQL อยู่เครื่องเดียวกัน ต่อผ่าน UNIX socket

```
DB_HOST=localhost
DB_PORT=3306
DB_NAME=fishing_db
DB_USER=fishing_db
DB_PASSWORD=<รหัสผ่านจริง>
```

ตั้งสิทธิ์ไฟล์ให้อ่านได้เฉพาะเจ้าของ

```bash
chmod 600 ~/fishing-config/.env
```

## deploy

```bash
bash ops/deploy.sh
```

สคริปต์จะทำตามลำดับนี้ และ**หยุดทันทีถ้าขั้นใดล้ม**

1. ทดสอบในเครื่อง — `node --check`, ตรวจ id ของ DOM, ตรวจ HTML
2. แสดงรายการไฟล์ที่จะส่งแล้วถามยืนยัน
3. ส่ง `index.html`, `styles.css`, `app.js`, `api/` ผ่าน tar over SSH
4. เรียก `/api/health.php` ตรวจว่าเซิร์ฟเวอร์ตอบ 200

รายการไฟล์ประกาศไว้ตายตัวในสคริปต์ ไม่ได้กวาดทั้งโฟลเดอร์ — `.git`, `.env`, `tests/`, `scripts/`,
`.github/` และไฟล์เอกสารจึงไม่มีทางหลุดขึ้น production

## หลัง deploy สำเร็จ

ถอด IP ของเครื่อง dev ออกจาก Remote MySQL ของเซิร์ฟเวอร์ ตอนนี้ PHP ต่อฐานข้อมูลผ่าน localhost แล้ว
ไม่จำเป็นต้องเปิดให้ต่อจากภายนอกอีก

## แก้ปัญหา

| อาการ | สาเหตุที่พบบ่อย |
|---|---|
| health check ตอบ 503 พร้อม `ไม่พบ extension pdo_mysql` | ติดตั้งด้วย `sudo apt install php8.4-mysql` แล้วรีสตาร์ท Apache |
| health check ตอบ 503 พร้อม `ต่อฐานข้อมูลไม่ได้` | `.env` บนเซิร์ฟเวอร์ผิด หรือ PHP หาไฟล์ไม่เจอ (ตรวจ `FIS_ENV_FILE`) |
| `tables_found` ไม่ครบ 8 | ยังไม่ได้โหลด `data-model.sql` เข้าฐานข้อมูลนั้น |
| ต่อ SSH ไม่ได้ | ไม่ได้อยู่ในเครือข่ายมหาวิทยาลัย เซิร์ฟเวอร์เป็น IP ภายใน 10.10.2.164 |
