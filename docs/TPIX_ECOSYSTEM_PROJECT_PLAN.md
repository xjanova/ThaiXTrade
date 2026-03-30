# TPIX Ecosystem — Project Plan
# เอกสารโครงการ TPIX Ecosystem

> **Version:** 1.0.0
> **Date:** 2026-03-17
> **Developer:** Xman Studio
> **Status:** Planning Phase

---

## 1. ภาพรวมโครงการ (Project Overview)

TPIX Ecosystem ประกอบด้วย 2 โปรเจคหลักที่ทำงานร่วมกัน:

```
┌─────────────────────────────────────────────────────────┐
│                   TPIX ECOSYSTEM                        │
│                                                         │
│  ┌─────────────────────┐   ┌─────────────────────────┐  │
│  │   ThaiPrompt         │   │   TPIX TRADE (DEX)      │  │
│  │   Affiliate          │   │   ThaiXTrade             │  │
│  │                      │   │                          │  │
│  │  • TPIX Blockchain   │   │  • Trading (BSC)         │  │
│  │    (Chain ID: 4289)  │◄─►│  • Swap (Multi-chain)    │  │
│  │  • Native Coin TPIX  │   │  • Portfolio              │  │
│  │  • DEX on TPIX Chain │   │  • AI Assistant           │  │
│  │  • Token Factory     │   │  • Wallet Management      │  │
│  │  • Master Node       │   │                           │  │
│  │  • Affiliate System  │   │  Stack:                   │  │
│  │                      │   │  Laravel 11 + Vue 3       │  │
│  │  Stack:              │   │  + Inertia + Tailwind     │  │
│  │  Laravel + Polygon   │   │                           │  │
│  │  Edge + Solidity     │   │  Repo: xjanova/           │  │
│  │                      │   │    ThaiXTrade              │  │
│  │  Repo: xjanova/      │   │                           │  │
│  │  Thaiprompt-Affiliate│   │                           │  │
│  └─────────────────────┘   └──────────────────────────┘  │
└─────────────────────────────────────────────────────────┘
```

---

## 2. เหรียญ TPIX — ข้อมูลทางเทคนิค (Token Specification)

### 2.1 พารามิเตอร์หลัก

| รายการ | ค่า |
|---|---|
| **ชื่อ** | TPIX |
| **สัญลักษณ์** | TPIX |
| **ประเภท** | Native Coin (ไม่ใช่ ERC-20/BEP-20) |
| **Blockchain** | TPIX Chain (Polygon Edge, IBFT consensus) |
| **Chain ID** | 4289 (mainnet), 4290 (testnet) |
| **Decimals** | 18 |
| **Total Supply** | 7,000,000,000 (7 พันล้าน — คงที่, ไม่สามารถ mint เพิ่ม) |
| **ราคาเริ่มต้น** | $0.10 USD |
| **Block Time** | 2 วินาที |
| **Gas Price** | 0 (gasless transactions บน mainnet) |
| **Block Gas Limit** | 30,000,000 |
| **EVM Compatible** | ใช่ — รองรับ ERC-20 tokens และ smart contracts |

### 2.2 การกระจายเหรียญ (Genesis Allocation)

```
Total Supply: 7,000,000,000 TPIX

┌──────────────────────────────────────────────────────┐
│ ████████████████████                    Team     20% │ 1,400,000,000
│ ██████████                              Dev      10% │   700,000,000
│ ██████████████████████████████          Liquid   30% │ 2,100,000,000
│ ████████████████████                    Master Node  20% │ 1,400,000,000
│ ██████████                              Eco      10% │   700,000,000
│ ██████████                              Public   10% │   700,000,000
└──────────────────────────────────────────────────────┘
```

| ส่วน | เปอร์เซ็นต์ | จำนวน TPIX | วัตถุประสงค์ |
|---|---|---|---|
| **Team** | 20% | 1,400,000,000 | ทีมพัฒนาและผู้ก่อตั้ง |
| **Development** | 10% | 700,000,000 | พัฒนาระบบและ infrastructure |
| **Liquidity** | 30% | 2,100,000,000 | สภาพคล่องใน DEX (TPIX Chain + BSC) |
| **Master Node Rewards** | 20% | 1,400,000,000 | รางวัลสำหรับ node operators |
| **Ecosystem** | 10% | 700,000,000 | partnerships, grants, marketing |
| **Public Sale** | 10% | 700,000,000 | จำหน่ายสาธารณะ |

