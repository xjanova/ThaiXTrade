/**
 * TPIX Master Node — Multi-Instance Manager
 * Manages multiple node instances on different ports.
 * Each instance = separate Polygon Edge process with its own data dir.
 *
 * Example: Run 3 validators on one machine:
 *   Instance 1: P2P 30303, RPC 8545, data ~/.tpix-node/instances/node-1
 *   Instance 2: P2P 30304, RPC 8546, data ~/.tpix-node/instances/node-2
 *   Instance 3: P2P 30305, RPC 8547, data ~/.tpix-node/instances/node-3
 *
 * Developed by Xman Studio
 */

const path = require('path');
const fs = require('fs');
const os = require('os');
const EventEmitter = require('events');
const NodeManager = require('./node-manager');

const BASE_DIR = path.join(os.homedir(), '.tpix-node');
const INSTANCES_DIR = path.join(BASE_DIR, 'instances');
const INSTANCES_FILE = path.join(BASE_DIR, 'instances.json');

// Default port ranges
const DEFAULT_BASE_P2P = 30303;
const DEFAULT_BASE_RPC = 8545;
const DEFAULT_BASE_GRPC = 9545;
const DEFAULT_BASE_DASHBOARD = 3847;

class InstanceManager extends EventEmitter {
    constructor() {
        super();
        /** @type {Map<string, {config: object, manager: NodeManager|null}>} */
        this.instances = new Map();
        this._loadInstances();
    }

    // ─── Instance CRUD ─────────────────────────────────────

    /**
     * Get all instances with their status.
     */
    getAll() {
        const result = [];
        for (const [id, inst] of this.instances) {
            result.push({
                id,
                ...inst.config,
                status: inst.manager ? inst.manager.status : 'stopped',
                uptime: inst.manager?.startTime ? Math.floor((Date.now() - inst.manager.startTime) / 1000) : 0,
                pid: inst.manager?.process?.pid || null,
            });
        }
        return result;
    }

    /**
     * Get a single instance by ID.
     */
    get(id) {
        const inst = this.instances.get(id);
        if (!inst) return null;
        return {
            id,
            ...inst.config,
            status: inst.manager ? inst.manager.status : 'stopped',
            uptime: inst.manager?.startTime ? Math.floor((Date.now() - inst.manager.startTime) / 1000) : 0,
            pid: inst.manager?.process?.pid || null,
        };
    }

    /**
     * Add a new node instance with smart validation.
     * Checks: port conflicts, IP collisions, tier stake, wallet format.
     */
    add(options = {}) {
        const count = this.instances.size;
        const id = options.id || `node-${count + 1}`;

        // Sanitize ID for filesystem safety
        if (!/^[a-zA-Z0-9_-]{1,50}$/.test(id)) {
            throw new Error('Invalid instance ID. Use only letters, numbers, hyphens, underscores (max 50).');
        }
        if (this.instances.has(id)) {
            throw new Error(`Instance "${id}" already exists`);
        }

        const config = {
            nodeName: options.nodeName || `TPIX Node ${count + 1}`,
            tier: options.tier || 'light',
            walletAddress: options.walletAddress || '',
            p2pPort: options.p2pPort || DEFAULT_BASE_P2P + count,
            rpcPort: options.rpcPort || DEFAULT_BASE_RPC + count,
            grpcPort: options.grpcPort || DEFAULT_BASE_GRPC + count,
            dashboardPort: options.dashboardPort || DEFAULT_BASE_DASHBOARD + count,
            bindAddress: options.bindAddress || '127.0.0.1',
            rewardWallet: options.rewardWallet || '',
            rpcUrl: options.rpcUrl || 'https://rpc.tpix.online',
            chainId: 4289,
            maxPeers: options.maxPeers || 50,
            bootnodes: options.bootnodes || [],
            autoStart: options.autoStart || false,
            dataDir: path.join(INSTANCES_DIR, id),
            createdAt: new Date().toISOString(),
        };

        // ─── Smart Validation ──────────────────────
        const errors = this.validate(config, null);
        if (errors.length > 0) {
            throw new Error(errors.join('\n'));
        }

        // Create instance data directory
        if (!fs.existsSync(config.dataDir)) {
            fs.mkdirSync(config.dataDir, { recursive: true });
        }

        this.instances.set(id, { config, manager: null });
        this._saveInstances();

        this.emit('instance-added', { id, config });
        return { id, ...config, warnings: this._getWarnings(config) };
    }

