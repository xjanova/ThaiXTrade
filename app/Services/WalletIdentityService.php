<?php

namespace App\Services;

use App\Models\User;
use App\Models\WalletConnection;
use Illuminate\Support\Facades\DB;

/**
 * TPIX TRADE — ที่เดียวที่ตัดสินว่ากระเป๋าใบนี้เป็นของบัญชีไหน.
 *
 * ก่อนหน้านี้ระบบมีทางเข้าสองทางที่ไม่รู้จักกัน: สมัครด้วยอีเมล กับ เชื่อมกระเป๋า
 * ใครที่ล็อกอินด้วยอีเมลแล้วเชื่อมกระเป๋า จะได้บัญชีใหม่แยกอีกใบเงียบๆ — ประวัติ
 * เทรด บอท และค่าตั้งของเขาแตกเป็นสองก้อนโดยไม่มีอะไรบอก
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ เรียกได้เฉพาะ "หลังพิสูจน์ลายเซ็นแล้ว" เท่านั้น
 * ═══════════════════════════════════════════════════════════════════════════
 * `linkVerified()` ผูกกระเป๋าเข้ากับบัญชีที่กำลังล็อกอินอยู่ ซึ่งเท่ากับให้สิทธิ์
 * เข้าบัญชีนั้นด้วยกระเป๋าใบนี้ตลอดไป ถ้าเรียกจากที่ที่ยังไม่ได้ ecrecover
 * ใครก็ตามจะยัดเลขกระเป๋าของคนอื่นเข้าบัญชีตัวเองได้ แล้วสวมสิทธิ์ทันที
 *
 * กฎที่ตั้งใจให้เข้มกว่าที่ผู้ใช้อยากได้:
 *   • ไม่รวมบัญชีอัตโนมัติ — การรวมบัญชีย้อนกลับไม่ได้ ต้องผ่านคนตรวจ
 *   • หนึ่งบัญชีมีได้กระเป๋าเดียว (คอลัมน์ users.wallet_address เป็น unique อยู่แล้ว)
 *   • บัญชีที่ถูกแบนไม่มีวันได้ session ไม่ว่าเซ็นถูกแค่ไหน
 *
 * Developed by Xman Studio.
 */
class WalletIdentityService
{
    /** ผลลัพธ์ที่เป็นไปได้ของการผูกกระเป๋า */
    public const RESULT_SIGNED_IN = 'signed_in';      // ไม่มี session มาก่อน → เข้าสู่ระบบด้วยกระเป๋า

    public const RESULT_LINKED = 'linked';            // ผูกกระเป๋าเข้าบัญชีที่ล็อกอินอยู่

    public const RESULT_ADOPTED = 'adopted';          // ผูก + ยุบบัญชีเปล่าที่ค้างอยู่กับกระเป๋านี้

    public const RESULT_ALREADY = 'already';          // เป็นของบัญชีนี้อยู่แล้ว ไม่ต้องทำอะไร

    public const ERR_WALLET_TAKEN = 'WALLET_TAKEN';   // กระเป๋าเป็นของบัญชีอื่นที่ล็อกอินได้จริง

    public const ERR_ALREADY_LINKED = 'ALREADY_LINKED'; // บัญชีนี้ผูกกระเป๋าใบอื่นไว้แล้ว

    public const ERR_BANNED = 'BANNED';

    public function __construct(private readonly UserWalletService $wallets) {}

