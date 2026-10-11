# Standard JSON input สำหรับยืนยันซอร์สบน Blockscout

`App\Services\BlockscoutVerifyService` ใช้ไฟล์ในโฟลเดอร์นี้ยืนยันซอร์สของเหรียญที่แฟกทอรีสร้าง
(เหรียญเกิดจาก `CREATE` ภายในแฟกทอรี — Blockscout ยืนยันจาก runtime bytecode ได้ตามปกติ)

ทีมสัญญา (repo TPIX-Coin) เป็นคน generate ไฟล์ — ห้ามเขียนเองด้วยมือ

## ไฟล์ที่ต้องมี (หนึ่งไฟล์ต่อสัญญา)

| ไฟล์ | token_type |
|---|---|
| `FactoryERC20V2.json` | standard, mintable, burnable, mintable_burnable |
| `UtilityToken.json` | utility |
| `RewardToken.json` | reward |
| `GovernanceToken.json` | governance |
| `StablecoinToken.json` | stablecoin |
| `FactoryERC721.json` | nft |
| `NFTCollection.json` | nft_collection |

## รูปแบบ

```json
{
  "compiler_version": "v0.8.24+commit.e11b9ed9",
  "contract_name": "src/token-factory/FactoryERC20V2.sol:FactoryERC20V2",
  "input": { "language": "Solidity", "sources": { "...": { "content": "..." } }, "settings": { "evmVersion": "london", "viaIR": true, "optimizer": { "enabled": true, "runs": 200 }, "outputSelection": { "*": { "*": ["*"] } } } }
}
```

- `input` คือ solc standard JSON input ก้อนเดียวกับที่ใช้คอมไพล์สัญญาที่ deploy จริง (settings ต้องตรงทุกค่า)
- `contract_name` อยู่ในรูป `<path ใน sources>:<ชื่อสัญญา>`

## ถ้าไม่มีไฟล์

การ deploy ไม่ล้ม — `metadata.explorer_verification` ของเหรียญจะเป็น
`{"status": "skipped", "message": "skipped: missing standard input"}`
