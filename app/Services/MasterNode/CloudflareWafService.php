<?php

namespace App\Services\MasterNode;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * CloudflareWafService — manage IP Access Rules ที่ Cloudflare
 *
 * ใช้ Cloudflare API endpoint:
 *   POST /zones/{zone_id}/firewall/access_rules/rules
 *   DELETE /zones/{zone_id}/firewall/access_rules/rules/{rule_id}
 *
 * Note ของ rule จะใช้ prefix "tpix-masternode-auto:<wallet>:<tier>"
 * เพื่อ filter ตอน cleanup ไม่ให้ลบ rule manual ที่ admin ตั้งเอง
 *
 * Developed by Xman Studio
 */
class CloudflareWafService
{
    private string $baseUrl = 'https://api.cloudflare.com/client/v4';

    private string $token;
    private string $zoneId;
    private string $mode;
    private string $notesPrefix;

    public function __construct()
    {
        $this->token = (string) config('masternode.cloudflare.api_token');
        $this->zoneId = (string) config('masternode.cloudflare.zone_id');
        $this->mode = (string) config('masternode.cloudflare.rule_mode', 'whitelist');
        $this->notesPrefix = (string) config('masternode.cloudflare.rule_notes_prefix', 'tpix-masternode-auto');
    }

    /**
     * เช็คว่า config CF พร้อมใช้งานมั้ย
     */
    public function configured(): bool
    {
        return $this->token !== '' && $this->zoneId !== '';
    }

    /**
     * เพิ่ม IP allowlist rule ที่ Cloudflare
     *
     * @return array|null ['id' => '...', 'created_on' => '...'] หรือ null ถ้า fail
     */
    public function addAllowRule(string $ip, string $walletAddress, string $tier): ?array
    {
        if (!$this->configured()) {
            Log::warning('Cloudflare not configured — skipping addAllowRule', compact('ip', 'walletAddress'));
            return null;
        }

        $note = sprintf('%s:%s:%s', $this->notesPrefix, $walletAddress, $tier);

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(15)
                ->post("{$this->baseUrl}/zones/{$this->zoneId}/firewall/access_rules/rules", [
                    'mode' => $this->mode,
                    'configuration' => [
                        'target' => $this->ipTarget($ip),
                        'value' => $ip,
                    ],
                    'notes' => $note,
                ]);

            if (!$response->successful()) {
                Log::error('Cloudflare addAllowRule failed', [
                    'ip' => $ip,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return null;
            }

            $data = $response->json('result');
            return [
                'id' => $data['id'] ?? null,
                'created_on' => $data['created_on'] ?? now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            Log::error('Cloudflare addAllowRule exception', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * ลบ IP rule (ใช้ตอน cleanup เมื่อ allowlist หมดอายุ)
     */
    public function deleteRule(string $ruleId): bool
    {
        if (!$this->configured() || $ruleId === '') {
            return false;
        }

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->timeout(15)
                ->delete("{$this->baseUrl}/zones/{$this->zoneId}/firewall/access_rules/rules/{$ruleId}");

            if ($response->status() === 404) {
                // rule ไม่อยู่แล้ว — treat เป็น success
                return true;
            }

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Cloudflare deleteRule exception', [
                'rule_id' => $ruleId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * List rules ที่สร้างโดย system นี้ (ใช้ admin dashboard)
     *
     * @return array<int, array{id:string, ip:string, mode:string, notes:string, created_on:string}>
     */
    public function listAutoRules(int $perPage = 50): array
    {
        if (!$this->configured()) {
            return [];
        }

        $cacheKey = 'masternode:cf-rules:'.$perPage;

        return Cache::remember($cacheKey, 30, function () use ($perPage) {
            try {
                $response = Http::withToken($this->token)
                    ->acceptJson()
                    ->timeout(15)
                    ->get("{$this->baseUrl}/zones/{$this->zoneId}/firewall/access_rules/rules", [
                        'mode' => $this->mode,
                        'notes' => $this->notesPrefix,
                        'per_page' => $perPage,
                    ]);

                if (!$response->successful()) {
                    return [];
                }

                $rules = $response->json('result', []);
                return array_map(function ($r) {
                    return [
                        'id' => $r['id'] ?? '',
                        'ip' => $r['configuration']['value'] ?? '',
                        'mode' => $r['mode'] ?? '',
                        'notes' => $r['notes'] ?? '',
                        'created_on' => $r['created_on'] ?? '',
                    ];
                }, $rules);
            } catch (\Throwable $e) {
                Log::error('Cloudflare listAutoRules exception', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Determine target type สำหรับ Cloudflare API:
     *   - "ip" สำหรับ IPv4 / IPv6 address เดี่ยว
     *   - "ip_range" สำหรับ CIDR (มี /)
     *   - "ip6" สำหรับ IPv6 (บาง endpoint แยก)
     */
    private function ipTarget(string $ip): string
    {
        if (str_contains($ip, '/')) {
            return 'ip_range';
        }
        if (str_contains($ip, ':')) {
            return 'ip6';
        }
        return 'ip';
    }
}