---

## 3. สถาปัตยกรรม Smart Contracts (Contract Architecture)

### 3.1 Contracts บน TPIX Chain

อยู่ใน `tpix-blockchain/contracts/` ของโปรเจค ThaiPrompt-Affiliate:

```
tpix-blockchain/contracts/
├── TPIXERC20.sol           # Base ERC-20 สำหรับ token ที่สร้างบน TPIX Chain
├── TPIXDEXFactory.sol      # Factory สร้าง liquidity pairs (Uniswap V2 style)
├── TPIXDEXPair.sol         # AMM pair — constant product (x*y=k)
├── TPIXDEXRouter02.sol     # Router สำหรับ swap และ liquidity
├── TPIXDEXLibrary.sol      # คำนวณ AMM math
├── interfaces/
│   ├── ITPIXDEXFactory.sol
│   ├── ITPIXDEXPair.sol
│   ├── ITPIXDEXRouter02.sol
│   └── ITPIXDEXCallee.sol  # Flash swap callback
└── libraries/
    ├── SafeMath.sol
    ├── Math.sol            # sqrt
    ├── UQ112x112.sol       # Fixed-point math
    └── TransferHelper.sol
```

### 3.2 DEX Parameters

| พารามิเตอร์ | ค่า |
|---|---|
| **Swap Fee** | 0.3% total |
| — LP Share | 0.25% (ผู้ให้สภาพคล่อง) |
| — Protocol Fee | 0.05% (แพลตฟอร์ม) |
| **Pool Creation Fee** | 10 TPIX |
| **Default Slippage** | 0.5% |
| **Price Impact Warning** | 5% |
| **Price Impact Critical** | 15% |
| **LP Token** | "TPIX LP Token" / "TPIX-LP" |
| **Pair Creation** | CREATE2 (deterministic addresses) |

### 3.3 Token Factory (สร้าง Token บน TPIX Chain) — Phase 2

ผู้ใช้สามารถสร้าง ERC-20 และ ERC-721 token ของตัวเองบน TPIX Chain ผ่าน 5-step wizard:

| พารามิเตอร์ | ค่า |
|---|---|
| **ค่าสร้าง Token** | 50-150+ TPIX (dynamic pricing ตาม type + options) |
| **Token Types** | 10 ประเภท (4 basic ERC-20 + utility + reward + governance + stablecoin + NFT + collection) |
| **Sub-Options** | 16 ออฟชั่นเสริม (pausable, blacklist, tax, anti-whale, royalty, vesting ฯลฯ) |
| **Supply Range** | 1 — 999,999,999,999,999 |
| **Testnet** | ฟรี (TPIX Testnet 4290, Sepolia, BSC Testnet) |
| **Auto-Verify** | Contract verify อัตโนมัติบน Blockscout |

**Token Categories:**
- **Fungible (ERC-20):** Standard, Mintable, Burnable, Mintable+Burnable, Utility, Reward
- **NFT (ERC-721):** Single NFT, NFT Collection
- **Special:** Governance (ERC20Votes), Stablecoin (freeze/KYC)

**Smart Contracts (11 ไฟล์):**
- `TPIXTokenFactory.sol` — V1 factory (basic types)
- `TPIXTokenFactoryV2.sol` — V2 factory (all ERC-20 types)
- `TPIXNFTFactory.sol` — NFT factory
- `FactoryERC20V2.sol`, `UtilityToken.sol`, `RewardToken.sol`
- `GovernanceToken.sol`, `StablecoinToken.sol`
- `FactoryERC721.sol`, `NFTCollection.sol`

---

## 4. ระบบ Master Node

### 4.1 TPIX Master Node

| ระยะเวลา Lock | APY |
|---|---|
| Flexible (ไม่ lock) | 4% |
| 7 วัน | 5% |
| 30 วัน | 7% |
| 90 วัน | 9% |
| 180 วัน | 12% |
| 365 วัน | 15% |

- แจกรางวัลทุก 1 ชั่วโมง
- Auto-compound รองรับ (ค่าธรรมเนียม 0.1%)
- ถอนก่อนกำหนด: ปรับ 10% (แจกให้ node operators ที่เหลือ)
- Master Node ขั้นต่ำ: 1 TPIX

### 4.2 Investment/ROI Master Node

- ระบบ ROI-based distribution
- รองรับ rank bonus multipliers
- Auto-compound สร้าง position ใหม่อัตโนมัติ
- คำนวณ ROI รายวัน พร้อมติดตาม maturity

