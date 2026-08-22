#!/usr/bin/env bash
# คัดลอกตอนที่เรนเดอร์เสร็จแล้วไปยัง public_html ทุก 2 นาที จนกว่าจะครบ 9 ตอน
cd "$(dirname "$0")/.."
for i in $(seq 1 200); do
  cp -u out/*.mp4 ../public_html/videos/whitepaper/ 2>/dev/null
  n=$(ls ../public_html/videos/whitepaper/*.mp4 2>/dev/null | wc -l)
  [ "$n" -ge 9 ] && grep -q "BATCH DONE" tmp/batch2.log 2>/dev/null && break
  sleep 120
done
echo "sync finished with $(ls ../public_html/videos/whitepaper/*.mp4 2>/dev/null | wc -l) files"
