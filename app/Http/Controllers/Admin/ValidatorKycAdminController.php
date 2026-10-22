<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KycSubmission;
use App\Models\ValidatorKeyBinding;
use App\Models\ValidatorKycAction;
use App\Services\MasterNode\NodeRegistryContract;
use App\Services\Validator\TpixRpc;
use App\Services\Validator\ValidatorKycContract;
use App\Services\Validator\ValidatorKycHasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ValidatorKycAdminController — หน้าตรวจ KYC ของผู้สมัคร Validator.
 *
 * ขั้นตอน KYC บนสัญญา ValidatorKYC:
 *   1. ผู้สมัครกด giveConsent() เอง (หน้า /masternode)
 *   2. ทีมงาน submitKYC(applicant, hash)  ← หน้านี้
 *   3. ทีมงาน approveKYC / rejectKYC      ← หน้านี้
 *
 * ⚠️ เว็บไม่ถือกุญแจ owner — กระเป๋า owner ในเบราว์เซอร์ของแอดมินเป็นคนเซ็น
 *    เซิร์ฟเวอร์แค่ (ก) บอกสถานะจริงจากเชน (ข) ตรวจ tx ที่ส่งไปแล้วก่อนบันทึกเป็นประวัติ
 *    ไม่เชื่อสิ่งที่เบราว์เซอร์บอกว่าทำ — ทุกแถวใน validator_kyc_actions ผ่านการอ่าน receipt จริง
 *
 * Developed by Xman Studio.
 */
class ValidatorKycAdminController extends Controller
{
    /** สถานะบนสัญญาที่ถือว่า action นั้น "เกิดแล้ว" (เผื่อยืนยันช้า ขั้นถัดไปทำไปแล้ว) */
    private const EXPECTED_STATUS = [
        'submit' => ['Submitted', 'Approved', 'Rejected'],
        'approve' => ['Approved'],
        'reject' => ['Rejected'],
    ];

