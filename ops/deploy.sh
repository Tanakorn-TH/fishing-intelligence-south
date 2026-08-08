#!/usr/bin/env bash
#
# ส่งเว็บและ API ขึ้น fishing.yru.ac.th ผ่าน SFTP
#
#   ทดสอบในเครื่อง -> ส่งขึ้นเซิร์ฟเวอร์ -> ตรวจสุขภาพ
#   ถ้าขั้นทดสอบไม่ผ่าน จะไม่ส่งอะไรขึ้นไปเลย
#
# บัญชีบนเซิร์ฟเวอร์เป็น sftp-only รันคำสั่งปลายทางไม่ได้ ทุกอย่างจึงทำผ่านการส่งไฟล์ล้วน ๆ
# ค่าเชื่อมต่ออยู่นอก repo ที่ ~/.fishing-secrets/deploy.env (ดู deploy.env.example)
#
# ใช้งาน:  bash ops/deploy.sh              ส่งเฉพาะโค้ด
#          bash ops/deploy.sh --setup      ส่งโค้ด + .env + .htaccess (ครั้งแรกครั้งเดียว)
#          bash ops/deploy.sh --yes        ไม่ถามยืนยัน

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${FISHING_DEPLOY_CONFIG:-$HOME/.fishing-secrets/deploy.env}"
ASSUME_YES=0
DO_SETUP=0
for arg in "$@"; do
  case "$arg" in
    --yes) ASSUME_YES=1 ;;
    --setup) DO_SETUP=1 ;;
    *) echo "ไม่รู้จักตัวเลือก: $arg" >&2; exit 2 ;;
  esac
done

die() { echo "ล้มเหลว: $*" >&2; exit 1; }
step() { echo; echo "=== $* ==="; }

# ---------- 1. อ่านค่าเชื่อมต่อ ----------
[ -f "$CONFIG" ] || die "ไม่พบไฟล์ตั้งค่า $CONFIG — คัดลอกจาก ops/deploy.env.example แล้วเติมค่า"
# shellcheck disable=SC1090
set -a; . "$CONFIG"; set +a

: "${DEPLOY_HOST:?ต้องตั้ง DEPLOY_HOST ใน $CONFIG}"
: "${DEPLOY_USER:?ต้องตั้ง DEPLOY_USER ใน $CONFIG}"
: "${DEPLOY_DOCROOT:?ต้องตั้ง DEPLOY_DOCROOT ใน $CONFIG}"
: "${DEPLOY_PRIVATE:?ต้องตั้ง DEPLOY_PRIVATE ใน $CONFIG}"
HEALTH_URL="${HEALTH_URL:-https://${DEPLOY_HOST}/api/health.php}"

SFTP_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 -o BatchMode=yes)
[ -n "${DEPLOY_SSH_KEY:-}" ] && SFTP_OPTS+=(-i "$DEPLOY_SSH_KEY")
[ -n "${DEPLOY_PORT:-}" ] && SFTP_OPTS+=(-P "$DEPLOY_PORT")
TARGET="${DEPLOY_USER}@${DEPLOY_HOST}"

# ---------- 2. ทดสอบก่อน ----------
cd "$REPO_ROOT"
step "ทดสอบในเครื่องก่อนส่ง"
command -v node >/dev/null || die "ไม่พบ node"
node --check app.js
node scripts/check-dom-ids.mjs
npx --yes html-validate@9 index.html
echo "ผ่านครบ"

# ---------- 3. รายการไฟล์ ----------
# ประกาศชัดเจนทีละไฟล์ ไม่กวาดทั้งโฟลเดอร์
# เพื่อไม่ให้ .git .env tests scripts หรือไฟล์เอกสารหลุดขึ้น production
ROOT_FILES=(index.html styles.css design.css fonts.css app.js map.js)
# ข้อมูลเส้นชายฝั่งสำหรับแผนที่เลือกหมาย สร้างด้วย scripts/build-coastline.mjs
MAP_FILES=(map/coastline-south.json map/depth-south.json)
API_FILES=(
  api/spots.php api/gear.php api/health.php
  api/weather.php api/solunar.php api/tides.php api/score.php api/places.php
)
LIB_FILES=(
  api/lib/config.php api/lib/db.php api/lib/http.php api/lib/.htaccess
  api/lib/astro.php api/lib/cache.php api/lib/remote.php api/lib/conditions.php
  api/lib/places-data.php
)

