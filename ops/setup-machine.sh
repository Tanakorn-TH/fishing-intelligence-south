#!/usr/bin/env bash
#
# เตรียมเครื่องนี้ให้ deploy ขึ้น fishing.yru.ac.th ได้
#
#   ops/deploy.sh เรียกสคริปต์นี้ให้เองทุกครั้ง เครื่องที่พร้อมแล้วจะจบในบรรทัดเดียว
#   รันเองก็ได้:  bash ops/setup-machine.sh
#
# ทำเฉพาะส่วนที่ยังขาด ตามลำดับนี้
#   1. ~/.fishing-secrets/deploy.env   ค่าเชื่อมต่อ — ค่าทุกตัวรู้อยู่แล้ว จึงสร้างให้เลย
#   2. ~/.ssh/fishing_deploy           กุญแจ SSH ของเครื่องนี้
#   3. ติดตั้งกุญแจบนเซิร์ฟเวอร์       ใช้รหัส FTP ของบัญชี fishing หนึ่งครั้งต่อเครื่อง
#
# ข้อ 3 ต้องผ่าน FTPS เพราะบัญชี fishing เป็น sftp-only ใช้ ssh-copy-id ไม่ได้
# รหัส FTP ไม่ถูกเก็บลงไฟล์ใด ๆ — พิมพ์ครั้งเดียว ส่งให้ curl ทาง stdin แล้วทิ้ง

set -euo pipefail

CONFIG="${FISHING_DEPLOY_CONFIG:-$HOME/.fishing-secrets/deploy.env}"
DEFAULT_KEY="$HOME/.ssh/fishing_deploy"

# ใบรับรอง FTPS ของเซิร์ฟเวอร์เป็น self-signed ของ Hestia (CN=host154.yru.ac.th) ตรวจกับ CA ไม่ได้
# จึงตรึงกุญแจสาธารณะของใบรับรองไว้แทนการปิดการตรวจเฉย ๆ รหัส FTP จะไม่ถูกส่งให้เครื่องอื่น
# ถ้าเซิร์ฟเวอร์เปลี่ยนใบรับรอง สคริปต์จะหยุด — วิธีอัปเดตค่านี้อยู่ใน ops/README.md
FTP_PIN="sha256//5vw7WeGyobuZMVptlIcH1oSo87LrMxToYaV2dbM0obM="

die() { echo "ล้มเหลว: $*" >&2; exit 1; }

# ---------- 1. ไฟล์ตั้งค่า ----------
if [ ! -f "$CONFIG" ]; then
  mkdir -p "$(dirname "$CONFIG")"
  cat > "$CONFIG" <<EOF
# สร้างโดย ops/setup-machine.sh — ค่าเชื่อมต่อของ ops/deploy.sh เก็บไว้นอก repo
DEPLOY_HOST=fishing.yru.ac.th
DEPLOY_USER=fishing
DEPLOY_DOCROOT=/home/fishing/web/fishing.yru.ac.th/public_html
DEPLOY_PRIVATE=/home/fishing/web/fishing.yru.ac.th/private
DEPLOY_SSH_KEY=$DEFAULT_KEY
DEPLOY_PORT=22
EOF
  echo "สร้างไฟล์ตั้งค่า $CONFIG"
fi
# shellcheck disable=SC1090
set -a; . "$CONFIG"; set +a
: "${DEPLOY_HOST:?ต้องตั้ง DEPLOY_HOST ใน $CONFIG}"
: "${DEPLOY_USER:?ต้องตั้ง DEPLOY_USER ใน $CONFIG}"
KEY="${DEPLOY_SSH_KEY:-$DEFAULT_KEY}"
PORT="${DEPLOY_PORT:-22}"
FTP_HOST="${FTP_HOST:-$DEPLOY_HOST}"
FTP_USER="${FTP_USER:-$DEPLOY_USER}"

# ---------- 2. กุญแจ ----------
if [ ! -f "$KEY" ]; then
  mkdir -p "$(dirname "$KEY")"
  ssh-keygen -q -t ed25519 -N "" -C "fishing deploy $(hostname) $(date +%F)" -f "$KEY"
  echo "สร้างกุญแจ $KEY"
fi
[ -f "$KEY.pub" ] || ssh-keygen -y -f "$KEY" > "$KEY.pub"

# ---------- 3. เซิร์ฟเวอร์รู้จักกุญแจนี้หรือยัง ----------
# นอกเครือข่ายมหาวิทยาลัย ชื่อนี้ชี้ไป Cloudflare ซึ่งไม่เปิด SSH — แยกกรณีนี้ออกมาก่อน
# ไม่งั้นจะดูเหมือนปัญหากุญแจ แล้วไปเสียรหัส FTP เปล่า ๆ
timeout 10 bash -c "echo > /dev/tcp/$DEPLOY_HOST/$PORT" 2>/dev/null \
  || die "ต่อ $DEPLOY_HOST:$PORT ไม่ได้ — ต้องอยู่ในเครือข่ายมหาวิทยาลัย (หรือ VPN ของมหาวิทยาลัย)"

SFTP_OPTS=(-o StrictHostKeyChecking=accept-new -o ConnectTimeout=15 -o BatchMode=yes
           -o IdentitiesOnly=yes -i "$KEY" -P "$PORT")
can_connect() { echo "pwd" | sftp "${SFTP_OPTS[@]}" -b - "$DEPLOY_USER@$DEPLOY_HOST" > /dev/null 2>&1; }

