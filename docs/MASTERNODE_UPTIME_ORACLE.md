# Uptime oracle — ให้รางวัลมาสเตอร์โหนดเฉพาะคนที่รันโหนดจริง

> ฝั่งเว็บ (ThaiXTrade) · ชิ้นอื่นของระบบ: bootnode + cron รายงาน peer อยู่ที่
> `TPIX-Coin/infrastructure/single-host/bootnode/` · heartbeat อยู่ในแอป `TPIX-Masternode`

## ภาพรวม

```
แอปมาสเตอร์โหนด (polygon-edge --seal=false)
  │ libp2p ─────────────► tpix-bootnode (เครื่องเชน)
  │                          └ cron ทุก 1 นาที: peers list → POST /api/infra/node-peers
  │ ทุก 2 นาที: heartbeat รุ่น 2 (ลงนามด้วยกุญแจ libp2p ของโหนด + บล็อกที่โหนดเห็น)
  └──────────────────────► POST /api/v1/node/heartbeat
                              ├ ตรวจ: กระเป๋า→มอบอำนาจ→กุญแจโหนด = peer ID, บล็อกตรงเชน, ไม่ล้าเกิน 60 บล็อก
                              ├ บันทึกช่องเวลา 5 นาที + ประเทศ/เมือง (GeoLite2)
                              └ ทุกชั่วโมง (masternode:uptime-oracle):
                                  uptime = ช่องที่ผ่าน / 12 → updateUptimeBatch ด้วยกุญแจ oracle
```

**ช่องหนึ่ง "ผ่าน"** เมื่อ heartbeat ในช่องนั้นผ่าน **และ** bootnode เห็น peer ID นั้นต่ออยู่
(ช่องเดียวกันหรือช่องติดกัน) — แฮชบล็อกอย่างเดียวพิสูจน์ไม่ได้ว่ารันโหนด เพราะใครก็อ่านจาก RPC สาธารณะได้

**กติกาที่ต้องรู้**
- โหนดที่ stake ไว้แต่ไม่ส่ง heartbeat **ได้ 0** — รวมถึงโหนดที่ลงทะเบียนก่อนตั้ง oracle (เริ่มที่ 100% บนเชน)
- ค่าที่ตั้งตอนจบชั่วโมง H เป็นตัวคูณรางวัลของชั่วโมง H+1 (สัญญา settle ที่ค่าเก่าก่อนเปลี่ยน)
- รอบที่ **ข้อมูลฝั่งเราขาด** (bootnode รายงานไม่ถึง 80% ของนาที หรือเว็บรับ heartbeat ไม่ได้ทั้งรอบ)
  ระบบ **ไม่ลด** uptime ของใคร — ยกขึ้นได้อย่างเดียว
- กุญแจโหนด 1 ตัวผูกได้ 1 กระเป๋า · ย้ายเครื่อง = มอบอำนาจใหม่ (ฉบับที่หมดอายุทีหลังชนะ)
- ไม่ได้ตั้งกุญแจของเราเป็น `uptimeOracle` บนเชน = **โหมดเงา**: คำนวณ + บันทึกครบ แต่ไม่ส่งธุรกรรม

## ตั้งค่าบนเว็บ (หลัง merge — ต้องได้รับอนุมัติก่อน)

```bash
php artisan migrate --force          # ตาราง masternode_nodes / _uptime_slots / _peer_sightings / _peer_reports / _uptime_periods
```

1. **กุญแจ oracle ใหม่** (ห้ามใช้กุญแจ owner/deployer/treasury):
   ```bash
   cd /path/to/tpix-trade
   node -e "const {Wallet}=require('ethers');(async()=>{const w=Wallet.createRandom();const pass=require('crypto').randomBytes(24).toString('base64');require('fs').writeFileSync('storage/app/private/uptime-oracle.json',await w.encrypt(pass),{mode:0o600});console.log('address',w.address);console.log('pass',pass)})()"
   ```
   ใส่ใน `.env`: `MASTERNODE_ORACLE_KEYSTORE_PATH` (พาธเต็ม), `MASTERNODE_ORACLE_KEYSTORE_PASS`, `MASTERNODE_ORACLE_ADDRESS`
   เชนแก๊ส 0 ไม่ต้องเติมเงิน · เก็บ passphrase ไว้ในที่เดียวกับความลับอื่นของเว็บ
2. `TPIX_NODE_PEERS_TOKEN=` สุ่มยาว ≥ 32 ตัว (ตัวเดียวกับที่ใส่ใน `/etc/tpix-node-peers.env` บนเครื่องเชน)
3. `MASTERNODE_PUBLIC_BOOTNODES=/ip4/123.253.62.252/tcp/30301/p2p/<peer id ของ bootnode>` (หลังเปิด bootnode แล้ว)
4. `MASTERNODE_DELEGATION_LIFETIME=15552000` — ถ้า `.env` เดิมตั้ง 2592000 (30 วัน) ไว้ต้องแก้ ไม่งั้นผู้ใช้ต้องกลับมาใส่รหัสทุกเดือน
5. GeoLite2: สมัครบัญชี GeoLite ฟรีที่ maxmind.com → `MAXMIND_ACCOUNT_ID`, `MAXMIND_LICENSE_KEY` → `php artisan geoip:update`
6. `php artisan config:cache` แล้วตรวจ:
   ```bash
   php artisan masternode:uptime-oracle --show-address   # ต้องเห็นที่อยู่กุญแจ oracle และ "ยังไม่ตรง — โหมดเงา"
   php artisan masternode:uptime-oracle --dry-run        # ตารางโหนดทั้งหมด + uptime ที่วัดได้
   ```

## เมื่อไหร่ถึงปลอดภัยที่จะเรียก `setUptimeOracle`

