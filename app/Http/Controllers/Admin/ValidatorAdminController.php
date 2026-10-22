<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\SystemAlert;
use App\Models\ValidatorApplication;
use App\Models\ValidatorKeyBinding;
use App\Models\ValidatorSealStat;
use App\Services\MasterNode\NodeRegistryContract;
use App\Services\Validator\IbftValidatorSet;
use App\Services\Validator\SealMonitor;
use App\Services\Validator\ValidatorKeyBindingService;
use App\Services\Validator\ValidatorVoteCommands;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ValidatorAdminController — Admin dashboard สำหรับจัดการ IBFT2 Validators.
 *
 * แสดงสถานะ validator จาก IBFT extraData (ชุดจริง + ผลเฝ้าดูการปิดบล็อกรายตัว)
 * รายการผูกกุญแจที่รอโหวต/ต้องถอด และคำสั่งโหวตที่ถูกต้องสำหรับเครื่องเชน
 *
 * เว็บไม่สั่งโหวตเอง (ตั้งใจ): ช่อง gRPC ของ validator เปิดแค่ 127.0.0.1 ในเครื่องเชน
 * ใครต่อถึงก็สั่งเพิ่ม/ถอด validator ได้ — เจ้าของ ssh เข้าไปรันสคริปต์เอง
 *
 * Developed by Xman Studio.
 */
class ValidatorAdminController extends Controller
{
    public function __construct(
        private readonly IbftValidatorSet $validatorSet,
        private readonly NodeRegistryContract $registry,
    ) {}