    /**
     * บัญชีที่ควรใช้ตอน "เชื่อมกระเป๋า" ซึ่งยังไม่ได้พิสูจน์ลายเซ็น.
     *
     * ⚠️ ห้ามผูกกระเป๋าเข้าบัญชีที่ล็อกอินอยู่ตรงนี้เด็ดขาด
     *    ปลายทางนี้ไม่ต้องพิสูจน์อะไรเลย ใครยิงเลขกระเป๋าอะไรมาก็ได้
     *
     * เมื่อมี session อยู่แล้วจะไม่สร้างบัญชีใหม่ให้กระเป๋าที่ยังไม่มีเจ้าของ —
     * ปล่อยให้ขั้นตอนเซ็นเป็นคนตัดสิน ไม่งั้นจะเกิดบัญชีเปล่าค้างไว้ทุกครั้งที่
     * ผู้ใช้ที่ล็อกอินด้วยอีเมลกดเชื่อมกระเป๋า
     */
    public function resolveForConnect(
        ?User $sessionUser,
        string $address,
        int $chainId,
        string $walletType,
        ?string $ip = null,
    ): ?User {
        $address = strtolower($address);

        if ($sessionUser) {
            // เป็นกระเป๋าของเขาเองอยู่แล้ว — บันทึกการเชื่อมต่อตามปกติ
            if ($sessionUser->wallet_address === $address) {
                $sessionUser->touchActivity($ip);
                $this->recordConnection($sessionUser, $address, $chainId, $walletType);

                return $sessionUser;
            }

            // กระเป๋าคนละใบกับที่ผูกไว้ / ยังไม่ได้ผูก → รอขั้นตอนเซ็นก่อน
            return $sessionUser;
        }

        return $this->wallets->findOrCreateByWallet($address, $chainId, $walletType, $ip);
    }

    /**
     * ตัดสินตัวตนหลังพิสูจน์ลายเซ็นสำเร็จแล้ว.
     *
     * @return array{user: ?User, result: ?string, error: ?string}
     */
    public function linkVerified(
        ?User $sessionUser,
        string $address,
        int $chainId,
        string $walletType,
        ?string $ip = null,
    ): array {
        $address = strtolower($address);
        $owner = User::where('wallet_address', $address)->first();

        // ── ยังไม่ได้ล็อกอิน: เข้าสู่ระบบด้วยกระเป๋า ────────────────────────
        if (! $sessionUser) {
            $user = $owner ?? $this->wallets->findOrCreateByWallet($address, $chainId, $walletType, $ip);

            if ($user->is_banned) {
                return $this->fail(self::ERR_BANNED);
            }

            $user->touchActivity($ip);
            $this->recordConnection($user, $address, $chainId, $walletType);

            return ['user' => $user, 'result' => self::RESULT_SIGNED_IN, 'error' => null];
        }

        // ── ล็อกอินอยู่แล้ว ─────────────────────────────────────────────────
        if ($sessionUser->wallet_address === $address) {
            $this->recordConnection($sessionUser, $address, $chainId, $walletType);

            return ['user' => $sessionUser, 'result' => self::RESULT_ALREADY, 'error' => null];
        }

        // ผูกกระเป๋าใบอื่นไว้แล้ว — ต้องถอดใบเดิมก่อน ไม่สลับให้เงียบๆ
        // (ผู้ใช้ที่สลับบัญชีในกระเป๋าโดยไม่ตั้งใจจะไม่โดนย้ายบัญชีทิ้ง)
        if ($sessionUser->wallet_address !== null) {
            return $this->fail(self::ERR_ALREADY_LINKED);
        }

        // กระเป๋าใบนี้มีเจ้าของอยู่แล้ว
        if ($owner && $owner->id !== $sessionUser->id) {
            // เจ้าของเป็นบัญชีที่ล็อกอินได้จริง = คนละคน ห้ามแตะ
            if ($this->canSignIn($owner)) {
                return $this->fail(self::ERR_WALLET_TAKEN);
            }

            // เจ้าของเป็นบัญชีเปล่าที่ระบบสร้างทิ้งไว้ตอนใครสักคนกดเชื่อมกระเป๋า
            // ไม่มีใครล็อกอินเข้าไปได้ และคนที่กำลังขอผูกก็เพิ่งพิสูจน์แล้วว่าคุมกระเป๋านี้
            // จึงยุบรวมเข้าบัญชีเขาได้ — ไม่ใช่การยึดบัญชีของใคร
            return $this->adopt($sessionUser, $owner, $address, $chainId, $walletType, $ip);
        }

        $sessionUser->update(['wallet_address' => $address]);
        $sessionUser->touchActivity($ip);
        $this->recordConnection($sessionUser, $address, $chainId, $walletType);

        return ['user' => $sessionUser->fresh(), 'result' => self::RESULT_LINKED, 'error' => null];
    }