    /**
     * Validate instance configuration.
     * Returns array of error messages (empty = valid).
     * @param {object} config - config to validate
     * @param {string|null} excludeId - instance ID to exclude from conflict checks (for updates)
     */
    validate(config, excludeId = null) {
        const errors = [];
        const existingConfigs = [];
        for (const [id, inst] of this.instances) {
            if (id !== excludeId) existingConfigs.push({ id, ...inst.config });
        }

        // 1. Port range validation
        const allPorts = [config.p2pPort, config.rpcPort, config.grpcPort, config.dashboardPort];
        for (const p of allPorts) {
            if (!p || p < 1024 || p > 65535) {
                errors.push(`Port ${p} is invalid. Must be 1024-65535.`);
            }
        }

        // 2. No duplicate ports within this instance
        const uniquePorts = new Set(allPorts);
        if (uniquePorts.size !== allPorts.length) {
            errors.push('Ports within an instance must all be different (P2P, RPC, gRPC, Dashboard).');
        }

        // 3. No port conflicts with other instances on same IP
        for (const existing of existingConfigs) {
            const sameIP = (config.bindAddress || '127.0.0.1') === (existing.bindAddress || '127.0.0.1')
                || config.bindAddress === '0.0.0.0' || existing.bindAddress === '0.0.0.0';

            if (sameIP) {
                const existingPorts = [existing.p2pPort, existing.rpcPort, existing.grpcPort, existing.dashboardPort];
                for (const p of allPorts) {
                    if (existingPorts.includes(p)) {
                        errors.push(`Port ${p} conflicts with instance "${existing.id}" on ${existing.bindAddress || '127.0.0.1'}.`);
                    }
                }
            }
        }

        // 4. IP format validation
        const ip = config.bindAddress || '127.0.0.1';
        if (!/^(\d{1,3}\.){3}\d{1,3}$/.test(ip) && ip !== 'localhost') {
            errors.push(`Invalid IP address: ${ip}`);
        }

        // 5. Wallet format validation (if provided)
        if (config.walletAddress && !/^0x[a-fA-F0-9]{40}$/.test(config.walletAddress)) {
            errors.push('Invalid wallet address format. Must be 0x + 40 hex characters.');
        }
        if (config.rewardWallet && !/^0x[a-fA-F0-9]{40}$/.test(config.rewardWallet)) {
            errors.push('Invalid reward wallet format. Must be 0x + 40 hex characters.');
        }

        // 6. Tier validation
        const validTiers = ['light', 'sentinel', 'validator'];
        if (!validTiers.includes(config.tier)) {
            errors.push(`Invalid tier "${config.tier}". Must be: ${validTiers.join(', ')}`);
        }

        // 7. Node name validation
        if (config.nodeName && config.nodeName.length > 50) {
            errors.push('Node name too long (max 50 characters).');
        }

        return errors;
    }

    /**
     * Get non-blocking warnings for a config.
     */
    _getWarnings(config) {
        const warnings = [];

        // Warn about 0.0.0.0 binding
        if (config.bindAddress === '0.0.0.0') {
            warnings.push('Binding to 0.0.0.0 exposes this node to your entire network. Use 127.0.0.1 for local-only.');
        }

        // Warn if no reward wallet set
        if (!config.rewardWallet) {
            warnings.push('No reward wallet set. Rewards will accumulate in the contract until you set one.');
        }

        return warnings;
    }

