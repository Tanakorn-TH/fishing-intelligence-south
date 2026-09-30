#!/usr/bin/env bash
#
# ส่งเว็บและ API ขึ้น fishing.yru.ac.th ผ่าน SFTP
#
#   เตรียมเครื่อง -> ทดสอบในเครื่อง -> เทียบกับเว็บจริง -> ส่งขึ้นเซิร์ฟเวอร์ -> ตรวจสุขภาพ
#   ถ้าขั้นใดไม่ผ่าน จะไม่ส่งอะไรขึ้นไปเลย
#
# บน Windows ดับเบิลคลิก deploy.cmd ที่รากโปรเจกต์ได้เลย (PowerShell หา bash ไม่เจอ)
# บัญชีบนเซิร์ฟเวอร์เป็น sftp-only รันคำสั่งปลายทางไม่ได้ ทุกอย่างจึงทำผ่านการส่งไฟล์ล้วน ๆ
# ค่าเชื่อมต่ออยู่นอก repo ที่ ~/.fishing-secrets/deploy.env — เครื่องใหม่ไม่ต้องเตรียมเอง
# ops/setup-machine.sh สร้างให้และขอรหัส FTP ครั้งเดียวตอนติดตั้งกุญแจ
#
# ใช้งาน:  bash ops/deploy.sh              ส่งเฉพาะโค้ด
#          bash ops/deploy.sh --setup      ส่งโค้ด + .env + .htaccess ของเซิร์ฟเวอร์ (ครั้งแรกครั้งเดียว)
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
ask_yes() {  # ask_yes "คำถาม" — ผ่านเมื่อพิมพ์ y หรือ yes หรือเมื่อสั่ง --yes
  [ "$ASSUME_YES" -eq 1 ] && return 0
  [ -t 0 ] || return 1
  local reply
  read -r -p "$1 (y/n): " reply
  [[ "$reply" =~ ^([yY]|yes|YES)$ ]]
}

cd "$REPO_ROOT"

# ---------- 1. เครื่องนี้ส่งขึ้นเซิร์ฟเวอร์ได้หรือยัง ----------
# ทำก่อนทดสอบ จะได้ไม่ต้องรอเทสต์จบแล้วค่อยรู้ว่าอยู่นอกเครือข่ายมหาวิทยาลัย
step "ตรวจการเชื่อมต่อ"
FISHING_DEPLOY_CONFIG="$CONFIG" bash "$REPO_ROOT/ops/setup-machine.sh"

# shellcheck disable=SC1090
set -a; . "$CONFIG"; set +a
: "${DEPLOY_HOST:?ต้องตั้ง DEPLOY_HOST ใน $CONFIG}"
: "${DEPLOY_USER:?ต้องตั้ง DEPLOY_USER ใน $CONFIG}"
: "${DEPLOY_DOCROOT:?ต้องตั้ง DEPLOY_DOCROOT ใน $CONFIG}"
: "${DEPLOY_PRIVATE:?ต้องตั้ง DEPLOY_PRIVATE ใน $CONFIG}"
SITE_URL="https://${DEPLOY_HOST}"
HEALTH_URL="${HEALTH_URL:-$SITE_URL/api/health.php}"

# IdentitiesOnly กันไม่ให้ ssh ลองกุญแจตัวอื่นในเครื่องก่อนจนเซิร์ฟเวอร์ตัดเพราะลองเกินจำนวนครั้ง
SFTP_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=20 -o BatchMode=yes
           -o IdentitiesOnly=yes -i "${DEPLOY_SSH_KEY:-$HOME/.ssh/fishing_deploy}"
           -P "${DEPLOY_PORT:-22}")
TARGET="${DEPLOY_USER}@${DEPLOY_HOST}"

# ---------- 2. ทดสอบก่อน ----------
step "ทดสอบในเครื่องก่อนส่ง"
command -v node >/dev/null || die "ไม่พบ node"
node --check app.js
node scripts/check-dom-ids.mjs
node scripts/check-version.mjs
npx --yes html-validate@9 index.html
echo "ผ่านครบ"

