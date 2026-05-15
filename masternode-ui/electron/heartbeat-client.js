/**
 * TPIX Master Node — Heartbeat Client
 *
 * ส่ง heartbeat ไปยัง backend API เพื่อขอ Cloudflare allowlist
 * Default endpoint: https://tpix.online/api/v1/node/heartbeat
 *
 * Lifecycle:
 *   1. Renderer call `heartbeat:start` (after delegation set up)
 *   2. ส่ง heartbeat ทันที + ทุก ~30 นาที
 *   3. Track sucess/fail + emit event ให้ renderer แสดงสถานะ
 *
 * Developed by Xman Studio
 */

const EventEmitter = require('events');
const https = require('https');
const http = require('http');

const DEFAULT_API_URL = 'https://tpix.online/api/v1/node/heartbeat';
const DEFAULT_RENEW_INTERVAL_MS = 30 * 60 * 1000; // 30 นาที

class HeartbeatClient extends EventEmitter {
    constructor(delegationManager) {
        super();
        this.delegationManager = delegationManager;
        this.apiUrl = DEFAULT_API_URL;
        this.renewInterval = DEFAULT_RENEW_INTERVAL_MS;
        this.timer = null;
        this.lastResult = null;
        this.lastError = null;
        this.lastSentAt = null;
    }

    setApiUrl(url) {
        if (typeof url === 'string' && url.startsWith('http')) {
            this.apiUrl = url;
        }
    }

    /**
     * Start sending periodic heartbeats
     * Returns Promise ของ first heartbeat result
     */
    async start() {
        if (this.timer) {
            return { ok: true, alreadyRunning: true };
        }

        // Send first heartbeat immediately
        const first = await this.sendOnce().catch((e) => ({ ok: false, error: e.message }));

        // Then schedule renew
        this.timer = setInterval(() => {
            this.sendOnce().catch((e) => {
                this.lastError = e.message;
                this.emit('heartbeat-error', { error: e.message });
            });
        }, this.renewInterval);

        return first;
    }

    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    isRunning() {
        return this.timer !== null;
    }

    /**
     * Send single heartbeat — ใช้ทั้งใน loop + manual trigger
     */
    async sendOnce() {
        const payload = await this.delegationManager.signHeartbeat();
        const result = await this._post(this.apiUrl, payload);

        this.lastSentAt = Date.now();
        this.lastResult = result;

        if (result.ok) {
            this.lastError = null;
            this.emit('heartbeat-success', result);
        } else {
            this.lastError = result.error || result.message || 'Unknown';
            this.emit('heartbeat-error', { error: this.lastError, status: result.status });
        }

        return result;
    }

    getStatus() {
        return {
            running: this.isRunning(),
            apiUrl: this.apiUrl,
            renewIntervalMs: this.renewInterval,
            lastSentAt: this.lastSentAt,
            lastResult: this.lastResult,
            lastError: this.lastError,
        };
    }

    /**
     * HTTP POST helper — return { ok, status, ...response_body }
     */
    _post(url, body) {
        return new Promise((resolve) => {
            try {
                const u = new URL(url);
                const isHttps = u.protocol === 'https:';
                const client = isHttps ? https : http;
                const data = JSON.stringify(body);

                const req = client.request(
                    {
                        hostname: u.hostname,
                        port: u.port || (isHttps ? 443 : 80),
                        path: u.pathname + (u.search || ''),
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Content-Length': Buffer.byteLength(data),
                            'User-Agent': 'TPIX-MasterNode/1.0',
                            'X-Client-Type': 'masternode-ui',
                            'Accept': 'application/json',
                        },
                        timeout: 15000,
                    },
                    (res) => {
                        let chunks = '';
                        res.on('data', (c) => (chunks += c));
                        res.on('end', () => {
                            try {
                                const json = JSON.parse(chunks);
                                resolve({
                                    ok: res.statusCode >= 200 && res.statusCode < 300 && json.ok !== false,
                                    status: res.statusCode,
                                    ...json,
                                });
                            } catch {
                                resolve({
                                    ok: false,
                                    status: res.statusCode,
                                    error: 'invalid_json',
                                    message: chunks.slice(0, 200),
                                });
                            }
                        });
                    }
                );

                req.on('error', (err) => {
                    resolve({ ok: false, status: 0, error: 'network_error', message: err.message });
                });
                req.on('timeout', () => {
                    req.destroy();
                    resolve({ ok: false, status: 0, error: 'timeout' });
                });

                req.write(data);
                req.end();
            } catch (e) {
                resolve({ ok: false, status: 0, error: 'exception', message: e.message });
            }
        });
    }
}

module.exports = HeartbeatClient;