    /**
     * Validator admin dashboard page.
     */
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/Validators/Index', $this->dashboard());
    }

    /**
     * API: List all pending validator applications with pagination.
     */
    public function applications(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 15), 100);

        $applications = ValidatorApplication::query()
            ->when($request->input('status'), function ($query, $status) {
                $query->where('status', $status);
            }, function ($query) {
                $query->where('status', 'pending');
            })
            ->when($request->input('search'), function ($query, $search) {
                $sanitized = trim($search);
                $query->where(function ($q) use ($sanitized) {
                    $q->where('wallet_address', 'like', "%{$sanitized}%")
                        ->orWhere('country_name', 'like', "%{$sanitized}%")
                        ->orWhere('contact_email', 'like', "%{$sanitized}%");
                });
            })
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $applications,
        ]);
    }

    /**
     * Approve a validator application.
     */
    public function approveApplication(Request $request, int $id): JsonResponse
    {
        $application = ValidatorApplication::findOrFail($id);

        if ($application->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Application is not in pending status. Current status: '.$application->status,
            ], 422);
        }

        $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $application->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_notes' => $request->input('admin_notes', ''),
        ]);

        Log::info('Validator application approved', [
            'application_id' => $application->id,
            'wallet_address' => $application->wallet_address,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application approved successfully.',
            'data' => $application->fresh(),
        ]);
    }

    /**
     * Reject a validator application with reason.
     */
    public function rejectApplication(Request $request, int $id): JsonResponse
    {
        $application = ValidatorApplication::findOrFail($id);

        if ($application->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Application is not in pending status. Current status: '.$application->status,
            ], 422);
        }

        $request->validate([
            'admin_notes' => ['required', 'string', 'max:1000'],
        ]);

        $application->update([
            'status' => 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'admin_notes' => $request->input('admin_notes'),
        ]);

        Log::info('Validator application rejected', [
            'application_id' => $application->id,
            'wallet_address' => $application->wallet_address,
            'reason' => $request->input('admin_notes'),
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application rejected.',
            'data' => $application->fresh(),
        ]);
    }

    /**
     * บันทึกความตั้งใจโหวต + คืนคำสั่งที่ต้องรันบนเครื่องเชน.
     *
     * เดิมคำแนะนำผิด 3 จุด: เมธอด RPC `ibft_proposeValidatorVote` ไม่มีจริงใน polygon-edge 0.9,
     * พอร์ต 9632 ไม่ใช่ (จริงคือ 10000 ในคอนเทนเนอร์) และไม่มี --bls ทั้งที่ genesis เป็น bls
     * → คำสั่งที่ copy ไปรันล้มทุกครั้ง
     */
    public function proposeVote(Request $request): JsonResponse
    {
        $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'action' => ['required', 'string', 'in:add,remove'],
            'bls' => ['required_if:action,add', 'nullable', 'string', 'regex:/^0x[a-fA-F0-9]{96}$/'],
            'node_id' => ['nullable', 'string', 'regex:/^16Uiu2HA[1-9A-HJ-NP-Za-km-z]{40,60}$/'],
        ]);

        $validatorAddress = strtolower($request->input('address'));
        $vote = $request->input('action');

        // Record the vote intent in the database
        SiteSetting::set('validators', "pending_vote_{$validatorAddress}", json_encode([
            'address' => $validatorAddress,
            'vote' => $vote,
            'bls' => $request->input('bls'),
            'proposed_by' => $request->user()->id,
            'proposed_at' => now()->toIso8601String(),
        ]));

        Log::info('Validator vote proposed', [
            'validator_address' => $validatorAddress,
            'vote' => $vote,
            'admin_id' => $request->user()->id,
        ]);

        $set = $this->validatorSet->current();
        $setSize = $set === null ? null : count($set['validators']);
        $commands = $vote === 'add'
            ? ValidatorVoteCommands::auth($validatorAddress, strtolower((string) $request->input('bls')), $request->input('node_id'))
            : ValidatorVoteCommands::drop($validatorAddress);

        return response()->json([
            'success' => true,
            'message' => 'บันทึกแล้ว — รันคำสั่งด้านล่างบนเครื่องเชนเพื่อโหวตจริง',
            'data' => [
                'validator_address' => $validatorAddress,
                'vote' => $vote,
                'cli_command' => $commands['script'],
                'cli_instructions' => $commands['lines'],
                'total_validators' => $setSize,
                'votes_needed' => $setSize === null ? null : IbftValidatorSet::votesNeeded($setSize),
                'warnings' => $vote === 'add' && $setSize !== null ? ValidatorVoteCommands::addWarnings($setSize) : [],
            ],
        ]);
    }

    /**
     * API: Detailed validator stats (AJAX polling).
     */
    public function stats(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->dashboard()]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * @return array{validators:list<array<string,mixed>>,onboarding:list<array<string,mixed>>,applications:mixed,stats:array<string,mixed>}
     */
    private function dashboard(): array
    {
        $set = $this->validatorSet->current();
        $validators = $set['validators'] ?? [];
        $setAddresses = array_column($validators, 'address');
        $sealStats = ValidatorSealStat::all()->keyBy('address');
        $bindings = ValidatorKeyBinding::active()->get();
        $boundBy = $bindings->keyBy('validator_address');
        $silentKeys = SystemAlert::active()->where('node', SealMonitor::ALERT_NODE)
            ->where('alert_key', 'like', 'validator_silent_%')->pluck('alert_key')->all();

        $rows = [];
        foreach ($validators as $i => $v) {
            $stat = $sealStats->get($v['address']);
            $rows[] = [
                'address' => $v['address'],
                'bls' => $v['bls'],
                'index' => $i,
                'active' => true,
                'last_sealed_block' => $stat?->last_proposed_block,
                'last_signed_block' => $stat?->last_signed_block,
                'blocks_sealed' => $stat?->window_proposed ?? 0,
                'sign_rate' => $stat?->signRate(),
                'missed_turns' => $stat?->window_missed_turns ?? 0,
                'window_blocks' => $stat?->window_blocks ?? 0,
                'silent' => in_array(SealMonitor::silentKey($v['address']), $silentKeys, true),
                'staking_wallet' => $boundBy->get($v['address'])?->staking_wallet,
            ];
        }

        $onboarding = [];
        foreach ($bindings as $binding) {
            $inSet = $set === null ? null : in_array($binding->validator_address, $setAddresses, true);
            $stage = ValidatorKeyBindingService::stage(true, $this->registry->isValidator($binding->staking_wallet), $inSet);
            $onboarding[] = [
                'staking_wallet' => $binding->staking_wallet,
                'validator_address' => $binding->validator_address,
                'bls_public_key' => $binding->bls_public_key,
                'node_id' => $binding->node_id,
                'signed_at' => $binding->signed_at,
                'voted_in_at' => $binding->voted_in_at?->toIso8601String(),
                'stage' => $stage,
                'commands' => match ($stage) {
                    'awaiting_vote' => ValidatorVoteCommands::auth($binding->validator_address, $binding->bls_public_key, $binding->node_id),
                    'drop_needed' => ValidatorVoteCommands::drop($binding->validator_address),
                    default => null,
                },
            ];
        }

        $setSize = count($validators);

        return [
            'validators' => $rows,
            'onboarding' => $onboarding,
            'applications' => ValidatorApplication::latest()->limit(50)->get(),
            'stats' => [
                'active_validators' => $setSize,
                'validator_addresses' => $setAddresses,
                'quorum' => IbftValidatorSet::quorum($setSize),
                'fault_tolerance' => IbftValidatorSet::faultTolerance($setSize),
                'votes_needed' => IbftValidatorSet::votesNeeded($setSize),
                'silent_validators' => count(array_filter($rows, fn ($r) => $r['silent'])),
                'awaiting_vote' => count(array_filter($onboarding, fn ($o) => $o['stage'] === 'awaiting_vote')),
                'drop_needed' => count(array_filter($onboarding, fn ($o) => $o['stage'] === 'drop_needed')),
                'monitor_block' => $sealStats->max('scanned_to_block'),
                'pending_applications' => ValidatorApplication::where('status', 'pending')->count(),
                'approved_applications' => ValidatorApplication::where('status', 'approved')->count(),
                'rejected_applications' => ValidatorApplication::where('status', 'rejected')->count(),
                'block_height' => $set['block'] ?? $this->getBlockHeight(config('blockchain.tpix_rpc_url', 'https://rpc1.tpix.online')),
                'block_time' => 2, // TPIX Chain 2-second blocks
                'consensus' => 'IBFT',
                'chain_id' => config('blockchain.tpix_chain_id', 4289),
                'rpc_connected' => $set !== null,
            ],
        ];
    }

    /**
     * Get the current block height from the chain.
     */
    private function getBlockHeight(string $rpcUrl): int
    {
        try {
            $response = Http::timeout(5)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_blockNumber',
                'params' => [],
                'id' => 1,
            ]);

            if ($response->successful()) {
                return (int) hexdec($response->json('result', '0x0'));
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