# ---------- 3. รายการไฟล์ ----------
# ประกาศชัดเจนทีละไฟล์ ไม่กวาดทั้งโฟลเดอร์
# เพื่อไม่ให้ .git .env tests scripts หรือไฟล์เอกสารหลุดขึ้น production
ROOT_FILES=(index.html styles.css design.css fonts.css app.js map.js)
# ชั้นข้อมูลของแผนที่ ทุกไฟล์สร้างด้วยสคริปต์ใน scripts/ ห้ามแก้ด้วยมือ
#   coastline  <- build-coastline.mjs   borders <- build-borders.mjs
#   depth      <- build-bathymetry.mjs  reefs กับ marks <- build-spots.py
MAP_FILES=(
  map/coastline-south.json
  map/borders-south.json
  map/depth-south.json
  map/reefs-south.json
  map/marks-south.json
)
# ระเบียนปลา สร้างด้วย build-species.py — ไม่ใช่ชั้นแผนที่จึงแยกออกมา
DATA_FILES=(
  data/species-south.json
)
API_FILES=(
  api/spots.php api/gear.php api/health.php
  api/weather.php api/solunar.php api/tides.php api/score.php api/places.php api/outlook.php
)
LIB_FILES=(
  api/lib/config.php api/lib/db.php api/lib/http.php api/lib/.htaccess
  api/lib/astro.php api/lib/cache.php api/lib/remote.php api/lib/conditions.php
  api/lib/places-data.php api/lib/scoring.php
)

# ---------- 3ก. กันรายการตกหล่น ----------
# รายการข้างบนเขียนมือเพื่อไม่ให้ .git .env tests หลุดขึ้น production
# ข้อเสียคือพอเพิ่ม endpoint ใหม่แล้วลืมมาต่อท้าย ไฟล์นั้นจะไม่ถูกส่งขึ้นไปเงียบ ๆ
# หน้าเว็บที่เรียกมันจะพังบน production ทั้งที่ในเครื่องทดสอบผ่านหมด
#
# เคยเกิดมาแล้วจริง: weather/solunar/tides/score กับ lib อีก 4 ตัวตกค้างอยู่ในเครื่อง
# ไม่เคยขึ้น production เลย จึงเพิ่มด่านนี้ไว้ให้ล้มตั้งแต่ก่อนส่ง แทนที่จะไปพังบนเว็บจริง
step "ตรวจว่าไม่มีไฟล์ตกหล่นจากรายการ"
LISTED=" ${ROOT_FILES[*]} ${API_FILES[*]} ${LIB_FILES[*]} ${MAP_FILES[*]} ${DATA_FILES[*]} "
MISSING_FROM_LIST=()

# ตรวจทั้ง PHP ฝั่งหลังบ้าน และไฟล์หน้าเว็บที่เบราว์เซอร์ต้องโหลด
# เคยพลาดมาแล้วทั้งสองแบบ: รอบแรก endpoint PHP หลายตัวไม่เคยขึ้น production
# รอบที่สอง map.js กับไฟล์เส้นชายฝั่งเกือบตกค้างเพราะด่านเดิมดูแต่ .php
while IFS= read -r found; do
  [[ "$LISTED" == *" $found "* ]] || MISSING_FROM_LIST+=("$found")
