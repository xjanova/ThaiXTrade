/**
 * TPIX Master Node — Renderer (Vue 3 + i18n)
 * Bilingual Thai/English, glass-morphism dark theme
 * Developed by Xman Studio
 */

const { createApp, ref, reactive, computed, onMounted, onUnmounted, watch } = Vue;

// ─── Translations ───────────────────────────────────────────

const LANG = {
    en: {
        switchLang: 'Switch to Thai',
        tabs: {
            dashboard: 'Dashboard',
            instances: 'My Nodes',
            setup: 'Add Node',
            wallet: 'Wallet',
            network: 'Network',
            links: 'Links',
            logs: 'Logs',
            settings: 'Settings',
            about: 'About',
        },
        inst: {
            title: 'My Node Instances',
            noInstances: 'No nodes yet. Click "Add Node" to create one.',
            addNode: 'Add New Node',
            port: 'Port',
            status: 'Status',
            actions: 'Actions',
            start: 'Start',
            stop: 'Stop',
            edit: 'Edit',
            remove: 'Remove',
            confirmRemove: 'Remove this node instance?',
            rewardWallet: 'Reward Wallet',
            rewardWalletDesc: 'TPIX rewards will be sent to this wallet. Default: your main wallet.',
            pendingRewards: 'Pending Rewards',
            totalEarned: 'Total Earned',
            rewardNote: 'Rewards accumulate in the smart contract. Set a reward wallet to receive auto-payouts.',
            rewardNotSet: 'Not set (using main wallet)',
            setWallet: 'Set Reward Wallet',
            useMainWallet: 'Use Main Wallet',
            customWallet: 'Custom Wallet Address',
        },
        dash: {
            nodeStatus: 'Node Status',
            blockHeight: 'Block Height',
            blockAge: 'Block Age',
            validators: 'Validators',
            peers: 'Peers',
            uptime: 'Uptime',
            chainHealthy: 'Chain is Healthy',
            chainHealthyDesc: 'Blocks are being produced every 2 seconds.',
            chainStopped: 'Chain Stopped!',
            chainStoppedDesc: 'Block production has stopped. Validators may be offline. More master nodes needed!',
            startNode: 'Start Node',
            stopNode: 'Stop Node',
            refresh: 'Refresh',
            memory: 'Memory',
        },
        setup: {
            steps: ['Choose Tier', 'Wallet', 'Configure & Run'],
            chooseTierDesc: 'Choose a node tier based on how much TPIX you want to stake. Higher tiers earn more rewards and help secure the network.',
            estimatedReward: 'Estimated Rewards',
            month: 'month',
            year: 'year',
            canAfford: 'Sufficient balance',
            cantAfford: 'Insufficient balance',
            next: 'Next',
            back: 'Back',
            walletDesc: 'Connect or create a wallet to register your node on TPIX Chain.',
            configDesc: 'Review your configuration and start running your master node!',
            tier: 'Tier',
            stake: 'Stake Required',
            reward: 'APY',
            launchNode: 'Launch Node',
            rewardInfo: 'Reward Distribution (3 Years)',
            totalEmission: 'Total Emission',
            total: 'Total',
            rewardNote: 'Rewards are distributed proportionally based on your stake and uptime. Validators earn the highest share. Pool: 1.4 Billion TPIX over 3 years (ending 2028).',
        },
        wallet: {
            setupTitle: 'Set Up Your Wallet',
            setupDesc: 'Create a new wallet or import an existing one to start running your master node.',
            create: 'Create New Wallet',
            import: 'Import Private Key',
            importDesc: 'Paste your private key (0x + 64 hex characters)',
            saveKeyTitle: 'Save Your Private Key!',
            saveKeyDesc: 'This is shown only once. Back it up securely. Anyone with this key controls your funds.',
            show: 'Show',
            hide: 'Hide',
            copy: 'Copy',
            exportKey: 'Export Key',
            clickCopy: 'click to copy',
            neverShare: 'Never share your private key with anyone!',
        },
        net: {
            production: 'Block Production',
            active: 'Active',
            stopped: 'Stopped',
            validatorsTitle: 'Active Validators',
            noValidators: 'No validator data available',
            ibftRequires: 'IBFT2 requires',
            ibftOnline: 'validators online for consensus.',
            faultTolerance: 'Fault tolerance',
            nodesFail: 'nodes can fail.',
            whyRunTitle: 'Why Run a Master Node?',
            whyRunReasons: [
                'Earn TPIX rewards from 1.4 Billion reward pool',
                'Help secure and decentralize the TPIX Chain',
                'More nodes = more stable network (fewer outages)',
                'Support the TPIX ecosystem and community',
                'Node operators earn 4-20% APY on staked TPIX',
            ],
        },
        logs: { empty: 'No logs yet. Start the node to see activity.' },
        settings: {
            nodeName: 'Node Name',
            tier: 'Node Tier',
            rpcUrl: 'RPC URL',
            p2pPort: 'P2P Port',
            maxPeers: 'Max Peers',
            save: 'Save Settings',
            openDir: 'Open Data Directory',
        },
        about: {
            description: 'About',
            descText: 'TPIX Master Node is a desktop application for running validator nodes on the TPIX Chain. It helps secure and decentralize the network while earning TPIX rewards.',
            developer: 'Developer',
            studio: 'Studio',
            license: 'License',
            version: 'Version',
            download: 'Download',
            downloadDesc: 'Get the latest version of TPIX Master Node and other TPIX apps.',
            downloadBtn: 'Go to TPIX Downloads',
            updateTitle: 'Auto Update',
            currentVersion: 'Current Version',
            checking: 'Checking for updates...',
            newVersion: 'New version available:',
            downloadUpdate: 'Download Update',
            readyInstall: 'Update Ready!',
            readyInstallDesc: 'The update has been downloaded. Restart to apply.',
            installRestart: 'Install & Restart',
            upToDate: 'You are up to date',
            checkNow: 'Check Now',
        },
        status: { stopped: 'Stopped', starting: 'Starting...', running: 'Running', syncing: 'Syncing', error: 'Error' },
    },
    th: {
        switchLang: 'Switch to English',
        tabs: {
            dashboard: 'แดชบอร์ด',
            instances: 'โหนดของฉัน',
            setup: 'เพิ่มโหนด',
            wallet: 'กระเป๋าเงิน',
            network: 'เครือข่าย',
            links: 'ลิงก์',
            logs: 'บันทึก',
            settings: 'ตั้งค่า',
            about: 'เกี่ยวกับ',
        },
        inst: {
            title: 'โหนดของฉัน',
            noInstances: 'ยังไม่มีโหนด คลิก "เพิ่มโหนด" เพื่อสร้าง',
            addNode: 'เพิ่มโหนดใหม่',
            port: 'พอร์ต',
            status: 'สถานะ',
            actions: 'จัดการ',
            start: 'เริ่ม',
            stop: 'หยุด',
            edit: 'แก้ไข',
            remove: 'ลบ',
            confirmRemove: 'ต้องการลบโหนดนี้?',
            rewardWallet: 'กระเป๋ารับรางวัล',
            rewardWalletDesc: 'รางวัล TPIX จะส่งไปกระเป๋านี้ ค่าเริ่มต้น: กระเป๋าหลักของคุณ',
            pendingRewards: 'รางวัลรอรับ',
            totalEarned: 'รางวัลรวม',
            rewardNote: 'รางวัลสะสมอยู่ใน smart contract กำหนดกระเป๋ารับรางวัลเพื่อรับอัตโนมัติ',
            rewardNotSet: 'ยังไม่ตั้ง (ใช้กระเป๋าหลัก)',
            setWallet: 'ตั้งกระเป๋ารับรางวัล',
            useMainWallet: 'ใช้กระเป๋าหลัก',
            customWallet: 'กระเป๋าอื่น (กำหนดเอง)',
        },
        dash: {
            nodeStatus: 'สถานะโหนด',
            blockHeight: 'ความสูงบล็อก',
            blockAge: 'อายุบล็อก',
            validators: 'ผู้ตรวจสอบ',
            peers: 'เพียร์',
            uptime: 'เวลาออนไลน์',
            chainHealthy: 'เชนทำงานปกติ',
            chainHealthyDesc: 'กำลังผลิตบล็อกทุก 2 วินาที',
            chainStopped: 'เชนหยุดทำงาน!',
            chainStoppedDesc: 'การผลิตบล็อกหยุดแล้ว ผู้ตรวจสอบอาจออฟไลน์ ต้องการ master node เพิ่ม!',
            startNode: 'เริ่มโหนด',
            stopNode: 'หยุดโหนด',
            refresh: 'รีเฟรช',
            memory: 'หน่วยความจำ',
        },
        setup: {
            steps: ['เลือกระดับ', 'กระเป๋าเงิน', 'ตั้งค่า & เริ่มรัน'],
            chooseTierDesc: 'เลือกระดับโหนดตามจำนวน TPIX ที่ต้องการ stake ระดับสูงกว่าจะได้รับรางวัลมากกว่าและช่วยเพิ่มความปลอดภัยของเครือข่าย',
            estimatedReward: 'รางวัลโดยประมาณ',
            month: 'เดือน',
            year: 'ปี',
            canAfford: 'ยอดเพียงพอ',
            cantAfford: 'ยอดไม่เพียงพอ',
            next: 'ถัดไป',
            back: 'ย้อนกลับ',
            walletDesc: 'เชื่อมต่อหรือสร้างกระเป๋าเงินเพื่อลงทะเบียนโหนดบน TPIX Chain',
            configDesc: 'ตรวจสอบการตั้งค่าและเริ่มรัน master node ของคุณ!',
            tier: 'ระดับ',
            stake: 'ต้อง Stake',
            reward: 'ผลตอบแทน',
            launchNode: 'เริ่มรันโหนด',
            rewardInfo: 'การแจกรางวัล (3 ปี)',
            totalEmission: 'จำนวนที่ปล่อย',
            total: 'รวม',
            rewardNote: 'รางวัลจะแจกตามสัดส่วนของ stake และ uptime ของคุณ Validator ได้ส่วนแบ่งมากที่สุด พูลรวม: 1,400 ล้าน TPIX ตลอด 3 ปี (สิ้นสุด 2028)',
        },
        wallet: {
            setupTitle: 'ตั้งค่ากระเป๋าเงิน',
            setupDesc: 'สร้างกระเป๋าใหม่หรือนำเข้ากระเป๋าที่มีอยู่เพื่อเริ่มรัน master node',
            create: 'สร้างกระเป๋าใหม่',
            import: 'นำเข้า Private Key',
            importDesc: 'วาง private key ของคุณ (0x + 64 ตัวอักษร hex)',
            saveKeyTitle: 'บันทึก Private Key ของคุณ!',
            saveKeyDesc: 'จะแสดงเพียงครั้งเดียว สำรองไว้อย่างปลอดภัย ใครก็ตามที่มี key นี้จะควบคุมเงินของคุณได้',
            show: 'แสดง',
            hide: 'ซ่อน',
            copy: 'คัดลอก',
            exportKey: 'ส่งออก Key',
            clickCopy: 'คลิกเพื่อคัดลอก',
            neverShare: 'อย่าแชร์ private key ของคุณกับใครเด็ดขาด!',
        },
        net: {
            production: 'การผลิตบล็อก',
            active: 'ทำงาน',
            stopped: 'หยุด',
            validatorsTitle: 'ผู้ตรวจสอบที่ใช้งาน',
            noValidators: 'ไม่มีข้อมูลผู้ตรวจสอบ',
            ibftRequires: 'IBFT2 ต้องการ',
            ibftOnline: 'ผู้ตรวจสอบออนไลน์เพื่อ consensus',
            faultTolerance: 'ทนทานต่อความผิดพลาด',
            nodesFail: 'โหนดที่ล่มได้',
            whyRunTitle: 'ทำไมต้องรัน Master Node?',
            whyRunReasons: [
                'รับรางวัล TPIX จากพูล 1,400 ล้าน TPIX',
                'ช่วยรักษาความปลอดภัยและกระจายอำนาจ TPIX Chain',
                'ยิ่งมี node มาก = เครือข่ายยิ่งเสถียร (ล่มน้อยลง)',
                'สนับสนุนระบบนิเวศและชุมชน TPIX',
                'ผู้ดำเนินการโหนดได้ผลตอบแทน 4-20% APY จาก TPIX ที่ stake',
            ],
        },
        logs: { empty: 'ยังไม่มีบันทึก เริ่มโหนดเพื่อดูกิจกรรม' },
        settings: {
            nodeName: 'ชื่อโหนด',
            tier: 'ระดับโหนด',
            rpcUrl: 'RPC URL',
            p2pPort: 'พอร์ต P2P',
            maxPeers: 'เพียร์สูงสุด',
            save: 'บันทึกการตั้งค่า',
            openDir: 'เปิดโฟลเดอร์ข้อมูล',
        },
        about: {
            description: 'เกี่ยวกับ',
            descText: 'TPIX Master Node คือแอปพลิเคชันเดสก์ท็อปสำหรับรัน validator node บน TPIX Chain ช่วยรักษาความปลอดภัยและกระจายอำนาจของเครือข่าย พร้อมรับรางวัล TPIX',
            developer: 'ผู้พัฒนา',
            studio: 'สตูดิโอ',
            license: 'ใบอนุญาต',
            version: 'เวอร์ชัน',
            download: 'ดาวน์โหลด',
            downloadDesc: 'ดาวน์โหลด TPIX Master Node เวอร์ชันล่าสุดและแอปอื่นๆ ของ TPIX',
            downloadBtn: 'ไปหน้าดาวน์โหลด TPIX',
            updateTitle: 'อัปเดตอัตโนมัติ',
            currentVersion: 'เวอร์ชันปัจจุบัน',
            checking: 'กำลังตรวจสอบอัปเดต...',
            newVersion: 'มีเวอร์ชันใหม่:',
            downloadUpdate: 'ดาวน์โหลดอัปเดต',
            readyInstall: 'พร้อมติดตั้ง!',
            readyInstallDesc: 'ดาวน์โหลดอัปเดตเสร็จแล้ว รีสตาร์ทเพื่อติดตั้ง',
            installRestart: 'ติดตั้ง & รีสตาร์ท',
            upToDate: 'เป็นเวอร์ชันล่าสุดแล้ว',
            checkNow: 'ตรวจสอบตอนนี้',
        },
        status: { stopped: 'หยุด', starting: 'กำลังเริ่ม...', running: 'ทำงาน', syncing: 'กำลังซิงค์', error: 'ข้อผิดพลาด' },
    },
};