---

## 5. ระบบ Affiliate/Referral

| พารามิเตอร์ | ค่า |
|---|---|
| **Referrer Reward** | 5% ของ transaction |
| **Referee Reward** | 2% ของ transaction |
| **Max Reward/Referral** | 1,000 TPIX |
| **Referral Code Format** | `TPIX` + 8 ตัวอักษร |
| **Token Referral Code** | `TK-` + 8 ตัวอักษร |
| **หมดอายุ** | ไม่หมดอายุ (default) |
| **เงื่อนไข** | ต้อง verify email ก่อนรับรางวัล |
| **สกุลเงินรางวัล** | TPIX หรือ custom token |

---

## 6. Admin — Coin Control

ระบบจัดการเหรียญสำหรับ admin:

| ฟังก์ชัน | รายละเอียด |
|---|---|
| **Mint** | สร้าง token ใหม่ (เฉพาะ user-created tokens, ไม่ใช่ native TPIX) |
| **Burn** | ทำลาย token |
| **Freeze/Unfreeze** | ระงับ/ปลดระงับ address |
| **Pause/Unpause** | หยุด/เปิด contract ชั่วคราว |

**ข้อจำกัดความปลอดภัย:**
- ต้องมี admin approval + 2FA + เหตุผลประกอบ
- Mint/Burn สูงสุด 1,000,000 ต่อวัน
- Cooldown 24 ชั่วโมงระหว่างแต่ละ operation
- บันทึก audit trail ทุกรายการ

---

## 7. แผนบูรณาการ TPIX TRADE ↔ TPIX Chain

### 7.1 ปัญหาปัจจุบัน

```
TPIX TRADE (DEX)          TPIX Chain
─────────────────          ──────────
ทำงานบน BSC               Chain ID: 4289
(Chain ID: 56)             Native coin: TPIX
                           ยังไม่ deploy contracts
ไม่รู้จักเหรียญ TPIX       RPC: localhost only
```

### 7.2 แนวทางที่เสนอ (3 Phases)

```
Phase 1                    Phase 2                    Phase 3
──────────────────         ──────────────────         ──────────────────
Deploy TPIX Chain          Bridge TPIX → BSC          Full Multi-Chain
• Validators setup         • Wrapped TPIX (wTPIX)     • TPIX Chain DEX
• Genesis block            • BEP-20 on BSC            • Cross-chain swap
• Explorer setup           • Liquidity pools           • Unified portfolio
• RPC endpoints            • TPIX/BNB, TPIX/USDT     • Master Node on both
                           • Trade on TPIX TRADE
```

---

### Phase 1: Deploy TPIX Chain (Infrastructure)

**เป้าหมาย:** TPIX Chain พร้อมใช้งานจริง

| ขั้นตอน | รายละเอียด | สถานะ |
|---|---|---|
| 1.1 | ตั้งค่า Validator nodes (IBFT consensus, ขั้นต่ำ 4 nodes) | TODO |
| 1.2 | สร้าง Genesis block (7B TPIX allocation) | TODO |
| 1.3 | Deploy TPIX Chain Mainnet (Chain ID: 4289) | TODO |
| 1.4 | ตั้งค่า RPC endpoints (public + private) | TODO |
| 1.5 | Deploy Block Explorer | TODO |
| 1.6 | Deploy DEX contracts (Factory, Router, WETH) | TODO |
| 1.7 | สร้าง initial liquidity pools | TODO |
| 1.8 | ทดสอบ testnet (Chain ID: 4290) ก่อน mainnet | TODO |

**ต้องการ:**
- เซิร์ฟเวอร์สำหรับ validator nodes (แนะนำ 4+ nodes)
- Domain สำหรับ RPC และ Explorer
- Private keys สำหรับ genesis accounts

---

### Phase 2: Bridge TPIX → BSC (Integration กับ TPIX TRADE)

**เป้าหมาย:** เทรด TPIX ได้บน TPIX TRADE (BSC)

