// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

import "@openzeppelin/contracts/token/ERC20/ERC20.sol";
import "@openzeppelin/contracts/token/ERC20/extensions/ERC20Burnable.sol";
import "@openzeppelin/contracts/access/Ownable.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

/**
 * @title FDP — FoodPassport Token (ERC-20) บน TPIX Chain
 * @author Xman Studio
 * @notice เหรียญ utility สำหรับระบบ FoodPassport
 *
 *  ══════════════════════════════════════════════════
 *  FDP Token ทำหน้าที่อะไร?
 *  ══════════════════════════════════════════════════
 *
 *  1. REWARD     — เกษตรกรได้ FDP เมื่อสินค้าผ่านการรับรอง (Mint NFT สำเร็จ)
 *  2. PAYMENT    — จ่าย FDP เป็นค่าตรวจสอบคุณภาพ / ค่า IoT service
 *  3. STAKING    — ล็อค FDP เพื่อเพิ่ม Trust Score ของผู้ผลิต
 *  4. GOVERNANCE — ผู้ถือ FDP โหวตเรื่อง food safety standards
 *  5. TRADE      — เทรดได้บน TPIX TRADE DEX (FDP/TPIX pair)
 *
 *  ══════════════════════════════════════════════════
 *  Tokenomics
 *  ══════════════════════════════════════════════════
 *
 *  Total Supply: 100,000,000 FDP (100M, fixed)
 *  - 40% = 40M  → Farmer Rewards Pool (ปล่อยทีละนิดเมื่อ mint certificate)
 *  - 20% = 20M  → Ecosystem Development (IoT infrastructure, partnerships)
 *  - 15% = 15M  → Liquidity (DEX pair FDP/TPIX)
 *  - 10% = 10M  → Team & Advisors (vesting 24 months)
 *  - 10% = 10M  → Community & Marketing
 *  - 5%  = 5M   → Reserve
 *
 *  Chain: TPIX Chain (ID: 4289) — Gas FREE
 */
