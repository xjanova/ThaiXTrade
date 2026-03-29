<?php

namespace App\Services;

use App\Jobs\DeployTokenJob;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class TokenFactoryService
{
    /**
     * สร้างคำขอสร้าง Token ใหม่.
     *
     * ตรวจสอบ:
     * - ระบบเปิดให้สร้างหรือไม่ (creation_enabled)
     * - fee_wallet ตั้งค่าแล้วหรือไม่
     * - fee payment record ถูกต้อง
     * - auto_approve flag
     */
    public function createToken(array $data): FactoryToken
    {
        $chainId = (int) ($data['chain_id'] ?? 4289);
        $onTestnet = $this->isTestnet($chainId);

        // Guard: ต้องตั้ง fee_wallet ก่อน — fallback ใช้ revenue tpix_wallet → legacy wallet_address
        // Testnet: ข้าม fee wallet guard — สร้างฟรี
        $feeWallet = '';
        if (! $onTestnet) {
            $feeWallet = SiteSetting::get('factory', 'fee_wallet', '');
            if (empty($feeWallet)) {
                $feeWallet = SiteSetting::get('revenue', 'tpix_wallet', '');
            }
            if (empty($feeWallet)) {
                $feeWallet = SiteSetting::get('revenue', 'wallet_address', '');
            }
            $feeMethod = SiteSetting::get('factory', 'fee_payment_method', 'tpix');
            $feeTpix = (float) SiteSetting::get('factory', 'creation_fee_tpix', 100);

            // ถ้า fee ไม่ใช่ free → ต้องมี fee_wallet
            if ($feeMethod !== 'free' && $feeTpix > 0 && empty($feeWallet)) {
                throw new \RuntimeException(
                    'Token Factory fee wallet is not configured. Please contact administrator.'
                );
            }
        }

        $tokenType = $data['token_type'] ?? 'standard';

        // Auto-determine category from type
        $nftTypes = ['nft', 'nft_collection'];
        $specialTypes = ['governance', 'stablecoin'];
        $category = $data['token_category']
            ?? (in_array($tokenType, $nftTypes) ? 'nft'
                : (in_array($tokenType, $specialTypes) ? 'special' : 'fungible'));

        // NFT ใช้ decimals = 0 เสมอ
        $decimals = in_array($tokenType, $nftTypes) ? 0 : ($data['decimals'] ?? 18);

        // คำนวณ fee แบบ dynamic ตามออฟชั่นที่เลือก (testnet = FREE)
        $feeResult = $this->calculateFee([
            'token_category' => $data['token_category'] ?? 'fungible',
            'token_type' => $tokenType,
            'decimals' => $data['decimals'] ?? 18,
            'total_supply' => $data['total_supply'] ?? 0,
            'chain_id' => $chainId,
        ]);

        $feeAmount = $feeResult['total'];

        // สร้าง metadata พร้อม fee info (ป้องกัน metadata เป็น non-array)
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata['fee_amount'] = $feeAmount;
        $metadata['fee_currency'] = $feeResult['currency'];
        $metadata['fee_breakdown'] = $feeResult['breakdown'];
        $metadata['fee_wallet'] = $feeWallet;
        $metadata['fee_tx_hash'] = $data['fee_tx_hash'] ?? null;
        $metadata['is_testnet'] = $onTestnet;

        // สร้าง token record
        $token = FactoryToken::create([
            'name' => $data['name'],
            'symbol' => strtoupper($data['symbol']),
            'decimals' => $decimals,
            'total_supply' => $data['total_supply'],
            'creator_address' => strtolower($data['creator_address']),
            'chain_id' => $data['chain_id'] ?? 4289,
            'logo_url' => $data['logo_url'] ?? null,
            'description' => $data['description'] ?? null,
            'website' => $data['website'] ?? null,
            'token_type' => $tokenType,
            'token_category' => $category,
            'status' => 'pending',
            'metadata' => $metadata,
        ]);

        Log::info('Token creation request submitted', [
            'token_id' => $token->id,
            'symbol' => $token->symbol,
            'creator' => $token->creator_address,
            'fee' => $feeAmount,
        ]);

        // Auto-approve ถ้าเปิดไว้ (เฉพาะ fungible ที่ไม่ใช่ special types)
        $autoApprove = filter_var(
            SiteSetting::get('factory', 'auto_approve', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if ($autoApprove && $category === 'fungible' && in_array($tokenType, ['standard', 'mintable', 'burnable', 'mintable_burnable'])) {
            $this->approveToken($token);
            Log::info("Token {$token->symbol} auto-approved", ['token_id' => $token->id]);
        }

        return $token->fresh();
    }

    /**
     * ดึง Tokens ของ creator.
     */
    public function getTokensByCreator(string $address, int $perPage = 20): LengthAwarePaginator
    {
        return FactoryToken::byCreator(strtolower($address))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * ดึง Token ที่ deployed ทั้งหมด (public listing).
     */
    public function getDeployedTokens(?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FactoryToken::deployed()
            ->where('is_listed', true)
            ->orderByDesc('created_at');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('symbol', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Admin: ดึงทุก token พร้อม filter.
     */
    public function getAllTokens(?string $status = null, ?string $search = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FactoryToken::query()->with('chain')->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('symbol', 'like', "%{$search}%")
                    ->orWhere('creator_address', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Admin: อนุมัติ token → dispatch job เพื่อ deploy จริงบน TPIX Chain.
     */
    public function approveToken(FactoryToken $token): FactoryToken
    {
        if (! in_array($token->status, ['pending', 'failed'])) {
            throw new \InvalidArgumentException(
                "Cannot approve token with status '{$token->status}'. Only pending or failed tokens can be approved."
            );
        }

        $token->update(['status' => 'deploying']);

        DeployTokenJob::dispatch($token);

        Log::info("Token {$token->symbol} approved, deployment job dispatched", [
            'token_id' => $token->id,
            'creator' => $token->creator_address,
            'type' => $token->token_type,
        ]);

        return $token->fresh();
    }

    /**
     * Admin: ปฏิเสธ token.
     */
    public function rejectToken(FactoryToken $token, string $reason): FactoryToken
    {
        if (! in_array($token->status, ['pending', 'failed'])) {
            throw new \InvalidArgumentException(
                "Cannot reject token with status '{$token->status}'. Only pending or failed tokens can be rejected."
            );
        }

        $token->update([
            'status' => 'rejected',
            'reject_reason' => $reason,
        ]);

        Log::info("Token {$token->symbol} rejected", [
            'token_id' => $token->id,
            'reason' => $reason,
        ]);

        return $token->fresh();
    }

    /**
     * Admin: toggle verify (เฉพาะ deployed tokens).
     */
    public function toggleVerified(FactoryToken $token): FactoryToken
    {
        if ($token->status !== 'deployed') {
            throw new \InvalidArgumentException(
                "Cannot verify token with status '{$token->status}'. Only deployed tokens can be verified."
            );
        }

        $token->update(['is_verified' => ! $token->is_verified]);

        return $token->fresh();
    }

    /**
     * Admin: toggle listed (เฉพาะ deployed tokens).
     */
    public function toggleListed(FactoryToken $token): FactoryToken
    {
        if ($token->status !== 'deployed') {
            throw new \InvalidArgumentException(
                "Cannot toggle listing for token with status '{$token->status}'."
            );
        }

        $token->update(['is_listed' => ! $token->is_listed]);

        return $token->fresh();
    }

    /**
     * ตรวจสอบว่าระบบพร้อมสร้าง token หรือไม่
     */
    public function isFactoryReady(): array
    {
        $creationEnabled = filter_var(
            SiteSetting::get('factory', 'creation_enabled', true),
            FILTER_VALIDATE_BOOLEAN
        );
        $feeWallet = SiteSetting::get('factory', 'fee_wallet', '');
        // Fallback: ใช้ revenue tpix_wallet → legacy wallet_address
        if (empty($feeWallet)) {
            $feeWallet = SiteSetting::get('revenue', 'tpix_wallet', '');
        }
        if (empty($feeWallet)) {
            $feeWallet = SiteSetting::get('revenue', 'wallet_address', '');
        }
        $feeMethod = SiteSetting::get('factory', 'fee_payment_method', 'tpix');
        $feeTpix = (float) SiteSetting::get('factory', 'creation_fee_tpix', 100);

        $needsWallet = $feeMethod !== 'free' && $feeTpix > 0;
        $walletConfigured = ! empty($feeWallet);

        return [
            'ready' => $creationEnabled && (! $needsWallet || $walletConfigured),
            'creation_enabled' => $creationEnabled,
            'fee_wallet_configured' => $walletConfigured,
            'fee_wallet_needed' => $needsWallet,
            'issues' => array_values(array_filter([
                ! $creationEnabled ? 'Token creation is disabled' : null,
                $needsWallet && ! $walletConfigured ? 'Fee wallet not configured' : null,
            ])),
        ];
    }

    /**
     * สถิติรวม
     */
    public function getStats(): array
    {
        return [
            'total' => FactoryToken::count(),
            'pending' => FactoryToken::where('status', 'pending')->count(),
            'deploying' => FactoryToken::where('status', 'deploying')->count(),
            'deployed' => FactoryToken::where('status', 'deployed')->count(),
            'failed' => FactoryToken::where('status', 'failed')->count(),
            'rejected' => FactoryToken::where('status', 'rejected')->count(),
            'verified' => FactoryToken::where('is_verified', true)->count(),
            'unique_creators' => FactoryToken::distinct('creator_address')->count('creator_address'),
        ];
    }

    /**
     * ตรวจว่า chain_id เป็น testnet หรือไม่
     * Testnet สร้าง token ฟรี — ไม่เสียค่าธรรมเนียม
     */
    public function isTestnet(int $chainId): bool
    {
        $testnetIds = config('blockchain.testnet_chain_ids', [4290, 11155111, 97]);

        return in_array($chainId, $testnetIds, true);
    }

    /**
     * คำนวณค่าธรรมเนียมแบบ dynamic ตามออฟชั่นที่เลือก.
     *
     * fee = base_fee + category_fee + type_fee + option_fees
     * ถ้าเป็น testnet → fee = 0 (FREE) เสมอ
     *
     * @param  array  $options  ['token_category', 'token_type', 'decimals', 'total_supply', 'chain_id']
     * @return array  ['total' => float, 'breakdown' => [...]]
     */
    public function calculateFee(array $options): array
    {
        // Testnet → สร้างฟรีเสมอ
        $chainId = (int) ($options['chain_id'] ?? 4289);
        if ($this->isTestnet($chainId)) {
            return [
                'total' => 0,
                'currency' => 'FREE',
                'breakdown' => [],
                'is_free' => true,
                'is_testnet' => true,
            ];
        }
        $fees = config('blockchain.creation_fees', []);

        // ดึง fee config จาก SiteSettings ก่อน (admin override) → fallback ไป config/blockchain.php
        $baseFee = (float) SiteSetting::get('factory', 'base_fee', $fees['base_fee'] ?? 50);

        $categoryFees = $fees['category_fees'] ?? [];
        $typeFees = $fees['type_fees'] ?? [];
        $optionFees = $fees['option_fees'] ?? [];

        // Override จาก SiteSettings (ถ้า admin ตั้งค่าไว้)
        $feeOverride = SiteSetting::get('factory', 'fee_override_json', '');
        if (! empty($feeOverride)) {
            $override = json_decode($feeOverride, true);
            if (is_array($override)) {
                $baseFee = (float) ($override['base_fee'] ?? $baseFee);
                $categoryFees = array_merge($categoryFees, $override['category_fees'] ?? []);
                $typeFees = array_merge($typeFees, $override['type_fees'] ?? []);
                $optionFees = array_merge($optionFees, $override['option_fees'] ?? []);
            }
        }

        $breakdown = [];
        $total = 0;

        // 1. Base fee
        $breakdown[] = ['label' => 'Base Fee', 'label_th' => 'ค่าพื้นฐาน', 'amount' => $baseFee];
        $total += $baseFee;

        // 2. Category fee
        $category = $options['token_category'] ?? 'fungible';
        $catFee = (float) ($categoryFees[$category] ?? 0);
        if ($catFee > 0) {
            $catLabels = ['nft' => 'NFT Category', 'special' => 'Special Category'];
            $catLabelsTh = ['nft' => 'ประเภท NFT', 'special' => 'ประเภทพิเศษ'];
            $breakdown[] = [
                'label' => $catLabels[$category] ?? ucfirst($category),
                'label_th' => $catLabelsTh[$category] ?? ucfirst($category),
                'amount' => $catFee,
            ];
            $total += $catFee;
        }

        // 3. Type fee
        $tokenType = $options['token_type'] ?? 'standard';
        $typFee = (float) ($typeFees[$tokenType] ?? 0);
        if ($typFee > 0) {
            $typeLabels = [
                'mintable' => 'Mint Function',
                'burnable' => 'Burn Function',
                'mintable_burnable' => 'Mint + Burn Functions',
                'utility' => 'Utility Features',
                'reward' => 'Reward Features',
                'nft_collection' => 'Collection Features',
                'governance' => 'Governance Mechanism',
                'stablecoin' => 'Stablecoin Mechanism',
            ];
            $typeLabelsTh = [
                'mintable' => 'ฟังก์ชัน Mint',
                'burnable' => 'ฟังก์ชัน Burn',
                'mintable_burnable' => 'ฟังก์ชัน Mint + Burn',
                'utility' => 'ฟีเจอร์ Utility',
                'reward' => 'ฟีเจอร์ Reward',
                'nft_collection' => 'ฟีเจอร์ Collection',
                'governance' => 'ระบบ Governance',
                'stablecoin' => 'ระบบ Stablecoin',
            ];
            $breakdown[] = [
                'label' => $typeLabels[$tokenType] ?? ucfirst($tokenType),
                'label_th' => $typeLabelsTh[$tokenType] ?? ucfirst($tokenType),
                'amount' => $typFee,
            ];
            $total += $typFee;
        }

        // 4. Option fees
        $decimals = (int) ($options['decimals'] ?? 18);
        if ($decimals !== 18 && $decimals !== 0) {
            $decFee = (float) ($optionFees['custom_decimals'] ?? 5);
            if ($decFee > 0) {
                $breakdown[] = ['label' => "Custom Decimals ({$decimals})", 'label_th' => "Decimals พิเศษ ({$decimals})", 'amount' => $decFee];
                $total += $decFee;
            }
        }

        // Supply size fee
        $supply = (float) ($options['total_supply'] ?? 0);
        $veryLargeThreshold = $fees['very_large_supply_threshold'] ?? 100000000000;
        $largeThreshold = $fees['large_supply_threshold'] ?? 1000000000;

        if ($supply > $veryLargeThreshold) {
            $supFee = (float) ($optionFees['very_large_supply'] ?? 25);
            if ($supFee > 0) {
                $breakdown[] = ['label' => 'Very Large Supply (>100B)', 'label_th' => 'Supply ขนาดใหญ่มาก (>100B)', 'amount' => $supFee];
                $total += $supFee;
            }
        } elseif ($supply > $largeThreshold) {
            $supFee = (float) ($optionFees['large_supply'] ?? 10);
            if ($supFee > 0) {
                $breakdown[] = ['label' => 'Large Supply (>1B)', 'label_th' => 'Supply ขนาดใหญ่ (>1B)', 'amount' => $supFee];
                $total += $supFee;
            }
        }

        // เช็คว่า fee method เป็น free หรือเปล่า
        $feeMethod = SiteSetting::get('factory', 'fee_payment_method', 'tpix');
        if ($feeMethod === 'free') {
            return [
                'total' => 0,
                'currency' => 'FREE',
                'breakdown' => [],
                'is_free' => true,
                'is_testnet' => false,
            ];
        }

        return [
            'total' => round($total, 2),
            'currency' => 'TPIX',
            'breakdown' => $breakdown,
            'is_free' => false,
            'is_testnet' => false,
        ];
    }

    /**
     * Get factory configuration for frontend (รวม dynamic fee config)
     */
    public function getFactoryConfig(): array
    {
        $fees = config('blockchain.creation_fees', []);

        return [
            'fee_payment_method' => SiteSetting::get('factory', 'fee_payment_method', 'tpix'),
            'fee_wallet' => SiteSetting::get('factory', 'fee_wallet', ''),
            'nft_enabled' => filter_var(SiteSetting::get('factory', 'nft_enabled', true), FILTER_VALIDATE_BOOLEAN),
            'max_supply_limit' => (float) SiteSetting::get('factory', 'max_supply_limit', 999999999999999),
            'auto_approve' => filter_var(SiteSetting::get('factory', 'auto_approve', false), FILTER_VALIDATE_BOOLEAN),
            'creation_enabled' => filter_var(SiteSetting::get('factory', 'creation_enabled', true), FILTER_VALIDATE_BOOLEAN),

            // Dynamic fee config — ส่งให้ frontend คำนวณได้ real-time
            'dynamic_fees' => [
                'base_fee' => (float) SiteSetting::get('factory', 'base_fee', $fees['base_fee'] ?? 50),
                'category_fees' => $fees['category_fees'] ?? ['fungible' => 0, 'nft' => 30, 'special' => 50],
                'type_fees' => $fees['type_fees'] ?? [],
                'option_fees' => $fees['option_fees'] ?? [],
                'large_supply_threshold' => $fees['large_supply_threshold'] ?? 1000000000,
                'very_large_supply_threshold' => $fees['very_large_supply_threshold'] ?? 100000000000,
            ],

            // Legacy (backward compat) — ค่า fee ของ standard token
            'creation_fee_tpix' => (float) SiteSetting::get('factory', 'base_fee', $fees['base_fee'] ?? 50),
            'creation_fee_usd' => (float) SiteSetting::get('factory', 'creation_fee_usd', 10),

            // Testnet: สร้าง token ฟรี + reset ข้อมูลเป็นระยะ
            'testnet_chain_ids' => config('blockchain.testnet_chain_ids', [4290, 11155111, 97]),
            'testnet_reset_months' => config('blockchain.testnet_reset_months', 3),
        ];
    }
}
