<?php

namespace App\Services\Validator;

use App\Models\ValidatorKeyBinding;
use App\Services\MasterNode\NodeRegistryContract;
use App\Services\MasterNode\Web3SignatureService;
use Illuminate\Support\Facades\DB;

/**
 * ValidatorKeyBindingService — ผูก "กระเป๋าที่ stake ชั้น Validator" กับ "กุญแจที่ปิดบล็อก".
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  ทำไมต้องมี
 * ═══════════════════════════════════════════════════════════════════════════
 *  NodeRegistryV2 เก็บแค่ว่าใคร stake ไม่มีช่องเก็บที่อยู่ IBFT / BLS key ของเครื่องที่จะปิดบล็อก
 *  แอปมาสเตอร์โหนดสร้างกุญแจ validator แยกต่างหาก (polygon-edge secrets init) ไม่เคยโชว์ ไม่เคยลงทะเบียน
 *  → เจ้าของเชนไม่มีทางรู้ว่าจะโหวต `ibft propose --addr ? --bls ?` ให้ใคร
 *
 * ═══════════════════════════════════════════════════════════════════════════
 *  ทำไมต้องเซ็นสองใบ (กระเป๋าที่ stake + กุญแจ validator)
 * ═══════════════════════════════════════════════════════════════════════════
 *  ถ้าเซ็นแค่กระเป๋า ใครก็เอาที่อยู่ validator หลัก 4 ตัวมาผูกกับ stake ของตัวเองได้
 *  วันที่เขาถอน stake ขั้นตอน "ถอดออกจากชุด" จะถอด validator หลักทิ้ง = เชนเสียตัวสำรอง
 *  ลายเซ็นของกุญแจ validator พิสูจน์ว่าคนผูกถือกุญแจนั้นจริง
 *
 *  BLS key พิสูจน์การถือครองไม่ได้ (polygon-edge 0.9 ไม่มีคำสั่งเซ็น BLS) — แอปอ่านจาก data-dir
 *  เดียวกับกุญแจ ECDSA จึงไม่พลาดโดยไม่ตั้งใจ ส่วน BLS ปลอมโดยตั้งใจ = โหนดนั้นเซ็นบล็อกไม่ได้เอง
 *  ตัวเฝ้าดูการ seal จับได้และถอดออกได้
 *
 * Developed by Xman Studio
 */
class ValidatorKeyBindingService
{
    public const TITLE = 'TPIX Chain validator key binding';

    /**
     * ลายเซ็นใช้ได้กี่วินาทีหลังเซ็น — กันเอาลายเซ็นเก่ามายิงซ้ำ + เผื่อนาฬิกาเครื่องคลาด
     * 30 นาทีเพราะโหนดจริงมักอยู่บนเซิร์ฟเวอร์: เซ็นด้วยกุญแจ validator ที่นั่น แล้วคัดลอกมาเซ็นต่อ
     * ด้วยกระเป๋าที่ stake ในแอป/เว็บ ซึ่งเป็นคนทำมือ.
     */
    public const MAX_SKEW_SECONDS = 1800;

    public function __construct(
        private readonly Web3SignatureService $signatures,
        private readonly NodeRegistryContract $registry,
        private readonly IbftValidatorSet $validatorSet,
    ) {}

    /**
     * ข้อความที่ทั้งสองกุญแจเซ็น — แอปมาสเตอร์โหนดต้องสร้างแบบเดียวกันทุกตัวอักษร
     * (TPIX-Masternode electron/validator-binding.js → buildBindingMessage).
     *
     * @param  array{chain_id:int,staking_wallet:string,validator_address:string,bls_public_key:string,node_id:string,signed_at:int}  $f
     */
    public static function message(array $f): string
    {
        return implode("\n", [
            self::TITLE,
            'chain_id: '.$f['chain_id'],
            'staking_wallet: '.strtolower($f['staking_wallet']),
            'validator_address: '.strtolower($f['validator_address']),
            'bls_public_key: '.strtolower($f['bls_public_key']),
            'node_id: '.$f['node_id'],
            'signed_at: '.$f['signed_at'],
        ]);
    }