| ขั้นตอน | รายละเอียด | ไฟล์ที่เกี่ยวข้อง |
|---|---|---|
| 2.1 | สร้าง Wrapped TPIX (wTPIX) BEP-20 contract บน BSC | `contracts/WTPIX.sol` (ใหม่) |
| 2.2 | Deploy bridge contract (TPIX Chain ↔ BSC) | `contracts/TPIXBridge.sol` (ใหม่) |
| 2.3 | เพิ่ม wTPIX token ใน `config/chains.php` | `config/chains.php` |
| 2.4 | สร้าง TPIX/BNB และ TPIX/USDT trading pairs | DB: `trading_pairs` |
| 2.5 | เพิ่มสภาพคล่อง wTPIX/BNB, wTPIX/USDT บน PancakeSwap | On-chain tx |
| 2.6 | เพิ่มราคา TPIX บนหน้าแรก TPIX TRADE | `resources/js/Pages/Home.vue` |
| 2.7 | เพิ่ม TPIX เป็น featured token ใน swap page | `resources/js/Pages/Swap.vue` |
| 2.8 | สร้าง Bridge UI สำหรับ TPIX ↔ wTPIX | `resources/js/Pages/Bridge.vue` (ใหม่) |

**Backend (TPIX TRADE):**

```
app/
├── Http/Controllers/Api/
│   └── BridgeController.php          # API สำหรับ bridge operations
├── Services/
│   ├── BridgeService.php             # Logic bridge TPIX ↔ wTPIX
│   └── TPIXPriceService.php          # ดึงราคา TPIX
├── Models/
│   └── BridgeTransaction.php         # บันทึก bridge transactions
```

**Frontend (TPIX TRADE):**

```
resources/js/
├── Pages/
│   └── Bridge.vue                    # หน้า Bridge UI
├── Components/
│   └── Bridge/
│       ├── BridgeForm.vue            # ฟอร์ม bridge
│       ├── BridgeHistory.vue         # ประวัติ bridge
│       └── BridgeStatus.vue          # สถานะ real-time
```

---

### Phase 3: Full Multi-Chain (TPIX Chain + BSC)

**เป้าหมาย:** TPIX TRADE รองรับทั้ง BSC และ TPIX Chain เต็มรูปแบบ

| ขั้นตอน | รายละเอียด |
|---|---|
| 3.1 | เพิ่ม TPIX Chain (4289) ใน `config/chains.php` |
| 3.2 | อัปเดต ChainSelector รองรับ TPIX Chain |
| 3.3 | สร้าง cross-chain swap (BSC ↔ TPIX Chain) |
| 3.4 | Unified Portfolio — แสดง holdings ทั้ง 2 chains |
| 3.5 | Master Node UI ใน TPIX TRADE |
| 3.6 | Affiliate integration — referral rewards ใน DEX |
| 3.7 | Token Factory UI — สร้าง token ผ่าน TPIX TRADE |

---

## 8. สถาปัตยกรรมทางเทคนิค (Technical Architecture)

### 8.1 Multi-Chain Flow

```
ผู้ใช้ (Browser)
    │
    ▼
┌──────────────┐
│  TPIX TRADE  │  Laravel 11 + Vue 3 + Inertia
│  (Frontend)  │
└──────┬───────┘
       │
       ├──── MetaMask / Trust Wallet ────┐
       │                                  │
       ▼                                  ▼
┌──────────────┐                 ┌──────────────┐
│   BSC (56)   │                 │ TPIX (4289)  │
│              │  ◄── Bridge ──► │              │
│ • wTPIX/BNB  │                 │ • Native TPIX│
│ • wTPIX/USDT │                 │ • DEX Pools  │
│ • PancakeSwap│                 │ • Master Node│
└──────────────┘                 └──────────────┘
```

### 8.2 Bridge Architecture

```
TPIX Chain                          BSC
──────────                          ───
  TPIX (native)                     wTPIX (BEP-20)
     │                                 │
     ▼                                 ▼
  Lock in Bridge  ──── Relay ────►  Mint wTPIX
  Contract           Service        Contract
     ▲                                 │
     │                                 ▼
  Unlock TPIX    ◄──── Relay ────  Burn wTPIX
  Contract           Service        Contract

  * Relay Service = backend process ตรวจสอบ events ทั้ง 2 ฝั่ง
```

### 8.3 ราคา TPIX (Price Feed)

```
แหล่งราคา:
  1. TPIX Chain DEX pools (on-chain)
  2. BSC PancakeSwap wTPIX pools (on-chain)
  3. ราคาเฉลี่ยถ่วงน้ำหนัก (TWAP)

  Backend Service:
  app/Services/TPIXPriceService.php
    → Cache ราคาทุก 30 วินาที
    → API: GET /api/v1/tpix/price
    → WebSocket: real-time price updates
```

