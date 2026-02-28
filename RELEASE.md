# Release Guide - TPIX TRADE

คู่มือการสร้าง Release อัตโนมัติสำหรับ TPIX TRADE

## 📋 ภาพรวม

ระบบ Release อัตโนมัติจะทำงานเมื่อคุณสร้าง Git Tag ที่เป็น version (เช่น `v1.0.0`)

### สิ่งที่ระบบจะทำอัตโนมัติ:

✅ Build production assets ด้วย Vite
✅ สร้างไฟล์ package (.tar.gz) สำหรับ deployment
✅ Generate changelog จาก git commits
✅ สร้าง GitHub Release พร้อม release notes
✅ แนบไฟล์ deployment package

---

## 🚀 วิธีการสร้าง Release

### ขั้นตอนที่ 1: Bump Version

ใช้สคริปต์ `bump-version.sh` เพื่ออัปเดต version:

```bash
# Patch release (1.0.0 -> 1.0.1)
./scripts/bump-version.sh patch

# Minor release (1.0.0 -> 1.1.0)
./scripts/bump-version.sh minor

# Major release (1.0.0 -> 2.0.0)
./scripts/bump-version.sh major
```

สคริปต์จะ:
1. อัปเดต `version.json`, `package.json`, `composer.json`
2. สร้าง changelog entry (ถ้าต้องการ)
3. Commit การเปลี่ยนแปลง
4. สร้าง git tag (ถ้าต้องการ)
5. Push tag ไปยัง remote (ถ้าต้องการ)

### ขั้นตอนที่ 2: Push Tag (ถ้ายังไม่ได้ push)

```bash
# Push tag ไปยัง GitHub
git push origin v1.0.1
```

### ขั้นตอนที่ 3: รอ GitHub Actions

GitHub Actions จะ:
1. รันอัตโนมัติเมื่อตรวจพบ tag ใหม่
2. Build production assets
3. สร้าง deployment package
4. สร้าง GitHub Release

คุณสามารถติดตามความคืบหน้าได้ที่:
```
https://github.com/YOUR_USERNAME/ThaiXTrade/actions
```

---

## 📦 สิ่งที่ได้จาก Release

### 1. GitHub Release Page
- Release notes พร้อม changelog
- Installation instructions
- System requirements
- Links ไปยัง documentation

### 2. Deployment Package
ไฟล์: `TPIX-TRADE-v1.0.1.tar.gz`

**ประกอบด้วย:**
- Production-ready code
- Built Vite assets
- Optimized Composer dependencies (no dev)
- Environment example file
- Migration files

**ไม่รวม:**
- `.git/` directory
- `node_modules/`
- `tests/` directory
- `.env` files
- Log files
- Cache files

---

## 🔧 การใช้งาน Deployment Package

### สำหรับ Production Server:

```bash
# 1. Download release
wget https://github.com/YOUR_USERNAME/ThaiXTrade/releases/download/v1.0.1/TPIX-TRADE-v1.0.1.tar.gz

# 2. Extract
tar -xzf TPIX-TRADE-v1.0.1.tar.gz
cd ThaiXTrade

# 3. Setup environment
cp .env.production.example .env
nano .env  # Edit configuration

# 4. Generate app key
php artisan key:generate

# 5. Run migrations
php artisan migrate --force

# 6. Setup permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 7. Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 📝 Best Practices

### 1. Semantic Versioning

ใช้ [Semantic Versioning](https://semver.org/):

- **MAJOR** (1.0.0 -> 2.0.0): Breaking changes
- **MINOR** (1.0.0 -> 1.1.0): New features (backward compatible)
- **PATCH** (1.0.0 -> 1.0.1): Bug fixes

### 2. Commit Messages

ใช้ Conventional Commits:

```bash
feat: add limit order functionality
fix: resolve wallet connection timeout
docs: update API documentation
refactor: optimize trading engine
test: add integration tests for orders
chore: update dependencies
```

### 3. Pre-Release Checklist

ก่อนสร้าง Release:

- [ ] รัน tests ทั้งหมด: `php artisan test`
- [ ] รัน JS tests: `npm run test:run`
- [ ] รัน code quality: `vendor/bin/pint`
- [ ] รัน security audit: `composer audit`
- [ ] อัปเดต CHANGELOG.md
- [ ] ทดสอบ build: `npm run build`
- [ ] Review migration files
- [ ] อัปเดต documentation

### 4. Release Notes

แก้ไข CHANGELOG.md ให้สมบูรณ์ก่อน release:

```markdown
## [1.0.1] - 2026-01-26

