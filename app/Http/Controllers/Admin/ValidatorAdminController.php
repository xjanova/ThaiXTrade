<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Models\ValidatorApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * ValidatorAdminController — Admin dashboard สำหรับจัดการ IBFT2 Validators.
 *
 * แสดงสถานะ validator จาก IBFT2 extraData และจัดการ applications.
 * Developed by Xman Studio.
 */
class ValidatorAdminController extends Controller
{
    /**
     * Validator admin dashboard page.
     */
    public function index(): InertiaResponse
    {
        $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');

        $activeValidators = $this->getActiveValidators($rpcUrl);
        $pendingCount = ValidatorApplication::where('status', 'pending')->count();

        return Inertia::render('Admin/Validators/Index', [
            'validators' => collect($activeValidators)->map(fn ($addr) => [
                'address' => $addr,
                'active' => true,
                'last_sealed_block' => null,
                'blocks_sealed' => 0,
            ])->values()->all(),
            'applications' => ValidatorApplication::latest()->limit(50)->get(),
            'stats' => $this->getValidatorStats($rpcUrl, $activeValidators),
        ]);
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
                'message' => 'Application is not in pending status. Current status: ' . $application->status,
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
                'message' => 'Application is not in pending status. Current status: ' . $application->status,
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
     * Propose an IBFT2 validator vote.
     *
     * Records the intent and returns CLI instructions for manual execution.
     * The actual ibft.proposeValidatorVote requires the admin's private key
     * which should never be stored in the web application.
     */
    public function proposeVote(Request $request): JsonResponse
    {
        $request->validate([
            'address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'action' => ['required', 'string', 'in:add,remove'],
        ]);

        $validatorAddress = $request->input('address');
        $vote = $request->input('action');
        $auth = $vote === 'add';

        // Record the vote intent in the database
        SiteSetting::set('validators', "pending_vote_{$validatorAddress}", json_encode([
            'address' => $validatorAddress,
            'vote' => $vote,
            'proposed_by' => $request->user()->id,
            'proposed_at' => now()->toIso8601String(),
        ]));

        Log::info('Validator vote proposed', [
            'validator_address' => $validatorAddress,
            'vote' => $vote,
            'admin_id' => $request->user()->id,
        ]);

        // Generate CLI instructions for manual execution
        $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');
        $authValue = $auth ? 'true' : 'false';

        return response()->json([
            'success' => true,
            'message' => "Vote intent recorded. Execute the following on each validator node to complete the vote.",
            'data' => [
                'validator_address' => $validatorAddress,
                'vote' => $vote,
                'cli_instructions' => [
                    "# Run this on each validator node's JSON-RPC:",
                    "curl -X POST --data '{\"jsonrpc\":\"2.0\",\"method\":\"ibft_proposeValidatorVote\",\"params\":[\"{$validatorAddress}\", {$authValue}],\"id\":1}' -H 'Content-Type: application/json' {$rpcUrl}",
                    '',
                    '# Or via Polygon Edge CLI:',
                    "polygon-edge ibft propose --addr {$validatorAddress} --vote " . ($auth ? 'auth' : 'drop') . " --grpc-address 127.0.0.1:9632",
                ],
            ],
        ]);
    }

    /**
     * API: Detailed validator stats (AJAX polling).
     */
    public function stats(): JsonResponse
    {
        $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');
        $activeValidators = $this->getActiveValidators($rpcUrl);

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $this->getValidatorStats($rpcUrl, $activeValidators),
                'validators' => collect($activeValidators)->map(fn ($addr) => [
                    'address' => $addr,
                    'active' => true,
                    'last_sealed_block' => null,
                    'blocks_sealed' => 0,
                ])->values()->all(),
                'applications' => ValidatorApplication::latest()->limit(50)->get(),
            ],
        ]);
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Get active validators by extracting from IBFT2 extraData in latest block.
     *
     * IBFT2 extraData format:
     * - 32 bytes vanity
     * - RLP-encoded list of validator addresses
     * - Remaining IBFT2 seal data
     *
     * @return array<int, string> List of validator addresses
     */
    private function getActiveValidators(string $rpcUrl): array
    {
        return Cache::remember('admin:validators:active', 30, function () use ($rpcUrl) {
            try {
                $response = Http::timeout(5)->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getBlockByNumber',
                    'params' => ['latest', false],
                    'id' => 1,
                ]);

                if (! $response->successful()) {
                    return [];
                }

                $block = $response->json('result');
                if (! $block || empty($block['extraData'])) {
                    return [];
                }

                return $this->parseIbft2ExtraData($block['extraData']);
            } catch (\Throwable $e) {
                Log::error('Failed to fetch active validators', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Parse IBFT2 extraData to extract validator addresses.
     *
     * IBFT2 extra data structure:
     * - Bytes 0-31: Vanity (32 bytes)
     * - Bytes 32+: RLP-encoded [validators, seal, committedSeals]
     *
     * @return array<int, string>
     */
    private function parseIbft2ExtraData(string $extraData): array
    {
        $validators = [];

        try {
            // Remove 0x prefix
            $hex = substr($extraData, 2);

            // Skip 32 bytes vanity (64 hex chars)
            if (strlen($hex) <= 64) {
                return [];
            }

            $rlpHex = substr($hex, 64);

            // Simple RLP decode for the validator list
            // The first byte(s) tell us the list structure
            $validators = $this->decodeRlpAddressList($rlpHex);
        } catch (\Throwable $e) {
            Log::warning('Failed to parse IBFT2 extraData', ['error' => $e->getMessage()]);
        }

        return $validators;
    }

    /**
     * Decode RLP-encoded address list from IBFT2 extra data.
     *
     * Handles the RLP structure where validators are the first element
     * of a top-level list: [[addr1, addr2, ...], seal, [committedSeals]]
     *
     * @return array<int, string>
     */
    private function decodeRlpAddressList(string $hex): array
    {
        $addresses = [];

        if (strlen($hex) < 2) {
            return [];
        }

        $firstByte = hexdec(substr($hex, 0, 2));
        $offset = 0;

        // RLP list prefix: 0xf8+ = long list
        if ($firstByte >= 0xf8) {
            $lengthOfLength = $firstByte - 0xf7;
            $offset = 2 + ($lengthOfLength * 2);
        } elseif ($firstByte >= 0xc0) {
            // Short list (0xc0 - 0xf7)
            $offset = 2;
        } else {
            return [];
        }

        // Now we should be at the inner list (validators list)
        $innerHex = substr($hex, $offset);
        $innerFirstByte = hexdec(substr($innerHex, 0, 2));
        $innerOffset = 0;

        if ($innerFirstByte >= 0xf8) {
            $lengthOfLength = $innerFirstByte - 0xf7;
            $listLength = hexdec(substr($innerHex, 2, $lengthOfLength * 2));
            $innerOffset = 2 + ($lengthOfLength * 2);
        } elseif ($innerFirstByte >= 0xc0) {
            $listLength = $innerFirstByte - 0xc0;
            $innerOffset = 2;
        } else {
            return [];
        }

        // Parse addresses from the validator list
        $pos = $innerOffset;
        $endPos = $innerOffset + ($listLength * 2);

        while ($pos < $endPos && $pos < strlen($innerHex)) {
            $byte = hexdec(substr($innerHex, $pos, 2));

            // Each address is 20 bytes, RLP-encoded as 0x94 + 20 bytes
            if ($byte === 0x94) {
                // 0x94 = 0x80 + 20, meaning a 20-byte string
                $pos += 2;
                $addrHex = substr($innerHex, $pos, 40);
                if (strlen($addrHex) === 40) {
                    $addresses[] = '0x' . $addrHex;
                }
                $pos += 40;
            } else {
                // Unexpected byte, skip
                $pos += 2;
            }
        }

        return $addresses;
    }

    /**
     * Build comprehensive validator stats.
     *
     * @param  array<int, string>  $activeValidators
     */
    private function getValidatorStats(string $rpcUrl, array $activeValidators): array
    {
        return Cache::remember('admin:validators:stats', 30, function () use ($rpcUrl, $activeValidators) {
            $blockHeight = $this->getBlockHeight($rpcUrl);
            $pendingCount = ValidatorApplication::where('status', 'pending')->count();
            $approvedCount = ValidatorApplication::where('status', 'approved')->count();
            $rejectedCount = ValidatorApplication::where('status', 'rejected')->count();

            return [
                'active_validators' => count($activeValidators),
                'validator_addresses' => $activeValidators,
                'pending_applications' => $pendingCount,
                'approved_applications' => $approvedCount,
                'rejected_applications' => $rejectedCount,
                'block_height' => $blockHeight,
                'block_time' => 2, // TPIX Chain 2-second blocks
                'consensus' => 'IBFT2',
                'chain_id' => config('blockchain.tpix_chain_id', 4289),
                'rpc_connected' => $blockHeight > 0,
            ];
        });
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
        } catch (\Throwable) {}

        return 0;
    }
}
