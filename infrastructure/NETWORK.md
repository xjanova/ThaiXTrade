# เส้นทางเครือข่ายระหว่างเครื่องเว็บกับเครื่องเชน

ตั้งค่าเมื่อ 2026-09-20 หลังตามหาสาเหตุ "เว็บช้าเป็นบางครั้ง" — เอกสารนี้อธิบายว่าเครื่อง
production คุยกันยังไง เพราะการตั้งค่าอยู่บนเซิร์ฟเวอร์ ไม่ได้อยู่ในโค้ด (สคริปต์ deploy
จึงไม่ทับ แต่ก็ไม่มีใครเห็นถ้าไม่จดไว้)

## เครื่องที่เกี่ยวข้อง

| เครื่อง | IP | หน้าที่ |
|---|---|---|
| เว็บ | 123.253.62.251 | Laravel (tpix.online) + DirectAdmin + อีก ~50 โดเมน |
| เชน | 123.253.62.252 | nginx + polygon-edge 4 validators + Blockscout |

ทั้งสองอยู่ subnet เดียวกัน วิ่งหากันได้ตรงในระดับ < 1 ms

## ปัญหาที่แก้

เดิมเว็บคุยกับเชนผ่าน Cloudflare (public DNS) ซึ่งเป็นเส้นทางที่ **แกว่งอย่างรุนแรงเป็นช่วง ๆ**
วัดจากเครื่องเว็บ ยิงคำขอเดียวกัน 3 ครั้งได้ 0.29s / 0.41s / **7.2s** (คนละ edge IP)

`SupplyService` ต้องขอยอดคงเหลือ 10 ที่อยู่แบบทีละตัว เจอ edge ช้าไม่กี่ตัวก็กลายเป็น 11–20
วินาที และเพราะ cache มีอายุสั้น **ผู้ใช้คนแรกที่เปิดหน้าแรกหลัง cache หมดคือคนที่ต้องยืนรอ**
ส่วนคนถัดไปเร็วปกติ อาการจึงจับไม่ติด

## เส้นทางปัจจุบัน

**ผู้ใช้ทั่วไป / MetaMask → Cloudflare → เครื่องเชน** (ไม่เปลี่ยน)

**เครื่องเว็บ → เครื่องเชนโดยตรง** ผ่าน `/etc/hosts` บนเครื่องเว็บ:

```
123.253.62.252	rpc.tpix.online rpc1.tpix.online explorer.tpix.online
```

ผลที่วัดได้: RPC 1 คำขอ 7.2s (worst) → **0.073s นิ่งทุกครั้ง** · ดึงครบ 10 ที่อยู่ 11–20s →
**1.25s** · `tpix:treasury-sync` จาก timeout ทุกรอบ → **1.06s** · `cURL error 28` จาก 55
ครั้ง/ชม. → **0**

## ทำไมยังปลอดภัย

ด่านกันยิงถล่มทั้งหมดอยู่ที่ **nginx บนเครื่องเชน ไม่ใช่ที่ Cloudflare** จึงยังทำงานครบ:

- njs gate `/etc/nginx/njs/tpix-rpc.js` — deny-list → allow-list → โควตาเขียนต่อ IP
- `limit_req zone=rpc_per_ip rate=30r/s burst=60` + `limit_conn rpc_conn_per_ip 10`
- fail2ban chain `f2b-tpix-rpc` อยู่ก่อน ufw ใน INPUT

ufw เดิมเปิด 80,443 ให้ **ทุกช่วง IP ของ Cloudflare** (เฉพาะ `104.16.0.0/13` ก็ 500,000+ IP)
การเพิ่มกฎให้ IP เครื่องเว็บเครื่องเดียวจึงทำให้พื้นที่โจมตี **แคบลง ไม่ใช่กว้างขึ้น**:

```bash
ufw allow from 123.253.62.251 to any port 443 proto tcp
```

## ใบรับรอง TLS

เดิมเครื่องเชนใช้ `/etc/ssl/tpix/temp.crt` ซึ่งเป็น self-signed (Cloudflare ตั้ง Full mode
จึงไม่เคยบ่น) — curl/Guzzle ปฏิเสธ ทำให้ยิงตรงไม่ได้ และใบนั้นจะหมดอายุ 4 พ.ย. 2026

ตอนนี้ออกใบจริงจาก Let's Encrypt แล้ว พร้อม auto-renew ที่ certbot ตั้งให้:

- `/etc/letsencrypt/live/rpc1.tpix.online/` — ครอบคลุม `rpc1` + `rpc`
- `/etc/letsencrypt/live/explorer.tpix.online/`

ออกด้วย `certbot certonly --webroot -w /var/www/html -d <domain>` — server block พอร์ต 80
ต้องมี `location ^~ /.well-known/acme-challenge/ { root /var/www/html; }` **ก่อน** การ redirect
และ redirect ต้องอยู่ใน `location / { return 301 ...; }` ไม่ใช่ `return 301` ที่ระดับ server
(ไม่งั้น ACME challenge ไปไม่ถึงและต่ออายุจะเงียบตายในอีก 3 เดือน)

## ⚠️ กับดัก: ห้ามวางไฟล์สำรองใน sites-enabled

`nginx.conf` ใช้ `include /etc/nginx/sites-enabled/*;` — **ไม่ใช่ `*.conf`** ไฟล์อย่าง
`tpix-explorer.conf.bak` จึงถูกโหลดเป็นคอนฟิกจริงทันที เกิด server block ซ้ำและ nginx เสิร์ฟ
ใบรับรองผิด อาการหลอกคือแก้คอนฟิกแล้ว reload แต่ `openssl s_client` ยังได้ใบเก่า

สำรองคอนฟิกไว้ **นอก** โฟลเดอร์ที่ include เสมอ (ของรอบนี้อยู่ที่ `/root/nginx-backups-20260920/`)

## วิธีถอนกลับ

| ที่ | ถอนยังไง |
|---|---|
| เครื่องเว็บ | ลบบรรทัด `123.253.62.252 ...` ใน `/etc/hosts` (สำรอง: `/etc/hosts.bak-20260920`) |
| เครื่องเชน — ufw | `ufw status numbered` แล้ว `ufw delete <เลขกฎ>` ของ 123.253.62.251 |
| เครื่องเชน — nginx | คอนฟิกเดิมอยู่ที่ `/root/nginx-backups-20260920/` |

ถอน `/etc/hosts` อย่างเดียวก็กลับไปใช้เส้นทาง Cloudflare ได้ทันทีโดยไม่ต้องแตะอย่างอื่น

## หมายเหตุ

- IPv6 บนเครื่องเว็บถูกปิดไว้ที่ `/etc/sysctl.conf` (`net.ipv6.conf.all.disable_ipv6 = 1`)
  เป็นการตั้งใจ ไม่ใช่ของพัง และไม่ทำให้ช้า เพราะ `Network is unreachable` เด้งกลับทันที
- ถ้า IP ของเครื่องใดเปลี่ยน ต้องแก้ทั้ง `/etc/hosts` และกฎ ufw