### Added
- Limit order support for all trading pairs
- Real-time price alerts via WebSocket
- Export trading history to CSV

### Changed
- Improved order matching algorithm performance by 40%
- Updated UI with new glass morphism effects

### Fixed
- Fixed MetaMask connection timeout on slow networks
- Resolved incorrect balance display after trades
- Fixed chart not updating on mobile devices
```

---

## 🔄 Release Workflow Diagram

```
┌─────────────────────────────────────────────┐
│  Developer                                   │
├─────────────────────────────────────────────┤
│  1. Make changes & commit                   │
│  2. Run: ./scripts/bump-version.sh patch    │
│  3. Review & confirm                         │
│  4. Script creates tag & pushes             │
└───────────────┬─────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────────┐
│  GitHub Actions (Automatic)                 │
├─────────────────────────────────────────────┤
│  1. Detect new tag (v1.0.1)                 │
│  2. Checkout code                            │
│  3. Install dependencies                     │
│  4. Build production assets                  │
│  5. Create deployment package               │
│  6. Generate changelog                       │
│  7. Create GitHub Release                    │
│  8. Upload package as asset                  │
└───────────────┬─────────────────────────────┘
                │
                ▼
┌─────────────────────────────────────────────┐
│  GitHub Release Page                        │
├─────────────────────────────────────────────┤
│  - Release v1.0.1                           │
│  - Changelog & notes                         │
│  - Download package                          │
│  - Installation guide                        │
└─────────────────────────────────────────────┘
```

---

## 🛠️ Manual Release (สำหรับกรณีพิเศษ)

หากต้องการสร้าง tag และ release ด้วยตนเอง:

```bash
# 1. สร้าง tag
git tag -a v1.0.1 -m "Release v1.0.1: Bug fixes and performance improvements"

# 2. Push tag
git push origin v1.0.1

# 3. GitHub Actions จะรันอัตโนมัติ
```

---

## 📊 ตัวอย่าง Release Timeline

```
v1.0.0 (Initial Release)
│
├─ v1.0.1 (Bug fixes)
│   - Fix: MetaMask connection
│   - Fix: Chart rendering
│
├─ v1.1.0 (New features)
│   - Add: Limit orders
│   - Add: Price alerts
│
└─ v2.0.0 (Major update)
    - Breaking: New API structure
    - Add: Advanced charting
    - Add: Multiple wallet support
```

---

## 🔍 Troubleshooting

### ปัญหา: Tag ถูกสร้างแล้วแต่ Release ไม่ถูกสร้าง

**สาเหตุ:** GitHub Actions อาจ fail

**แก้ไข:**
1. ไปที่ Actions tab: `https://github.com/YOUR_USERNAME/ThaiXTrade/actions`
2. ดู workflow run ที่ fail
3. อ่าน error logs
4. แก้ไขปัญหาและสร้าง tag ใหม่ หรือ re-run workflow

### ปัญหา: Build assets ล้มเหลว

**สาเหตุ:** npm dependencies หรือ Vite config ผิดพลาด

**แก้ไข:**
1. ทดสอบ build ใน local: `npm run build`
2. แก้ไข errors
3. Commit และ push
4. ลบ tag เก่า: `git tag -d v1.0.1 && git push origin :refs/tags/v1.0.1`
5. สร้าง tag ใหม่

### ปัญหา: Package ขนาดใหญ่เกินไป

**สาเหตุ:** รวมไฟล์ที่ไม่จำเป็น

**แก้ไข:**
1. ตรวจสอบ `.github/workflows/release.yml`
2. เพิ่ม `--exclude` patterns ในคำสั่ง `tar`
3. Commit และสร้าง release ใหม่

---

## 📞 Support

หากมีปัญหาหรือคำถาม:

- **Issues**: https://github.com/YOUR_USERNAME/ThaiXTrade/issues
- **Discussions**: https://github.com/YOUR_USERNAME/ThaiXTrade/discussions
- **Email**: support@xmanstudio.com
- **Website**: https://xmanstudio.com

---

## 📜 License

TPIX TRADE - Developed by Xman Studio

© 2026 Xman Studio. All rights reserved.
