#!/usr/bin/env bash
# ทยอยอัปตอนที่เรนเดอร์เสร็จขึ้น production แล้วหยุดเองเมื่อทั้ง 9 ตอนบนเซิร์ฟเวอร์
# มีขนาดตรงกับไฟล์ในเครื่องครบทุกตัว
#
# ⚠️ ห้ามอัปไฟล์ที่ ffmpeg ยังเขียนอยู่ — เช็คสองชั้น:
#    (1) ไฟล์ต้องไม่ถูกแตะมาแล้วอย่างน้อย 45 วินาที
#    (2) ขนาดต้องนิ่งตลอด 15 วินาที
#    รอบแรกที่เขียนไว้หลวมกว่านี้ อัปไฟล์ครึ่ง ๆ กลาง ๆ ขึ้นไปจริง
set -u
cd "$(dirname "$0")/.."

KEY="$HOME/.ssh/thaiprompt_admin"
HOST="admin@123.253.62.251"
RDIR="/home/admin/domains/tpix.online/public_html/videos/whitepaper"
SSHOPT="-i $KEY -o StrictHostKeyChecking=accept-new -o BatchMode=yes"

remote_size() { ssh $SSHOPT "$HOST" "stat -c%s $RDIR/$1 2>/dev/null || echo 0"; }

settled() {                       # $1 = path
  local now mtime s1 s2
  now=$(date +%s); mtime=$(stat -c%Y "$1")
  [ $((now - mtime)) -lt 45 ] && return 1
  s1=$(stat -c%s "$1"); sleep 15; s2=$(stat -c%s "$1")
  [ "$s1" = "$s2" ]
}

for round in $(seq 1 400); do
  for f in out/*.mp4; do
    [ -e "$f" ] || continue
    name=$(basename "$f")
    settled "$f" || { echo "… $name ยังเขียนอยู่ ข้ามไปก่อน"; continue; }

    local_size=$(stat -c%s "$f")
    [ "$(remote_size "$name")" = "$local_size" ] && continue

    echo "↑ $name  ($((local_size/1024/1024)) MB)"
    if scp $SSHOPT "$f" "$HOST:$RDIR/"; then echo "  ok"; else echo "  FAILED"; fi
  done

  # จบเมื่อครบ 9 ตอน และทุกตัวขนาดตรงกับบนเซิร์ฟเวอร์
  # grep -c คืนเลข 0 พร้อม exit 1 พอต่อ || echo 0 เลยได้สองบรรทัด แล้ว [ ] พัง
  n=$(ls out/*.mp4 2>/dev/null | wc -l)
  if grep -q "FINAL PASS DONE" tmp/batch3.log 2>/dev/null && [ "$n" -ge 9 ]; then
    mismatch=0
    for f in out/*.mp4; do
      [ "$(remote_size "$(basename "$f")")" = "$(stat -c%s "$f")" ] || mismatch=$((mismatch+1))
    done
    if [ "$mismatch" -eq 0 ]; then
      echo "=== UPLOAD DONE — $n ตอนขึ้น production ครบ ขนาดตรงทุกไฟล์ ==="
      break
    fi
    echo "ยังไม่ตรง $mismatch ไฟล์ — วนอีกรอบ"
  fi
  sleep 90
done
