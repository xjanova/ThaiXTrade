<?php

namespace App\Services;

use App\Jobs\DeployTokenJob;
use App\Models\FactoryToken;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;

class TokenFactoryService
{
    /**
     * สร้างคำขอสร้าง Token ใหม่.
     */
    public function createToken(array $data): FactoryToken
    {
        $tokenType = $data['token_type'] ?? 'standard';

        // Auto-determine category from type
        $nftTypes = ['nft', 'nft_collection'];
        $specialTypes = ['governance', 'stablecoin'];
        $category = $data['token_category']
            ?? (in_array($tokenType, $nftTypes) ? 'nft'
                : (in_array($tokenType, $specialTypes) ? 'special' : 'fungible'));

        // NFT ใช้ decimals = 0 เสมอ
        $decimals = in_array($tokenType, $nftTypes) ? 0 : ($data['decimals'] ?? 18);

        return FactoryToken::create([
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
            'metadata' => $data['metadata'] ?? null,
        ]);
    }

    /**
     * ดึง Tokens ของ creator.
     */
    public function getTokensByCreator(string $address, int $perPage = 20): LengthAwarePaginator
    {
        return FactoryToken::byCreator($address)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * ดึง Token ที่ deployed ทั้งหมด (public listing).
     */
    public function getDeployedTokens(int $perPage = 20): LengthAwarePaginator
    {
        return FactoryToken::deployed()
            ->where('is_listed', true)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * Admin: ดึงทุก token พร้อม filter.
     */
    public function getAllTokens(?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = FactoryToken::query()->orderByDesc('created_at');

        if ($status) {
            $query->where('status', $status);
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
     * สถิติรวม
     */
    public function getStats(): array
    {
        return [
            'total' => FactoryToken::count(),
            'pending' => FactoryToken::where('status', 'pending')->count(),
            'deployed' => FactoryToken::where('status', 'deployed')->count(),
            'rejected' => FactoryToken::where('status', 'rejected')->count(),
            'verified' => FactoryToken::where('is_verified', true)->count(),
            'unique_creators' => FactoryToken::distinct('creator_address')->count('creator_address'),
        ];
    }
}
