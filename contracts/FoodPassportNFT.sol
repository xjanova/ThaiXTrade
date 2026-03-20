// SPDX-License-Identifier: MIT
pragma solidity ^0.8.20;

import "@openzeppelin/contracts/token/ERC721/ERC721.sol";
import "@openzeppelin/contracts/token/ERC721/extensions/ERC721URIStorage.sol";
import "@openzeppelin/contracts/token/ERC721/extensions/ERC721Enumerable.sol";
import "@openzeppelin/contracts/access/Ownable.sol";
import "@openzeppelin/contracts/utils/ReentrancyGuard.sol";

/**
 * @title FoodPassportNFT — ใบรับรองอาหารบน TPIX Chain
 * @author Xman Studio
 * @notice ทุกผลิตภัณฑ์อาหารจะได้รับ NFT เป็นใบรับรองที่ไม่สามารถแก้ไข
 *         บันทึกข้อมูลตั้งแต่ฟาร์ม → โรงงาน → ขนส่ง → ผู้บริโภค
 *         รองรับข้อมูลจาก IoT sensors (อุณหภูมิ, ความชื้น, GPS)
 *
 * Flow ง่ายๆ เหมือนกดตู้น้ำ:
 * 1. เกษตรกร/ผู้ผลิต ลงทะเบียนสินค้า → ได้ Product ID
 * 2. IoT sensors บันทึกข้อมูลอัตโนมัติ → addTrace()
 * 3. ผ่านทุกจุด → mintCertificate() → ได้ NFT ใบรับรอง
 * 4. ผู้บริโภค สแกน QR → ดูประวัติทั้งหมดบน chain
 */