# ---------- 3ก. กันรายการตกหล่น ----------
# รายการข้างบนเขียนมือเพื่อไม่ให้ .git .env tests หลุดขึ้น production
# ข้อเสียคือพอเพิ่ม endpoint ใหม่แล้วลืมมาต่อท้าย ไฟล์นั้นจะไม่ถูกส่งขึ้นไปเงียบ ๆ
# หน้าเว็บที่เรียกมันจะพังบน production ทั้งที่ในเครื่องทดสอบผ่านหมด
#
# เคยเกิดมาแล้วจริง: weather/solunar/tides/score กับ lib อีก 4 ตัวตกค้างอยู่ในเครื่อง
# ไม่เคยขึ้น production เลย จึงเพิ่มด่านนี้ไว้ให้ล้มตั้งแต่ก่อนส่ง แทนที่จะไปพังบนเว็บจริง
step "ตรวจว่าไม่มีไฟล์ตกหล่นจากรายการ"
LISTED=" ${ROOT_FILES[*]} ${API_FILES[*]} ${LIB_FILES[*]} ${MAP_FILES[*]} "
MISSING_FROM_LIST=()

# ตรวจทั้ง PHP ฝั่งหลังบ้าน และไฟล์หน้าเว็บที่เบราว์เซอร์ต้องโหลด
# เคยพลาดมาแล้วทั้งสองแบบ: รอบแรก endpoint PHP หลายตัวไม่เคยขึ้น production
# รอบที่สอง map.js กับไฟล์เส้นชายฝั่งเกือบตกค้างเพราะด่านเดิมดูแต่ .php
while IFS= read -r found; do
  [[ "$LISTED" == *" $found "* ]] || MISSING_FROM_LIST+=("$found")
done < <({
  find api -name '*.php'
  find . -maxdepth 1 \( -name '*.js' -o -name '*.css' -o -name '*.html' \) -printf '%P\n'
  find map -type f 2>/dev/null
} | sort)

if [ "${#MISSING_FROM_LIST[@]}" -gt 0 ]; then
  echo "ไฟล์เหล่านี้มีอยู่ในโปรเจคแต่ไม่อยู่ในรายการที่จะส่ง:" >&2
  printf '  %s\n' "${MISSING_FROM_LIST[@]}" >&2
  die "เพิ่มเข้ารายการใน ops/deploy.sh ก่อน แล้วค่อย deploy"
fi
echo "ครบทุกไฟล์"
# ฟอนต์ self-host — ไล่จากไฟล์จริงในโฟลเดอร์ ไม่ต้องมาแก้สคริปต์ทุกครั้งที่เพิ่มน้ำหนัก
mapfile -t FONT_FILES < <(find fonts -maxdepth 1 -name '*.woff2' | sort)
[ "${#FONT_FILES[@]}" -gt 0 ] || die "ไม่พบไฟล์ฟอนต์ใน fonts/"

step "ไฟล์ที่จะส่ง"
for f in "${ROOT_FILES[@]}" "${API_FILES[@]}" "${LIB_FILES[@]}" "${MAP_FILES[@]}"; do
  [ -f "$f" ] || die "ไม่พบ $f"
  echo "  $f"
done
echo "  fonts/ (${#FONT_FILES[@]} ไฟล์)"
echo "ปลายทาง: ${TARGET}:${DEPLOY_DOCROOT}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
BATCH="$WORK/batch.sftp"