    /**
     * @param  array{chain_id:int,staking_wallet:string,validator_address:string,bls_public_key:string,node_id:string,signed_at:int,staking_signature:string,validator_signature:string}  $input
     *
     * @throws ValidatorBindingRefused
     */
    public function bind(array $input): ValidatorKeyBinding
    {
        $wallet = strtolower($input['staking_wallet']);
        $validator = strtolower($input['validator_address']);
        $bls = strtolower($input['bls_public_key']);

        if ((int) $input['chain_id'] !== (int) config('blockchain.tpix_chain_id', 4289)) {
            throw new ValidatorBindingRefused('CHAIN_MISMATCH', 'ลายเซ็นนี้ทำสำหรับเชนอื่น ไม่ใช่ TPIX Chain');
        }
        if (abs(time() - (int) $input['signed_at']) > self::MAX_SKEW_SECONDS) {
            throw new ValidatorBindingRefused('STALE_SIGNATURE', 'ลายเซ็นหมดอายุ (เกิน 30 นาที) หรือนาฬิกาเครื่องคลาด — ตั้งเวลาเครื่องให้ตรงแล้วเซ็นใหม่');
        }
        if ($wallet === $validator) {
            throw new ValidatorBindingRefused('SAME_KEY', 'กุญแจ validator ต้องไม่ใช่กระเป๋าที่ stake — กุญแจ validator อยู่ในเครื่องแบบไม่เข้ารหัส');
        }

        $message = self::message([...$input, 'staking_wallet' => $wallet, 'validator_address' => $validator, 'bls_public_key' => $bls]);
        if (! $this->signatures->verify($message, $input['staking_signature'], $wallet)) {
            throw new ValidatorBindingRefused('BAD_STAKING_SIGNATURE', 'ลายเซ็นของกระเป๋าที่ stake ไม่ถูกต้อง');
        }
        if (! $this->signatures->verify($message, $input['validator_signature'], $validator)) {
            throw new ValidatorBindingRefused('BAD_VALIDATOR_SIGNATURE', 'ลายเซ็นของกุญแจ validator ไม่ถูกต้อง — ต้องเซ็นด้วยกุญแจของเครื่องที่จะปิดบล็อก');
        }

        if (! $this->registry->isValidator($wallet)) {
            throw new ValidatorBindingRefused('NOT_VALIDATOR_STAKE', 'กระเป๋านี้ยังไม่มีโหนดชั้น Validator ที่ active บนเชน (หรืออ่านเชนไม่ได้ตอนนี้) — stake ก่อนแล้วค่อยผูกกุญแจ');
        }

        $set = $this->validatorSet->current();
        if ($set === null) {
            throw new ValidatorBindingRefused('CHAIN_UNREACHABLE', 'อ่านชุด validator จากเชนไม่ได้ตอนนี้ — ลองใหม่อีกครั้ง', 503);
        }
        $inSet = array_column($set['validators'], 'address');
        $blsInSet = array_filter(array_column($set['validators'], 'bls'));

        return DB::transaction(function () use ($input, $wallet, $validator, $bls, $message, $inSet, $blsInSet) {
            $current = ValidatorKeyBinding::active()->where('staking_wallet', $wallet)->lockForUpdate()->first();

            if ($current !== null && (int) $input['signed_at'] <= $current->signed_at) {
                throw new ValidatorBindingRefused('REPLAYED', 'มีการผูกที่ใหม่กว่านี้อยู่แล้ว — ลายเซ็นนี้เก่ากว่า');
            }

            $rebind = $current !== null && $current->validator_address === $validator;

            if (! $rebind && in_array($validator, $inSet, true)) {
                throw new ValidatorBindingRefused('ADDRESS_IN_SET', 'ที่อยู่นี้เป็น validator ที่ปิดบล็อกอยู่แล้ว ผูกกับกระเป๋าอื่นไม่ได้');
            }
            if (! $rebind && in_array($bls, $blsInSet, true)) {
                throw new ValidatorBindingRefused('BLS_IN_SET', 'BLS key นี้เป็นของ validator ที่ปิดบล็อกอยู่แล้ว');
            }
            if ($current !== null && ! $rebind && in_array($current->validator_address, $inSet, true)) {
                throw new ValidatorBindingRefused('OLD_KEY_STILL_SEALING', 'กุญแจเดิมของกระเป๋านี้ยังปิดบล็อกอยู่ — ให้ทีมถอดกุญแจเดิมออกจากชุดก่อนเปลี่ยน');
            }

            $taken = ValidatorKeyBinding::active()
                ->where('staking_wallet', '!=', $wallet)
                ->where(fn ($q) => $q->where('validator_address', $validator)->orWhere('bls_public_key', $bls))
                ->exists();
            if ($taken) {
                throw new ValidatorBindingRefused('KEY_TAKEN', 'กุญแจนี้ถูกผูกกับกระเป๋าอื่นแล้ว');
            }

            $current?->update(['status' => ValidatorKeyBinding::STATUS_SUPERSEDED]);

            return ValidatorKeyBinding::create([
                'staking_wallet' => $wallet,
                'validator_address' => $validator,
                'bls_public_key' => $bls,
                'node_id' => $input['node_id'],
                'message' => $message,
                'staking_signature' => strtolower($input['staking_signature']),
                'validator_signature' => strtolower($input['validator_signature']),
                'signed_at' => (int) $input['signed_at'],
                'status' => ValidatorKeyBinding::STATUS_ACTIVE,
                'voted_in_at' => $rebind ? $current->voted_in_at : null,
            ]);
        });
    }