contract FoodPassportToken is ERC20, ERC20Burnable, Ownable, ReentrancyGuard {

    // ═══════════════════════════════════════════
    //  CONSTANTS
    // ═══════════════════════════════════════════

    uint256 public constant MAX_SUPPLY = 100_000_000 * 10 ** 18; // 100M FDP

    /// @notice จำนวน FDP reward ต่อการ mint certificate 1 ครั้ง
    uint256 public rewardPerCertificate = 100 * 10 ** 18; // 100 FDP

    /// @notice จำนวน FDP ที่ IoT device ได้รับต่อการส่ง trace 1 ครั้ง
    uint256 public rewardPerTrace = 1 * 10 ** 18; // 1 FDP

    /// @notice จำนวน FDP reward ที่แจกไปแล้ว
    uint256 public totalRewardsDistributed;

    /// @notice Budget สำหรับ rewards (40M)
    uint256 public constant REWARDS_BUDGET = 40_000_000 * 10 ** 18;

    // ═══════════════════════════════════════════
    //  ACCESS CONTROL
    // ═══════════════════════════════════════════

    /// @notice FoodPassportNFT contract — มีสิทธิ์เรียก rewardCertificate()
    address public foodPassportContract;

    /// @notice Authorized reward distributors (backend service, IoT gateway)
    mapping(address => bool) public rewardDistributors;

    // ═══════════════════════════════════════════
    //  STAKING (Trust Score)
    // ═══════════════════════════════════════════

    struct Stake {
        uint256 amount;
        uint256 stakedAt;
        uint256 unlockAt;
    }

    /// @notice Producer → Stake info
    mapping(address => Stake) public stakes;

    /// @notice Total FDP staked
    uint256 public totalStaked;

    // ═══════════════════════════════════════════
    //  EVENTS
    // ═══════════════════════════════════════════

    event CertificateReward(address indexed farmer, uint256 productId, uint256 amount);
    event TraceReward(address indexed recorder, uint256 amount);
    event Staked(address indexed producer, uint256 amount, uint256 unlockAt);
    event Unstaked(address indexed producer, uint256 amount);
    event RewardRateUpdated(uint256 perCertificate, uint256 perTrace);
    event FoodPassportContractSet(address indexed contractAddr);
    event DistributorSet(address indexed distributor, bool status);

    // ═══════════════════════════════════════════
    //  CONSTRUCTOR
    // ═══════════════════════════════════════════

    constructor() ERC20("FoodPassport Token", "FDP") Ownable(msg.sender) {
        // Mint initial supply ให้ owner (จะ distribute ตาม tokenomics)
        _mint(msg.sender, MAX_SUPPLY);
    }

    // ═══════════════════════════════════════════
    //  ADMIN: ตั้งค่า
    // ═══════════════════════════════════════════

    function setFoodPassportContract(address addr) external onlyOwner {
        foodPassportContract = addr;
        emit FoodPassportContractSet(addr);
    }

    function setDistributor(address distributor, bool status) external onlyOwner {
        rewardDistributors[distributor] = status;
        emit DistributorSet(distributor, status);
    }

    function setRewardRates(uint256 perCertificate, uint256 perTrace) external onlyOwner {
        rewardPerCertificate = perCertificate;
        rewardPerTrace = perTrace;
        emit RewardRateUpdated(perCertificate, perTrace);
    }

    // ═══════════════════════════════════════════
    //  REWARDS: แจก FDP อัตโนมัติ
    // ═══════════════════════════════════════════

    /**
     * @notice แจก FDP ให้เกษตรกรเมื่อ mint certificate สำเร็จ
     *         เรียกจาก FoodPassportNFT contract หรือ backend
     * @param farmer wallet ของเกษตรกร
     * @param productId รหัสสินค้าที่ได้ใบรับรอง
     */
    function rewardCertificate(address farmer, uint256 productId) external nonReentrant {
        require(
            msg.sender == foodPassportContract || rewardDistributors[msg.sender] || msg.sender == owner(),
            "FDP: not authorized"
        );
        require(totalRewardsDistributed + rewardPerCertificate <= REWARDS_BUDGET, "FDP: rewards budget exceeded");

        totalRewardsDistributed += rewardPerCertificate;
        _transfer(owner(), farmer, rewardPerCertificate);

        emit CertificateReward(farmer, productId, rewardPerCertificate);
    }

    /**
     * @notice แจก FDP ให้ IoT device / recorder เมื่อส่ง trace สำเร็จ
     * @param recorder wallet ของอุปกรณ์หรือผู้บันทึก
     */
    function rewardTrace(address recorder) external nonReentrant {
        require(
            msg.sender == foodPassportContract || rewardDistributors[msg.sender] || msg.sender == owner(),
            "FDP: not authorized"
        );
        require(totalRewardsDistributed + rewardPerTrace <= REWARDS_BUDGET, "FDP: rewards budget exceeded");

        totalRewardsDistributed += rewardPerTrace;
        _transfer(owner(), recorder, rewardPerTrace);

        emit TraceReward(recorder, rewardPerTrace);
    }

    // ═══════════════════════════════════════════
    //  STAKING: เพิ่ม Trust Score
    // ═══════════════════════════════════════════

    /**
     * @notice Stake FDP เพื่อเพิ่ม Trust Score ของผู้ผลิต
     *         ยิ่ง stake มาก → Trust Score ยิ่งสูง → ผู้บริโภคเชื่อมั่นมากขึ้น
     * @param amount จำนวน FDP
     * @param lockDays จำนวนวันที่ล็อค (ขั้นต่ำ 30 วัน)
     */
    function stake(uint256 amount, uint256 lockDays) external nonReentrant {
        require(amount > 0, "FDP: amount must be > 0");
        require(lockDays >= 30, "FDP: minimum 30 days");
        require(stakes[msg.sender].amount == 0, "FDP: already staking, unstake first");

        _transfer(msg.sender, address(this), amount);

        stakes[msg.sender] = Stake({
            amount: amount,
            stakedAt: block.timestamp,
            unlockAt: block.timestamp + (lockDays * 1 days)
        });

        totalStaked += amount;
        emit Staked(msg.sender, amount, stakes[msg.sender].unlockAt);
    }

    /**
     * @notice ถอน FDP ที่ stake ไว้ (หลังครบกำหนด)
     */
    function unstake() external nonReentrant {
        Stake storage s = stakes[msg.sender];
        require(s.amount > 0, "FDP: nothing staked");
        require(block.timestamp >= s.unlockAt, "FDP: still locked");

        uint256 amount = s.amount;
        totalStaked -= amount;
        delete stakes[msg.sender];

        _transfer(address(this), msg.sender, amount);
        emit Unstaked(msg.sender, amount);
    }

    /**
     * @notice ดู Trust Score ของผู้ผลิต (ยิ่ง stake มาก score ยิ่งสูง)
     *         Score = staked amount / 1000 (cap at 100)
     */
    function getTrustScore(address producer) external view returns (uint256) {
        uint256 staked = stakes[producer].amount / 10 ** 18;
        uint256 score = staked / 1000; // 1000 FDP = 1 point
        return score > 100 ? 100 : score;
    }

    /**
     * @notice ดูข้อมูล rewards ที่แจกไป
     */
    function getRewardsInfo() external view returns (
        uint256 distributed,
        uint256 remaining,
        uint256 perCert,
        uint256 perTrc
    ) {
        return (
            totalRewardsDistributed,
            REWARDS_BUDGET - totalRewardsDistributed,
            rewardPerCertificate,
            rewardPerTrace
        );
    }
}