    /**
     * ถอดกระเป๋าออกจากบัญชี.
     *
     * ปฏิเสธเมื่อกระเป๋าเป็นทางเข้าเดียวที่เหลือ — ถอดแล้วเจ้าของจะล็อกอินกลับ
     * เข้ามาไม่ได้อีกเลย และบัญชีนั้นก็กู้คืนเองไม่ได้ด้วย (ไม่มีอีเมลให้ส่งลิงก์)
     * กฎเดียวกับการถอดบัญชีโซเชียล
     *
     * @return array{ok: bool, error: ?string}
     */
    public function unlink(User $user): array
    {
        if ($user->wallet_address === null) {
            return ['ok' => false, 'error' => 'NOT_LINKED'];
        }

        if (! $this->hasOtherSignInMethod($user)) {
            return ['ok' => false, 'error' => 'ONLY_SIGN_IN_METHOD'];
        }

        $address = $user->wallet_address;

        DB::transaction(function () use ($user, $address) {
            $user->update(['wallet_address' => null]);
            WalletConnection::where('user_id', $user->id)
                ->where('wallet_address', $address)
                ->whereNull('disconnected_at')
                ->update(['disconnected_at' => now()]);
        });

        return ['ok' => true, 'error' => null];
    }

    /** บัญชีนี้ล็อกอินได้ด้วยวิธีอื่นนอกจากกระเป๋าไหม */
    public function hasOtherSignInMethod(User $user): bool
    {
        return ($user->email !== null && $user->password !== null)
            || $user->socialAccounts()->exists();
    }

    // ── ภายใน ────────────────────────────────────────────────────────────────

    /**
     * บัญชีนี้มีใครล็อกอินเข้าไปได้ไหม.
     *
     * บัญชีที่ระบบสร้างให้ตอนเชื่อมกระเป๋าจะมีแต่ wallet_address ล้วนๆ
     * ไม่มีอีเมล ไม่มีรหัสผ่าน ไม่มีโซเชียล — ไม่มีใครเข้าถึงมันได้เลยนอกจาก
     * คนที่ถือกระเป๋า
     */
    private function canSignIn(User $user): bool
    {
        return $user->email !== null
            || $user->password !== null
            || $user->socialAccounts()->exists();
    }

    /** ยุบบัญชีเปล่าเข้าบัญชีที่ล็อกอินอยู่ */
    private function adopt(
        User $sessionUser,
        User $shell,
        string $address,
        int $chainId,
        string $walletType,
        ?string $ip,
    ): array {
        DB::transaction(function () use ($sessionUser, $shell, $address) {
            // ปลดกระเป๋าออกจากบัญชีเปล่าก่อน — คอลัมน์เป็น unique ผูกซ้อนไม่ได้
            $shell->update(['wallet_address' => null]);

            // ประวัติการเชื่อมต่อย้ายตามเจ้าของใหม่ ไม่ทิ้งให้หายไปกับบัญชีที่ถูกลบ
            WalletConnection::where('user_id', $shell->id)
                ->update(['user_id' => $sessionUser->id]);

            $sessionUser->update(['wallet_address' => $address]);

            // soft delete — เก็บร่องรอยไว้ให้แอดมินตรวจย้อนหลังได้ว่าเกิดอะไรขึ้น
            $shell->delete();
        });

        $sessionUser->touchActivity($ip);
        $this->recordConnection($sessionUser, $address, $chainId, $walletType);

        return ['user' => $sessionUser->fresh(), 'result' => self::RESULT_ADOPTED, 'error' => null];
    }

    /** บันทึกการเชื่อมต่อ โดยไม่เพิ่มแถวซ้ำถ้าเพิ่งบันทึกไปเมื่อครู่ */
    private function recordConnection(User $user, string $address, int $chainId, string $walletType): void
    {
        $recent = WalletConnection::where('user_id', $user->id)
            ->where('wallet_address', $address)
            ->where('chain_id', $chainId)
            ->whereNull('disconnected_at')
            ->where('connected_at', '>=', now()->subMinutes(5))
            ->exists();

        if ($recent) {
            return;
        }

        WalletConnection::create([
            'user_id' => $user->id,
            'wallet_address' => $address,
            'chain_id' => $chainId,
            'wallet_type' => $walletType,
            'is_primary' => true,
            'connected_at' => now(),
        ]);
    }

    private function fail(string $code): array
    {
        return ['user' => null, 'result' => null, 'error' => $code];
    }
}