// ─── App ────────────────────────────────────────────────────

const app = createApp({
    setup() {
        const appVersion = '1.0.0';
        const lang = ref(localStorage.getItem('tpix-lang') || 'th');
        const i18n = computed(() => LANG[lang.value]);
        const activeTab = ref('dashboard');
        const setupStep = ref(0);

        const tabs = [
            { id: 'dashboard',  icon: '&#9661;' },
            { id: 'instances',  icon: '&#9881;' },
            { id: 'setup',      icon: '&#10010;' },
            { id: 'wallet',     icon: '&#128176;' },
            { id: 'network',    icon: '&#127760;' },
            { id: 'links',      icon: '&#128279;' },
            { id: 'logs',       icon: '&#128196;' },
            { id: 'settings',   icon: '&#9881;' },
            { id: 'about',      icon: '&#8505;' },
        ];

        function toggleLang() {
            lang.value = lang.value === 'th' ? 'en' : 'th';
            localStorage.setItem('tpix-lang', lang.value);
        }

        // ─── Tiers ────────────────────────────────
        const tiers = computed(() => [
            {
                id: 'light', name: 'Light Node', stake: 10000,
                apy: '4-6% APY',
                monthlyReward: Math.round(10000 * 0.05 / 12),
                yearlyReward: Math.round(10000 * 0.05),
                features: lang.value === 'th'
                    ? ['Stake ต่ำสุด', 'ล็อค 7 วัน', 'ไม่มีค่าปรับ', 'ไม่จำกัดจำนวน']
                    : ['Lowest stake', '7-day lock', 'No slashing', 'Unlimited nodes'],
            },
            {
                id: 'sentinel', name: 'Sentinel Node', stake: 100000,
                apy: '7-10% APY',
                monthlyReward: Math.round(100000 * 0.085 / 12),
                yearlyReward: Math.round(100000 * 0.085),
                features: lang.value === 'th'
                    ? ['ผลตอบแทนปานกลาง', 'ล็อค 30 วัน', 'ค่าปรับ 5%', 'สูงสุด 500 โหนด']
                    : ['Medium rewards', '30-day lock', '5% slashing', 'Max 500 nodes'],
            },
            {
                id: 'guardian', name: 'Guardian Node', stake: 1000000,
                apy: '10-12% APY',
                monthlyReward: Math.round(1000000 * 0.11 / 12),
                yearlyReward: Math.round(1000000 * 0.11),
                features: lang.value === 'th'
                    ? ['ผลตอบแทนสูง', 'ล็อค 90 วัน', 'ค่าปรับ 10%', 'สูงสุด 100 โหนด']
                    : ['High rewards', '90-day lock', '10% slashing', 'Max 100 nodes'],
            },
            {
                id: 'validator', name: 'Validator Node', stake: 10000000,
                apy: '15-20% APY',
                monthlyReward: Math.round(10000000 * 0.175 / 12),
                yearlyReward: Math.round(10000000 * 0.175),
                features: lang.value === 'th'
                    ? ['ผลตอบแทนสูงสุด', 'ล็อค 180 วัน', 'ค่าปรับ 15%', 'สูงสุด 21 โหนด', 'ผลิตบล็อก IBFT2', 'ต้องผ่าน KYC']
                    : ['Highest rewards', '180-day lock', '15% slashing', 'Max 21 nodes', 'IBFT2 block sealer', 'KYC required'],
            },
        ]);

        const selectedTier = computed(() => tiers.value.find(t => t.id === config.tier));

        // ─── Links ────────────────────────────────
        const linkGroups = computed(() => [
            {
                title: lang.value === 'th' ? 'เว็บไซต์หลัก' : 'Main Websites',
                links: [
                    { icon: '🌐', name: 'TPIX Trade', desc: lang.value === 'th' ? 'แพลตฟอร์มเทรด DEX' : 'DEX Trading Platform', url: 'https://tpix.online' },
                    { icon: '🔍', name: 'Block Explorer', desc: lang.value === 'th' ? 'ดูธุรกรรมบน TPIX Chain' : 'View TPIX Chain transactions', url: 'https://explorer.tpix.online' },
                    { icon: '⬇️', name: 'TPIX Download', desc: lang.value === 'th' ? 'ดาวน์โหลดแอปทั้งหมด' : 'Download all TPIX apps', url: 'https://tpix.online/download' },
                    { icon: '💻', name: 'Xman Studio', desc: lang.value === 'th' ? 'ผู้พัฒนา' : 'Developer', url: 'https://xman4289.com' },
                ],
            },
            {
                title: lang.value === 'th' ? 'เอกสาร' : 'Documentation',
                links: [
                    { icon: '📃', name: 'Whitepaper', desc: lang.value === 'th' ? 'เอกสาร Whitepaper ของ TPIX' : 'TPIX Whitepaper document', url: 'https://tpix.online/whitepaper' },
                    { icon: '📚', name: 'API Docs', desc: lang.value === 'th' ? 'เอกสาร API สำหรับนักพัฒนา' : 'API documentation for developers', url: 'https://tpix.online/api-docs' },
                    { icon: '💻', name: 'GitHub', desc: lang.value === 'th' ? 'ซอร์สโค้ดโอเพนซอร์ส' : 'Open source code', url: 'https://github.com/xjanova/ThaiXTrade' },
                ],
            },
            {
                title: lang.value === 'th' ? 'โซเชียล & ชุมชน' : 'Social & Community',
                links: [
                    { icon: '💬', name: 'Telegram', desc: 'TPIX Community', url: 'https://t.me/tpixtrade' },
                    { icon: '🐦', name: 'Twitter / X', desc: '@TPIXTrade', url: 'https://x.com/TPIXTrade' },
                    { icon: '🎬', name: 'YouTube', desc: 'TPIX Channel', url: 'https://youtube.com/@tpixtrade' },
                    { icon: '💬', name: 'Discord', desc: 'TPIX Discord', url: 'https://discord.gg/tpix' },
                    { icon: '📷', name: 'Facebook', desc: 'TPIX Trade', url: 'https://facebook.com/tpixtrade' },
                ],
            },
            {
                title: lang.value === 'th' ? 'เครื่องมือ Blockchain' : 'Blockchain Tools',
                links: [
                    { icon: '⛓️', name: 'TPIX RPC', desc: 'https://rpc.tpix.online', url: 'https://rpc.tpix.online' },
                    { icon: '💰', name: 'Token Factory', desc: lang.value === 'th' ? 'สร้างเหรียญบน TPIX Chain' : 'Create tokens on TPIX Chain', url: 'https://tpix.online/token-factory' },
                    { icon: '🌱', name: 'Carbon Credits', desc: lang.value === 'th' ? 'ระบบ Carbon Credit' : 'Carbon Credit system', url: 'https://tpix.online/carbon-credit' },
                    { icon: '🔗', name: 'Bridge', desc: 'BSC ↔ TPIX Chain', url: 'https://tpix.online/bridge' },
                ],
            },
        ]);

        // ─── Node State ───────────────────────────
        const nodeStatus = ref('stopped');
        const nodeUptime = ref(0);
        const network = ref({
            blockNumber: 0, blockAge: -1, isProducing: false,
            peerCount: 0, chainId: 4289, validators: [], validatorCount: 0,
        });
        const metrics = ref(null);
        const logs = ref([]);
        const config = reactive({
            nodeName: '', tier: 'light', walletAddress: '',
            rpcUrl: 'https://rpc.tpix.online', p2pPort: 30303, maxPeers: 50,
        });

        // ─── Wallet State ─────────────────────────
        const walletAddress = ref(null);
        const walletBalance = ref('0');
        const walletLoading = ref(false);
        const newWalletData = ref(null);
        const showPrivateKey = ref(false);
        const showImportModal = ref(false);
        const importKeyInput = ref('');
        const importError = ref('');
        const exportedKey = ref(null);

        // ─── Instance State ────────────────────────
        const instances = ref([]);
        const showAddInstance = ref(false);
        const newInstance = reactive({
            nodeName: '', tier: 'light', p2pPort: 30303, rpcPort: 8545,
            bindAddress: '127.0.0.1', walletAddress: '', rewardWallet: '',
        });
        const editingInstance = ref(null);
        const instanceRewardWallet = ref('');
        const addInstanceErrors = ref([]);
        const addInstanceWarnings = ref([]);

        // ─── Update State ─────────────────────────
        const updateStatus = ref({
            checking: false, updateAvailable: false, updateDownloaded: false,
            updateInfo: null, downloadProgress: null, error: null,
        });

        const statusLabel = computed(() => i18n.value.status[nodeStatus.value] || nodeStatus.value);

        // ─── Intervals ────────────────────────────
        let networkInterval, metricsInterval, uptimeInterval;

        // ─── Actions ──────────────────────────────
        async function startNode() {
            const cfg = { ...config };
            if (walletAddress.value) cfg.walletAddress = walletAddress.value;
            nodeStatus.value = 'starting';
            const result = await window.tpix.node.start(cfg);
            if (!result.success) {
                nodeStatus.value = 'error';
                logs.value.push({ time: new Date().toISOString(), level: 'error', message: result.error });
            }
        }
        async function stopNode() {
            await window.tpix.node.stop();
            nodeStatus.value = 'stopped';
            nodeUptime.value = 0;
        }
        function launchNode() {
            activeTab.value = 'dashboard';
            startNode();
        }
        async function refreshNetwork() {
            try {
                const stats = await window.tpix.rpc.getNetworkStats();
                if (stats) network.value = stats;
            } catch {}
        }
        async function refreshMetrics() {
            try { const m = await window.tpix.system.getMetrics(); if (m) metrics.value = m; } catch {}
        }

        // ─── Wallet ───────────────────────────────
        async function loadWallet() {
            try {
                const exists = await window.tpix.wallet.exists();
                if (exists) {
                    walletAddress.value = await window.tpix.wallet.getAddress();
                    await refreshBalance();
                }
            } catch {}
        }
        async function createWallet() {
            walletLoading.value = true;
            try {
                const result = await window.tpix.wallet.create();
                if (result.success) {
                    newWalletData.value = result.data;
                    walletAddress.value = result.data.address;
                    config.walletAddress = result.data.address;
                    saveSettings();
                }
            } finally { walletLoading.value = false; }
        }
        async function importWallet() {
            importError.value = '';
            try {
                const result = await window.tpix.wallet.import(importKeyInput.value.trim());
                if (result.success) {
                    walletAddress.value = result.data.address;
                    config.walletAddress = result.data.address;
                    showImportModal.value = false;
                    importKeyInput.value = '';
                    saveSettings();
                    await refreshBalance();
                } else { importError.value = result.error || 'Import failed'; }
            } catch (e) { importError.value = e.message; }
        }
        async function refreshBalance() {
            try { walletBalance.value = await window.tpix.wallet.getBalance(); } catch { walletBalance.value = '0'; }
        }
        async function showExportKey() {
            try { exportedKey.value = await window.tpix.wallet.exportKey(); } catch {}
        }

        // ─── Settings ─────────────────────────────
        async function loadConfig() {
            try { const c = await window.tpix.node.getConfig(); if (c) Object.assign(config, c); } catch {}
        }
        async function saveSettings() { await window.tpix.node.saveConfig({ ...config }); }
        function openDataDir() { window.tpix.system.openDataDir(); }

        // ─── Instance Actions ─────────────────────
        async function autoFillInstance(tier) {
            try {
                const cfg = await window.tpix.instances.autoConfig(walletAddress.value || '', tier);
                newInstance.nodeName = cfg.nodeName;
                newInstance.tier = cfg.tier;
                newInstance.p2pPort = cfg.p2pPort;
                newInstance.rpcPort = cfg.rpcPort;
                newInstance.bindAddress = cfg.bindAddress;
                newInstance.rewardWallet = cfg.rewardWallet;
                newInstance.walletAddress = cfg.walletAddress;
                addInstanceErrors.value = [];
                addInstanceWarnings.value = cfg._tierInfo
                    ? [`${cfg.tier}: ${cfg._tierInfo.stake.toLocaleString()} TPIX stake, ${cfg._tierInfo.apy} APY`]
                    : [];
            } catch {}
        }

        function portConflict(portKey) {
            const port = newInstance[portKey];
            if (!port) return false;
            for (const inst of instances.value) {
                if (inst.p2pPort === port || inst.rpcPort === port) return true;
            }
            // Also check own ports don't duplicate
            const myPorts = [newInstance.p2pPort, newInstance.rpcPort];
            return myPorts.filter(p => p === port).length > 1;
        }

        async function loadInstances() {
            try { instances.value = await window.tpix.instances.getAll(); } catch {}
        }
        async function addInstance() {
            addInstanceErrors.value = [];
            const opts = { ...newInstance };
            if (!opts.rewardWallet && walletAddress.value) opts.rewardWallet = walletAddress.value;
            if (!opts.walletAddress && walletAddress.value) opts.walletAddress = walletAddress.value;

            // Client-side pre-validation
            try {
                const errs = await window.tpix.instances.validate(opts, null);
                if (errs && errs.length > 0) {
                    addInstanceErrors.value = errs;
                    return { success: false };
                }
            } catch {}

            const r = await window.tpix.instances.add(opts);
            if (r.success) {
                showAddInstance.value = false;
                newInstance.nodeName = '';
                addInstanceErrors.value = [];
                addInstanceWarnings.value = [];
                await loadInstances();
                // Auto-fill for next potential add
                await autoFillInstance('light');
            } else {
                addInstanceErrors.value = (r.error || '').split('\n').filter(Boolean);
            }
            return r;
        }
        async function removeInstance(id) {
            const r = await window.tpix.instances.remove(id);
            if (r.success) await loadInstances();
        }
        async function startInstance(id) {
            await window.tpix.instances.start(id);
            await loadInstances();
        }
        async function stopInstance(id) {
            await window.tpix.instances.stop(id);
            await loadInstances();
        }
        async function setRewardWallet(instanceId, wallet) {
            await window.tpix.instances.update(instanceId, { rewardWallet: wallet });
            await loadInstances();
            editingInstance.value = null;
        }

        // ─── Update Actions ───────────────────────
        async function checkUpdate() {
            try { const r = await window.tpix.update.check(); if (r?.data) updateStatus.value = r.data; } catch {}
        }
        async function downloadUpdate() {
            try { await window.tpix.update.download(); } catch {}
        }
        function installUpdate() {
            try { window.tpix.update.install(); } catch {}
        }

        // ─── Links ────────────────────────────────
        function openLink(key) {
            const map = {
                explorer: 'https://explorer.tpix.online',
                explorerAddr: `https://explorer.tpix.online/address/${walletAddress.value}`,
                download: 'https://tpix.online/download',
                xmanstudio: 'https://xman4289.com',
            };
            const url = map[key] || key;
            window.tpix.system.openExternal(url);
        }

        // ─── Logs ─────────────────────────────────
        async function loadLogs() {
            try { const l = await window.tpix.node.getLogs(200); if (l) logs.value = l; } catch {}
        }

        // ─── Helpers ──────────────────────────────
        function formatNumber(n) { return n ? n.toLocaleString() : '0'; }
        function formatDuration(s) {
            if (!s || s < 0) return 'N/A';
            if (s < 60) return s + 's';
            if (s < 3600) return Math.floor(s / 60) + 'm ' + (s % 60) + 's';
            return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
        }
        function formatMB(mb) { return mb >= 1024 ? (mb / 1024).toFixed(1) + ' GB' : (mb || 0) + ' MB'; }
        function formatLogTime(iso) { try { return new Date(iso).toLocaleTimeString('en-US', { hour12: false }); } catch { return ''; } }
        function copyToClipboard(t) { if (t) navigator.clipboard.writeText(t).catch(() => {}); }
        function formatBytes(b) { if (!b) return '0 B'; if (b < 1024) return b + ' B'; if (b < 1048576) return (b/1024).toFixed(1) + ' KB'; return (b/1048576).toFixed(1) + ' MB'; }
        function shortAddr(a) { return a ? a.slice(0, 8) + '...' + a.slice(-6) : ''; }
        function minimize() { window.tpix.window.minimize(); }
        function maximize() { window.tpix.window.maximize(); }
        function closeWindow() { window.tpix.window.close(); }

        // ─── Lifecycle ────────────────────────────
        onMounted(async () => {
            await loadConfig();
            await loadWallet();
            await loadInstances();
            await refreshNetwork();
            await refreshMetrics();
            await loadLogs();
            try { const s = await window.tpix.node.status(); if (s) { nodeStatus.value = s.status; nodeUptime.value = s.uptime || 0; } } catch {}
            networkInterval = setInterval(refreshNetwork, 15000);
            metricsInterval = setInterval(refreshMetrics, 5000);
            uptimeInterval = setInterval(() => { if (nodeStatus.value === 'running' || nodeStatus.value === 'syncing') nodeUptime.value++; }, 1000);
            window.tpix.node.onStatusUpdate(d => { if (d.status) nodeStatus.value = d.status; if (d.network) network.value = d.network; });
            window.tpix.node.onLog(e => { logs.value.push(e); if (logs.value.length > 500) logs.value.shift(); });
            window.tpix.node.onMetrics(m => { metrics.value = m; });
            // Instance status events
            if (window.tpix.instances) {
                window.tpix.instances.onStatus(async () => { await loadInstances(); });
            }
            // Update events
            if (window.tpix.update) {
                window.tpix.update.onStatus(s => { updateStatus.value = s; });
                window.tpix.update.onProgress(p => { updateStatus.value = { ...updateStatus.value, downloadProgress: p }; });
                try { const s = await window.tpix.update.getStatus(); if (s) updateStatus.value = s; } catch {}
            }
        });
        onUnmounted(() => { clearInterval(networkInterval); clearInterval(metricsInterval); clearInterval(uptimeInterval); });

        return {
            appVersion, lang, i18n, toggleLang,
            activeTab, tabs, setupStep,
            tiers, selectedTier, linkGroups,
            nodeStatus, statusLabel, nodeUptime,
            network, metrics, logs, config,
            walletAddress, walletBalance, walletLoading,
            newWalletData, showPrivateKey, showImportModal, importKeyInput, importError, exportedKey,
            startNode, stopNode, launchNode, refreshNetwork, refreshMetrics,
            loadWallet, createWallet, importWallet, refreshBalance, showExportKey,
            loadConfig, saveSettings, openDataDir, openLink, loadLogs,
            formatNumber, formatDuration, formatMB, formatLogTime,
            instances, showAddInstance, newInstance, editingInstance, instanceRewardWallet,
            addInstanceErrors, addInstanceWarnings,
            autoFillInstance, portConflict,
            loadInstances, addInstance, removeInstance, startInstance, stopInstance, setRewardWallet,
            updateStatus, checkUpdate, downloadUpdate, installUpdate,
            copyToClipboard, shortAddr, formatBytes, minimize, maximize, closeWindow,
        };
    },
});

app.mount('#app');