---

## 9. Database Schema เพิ่มเติม

### สำหรับ TPIX TRADE (ThaiXTrade)

```sql
-- Bridge transactions
CREATE TABLE bridge_transactions (
    id             BIGINT PRIMARY KEY,
    uuid           CHAR(36) UNIQUE,
    wallet_address VARCHAR(42) NOT NULL,
    direction      ENUM('tpix_to_bsc', 'bsc_to_tpix') NOT NULL,
    amount         DECIMAL(36,18) NOT NULL,
    fee_amount     DECIMAL(36,18) DEFAULT 0,
    source_tx_hash VARCHAR(66),       -- tx hash บน chain ต้นทาง
    dest_tx_hash   VARCHAR(66),       -- tx hash บน chain ปลายทาง
    status         ENUM('pending', 'confirming', 'completed', 'failed') DEFAULT 'pending',
    source_chain_id INT NOT NULL,
    dest_chain_id   INT NOT NULL,
    created_at     TIMESTAMP,
    updated_at     TIMESTAMP
);

-- TPIX price history
CREATE TABLE tpix_price_history (
    id         BIGINT PRIMARY KEY,
    price_usd  DECIMAL(20,8) NOT NULL,
    volume_24h DECIMAL(20,2),
    source     VARCHAR(50),           -- 'tpix_dex', 'pancakeswap', 'aggregated'
    chain_id   INT,
    recorded_at TIMESTAMP
);

-- Master Node positions (TPIX TRADE side)
CREATE TABLE master_node_positions (
    id             BIGINT PRIMARY KEY,
    uuid           CHAR(36) UNIQUE,
    wallet_address VARCHAR(42) NOT NULL,
    pool_name      VARCHAR(100),
    amount         DECIMAL(36,18) NOT NULL,
    lock_period    INT DEFAULT 0,        -- วัน (0 = flexible)
    apy            DECIMAL(8,4),
    rewards_earned DECIMAL(36,18) DEFAULT 0,
    chain_id       INT NOT NULL,
    status         ENUM('active', 'withdrawn', 'matured') DEFAULT 'active',
    staked_at      TIMESTAMP,
    matures_at     TIMESTAMP NULL,
    created_at     TIMESTAMP,
    updated_at     TIMESTAMP
);
```

---

## 10. API Endpoints ใหม่

### TPIX TRADE — เพิ่มเติม

```
# ราคา TPIX
GET    /api/v1/tpix/price              # ราคาปัจจุบัน + 24h change
GET    /api/v1/tpix/price/history      # ราคาย้อนหลัง (chart data)

# Bridge
POST   /api/v1/bridge/initiate         # เริ่ม bridge transaction
GET    /api/v1/bridge/status/{uuid}    # ตรวจสอบสถานะ
GET    /api/v1/bridge/history          # ประวัติ bridge ของ wallet
GET    /api/v1/bridge/fees             # ค่าธรรมเนียม bridge

# Master Node
GET    /api/v1/master-node/pools       # รายการ master node network
POST   /api/v1/master-node/stake       # stake TPIX
POST   /api/v1/master-node/unstake     # ถอน stake
GET    /api/v1/master-node/positions   # positions ของ wallet
POST   /api/v1/master-node/claim       # claim rewards

# TPIX Chain Info
GET    /api/v1/tpix/chain/info         # ข้อมูล TPIX Chain
GET    /api/v1/tpix/chain/stats        # สถิติ (blocks, txs, validators)
```

---

## 11. ลำดับการพัฒนา (Development Priority)

### ระยะสั้น (สัปดาห์ 1-2)

- [ ] ตั้งค่า TPIX Chain testnet (Chain ID: 4290)
- [ ] Deploy DEX contracts บน testnet
- [ ] เพิ่ม TPIX Chain ใน `config/chains.php` ของ TPIX TRADE
- [ ] ทดสอบ chain switching ไปยัง TPIX Chain

### ระยะกลาง (สัปดาห์ 3-4)

- [ ] สร้าง Wrapped TPIX (wTPIX) BEP-20 contract
- [ ] Deploy bridge contracts (ทั้ง 2 chains)
- [ ] สร้าง Bridge UI ใน TPIX TRADE
- [ ] เพิ่ม TPIX/BNB, TPIX/USDT trading pairs
- [ ] แสดงราคา TPIX บนหน้าแรก