    /**
     * Generate smart auto-config for a new instance.
     * Finds next available ports, uses default wallet, avoids all conflicts.
     */
    autoConfig(walletAddress = '', tier = 'light') {
        // Find next unused node number (handles deletions)
        let count = 0;
        while (this.instances.has(`node-${count + 1}`)) count++;
        count = Math.max(count, this.instances.size);

        // Collect all used ports across all instances
        const usedPorts = new Set();
        for (const [, inst] of this.instances) {
            usedPorts.add(inst.config.p2pPort);
            usedPorts.add(inst.config.rpcPort);
            usedPorts.add(inst.config.grpcPort);
            usedPorts.add(inst.config.dashboardPort);
        }

        // Find next available port starting from base
        const findPort = (base) => {
            let port = base;
            while (usedPorts.has(port)) port++;
            usedPorts.add(port); // reserve it
            return port;
        };

        const p2pPort = findPort(DEFAULT_BASE_P2P);
        const rpcPort = findPort(DEFAULT_BASE_RPC);
        const grpcPort = findPort(DEFAULT_BASE_GRPC);
        const dashboardPort = findPort(DEFAULT_BASE_DASHBOARD);

        // Tier info for display
        const tierInfo = {
            light: { stake: 10000, apy: '4-6%' },
            sentinel: { stake: 100000, apy: '7-10%' },
            validator: { stake: 1000000, apy: '12-15%' },
        };

        return {
            id: `node-${count + 1}`,
            nodeName: `TPIX Node ${count + 1}`,
            tier,
            walletAddress: walletAddress || '',
            rewardWallet: walletAddress || '', // default: same as main wallet
            p2pPort,
            rpcPort,
            grpcPort,
            dashboardPort,
            bindAddress: '127.0.0.1',
            maxPeers: 50,
            rpcUrl: 'https://rpc.tpix.online',
            // Info for UI
            _tierInfo: tierInfo[tier] || tierInfo.light,
            _totalInstances: count + 1,
        };
    }

    /**
     * Update an existing instance configuration.
     * Cannot update while running.
     */
    update(id, updates) {
        const inst = this.instances.get(id);
        if (!inst) throw new Error(`Instance "${id}" not found`);
        if (inst.manager && inst.manager.isRunning()) {
            throw new Error('Cannot update a running instance. Stop it first.');
        }

        const ALLOWED = ['nodeName', 'tier', 'walletAddress', 'rewardWallet', 'p2pPort', 'rpcPort', 'grpcPort',
            'dashboardPort', 'bindAddress', 'rpcUrl', 'maxPeers', 'bootnodes', 'autoStart'];

        // Apply allowed updates to a temp config for validation
        const tempConfig = { ...inst.config };
        for (const key of Object.keys(updates)) {
            if (ALLOWED.includes(key)) {
                tempConfig[key] = updates[key];
            }
        }

        // Validate updated config
        const errors = this.validate(tempConfig, id);
        if (errors.length > 0) {
            throw new Error(errors.join('\n'));
        }

        // Apply validated changes
        inst.config = tempConfig;
        this._saveInstances();
        this.emit('instance-updated', { id, config: inst.config });
        return { id, ...inst.config };
    }

    /**
     * Remove an instance. Must be stopped first.
     */
    remove(id) {
        const inst = this.instances.get(id);
        if (!inst) throw new Error(`Instance "${id}" not found`);
        if (inst.manager && inst.manager.isRunning()) {
            throw new Error('Cannot remove a running instance. Stop it first.');
        }

        this.instances.delete(id);
        this._saveInstances();

        // Optionally clean up data dir
        // (keep it for now — user might want to re-add)

        this.emit('instance-removed', { id });
        return { success: true };
    }

    // ─── Instance Lifecycle ────────────────────────────────