**ห้ามเรียกจนกว่าครบทุกข้อ** — เรียกก่อน = โหนดใหม่ทุกตัวได้ 0 และโหนดที่ stake อยู่ถูกลดเป็น 0 ในรอบแรกที่ระบบส่งจริง

1. สัญญาที่ใช้จริงคือ NodeRegistryV2 รุ่นที่มี `uptimeOracle()` (runtime hash `0x1c49f5e7…16fb`) และ `--show-address` อ่าน `uptimeOracle()` ได้ (ไม่ขึ้น "อ่านไม่ได้")
2. bootnode สาธารณะเปิดแล้ว: จากนอกเครื่อง `Test-NetConnection 123.253.62.252 -Port 30301` = True
3. แอปรุ่นที่ส่ง heartbeat รุ่น 2 ปล่อยแล้ว **และมีไบนารี polygon-edge ที่รันได้บน OS ที่ผู้ใช้ใช้จริง** (upstream ไม่มี Windows)
4. โหมดเงาเดินอย่างน้อย 48 ชม. แล้วดูตาราง `masternode_uptime_periods`:
   ```sql
   -- bootnode รายงานครบไหม (ควร ≥ 0.8 ทุกชั่วโมง)
   SELECT FROM_UNIXTIME(slot*300 - MOD(slot*300,3600)) h, SUM(LEAST(reports,5))/60 coverage
     FROM masternode_peer_reports GROUP BY h ORDER BY h DESC LIMIT 48;
   -- ผลรายชั่วโมงของโหนดทดสอบ
   SELECT FROM_UNIXTIME(period_start), slots_ok, slots_expected, uptime_bps, degraded
     FROM masternode_uptime_periods WHERE wallet_address = '<กระเป๋าโหนดทดสอบ>' ORDER BY period_start DESC LIMIT 48;
   ```
   - โหนดทดสอบที่เรารันเอง ≥ 95% ต่อเนื่อง 48 ชม.
   - ปิดโหนดทดสอบ 1 ชม. → ชั่วโมงนั้นได้ 0% จริง แล้วกลับมา 100% เมื่อเปิด
   - ไม่มีชั่วโมงที่ `degraded = 1` ติดกันเกิน 1 รอบ
5. **แจ้งผู้ stake ทุกคนล่วงหน้า** ว่าหลังวันที่ X ต้องรันแอปถึงจะได้รางวัล และส่วนใหญ่ขึ้นสถานะ "รันจริง" ในโหมดเงาแล้ว
6. `php artisan masternode:uptime-oracle --show-address` → จดที่อยู่ "กุญแจ oracle ในเว็บ" แล้วเรียก
   `setUptimeOracle(<ที่อยู่นั้น>)` ด้วยกุญแจ owner (เจ้าของทำเอง) — ตรวจซ้ำว่าที่อยู่ตรงกันทุกตัวอักษร

หลังตั้ง: รอบชั่วโมงถัดไประบบส่งจริงเอง (`--show-address` ขึ้น "ตรงกัน")
ดูผล: `masternode_uptime_periods.status` = `confirmed` + `tx_hash` เปิดดูใน explorer ได้

## ถอยกลับ

| อาการ | ทำ |
|---|---|
| ตัวเลขผิดหลังเปิดจริง | `setUptimeOracle(0x0000000000000000000000000000000000000000)` ด้วยกุญแจ owner → ระบบกลับโหมดเงาเองรอบถัดไป · โหนดที่ลงทะเบียนหลังจากนั้นเริ่ม 100% |
| คืนค่าให้โหนดที่ถูกตั้งผิด | owner เรียก `updateUptimeBatch([...], [10000, ...])` เอง (≤100 โหนดต่อครั้ง) |
| กุญแจ oracle หลุด | `setUptimeOracle(0x0)` ทันที → สร้างกุญแจใหม่ → ตั้งใหม่ · กุญแจนี้ทำได้แค่ตั้ง uptime ไม่แตะเงินต้น/รางวัลที่ได้ไปแล้ว |
| bootnode ล่มนาน | ไม่ต้องทำอะไร — รอบที่รายงานขาดระบบไม่ลดใคร |

## คำสั่ง

| คำสั่ง | ใช้ทำอะไร |
|---|---|
| `masternode:uptime-oracle` | (cron ทุกชั่วโมง นาทีที่ 2) ปิดรอบล่าสุด |
| `masternode:uptime-oracle --dry-run` | คำนวณให้ดู ไม่บันทึก ไม่ส่ง |
| `masternode:uptime-oracle --period=<unix>` | ทำรอบที่ระบุ (ต้องเป็นต้นชั่วโมงที่ปิดแล้ว) — รอบที่ส่งไปแล้วจะไม่ถูกทับ |
| `masternode:uptime-oracle --show-address` | ที่อยู่กุญแจ oracle เทียบกับบนเชน |
| `geoip:update` | (cron รายสัปดาห์) โหลด GeoLite2-City ใหม่ |

## API

| Endpoint | ใคร | หมายเหตุ |
|---|---|---|
| `POST /api/v1/node/heartbeat` | แอป | รุ่น 2 มี `peer_id`, `block_number`, `block_hash` · รุ่น 1 (ไม่มี peer_id) ยังใช้ได้แต่ไม่นับ uptime |
| `POST /api/infra/node-peers` | cron บนเครื่องเชน | Bearer `TPIX_NODE_PEERS_TOKEN` |
| `GET /api/v1/masternode/live-nodes` | สาธารณะ | ประเทศ/เมือง/ออนไลน์/uptime 24 ชม. — **ไม่มี IP, กระเป๋า หรือ peer ID** |
| `GET /api/v1/masternode/config` | แอป | เพิ่ม `bootnodes`, `delegation_max_lifetime_seconds` |