if can_connect; then
  echo "เครื่องนี้พร้อม deploy ($DEPLOY_USER@$DEPLOY_HOST)"
  exit 0
fi

# ---------- 4. ติดตั้งกุญแจผ่าน FTPS ----------
echo
echo "เซิร์ฟเวอร์ยังไม่รู้จักกุญแจของเครื่องนี้ ต้องติดตั้งหนึ่งครั้งด้วยรหัส FTP ของบัญชี $FTP_USER"
echo "(รหัส FTP กับรหัส SSH ของบัญชีนี้เป็นคนละตัว — ถ้าจำไม่ได้ ตั้งใหม่ได้ใน Hestia หรือขอผู้ดูแลเซิร์ฟเวอร์)"
[ -t 0 ] || die "ต้องรันในหน้าต่างที่พิมพ์รหัสได้"
read -r -s -p "รหัส FTP: " FTP_PASS; echo
[ -n "$FTP_PASS" ] || die "ไม่ได้ใส่รหัส"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# รหัสผ่านเข้า curl ทาง stdin (-K -) ไม่โผล่ใน command line ที่โปรเซสอื่นเห็นได้ และไม่ลงไฟล์
# ในไฟล์ config ของ curl ต้อง escape \ กับ " ส่วนแทนที่เขียนเป็นตัวแปรในเครื่องหมายคำพูด
# เพราะ bash 5.2 ขึ้นไปตีความ \ ในส่วนแทนที่ต่างจากรุ่นเก่า — รหัสที่มี \ จะเพี้ยนเงียบ ๆ
ftp() {
  local bs='\' q='"'
  local esc=${FTP_PASS//"$bs"/"$bs$bs"}
  esc=${esc//"$q"/"$bs$q"}
  printf 'user = "%s:%s"\n' "$FTP_USER" "$esc" \
    | curl -K - -sS --ssl-reqd --insecure --pinnedpubkey "$FTP_PIN" --max-time 30 "$@"
}
ftp_fail() {
  case "$1" in
    67) die "รหัส FTP ไม่ถูก" ;;
    90) die "ใบรับรอง FTPS ของเซิร์ฟเวอร์ไม่ตรงกับที่ตรึงไว้ — ดู ops/README.md หัวข้อใบรับรอง FTPS ก่อนลองใหม่" ;;
    *) die "$2 (curl รหัส $1) $(cat "$WORK/err" 2>/dev/null)" ;;
  esac
}

# อ่านของเดิมมาต่อท้าย ไม่เขียนทับ — กุญแจของเครื่องอื่นที่ยังใช้งานอยู่จะได้ไม่หาย
rc=0
ftp -o "$WORK/authorized_keys" "ftp://$FTP_HOST/.ssh/authorized_keys" 2> "$WORK/err" || rc=$?
case "$rc" in
  0) ;;
  78) : > "$WORK/authorized_keys" ;;  # ยังไม่มีไฟล์ — ครั้งแรกของบัญชีนี้
  *) ftp_fail "$rc" "อ่าน .ssh/authorized_keys บนเซิร์ฟเวอร์ไม่สำเร็จ" ;;
esac

PUB="$(tr -d '\r\n' < "$KEY.pub")"
if grep -qF "$PUB" "$WORK/authorized_keys"; then
  echo "กุญแจนี้อยู่บนเซิร์ฟเวอร์แล้ว ตั้งสิทธิ์ไฟล์ใหม่อย่างเดียว"
else
  if [ -s "$WORK/authorized_keys" ] && [ -n "$(tail -c1 "$WORK/authorized_keys")" ]; then
    echo >> "$WORK/authorized_keys"
  fi
  echo "$PUB" >> "$WORK/authorized_keys"
  rc=0
  ftp --ftp-create-dirs -T "$WORK/authorized_keys" "ftp://$FTP_HOST/.ssh/authorized_keys" 2> "$WORK/err" || rc=$?
  [ "$rc" -eq 0 ] || ftp_fail "$rc" "ส่ง authorized_keys ขึ้นเซิร์ฟเวอร์ไม่สำเร็จ"
  echo "ติดตั้งกุญแจแล้ว (บนเซิร์ฟเวอร์มีกุญแจ $(grep -c . "$WORK/authorized_keys") ตัว รวมของเครื่องนี้)"
fi

# FTP อัปโหลดมาเป็น 664 ซึ่ง sshd ไม่ยอมรับ (StrictModes ห้าม group เขียนได้)
# MFF UNIX.mode ใช้กับเซิร์ฟเวอร์นี้ไม่ได้ ต้องเป็น SITE CHMOD
rc=0
ftp -Q "SITE CHMOD 700 /.ssh" -Q "SITE CHMOD 600 /.ssh/authorized_keys" \
  -l -o /dev/null "ftp://$FTP_HOST/.ssh/" 2> "$WORK/err" || rc=$?
[ "$rc" -eq 0 ] || ftp_fail "$rc" "ตั้งสิทธิ์ .ssh ไม่สำเร็จ"
unset FTP_PASS

if can_connect; then
  echo "เครื่องนี้พร้อม deploy ($DEPLOY_USER@$DEPLOY_HOST)"
  exit 0
fi
die "ติดตั้งกุญแจแล้วแต่ยังเข้า SFTP ไม่ได้ — ดูหัวข้อแก้ปัญหาใน ops/README.md (มักเป็นเรื่องสิทธิ์โฟลเดอร์)"