    /**
     * ขั้นของกระเป๋านี้บนทาง "stake → ปิดบล็อก" — แอปใช้โชว์ความคืบหน้า แอดมินใช้ทำรายการโหวต.
     *
     * @return array{wallet:string,stage:string,binding:?array,stake_active:bool,in_set:?bool}
     */
    public function statusFor(string $wallet): array
    {
        $wallet = strtolower($wallet);
        $binding = ValidatorKeyBinding::active()->where('staking_wallet', $wallet)->first();
        $stakeActive = $this->registry->isValidator($wallet);
        $inSet = $binding === null ? null : $this->validatorSet->contains($binding->validator_address);

        return [
            'wallet' => $wallet,
            'stage' => self::stage($binding !== null, $stakeActive, $inSet),
            'binding' => $binding === null ? null : [
                'validator_address' => $binding->validator_address,
                'bls_public_key' => $binding->bls_public_key,
                'node_id' => $binding->node_id,
                'signed_at' => $binding->signed_at,
                'voted_in_at' => $binding->voted_in_at?->toIso8601String(),
            ],
            'stake_active' => $stakeActive,
            'in_set' => $inSet,
        ];
    }

    /**
     *   not_bound      — ยังไม่ผูกกุญแจ
     *   awaiting_vote  — ผูกแล้ว stake active รอเจ้าของเชนโหวตเข้า
     *   sealing        — อยู่ในชุดปิดบล็อกแล้ว
     *   drop_needed    — ยังอยู่ในชุดแต่ stake ไม่ active แล้ว (ถอน/ถูก slash) → ต้องโหวตถอด
     *   stake_inactive — ผูกไว้แต่ stake ไม่ active และไม่ได้อยู่ในชุด
     *   unknown        — อ่านชุด validator ไม่ได้ตอนนี้.
     */
    public static function stage(bool $bound, bool $stakeActive, ?bool $inSet): string
    {
        if (! $bound) {
            return 'not_bound';
        }
        if ($inSet === null) {
            return 'unknown';
        }
        if ($inSet) {
            return $stakeActive ? 'sealing' : 'drop_needed';
        }

        return $stakeActive ? 'awaiting_vote' : 'stake_inactive';
    }
}
