#!/usr/bin/env bash
# รอบสุดท้าย — เรนเดอร์ใหม่ทั้ง 9 ตอน
#   · เอนจินแก้ลำดับชั้นพื้นหลังแล้ว (เกรน/กริด/ขอบมืดจะขึ้นจริง)
#   · การ์ดเปิดตอนมีภาพประดับ
# ep08 อยู่ลำดับที่ 8 ของคิว ถ้าภาพของมันมาถึงก่อนคิว ฉากจะหยิบไปใช้เอง
set -u
cd "$(dirname "$0")/.."

mkdir -p out/v1
mv out/*.mp4 out/v1/ 2>/dev/null || true

echo "plates at start: $(ls assets/plates/ep0*.jpg 2>/dev/null | wc -l)/9"

bash build/render-all.sh ep01 ep02 ep03 ep04 ep05 ep06 ep07 ep08 ep09

cp -f out/*.mp4 ../public_html/videos/whitepaper/ 2>/dev/null || true
echo "=== FINAL PASS DONE ==="