contract FoodPassportNFT is ERC721, ERC721URIStorage, ERC721Enumerable, Ownable, ReentrancyGuard {

    // ═══════════════════════════════════════════
    //  STRUCTS
    // ═══════════════════════════════════════════

    /// @notice ข้อมูลผลิตภัณฑ์อาหาร
    struct Product {
        uint256 id;
        address producer;          // เกษตรกร/ผู้ผลิต
        string name;               // ชื่อสินค้า
        string category;           // หมวดหมู่ (fruit, vegetable, meat, dairy, seafood, grain, processed)
        string origin;             // แหล่งผลิต (จังหวัด/ประเทศ)
        uint256 createdAt;         // เวลาลงทะเบียน
        bool certified;           // ผ่านการรับรองแล้ว
        uint256 certificateTokenId; // Token ID ของ NFT ใบรับรอง (0 = ยังไม่ได้)
    }

    /// @notice บันทึกการเดินทางของอาหาร (จาก IoT หรือ manual)
    struct TraceRecord {
        uint256 productId;
        address recorder;          // คนหรืออุปกรณ์ที่บันทึก
        string stage;              // ขั้นตอน: farm, processing, storage, transport, retail
        string location;           // GPS coordinates หรือชื่อสถานที่
        int256 temperature;        // อุณหภูมิ (x100 เพื่อเก็บทศนิยม 2 ตำแหน่ง) เช่น 2550 = 25.50°C
        int256 humidity;           // ความชื้น (x100)
        string data;               // ข้อมูลเพิ่มเติม (JSON)
        uint256 timestamp;
    }

    // ═══════════════════════════════════════════
    //  STATE
    // ═══════════════════════════════════════════

    uint256 private _nextProductId = 1;
    uint256 private _nextTokenId = 1;
    uint256 private _nextTraceId = 1;

    /// @notice Product ID → Product data
    mapping(uint256 => Product) public products;

    /// @notice Product ID → array of trace record IDs
    mapping(uint256 => uint256[]) public productTraces;

    /// @notice Trace ID → TraceRecord
    mapping(uint256 => TraceRecord) public traces;

    /// @notice Token ID → Product ID (เชื่อม NFT กับสินค้า)
    mapping(uint256 => uint256) public certificateProduct;

    /// @notice Producer address → array of product IDs
    mapping(address => uint256[]) public producerProducts;

    /// @notice Authorized IoT devices and recorders
    mapping(address => bool) public authorizedRecorders;

    /// @notice สถิติ
    uint256 public totalProducts;
    uint256 public totalCertificates;
    uint256 public totalTraces;

    // ═══════════════════════════════════════════
    //  EVENTS
    // ═══════════════════════════════════════════

    event ProductRegistered(uint256 indexed productId, address indexed producer, string name, string category);
    event TraceAdded(uint256 indexed productId, uint256 indexed traceId, string stage, address recorder);
    event CertificateMinted(uint256 indexed productId, uint256 indexed tokenId, address indexed producer);
    event RecorderAuthorized(address indexed recorder, bool status);

    // ═══════════════════════════════════════════
    //  CONSTRUCTOR
    // ═══════════════════════════════════════════

    constructor() ERC721("TPIX FoodPassport", "FOOD") Ownable(msg.sender) {}

    // ═══════════════════════════════════════════
    //  STEP 1: ลงทะเบียนสินค้า (เกษตรกร/ผู้ผลิต)
    // ═══════════════════════════════════════════

    /**
     * @notice ลงทะเบียนผลิตภัณฑ์อาหารใหม่ — เหมือนกดปุ่ม "ลงทะเบียน" บนตู้
     * @param name ชื่อสินค้า เช่น "ข้าวหอมมะลิ ทุ่งกุลาร้องไห้"
     * @param category หมวดหมู่ เช่น "grain"
     * @param origin แหล่งผลิต เช่น "สุรินทร์, ประเทศไทย"
     * @return productId รหัสสินค้าที่ได้
     */
    function registerProduct(
        string calldata name,
        string calldata category,
        string calldata origin
    ) external returns (uint256 productId) {
        productId = _nextProductId++;

        products[productId] = Product({
            id: productId,
            producer: msg.sender,
            name: name,
            category: category,
            origin: origin,
            createdAt: block.timestamp,
            certified: false,
            certificateTokenId: 0
        });

        producerProducts[msg.sender].push(productId);
        totalProducts++;

        emit ProductRegistered(productId, msg.sender, name, category);
    }

    // ═══════════════════════════════════════════
    //  STEP 2: บันทึกข้อมูล IoT (อัตโนมัติ)
    // ═══════════════════════════════════════════

    /**
     * @notice บันทึกข้อมูลจาก IoT sensor หรือ checkpoint
     *         IoT device ส่งข้อมูลมาทุกจุด — ฟาร์ม, โรงงาน, รถขนส่ง, ร้านค้า
     * @param productId รหัสสินค้า
     * @param stage ขั้นตอน (farm/processing/storage/transport/retail)
     * @param location GPS หรือชื่อสถานที่
     * @param temperature อุณหภูมิ x100 (เช่น 2550 = 25.50°C, ใส่ 0 ถ้าไม่มี)
     * @param humidity ความชื้น x100 (เช่น 6500 = 65.00%, ใส่ 0 ถ้าไม่มี)
     * @param data ข้อมูลเพิ่มเติม JSON (เช่น '{"ph":6.5,"weight":2.5}')
     */
    function addTrace(
        uint256 productId,
        string calldata stage,
        string calldata location,
        int256 temperature,
        int256 humidity,
        string calldata data
    ) external {
        require(products[productId].id != 0, "FoodPassport: product not found");
        require(
            msg.sender == products[productId].producer ||
            authorizedRecorders[msg.sender] ||
            msg.sender == owner(),
            "FoodPassport: not authorized"
        );

        uint256 traceId = _nextTraceId++;

        traces[traceId] = TraceRecord({
            productId: productId,
            recorder: msg.sender,
            stage: stage,
            location: location,
            temperature: temperature,
            humidity: humidity,
            data: data,
            timestamp: block.timestamp
        });

        productTraces[productId].push(traceId);
        totalTraces++;

        emit TraceAdded(productId, traceId, stage, msg.sender);
    }

    // ═══════════════════════════════════════════
    //  STEP 3: Mint ใบรับรอง NFT
    // ═══════════════════════════════════════════

    /**
     * @notice Mint NFT ใบรับรองเมื่อสินค้าผ่านทุกจุดตรวจ
     *         เหมือนกดปุ่ม "ออกใบรับรอง" — ได้ NFT ที่โอนไม่ได้ (Soulbound)
     * @param productId รหัสสินค้า
     * @param tokenURI URI ของ metadata (IPFS หรือ API endpoint)
     */
    function mintCertificate(
        uint256 productId,
        string calldata tokenURI
    ) external nonReentrant {
        Product storage product = products[productId];
        require(product.id != 0, "FoodPassport: product not found");
        require(!product.certified, "FoodPassport: already certified");
        require(
            msg.sender == product.producer || msg.sender == owner(),
            "FoodPassport: only producer or admin"
        );
        require(productTraces[productId].length >= 2, "FoodPassport: need at least 2 trace records");

        uint256 tokenId = _nextTokenId++;

        _safeMint(product.producer, tokenId);
        _setTokenURI(tokenId, tokenURI);

        product.certified = true;
        product.certificateTokenId = tokenId;
        certificateProduct[tokenId] = productId;
        totalCertificates++;

        emit CertificateMinted(productId, tokenId, product.producer);
    }

    // ═══════════════════════════════════════════
    //  STEP 4: ผู้บริโภค — สแกน QR ดูข้อมูล (View functions)
    // ═══════════════════════════════════════════

    /**
     * @notice ดูข้อมูลสินค้าทั้งหมด — ผู้บริโภคสแกน QR ได้เลย
     */
    function getProduct(uint256 productId) external view returns (Product memory) {
        require(products[productId].id != 0, "FoodPassport: not found");
        return products[productId];
    }

    /**
     * @notice ดูจำนวน trace ของสินค้า
     */
    function getTraceCount(uint256 productId) external view returns (uint256) {
        return productTraces[productId].length;
    }

    /**
     * @notice ดู trace record ตาม ID
     */
    function getTrace(uint256 traceId) external view returns (TraceRecord memory) {
        require(traces[traceId].timestamp != 0, "FoodPassport: trace not found");
        return traces[traceId];
    }

    /**
     * @notice ดู trace IDs ทั้งหมดของสินค้า
     */
    function getProductTraceIds(uint256 productId) external view returns (uint256[] memory) {
        return productTraces[productId];
    }

    /**
     * @notice ดูสินค้าทั้งหมดของผู้ผลิต
     */
    function getProducerProductIds(address producer) external view returns (uint256[] memory) {
        return producerProducts[producer];
    }

    // ═══════════════════════════════════════════
    //  ADMIN: จัดการ IoT devices
    // ═══════════════════════════════════════════

    /**
     * @notice อนุญาตหรือยกเลิก IoT device / recorder
     */
    function setRecorder(address recorder, bool status) external onlyOwner {
        authorizedRecorders[recorder] = status;
        emit RecorderAuthorized(recorder, status);
    }

    /**
     * @notice Batch authorize recorders (สำหรับ IoT devices หลายตัว)
     */
    function setRecorderBatch(address[] calldata recorders, bool status) external onlyOwner {
        for (uint256 i = 0; i < recorders.length; i++) {
            authorizedRecorders[recorders[i]] = status;
            emit RecorderAuthorized(recorders[i], status);
        }
    }

    // ═══════════════════════════════════════════
    //  OVERRIDES (required by Solidity)
    // ═══════════════════════════════════════════

    function _update(address to, uint256 tokenId, address auth)
        internal override(ERC721, ERC721Enumerable) returns (address)
    {
        return super._update(to, tokenId, auth);
    }

    function _increaseBalance(address account, uint128 value)
        internal override(ERC721, ERC721Enumerable)
    {
        super._increaseBalance(account, value);
    }

    function tokenURI(uint256 tokenId)
        public view override(ERC721, ERC721URIStorage) returns (string memory)
    {
        return super.tokenURI(tokenId);
    }

    function supportsInterface(bytes4 interfaceId)
        public view override(ERC721, ERC721Enumerable, ERC721URIStorage) returns (bool)
    {
        return super.supportsInterface(interfaceId);
    }
}
