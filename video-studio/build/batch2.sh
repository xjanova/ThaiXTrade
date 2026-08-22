#!/usr/bin/env bash
# รอชุดแรกจบก่อน แล้วค่อยเรนเดอร์ชุดที่สอง — ห้ามรันพร้อมกัน CPU จะแย่งกัน
cd "$(dirname "$0")/.."
for i in $(seq 1 720); do
  grep -q "BATCH DONE" tmp/batch.log 2>/dev/null && break
  sleep 15
done
bash build/render-all.sh ep05 ep07 ep08 ep09