    public function __construct(
        private readonly ValidatorKycContract $kyc,
        private readonly ValidatorKycHasher $hasher,
        private readonly NodeRegistryContract $registry,
        private readonly TpixRpc $rpc,
    ) {}

    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/Validators/Kyc', [
            'contract' => $this->kyc->address(),
            'chainId' => (int) config('blockchain.tpix_chain_id', 4289),
            'rpcUrl' => $this->registry->publicRpcUrl(),
            'explorerUrl' => 'https://explorer.tpix.online',
            ...$this->snapshot(),
        ]);
    }

    /** GET — รีเฟรชรายชื่อหลังเซ็น tx โดยไม่โหลดหน้าใหม่ */
    public function applicants(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->snapshot()]);
    }

    /**
     * POST — เบราว์เซอร์ส่ง tx ไปแล้ว ขอให้เซิร์ฟเวอร์ตรวจกับเชนแล้วบันทึกเป็นประวัติ.
     */
    public function confirm(Request $request, string $address): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:submit,approve,reject'],
            'tx_hash' => ['required', 'string', 'regex:/^0x[0-9a-fA-F]{64}$/'],
            'kyc_submission_uuid' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if (! preg_match('/^0x[0-9a-fA-F]{40}$/', $address)) {
            return $this->fail('INVALID_ADDRESS', 'ที่อยู่ผู้สมัครไม่ถูกต้อง');
        }
        $applicant = strtolower($address);
        $txHash = strtolower($validated['tx_hash']);

        // ยืนยันซ้ำ (กดรัว / เบราว์เซอร์ retry) = คืนแถวเดิม ไม่สร้างซ้ำ
        $existing = ValidatorKycAction::where('tx_hash', $txHash)->first();
        if ($existing !== null) {
            return response()->json(['success' => true, 'data' => ['action' => $existing, 'record' => $this->kyc->record($applicant)]]);
        }

        $contract = $this->kyc->address();
        if ($contract === null) {
            return $this->fail('KYC_NOT_CONFIGURED', 'ยังไม่ได้ตั้งที่อยู่สัญญา ValidatorKYC ในทะเบียนสัญญา', 503);
        }

        $receipt = $this->rpc->receipt($txHash);
        if ($receipt === null) {
            return $this->fail('TX_NOT_FOUND', 'ยังไม่เห็นธุรกรรมนี้บนเชน — รอสักครู่แล้วกดตรวจอีกครั้ง', 409);
        }
        if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
            return $this->fail('TX_FAILED', 'ธุรกรรมนี้ไม่สำเร็จบนเชน (revert) — สถานะ KYC ไม่เปลี่ยน');
        }

        $tx = $this->rpc->transaction($txHash);
        if ($tx === null || strtolower((string) ($tx['to'] ?? '')) !== strtolower($contract)) {
            return $this->fail('TX_WRONG_CONTRACT', 'ธุรกรรมนี้ไม่ได้เรียกสัญญา ValidatorKYC ที่เว็บใช้อยู่');
        }

        $call = $this->kyc->decodeWrite((string) ($tx['input'] ?? ''));
        if ($call === null || $call['action'] !== $validated['action'] || $call['applicant'] !== $applicant) {
            return $this->fail('TX_MISMATCH', 'ธุรกรรมนี้ไม่ใช่รายการที่ขอให้ตรวจ (คนละคำสั่งหรือคนละผู้สมัคร)');
        }

        $owner = $this->kyc->owner();
        $signer = strtolower((string) ($tx['from'] ?? ''));
        if ($owner === null || $signer !== $owner) {
            return $this->fail('TX_NOT_OWNER', 'ผู้เซ็นธุรกรรมไม่ใช่ owner ของสัญญา');
        }

        $record = $this->kyc->record($applicant);
        if ($record === null || ! in_array($record['status'], self::EXPECTED_STATUS[$call['action']], true)) {
            return $this->fail('CHAIN_STATE_MISMATCH', 'สถานะบนสัญญายังไม่ตรงกับรายการนี้ — ลองตรวจอีกครั้งในอีกสักครู่', 409);
        }

        $submission = null;
        if ($call['action'] === 'submit') {
            if (! empty($validated['kyc_submission_uuid'])) {
                $submission = KycSubmission::where('uuid', $validated['kyc_submission_uuid'])->first();
                if ($submission === null || $this->hasher->hash($submission) !== $call['kyc_hash']) {
                    return $this->fail('HASH_NOT_FROM_SUBMISSION', 'hash บนเชนไม่ตรงกับใบ KYC ที่อ้าง — ประวัติไม่ถูกบันทึก');
                }
            } elseif (trim((string) ($validated['note'] ?? '')) === '') {
                return $this->fail('NOTE_REQUIRED', 'hash ที่ไม่ได้มาจากใบ KYC ของเว็บต้องมีเหตุผลกำกับ');
            }
        }

        $action = ValidatorKycAction::create([
            'applicant' => $applicant,
            'action' => $call['action'],
            'tx_hash' => $txHash,
            'signer' => $signer,
            'kyc_hash' => $call['kyc_hash'],
            'kyc_submission_id' => $submission?->id,
            'note' => $validated['note'] ?? null,
            'chain_status' => $record['status'],
            'admin_user_id' => $request->user('admin')?->id,
            'block_number' => isset($receipt['blockNumber']) ? (int) hexdec(substr((string) $receipt['blockNumber'], 2)) : null,
        ]);

        Log::info('Validator KYC action confirmed on-chain', [
            'applicant' => $applicant,
            'action' => $call['action'],
            'tx_hash' => $txHash,
            'admin_id' => $action->admin_user_id,
        ]);

        return response()->json(['success' => true, 'data' => ['action' => $action, 'record' => $record]]);
    }

    // =========================================================================

    /**
     * @return array{owner:?string,applicants:list<array<string,mixed>>,loadError:?string}
     */
    private function snapshot(): array
    {
        if ($this->kyc->address() === null) {
            return ['owner' => null, 'applicants' => [], 'loadError' => 'KYC_NOT_CONFIGURED'];
        }

        $records = $this->kyc->applicants();
        if ($records === null) {
            return ['owner' => $this->kyc->owner(), 'applicants' => [], 'loadError' => 'CHAIN_UNREACHABLE'];
        }

        $wallets = array_column($records, 'applicant');
        $bindings = ValidatorKeyBinding::active()->whereIn('staking_wallet', $wallets)->get()->keyBy('staking_wallet');
        $actions = ValidatorKycAction::whereIn('applicant', $wallets)->latest('id')->get()->groupBy('applicant');

        $rows = [];
        foreach ($records as $record) {
            $wallet = $record['applicant'];
            $site = $this->hasher->siteKycSummary($wallet);
            $node = $this->registry->nodeInfo($wallet);
            $binding = $bindings->get($wallet);

            $rows[] = [
                ...$record,
                'site_kyc' => $site,
                'hash_matches_site' => $record['kyc_hash'] === null || $site['doc_hash'] === null
                    ? null
                    : $record['kyc_hash'] === $site['doc_hash'],
                'node' => $node === null ? null : [
                    'tier' => $node['tier'],
                    'status' => $node['status'],
                ],
                'binding' => $binding === null ? null : [
                    'validator_address' => $binding->validator_address,
                    'bls_public_key' => $binding->bls_public_key,
                    'node_id' => $binding->node_id,
                    'signed_at' => $binding->signed_at,
                ],
                'actions' => $actions->get($wallet, collect())->take(5)->map(fn (ValidatorKycAction $a) => [
                    'action' => $a->action,
                    'tx_hash' => $a->tx_hash,
                    'chain_status' => $a->chain_status,
                    'note' => $a->note,
                    'at' => $a->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return ['owner' => $this->kyc->owner(), 'applicants' => $rows, 'loadError' => null];
    }

    private function fail(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
    }
}
