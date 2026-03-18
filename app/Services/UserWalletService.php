<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletConnection;

/**
 * TPIX TRADE — User Wallet Service
 * จัดการสมัครสมาชิกอัตโนมัติเมื่อ connect wallet + ประวัติ connection
 * Developed by Xman Studio.
 */
class UserWalletService
{
    /**
     * หาผู้ใช้จาก wallet address หรือสร้างใหม่อัตโนมัติ
     * เรียกทุกครั้งที่ wallet connect สำเร็จ
     */
    public function findOrCreateByWallet(
        string $address,
        int $chainId = 56,
        string $walletType = 'metamask',
        ?string $ip = null
    ): User {
        $address = strtolower($address);

        // หา user จาก wallet address
        $user = User::where('wallet_address', $address)->first();

        if (! $user) {
            // สร้างใหม่อัตโนมัติ
            $user = User::create([
                'wallet_address' => $address,
                'last_active_at' => now(),
                'last_ip' => $ip,
            ]);
        } else {
            // อัปเดต activity
            $user->touchActivity($ip);
        }

        // บันทึก wallet connection
        WalletConnection::create([
            'user_id' => $user->id,
            'wallet_address' => $address,
            'chain_id' => $chainId,
            'wallet_type' => $walletType,
            'is_primary' => true,
            'connected_at' => now(),
        ]);

        return $user;
    }

    /**
     * บันทึก disconnect
     */
    public function recordDisconnect(string $address): void
    {
        WalletConnection::where('wallet_address', strtolower($address))
            ->whereNull('disconnected_at')
            ->update(['disconnected_at' => now()]);
    }

    /**
     * อัปเดต profile (email, name)
     */
    public function updateProfile(User $user, array $data): User
    {
        $user->update(array_filter([
            'email' => $data['email'] ?? null,
            'name' => $data['name'] ?? null,
        ]));

        return $user->fresh();
    }

    /**
     * สถิติรวมสำหรับ admin dashboard
     */
    public function getStats(): array
    {
        return [
            'total_users' => User::count(),
            'active_users' => User::active()->count(),
            'banned_users' => User::banned()->count(),
            'kyc_pending' => User::where('kyc_status', 'pending')->count(),
            'total_wallets' => WalletConnection::distinct('wallet_address')->count('wallet_address'),
            'wallet_types' => WalletConnection::selectRaw('wallet_type, count(*) as total')
                ->groupBy('wallet_type')
                ->pluck('total', 'wallet_type'),
            'chain_distribution' => WalletConnection::selectRaw('chain_id, count(distinct user_id) as total')
                ->groupBy('chain_id')
                ->pluck('total', 'chain_id'),
        ];
    }
}
