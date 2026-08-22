#!/usr/bin/env bash
# เรนเดอร์ทีละตอนต่อกันไป — ตัวเดียวเท่านั้นที่รันพร้อมกัน เพื่อไม่ให้แย่ง CPU
cd "$(dirname "$0")/.."
for ep in "$@"; do
  name=$(python -c "import json;print(json.load(open('script/$ep.json',encoding='utf-8'))['title'].lower().replace(' ','-'))")
  echo "=== $ep ($name) ==="
  node build/render.mjs --scene "scenes/$ep.html" \
       --out "out/$ep-$name.mp4" --audio "tmp/$ep/voice.mp3" 2>&1 | tail -2
done
echo "=== BATCH DONE ==="