done < <({
  find api -name '*.php'
  find . -maxdepth 1 \( -name '*.js' -o -name '*.css' -o -name '*.html' \) -printf '%P\n'
  # desktop.ini คือไฟล์ที่ Google Drive แทรกไว้ทุกโฟลเดอร์ ไม่ใช่ของโปรเจกต์
  find map -type f ! -name desktop.ini 2>/dev/null
  # แค่ชั้นบนสุด — data/raw/ เป็นไฟล์ดิบที่ .gitignore กันไว้ ไม่ต้องขึ้น production
  find data -maxdepth 1 -type f ! -name desktop.ini 2>/dev/null
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

for f in "${ROOT_FILES[@]}" "${API_FILES[@]}" "${LIB_FILES[@]}" "${MAP_FILES[@]}" "${DATA_FILES[@]}"; do
  [ -f "$f" ] || die "ไม่พบ $f"
done

# ---------- 4. เทียบกับเว็บจริง ----------
# เซิร์ฟเวอร์ตั้งแคชไฟล์สแตติกไว้ 10 ปี (max-age=315360000) ถ้า app.js หรือ css เปลี่ยน
# แต่เลขเวอร์ชันไม่ขยับ ?v= จะเหมือนเดิม คนที่เคยเข้าเว็บจะได้ไฟล์เก่าจากแคชไปตลอด
# check-version.mjs ตรวจได้แค่ว่าเลขตรงกันทุกที่ ตรวจไม่ได้ว่าลืมขยับ — ต้องเทียบกับของจริงตรงนี้
step "เทียบกับเว็บจริง"
local_version() { sed -n "s/^const APP_VERSION = '\([^']*\)'.*/\1/p" app.js | tr -d '\r'; }
LOCAL_VERSION="$(local_version)"
LIVE_VERSION="$(curl -s --max-time 20 "$SITE_URL/" | sed -n 's/.*id="appVersion">v\([0-9.]*\)<.*/\1/p' | head -1)"
BUMPED=0

if [ -z "$LIVE_VERSION" ]; then
  echo "อ่านเวอร์ชันบนเว็บไม่ได้ ข้ามการเทียบ"
else
  echo "บนเว็บ v$LIVE_VERSION · ในเครื่อง v$LOCAL_VERSION"
  CHANGED=()
  for f in styles.css design.css fonts.css app.js map.js; do
    # ต่อ query ที่ไม่ซ้ำ เพื่อให้ได้ไฟล์จากเซิร์ฟเวอร์จริง ไม่ใช่จากแคชระหว่างทาง
    # ตัด \r ทั้งสองฝั่ง เพราะ Windows ที่ตั้ง core.autocrlf ส่งไฟล์ขึ้นไปเป็น CRLF
    live_sum="$(curl -s --max-time 20 "$SITE_URL/$f?deploy-check=$(date +%s)" | tr -d '\r' | md5sum)"
    mine_sum="$(tr -d '\r' < "$f" | md5sum)"
    [ "$live_sum" = "$mine_sum" ] || CHANGED+=("$f")
  done

  if [ "${#CHANGED[@]}" -gt 0 ] && [ "$LIVE_VERSION" = "$LOCAL_VERSION" ]; then
    echo
    echo "ไฟล์เหล่านี้ต่างจากบนเว็บ แต่เลขเวอร์ชันยังเป็น v$LOCAL_VERSION เท่ากับบนเว็บ"
    printf '  %s\n' "${CHANGED[@]}"
    echo "ถ้าส่งไปแบบนี้ คนที่เคยเข้าเว็บจะยังเห็นของเก่าจากแคช"
    if [ "$ASSUME_YES" -eq 0 ] && ask_yes "ขยับเป็นเวอร์ชันถัดไปให้เลยไหม"; then
      node scripts/bump-version.mjs
      node scripts/check-version.mjs
      LOCAL_VERSION="$(local_version)"
      BUMPED=1
    else
      die "ขยับเวอร์ชันก่อน: node scripts/bump-version.mjs"
    fi
  elif [ "${#CHANGED[@]}" -eq 0 ]; then
    echo "ไฟล์หน้าเว็บตรงกับบนเว็บทั้งหมด (ส่ง PHP ข้อมูลแผนที่ และฟอนต์ซ้ำตามปกติ)"
  fi

  # ส่งของที่เก่ากว่าทับของใหม่ — เกิดได้ถ้าทำงานหลายเครื่องแล้วลืม pull
  if [ "$LIVE_VERSION" != "$LOCAL_VERSION" ] \
     && [ "$(printf '%s\n%s\n' "$LIVE_VERSION" "$LOCAL_VERSION" | sort -V | tail -1)" = "$LIVE_VERSION" ]; then
    echo
    echo "⚠ บนเว็บเป็น v$LIVE_VERSION ใหม่กว่าในเครื่อง (v$LOCAL_VERSION) — กำลังจะส่งโค้ดที่เก่ากว่าทับขึ้นไป"
    ask_yes "แน่ใจว่าจะส่งเวอร์ชันเก่ากว่าขึ้นไป" || die "ยกเลิก — git pull ก่อนแล้วลองใหม่"
  fi
fi

# ---------- 5. สรุปก่อนส่ง ----------
step "จะส่งอะไรขึ้นไป"
if git rev-parse --git-dir > /dev/null 2>&1; then
  BRANCH="$(git rev-parse --abbrev-ref HEAD)"
  echo "จาก branch $BRANCH @ $(git rev-parse --short HEAD)"
  # ไม่บล็อก — ทำงานคนเดียวส่งจาก branch ได้ แค่ให้เห็นว่ากำลังส่งอะไร
  if timeout 15 git fetch -q origin main 2> /dev/null; then
    behind="$(git rev-list --count HEAD..origin/main)"
    [ "$behind" -eq 0 ] || echo "⚠ main บน GitHub มี $behind commit ที่เครื่องนี้ยังไม่มี — ส่งไปจะทับงานนั้นบนเว็บ"
  fi
  DIRTY="$(git status --porcelain --untracked-files=no | wc -l | tr -d ' ')"
  [ "$DIRTY" -eq 0 ] || echo "มีไฟล์ที่แก้แต่ยังไม่ commit $DIRTY ไฟล์ — จะถูกส่งขึ้นไปตามที่อยู่ในเครื่อง"
fi
echo "เวอร์ชัน v$LOCAL_VERSION · ${#ROOT_FILES[@]} ไฟล์หน้าเว็บ · $(( ${#API_FILES[@]} + ${#LIB_FILES[@]} )) ไฟล์ PHP" \
     "· ${#MAP_FILES[@]} ชั้นแผนที่ · ${#DATA_FILES[@]} ไฟล์ข้อมูล · ${#FONT_FILES[@]} ฟอนต์"
echo "ปลายทาง ${TARGET}:${DEPLOY_DOCROOT}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
BATCH="$WORK/batch.sftp"

{
  echo "-mkdir ${DEPLOY_DOCROOT}/api"
  echo "-mkdir ${DEPLOY_DOCROOT}/api/lib"
  echo "-mkdir ${DEPLOY_DOCROOT}/fonts"
  echo "-mkdir ${DEPLOY_DOCROOT}/map"
  echo "-mkdir ${DEPLOY_DOCROOT}/data"
  for f in "${ROOT_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/$(basename "$f")"; done
  for f in "${API_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/api/$(basename "$f")"; done
  for f in "${LIB_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/api/lib/$(basename "$f")"; done
  for f in "${MAP_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/map/$(basename "$f")"; done
  for f in "${DATA_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/data/$(basename "$f")"; done
  for f in "${FONT_FILES[@]}"; do echo "put $f ${DEPLOY_DOCROOT}/fonts/$(basename "$f")"; done
} > "$BATCH"

# ---------- 6. ไฟล์ตั้งค่าเซิร์ฟเวอร์ (เฉพาะ --setup) ----------
if [ "$DO_SETUP" -eq 1 ]; then
  step "เตรียมไฟล์ตั้งค่าสำหรับเซิร์ฟเวอร์"
  [ -f "$REPO_ROOT/.env" ] || die "ไม่พบ .env ในโปรเจค — ต้องใช้ค่าฐานข้อมูลจากไฟล์นี้"

  # บนเซิร์ฟเวอร์ PHP กับ MySQL อยู่เครื่องเดียวกัน ต่อผ่าน localhost ไม่ใช่ชื่อโดเมน
  # ตัด \r ก่อน เพราะ .env ที่แก้บน Windows อาจเป็น CRLF แล้วรหัสผ่านจะมี \r ติดท้าย
  tr -d '\r' < "$REPO_ROOT/.env" | awk -F= '
    /^[[:space:]]*(#|$)/ { next }
    $1 ~ /^(DB_NAME|DB_USER|DB_PASSWORD|DB_PORT)$/ { print; next }
  ' > "$WORK/server.env"
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

# ---------- 7. ยืนยัน ----------
echo
[ "$DO_SETUP" -eq 1 ] && echo "โหมด --setup: จะส่ง .env และ .htaccess ขึ้นไปด้วย"
ask_yes "ส่งขึ้น fishing.yru.ac.th เลยไหม" || { echo "ยกเลิก ไม่ได้ส่งอะไรขึ้นไป"; exit 1; }

# ---------- 8. ส่งไฟล์ ----------
step "ส่งไฟล์ผ่าน SFTP"
sftp "${SFTP_OPTS[@]}" -b "$BATCH" "$TARGET" > "$WORK/sftp.log" 2>&1 \
  || { cat "$WORK/sftp.log" >&2; die "ส่งไฟล์ไม่สำเร็จ"; }
echo "ส่งเสร็จ $(grep -c '^put ' "$BATCH") ไฟล์"

# ---------- 9. ตรวจสุขภาพ ----------
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

LIVE_NOW="$(curl -s --max-time 20 "$SITE_URL/?deploy-check=$(date +%s)" | sed -n 's/.*id="appVersion">v\([0-9.]*\)<.*/\1/p' | head -1)"
echo
echo "deploy สำเร็จ — เว็บตอนนี้เป็น v${LIVE_NOW:-?}"
if [ "$BUMPED" -eq 1 ]; then
  echo "เลขเวอร์ชันถูกขยับในเครื่อง อย่าลืม commit app.js กับ index.html"
fi
