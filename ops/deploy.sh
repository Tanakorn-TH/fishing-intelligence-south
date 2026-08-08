#!/usr/bin/env bash
#
# ส่งเว็บและ API ขึ้น fishing.yru.ac.th ผ่าน SSH
#
#   ทดสอบในเครื่อง -> ส่งขึ้นเซิร์ฟเวอร์ -> ตรวจสุขภาพ
#   ถ้าขั้นทดสอบไม่ผ่าน จะไม่ส่งอะไรขึ้นไปเลย
#
# ค่าเชื่อมต่ออยู่นอก repo ที่ ~/.fishing-secrets/deploy.env (ดู deploy.env.example)
# สคริปต์นี้ *ไม่* ส่งไฟล์ .env ขึ้นเซิร์ฟเวอร์ — ตั้งค่านั้นครั้งเดียวแยกต่างหาก ดู ops/README.md
#
# ใช้งาน:  bash ops/deploy.sh            ถามยืนยันก่อนส่ง
#          bash ops/deploy.sh --yes      ไม่ถาม (สำหรับงานอัตโนมัติ)

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CONFIG="${FISHING_DEPLOY_CONFIG:-$HOME/.fishing-secrets/deploy.env}"
ASSUME_YES=0
[ "${1:-}" = "--yes" ] && ASSUME_YES=1

die() { echo "ล้มเหลว: $*" >&2; exit 1; }
step() { echo; echo "=== $* ==="; }

# ---------- 1. อ่านค่าเชื่อมต่อ ----------
[ -f "$CONFIG" ] || die "ไม่พบไฟล์ตั้งค่า $CONFIG — คัดลอกจาก ops/deploy.env.example แล้วเติมค่า"
# shellcheck disable=SC1090
set -a; . "$CONFIG"; set +a

: "${DEPLOY_HOST:?ต้องตั้ง DEPLOY_HOST ใน $CONFIG}"
: "${DEPLOY_USER:?ต้องตั้ง DEPLOY_USER ใน $CONFIG}"
: "${DEPLOY_PATH:?ต้องตั้ง DEPLOY_PATH ใน $CONFIG}"
HEALTH_URL="${HEALTH_URL:-https://${DEPLOY_HOST}/api/health.php}"

SSH_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
[ -n "${DEPLOY_SSH_KEY:-}" ] && SSH_OPTS+=(-i "$DEPLOY_SSH_KEY")
[ -n "${DEPLOY_PORT:-}" ] && SSH_OPTS+=(-p "$DEPLOY_PORT")
TARGET="${DEPLOY_USER}@${DEPLOY_HOST}"

# ---------- 2. ทดสอบก่อน ----------
cd "$REPO_ROOT"
step "ทดสอบในเครื่องก่อนส่ง"
command -v node >/dev/null || die "ไม่พบ node"
node --check app.js
node scripts/check-dom-ids.mjs
npx --yes html-validate@9 index.html
echo "ผ่านครบ"

# ---------- 3. รายการไฟล์ที่จะส่ง ----------
# ประกาศชัดเจนทีละรายการ ไม่ใช้การกวาดทั้งโฟลเดอร์
# เพื่อไม่ให้ .git .env tests scripts หรือไฟล์เอกสารหลุดขึ้น production
PAYLOAD=(index.html styles.css app.js api)

step "ไฟล์ที่จะส่ง"
for f in "${PAYLOAD[@]}"; do
  [ -e "$f" ] || die "ไม่พบ $f"
  echo "  $f"
done
echo "ปลายทาง: ${TARGET}:${DEPLOY_PATH}"

# กันพลาดซ้ำอีกชั้น
for f in "${PAYLOAD[@]}"; do
  case "$f" in .env|.git|.git/*|tests|tests/*) die "รายการส่งมีไฟล์ต้องห้าม: $f" ;; esac
done

# ---------- 4. ยืนยัน ----------
if [ "$ASSUME_YES" -ne 1 ]; then
  echo
  read -r -p "ส่งขึ้น production เลยไหม? พิมพ์ yes เพื่อยืนยัน: " reply
  [ "$reply" = "yes" ] || { echo "ยกเลิก"; exit 1; }
fi

# ---------- 5. ส่งขึ้นเซิร์ฟเวอร์ ----------
step "ตรวจการเชื่อมต่อ"
ssh "${SSH_OPTS[@]}" "$TARGET" "test -d '$DEPLOY_PATH'" \
  || die "ต่อ SSH ไม่ได้ หรือไม่มีโฟลเดอร์ $DEPLOY_PATH บนเซิร์ฟเวอร์"
echo "เชื่อมต่อได้ และพบโฟลเดอร์ปลายทาง"

step "ส่งไฟล์"
# tar ผ่าน ssh — เชื่อมต่อครั้งเดียว รักษาโครงสร้างโฟลเดอร์ ไม่ต้องใช้ rsync
tar czf - "${PAYLOAD[@]}" \
  | ssh "${SSH_OPTS[@]}" "$TARGET" "tar xzf - -C '$DEPLOY_PATH'"
echo "ส่งเสร็จ"

# ---------- 6. ตรวจสุขภาพ ----------
step "ตรวจสุขภาพหลัง deploy"
sleep 2
code=$(curl -s -o /tmp/fishing-health.json -w "%{http_code}" --max-time 30 "$HEALTH_URL" || echo 000)
echo "GET $HEALTH_URL -> $code"
cat /tmp/fishing-health.json 2>/dev/null; echo

if [ "$code" != "200" ]; then
  echo
  echo "health check ไม่ผ่าน — โค้ดขึ้นไปแล้วแต่ยังทำงานไม่ถูก" >&2
  echo "จุดที่ควรดูก่อน: ไฟล์ .env บนเซิร์ฟเวอร์ หรือ extension pdo_mysql" >&2
  exit 1
fi

echo
echo "deploy สำเร็จ"