### ระยะยาว (สัปดาห์ 5-8)

- [ ] Deploy TPIX Chain mainnet (Chain ID: 4289)
- [ ] Bridge relay service (production)
- [ ] Master Node UI ใน TPIX TRADE
- [ ] Cross-chain portfolio
- [ ] Affiliate integration
- [ ] Token Factory UI
- [ ] Security audit (smart contracts)

---

## 12. ความเสี่ยงและข้อควรระวัง

| ความเสี่ยง | ระดับ | แนวทางจัดการ |
|---|---|---|
| Smart contract bugs | สูง | Audit ก่อน mainnet, ใช้ testnet ทดสอบ |
| Bridge security | สูง | Multi-sig, time-lock, rate limiting |
| Validator centralization | กลาง | กระจาย validators หลายเจ้า |
| Liquidity fragmentation | กลาง | Incentivize liquidity ด้วย master node rewards |
| Gas price = 0 (spam risk) | กลาง | Rate limiting per address, anti-spam |
| Price manipulation | กลาง | TWAP oracle, minimum liquidity requirements |

---

## 13. โครงสร้างไฟล์ที่ต้องเพิ่มใน TPIX TRADE

```
ThaiXTrade/
├── app/
│   ├── Http/Controllers/Api/
│   │   ├── BridgeController.php          # [ใหม่] Bridge API
│   │   └── MasterNodeController.php      # [ใหม่] Master Node API
│   ├── Services/
│   │   ├── BridgeService.php             # [ใหม่] Bridge logic
│   │   ├── TPIXPriceService.php          # [ใหม่] ราคา TPIX
│   │   └── MasterNodeService.php         # [ใหม่] Master Node logic
│   └── Models/
│       ├── BridgeTransaction.php         # [ใหม่]
│       └── MasterNodePosition.php        # [ใหม่]
├── config/
│   └── chains.php                        # [แก้ไข] เพิ่ม TPIX Chain
├── contracts/
│   ├── WTPIX.sol                         # [ใหม่] Wrapped TPIX (BEP-20)
│   └── TPIXBridge.sol                    # [ใหม่] Bridge contract
├── database/migrations/
│   ├── xxxx_create_bridge_transactions.php
│   ├── xxxx_create_tpix_price_history.php
│   └── xxxx_create_master_node_positions.php
├── resources/js/
│   ├── Pages/
│   │   ├── Bridge.vue                    # [ใหม่] Bridge page
│   │   └── MasterNode.vue                # [ใหม่] Master Node page
│   ├── Components/
│   │   ├── Bridge/
│   │   │   ├── BridgeForm.vue            # [ใหม่]
│   │   │   ├── BridgeHistory.vue         # [ใหม่]
│   │   │   └── BridgeStatus.vue          # [ใหม่]
│   │   └── MasterNode/
│   │       ├── MasterNodePools.vue       # [ใหม่]
│   │       ├── MasterNodeForm.vue        # [ใหม่]
│   │       └── MasterNodePositions.vue   # [ใหม่]
│   └── Composables/
│       ├── useTPIXPrice.js               # [ใหม่] real-time TPIX price
│       └── useBridge.js                  # [ใหม่] bridge composable
└── routes/
    └── api.php                           # [แก้ไข] เพิ่ม bridge + master node routes
```

---

## 14. ข้อมูลอ้างอิง

| รายการ | ลิงก์/ที่อยู่ |
|---|---|
| ThaiPrompt-Affiliate Repo | `github.com/xjanova/Thaiprompt-Affiliate` |
| TPIX TRADE Repo | `github.com/xjanova/ThaiXTrade` |
| TPIX Chain Config | `Thaiprompt-Affiliate/config/tpix.php` |
| Smart Contracts | `Thaiprompt-Affiliate/tpix-blockchain/contracts/` |
| Hardhat Config | `Thaiprompt-Affiliate/tpix-blockchain/hardhat.config.js` |
| Token Factory Service | `Thaiprompt-Affiliate/app/Services/TPIX/TokenFactoryService.php` |
| DEX Service | `Thaiprompt-Affiliate/app/Services/TPIX/DEXService.php` |
| Referral Service | `Thaiprompt-Affiliate/app/Services/TPIX/ReferralService.php` |

---

*เอกสารนี้สร้างโดย Xman Studio เพื่อเป็นแนวทางในการพัฒนา TPIX Ecosystem*
*อัปเดตล่าสุด: 2026-03-17*