    /**
     * Start a specific instance.
     */
    async start(id) {
        const inst = this.instances.get(id);
        if (!inst) throw new Error(`Instance "${id}" not found`);
        if (inst._starting) throw new Error('Instance is already starting');
        if (inst.manager && inst.manager.isRunning()) throw new Error('Instance is already running');

        inst._starting = true;
        try {

        // Create a NodeManager for this instance with custom config
        const manager = new NodeManager();
        // Override config with instance-specific settings
        manager.config = { ...manager.config, ...inst.config };
        manager.dataDir = inst.config.dataDir;
        manager.configPath = path.join(inst.config.dataDir, 'config.json');

        // Forward events with instance ID
        manager.on('status-change', (status) => {
            this.emit('instance-status', { id, ...status });
        });
        manager.on('log', (entry) => {
            this.emit('instance-log', { id, ...entry });
        });
        manager.on('metrics', (metrics) => {
            this.emit('instance-metrics', { id, ...metrics });
        });

        inst.manager = manager;
        await manager.start(inst.config);

        return { success: true, id, status: manager.status };
        } finally {
            inst._starting = false;
        }
    }

    /**
     * Stop a specific instance.
     */
    async stop(id) {
        const inst = this.instances.get(id);
        if (!inst) throw new Error(`Instance "${id}" not found`);
        if (!inst.manager || inst._stopping) return { success: true, id };

        inst._stopping = true;
        try {
            await inst.manager.stop();
            inst.manager.removeAllListeners();
            inst.manager = null;
        } finally {
            inst._stopping = false;
        }

        return { success: true, id };
    }

    /**
     * Start all instances that have autoStart enabled.
     */
    async autoStartAll() {
        for (const [id, inst] of this.instances) {
            if (inst.config.autoStart) {
                try {
                    await this.start(id);
                } catch (err) {
                    console.error(`Failed to auto-start ${id}:`, err.message);
                }
            }
        }
    }

    /**
     * Stop all running instances.
     */
    async stopAll() {
        const promises = [];
        for (const [id, inst] of this.instances) {
            if (inst.manager && inst.manager.isRunning()) {
                promises.push(this.stop(id));
            }
        }
        await Promise.allSettled(promises);
    }

    /**
     * Get logs for a specific instance.
     */
    getLogs(id, count = 100) {
        const inst = this.instances.get(id);
        if (!inst || !inst.manager) return [];
        return inst.manager.getLogs(count);
    }

    /**
     * Check how many instances are running.
     */
    runningCount() {
        let count = 0;
        for (const [, inst] of this.instances) {
            if (inst.manager && inst.manager.isRunning()) count++;
        }
        return count;
    }

    /**
     * Get suggested ports for next instance (auto-increment).
     */
    suggestPorts() {
        const count = this.instances.size;
        return {
            p2pPort: DEFAULT_BASE_P2P + count,
            rpcPort: DEFAULT_BASE_RPC + count,
            grpcPort: DEFAULT_BASE_GRPC + count,
            dashboardPort: DEFAULT_BASE_DASHBOARD + count,
        };
    }

    // ─── Persistence ───────────────────────────────────────

    _loadInstances() {
        try {
            if (fs.existsSync(INSTANCES_FILE)) {
                const data = JSON.parse(fs.readFileSync(INSTANCES_FILE, 'utf-8'));
                for (const [id, config] of Object.entries(data)) {
                    this.instances.set(id, { config, manager: null });
                }
            }
        } catch (err) {
            console.error('Failed to load instances:', err.message);
        }
    }

    _saveInstances() {
        if (!fs.existsSync(BASE_DIR)) {
            fs.mkdirSync(BASE_DIR, { recursive: true });
        }

        const data = {};
        for (const [id, inst] of this.instances) {
            data[id] = inst.config;
        }
        fs.writeFileSync(INSTANCES_FILE, JSON.stringify(data, null, 2));
    }
}

module.exports = InstanceManager;