{
  echo "-mkdir ${DEPLOY_DOCROOT}/api"
  echo "-mkdir ${DEPLOY_DOCROOT}/api/lib"
  echo "-mkdir ${DEPLOY_DOCROOT}/fonts"
  echo "-mkdir ${DEPLOY_DOCROOT}/map"
  for f in "${ROOT_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/$(basename "$f")"; done
  for f in "${API_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/api/$(basename "$f")"; done
  for f in "${LIB_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/api/lib/$(basename "$f")"; done
  for f in "${MAP_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/map/$(basename "$f")"; done
  for f in "${FONT_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/fonts/$(basename "$f")"; done
} > "$BATCH"

# ---------- 4. ไฟล์ตั้งค่าเซิร์ฟเวอร์ (เฉพาะ --setup) ----------
if [ "$DO_SETUP" -eq 1 ]; then
  step "เตรียมไฟล์ตั้งค่าสำหรับเซิร์ฟเวอร์"
  [ -f "$REPO_ROOT/.env" ] || die "ไม่พบ .env ในโปรเจค — ต้องใช้ค่าฐานข้อมูลจากไฟล์นี้"

  # บนเซิร์ฟเวอร์ PHP กับ MySQL อยู่เครื่องเดียวกัน ต่อผ่าน localhost ไม่ใช่ชื่อโดเมน
  awk -F= '
    /^[[:space:]]*(#|$)/ { next }
    $1 ~ /^(DB_NAME|DB_USER|DB_PASSWORD|DB_PORT)$/ { print; next }
  ' "$REPO_ROOT/.env" > "$WORK/server.env"
  echo "DB_HOST=localhost" >> "$WORK/server.env"
  grep -q "^DB_PASSWORD=." "$WORK/server.env" || die "ไม่พบ DB_PASSWORD ใน .env"
  echo "  สร้าง .env สำหรับเซิร์ฟเวอร์แล้ว (DB_HOST=localhost)"

  # บอก PHP ว่า .env อยู่นอก document root
  cat > "$WORK/htaccess" <<HT
# บอก API ว่าไฟล์ตั้งค่าอยู่ที่ไหน — ต้องอยู่นอก document root เสมอ
SetEnv FIS_ENV_FILE ${DEPLOY_PRIVATE}/.env
HT
  echo "  สร้าง .htaccess ชี้ไป ${DEPLOY_PRIVATE}/.env"

  {
    echo "put $WORK/server.env ${DEPLOY_PRIVATE}/.env"
    echo "chmod 600 ${DEPLOY_PRIVATE}/.env"
    echo "put $WORK/htaccess ${DEPLOY_DOCROOT}/.htaccess"
  } >> "$BATCH"
fi

# ---------- 5. ยืนยัน ----------
if [ "$ASSUME_YES" -ne 1 ]; then
  echo
  [ "$DO_SETUP" -eq 1 ] && echo "โหมด --setup: จะส่ง .env และ .htaccess ขึ้นไปด้วย"
  read -r -p "ส่งขึ้น production เลยไหม? พิมพ์ yes เพื่อยืนยัน: " reply
  [ "$reply" = "yes" ] || { echo "ยกเลิก"; exit 1; }
fi

# ---------- 6. ส่งไฟล์ ----------
step "ส่งไฟล์ผ่าน SFTP"
sftp "${SFTP_OPTS[@]}" -b "$BATCH" "$TARGET" || die "ส่งไฟล์ไม่สำเร็จ"
echo "ส่งเสร็จ"

# ---------- 7. ตรวจสุขภาพ ----------
step "ตรวจสุขภาพหลัง deploy"
sleep 2
BODY="$WORK/health.json"
code=$(curl -s -o "$BODY" -w "%{http_code}" --max-time 30 "$HEALTH_URL" || echo 000)
echo "GET $HEALTH_URL -> $code"
cat "$BODY" 2>/dev/null; echo

if [ "$code" != "200" ]; then
  echo
  echo "health check ไม่ผ่าน — ไฟล์ขึ้นไปแล้วแต่ยังทำงานไม่ถูก" >&2
  echo "ดู ops/README.md หัวข้อแก้ปัญหา" >&2
  exit 1
fi

echo
echo "deploy สำเร็จ"
