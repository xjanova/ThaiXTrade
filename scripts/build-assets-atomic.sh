#!/usr/bin/env bash
#
# TPIX TRADE — build หน้าเว็บแบบไม่มีช่วงที่ CSS/JS หายจากเว็บจริง
# Developed by Xman Studio
#
# ทำไมต้องมี (2026-10-20 — มือถือเปิดเว็บแล้วไม่มี CSS ทั้งเว็บ):
#   เดิม deploy รัน `vite build` ทับ public_html/build ตรง ๆ — vite ล้างโฟลเดอร์ก่อน build
#   ระหว่าง 1-3 นาทีนั้นหน้าเว็บยังชี้ไฟล์ตาม manifest เก่าที่ถูกลบไปแล้ว → 404
#   Cloudflare (Browser Cache TTL ของโซน) เขียนทับ 404 นั้นเป็น max-age=31536000
#   เบราว์เซอร์มือถือจึงจำว่า "ไฟล์ CSS นี้ไม่มี" ไปหนึ่งปี และชื่อไฟล์ไม่เปลี่ยน
#   ถ้าไม่ได้แก้ CSS → หน้าเว็บเป็นตัวหนังสือล้วนจนกว่าผู้ใช้จะล้างข้อมูลเว็บเอง
#
# วิธีใหม่:
#   1. build ลง public_html/build.next (โฟลเดอร์จริงไม่ถูกแตะ)
#   2. ยกไฟล์เก่าที่ยังไม่หมดอายุไปด้วย — หน้าที่เปิดค้าง/HTML ที่แคชไว้ยังโหลด chunk เดิมได้
#   3. สลับโฟลเดอร์ด้วย mv (เสี้ยววินาที) build ล้ม = ไม่สลับ เว็บยังใช้ชุดเดิม
#
# ใช้: bash scripts/build-assets-atomic.sh   (รันจากรากโปรเจกต์)

set -euo pipefail

PUBLIC_DIR="public_html"
LIVE="${PUBLIC_DIR}/build"
NEXT="${PUBLIC_DIR}/build.next"
PREV="${PUBLIC_DIR}/build.prev"
# ไฟล์ของ build ก่อน ๆ เก็บไว้กี่วัน (ชื่อไฟล์มีแฮช ไม่ชนกับของใหม่)
KEEP_OLD_DAYS="${KEEP_OLD_DAYS:-14}"

rm -rf "${NEXT}" "${PREV}"

# --outDir ส่งผ่าน inline config → laravel-vite-plugin ใช้ค่านี้แทน public_html/build
node ./node_modules/vite/bin/vite.js build --outDir "${NEXT}" --emptyOutDir

if [ ! -s "${NEXT}/manifest.json" ]; then
    echo "❌ build ไม่มี manifest.json — ไม่สลับ เว็บยังใช้ชุดเดิม" >&2
    rm -rf "${NEXT}"
    exit 1
fi

if [ -d "${LIVE}/assets" ]; then
    # -n ไม่ทับไฟล์ใหม่ · -p คง mtime เดิม ไฟล์เก่าจึงหมดอายุตามวันที่ build จริงของมัน
    cp -npr "${LIVE}/assets/." "${NEXT}/assets/"
    find "${NEXT}/assets" -type f -mtime "+${KEEP_OLD_DAYS}" -delete
fi

if [ -d "${LIVE}" ]; then
    mv "${LIVE}" "${PREV}"
fi
mv "${NEXT}" "${LIVE}"
rm -rf "${PREV}"

echo "✅ assets พร้อม: $(find "${LIVE}/assets" -type f | wc -l) ไฟล์ (รวมของเก่าไม่เกิน ${KEEP_OLD_DAYS} วัน)"
