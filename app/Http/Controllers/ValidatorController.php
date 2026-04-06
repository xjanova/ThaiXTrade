<?php

namespace App\Http\Controllers;

use App\Models\ValidatorApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * ValidatorController — จัดการ Validator Management System.
 *
 * อ่านข้อมูล validators จาก IBFT2 extraData บน TPIX Chain.
 * Developed by Xman Studio.
 */
class ValidatorController extends Controller
{
    /**
     * Validator dashboard page.
     */
    public function index()
    {
        return Inertia::render('Validators/Index', [
            'stats' => $this->getValidatorStats(),
            'validators' => $this->getValidatorList(),
            'rpcUrl' => config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online'),
            'chainId' => config('blockchain.tpix_chain_id', 4289),
        ]);
    }

    /**
     * Validator application form page.
     */
    public function apply()
    {
        return Inertia::render('Validators/Apply', [
            'rpcUrl' => config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online'),
            'chainId' => config('blockchain.tpix_chain_id', 4289),
        ]);
    }

    /**
     * API: Get validator stats (public).
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getValidatorStats(),
        ]);
    }

    /**
     * API: Get all validators with their info.
     */
    public function list(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->getValidatorList(),
        ]);
    }

    /**
     * Store a validator application.
     */
    public function submitApplication(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'tier' => ['required', 'string', 'in:validator,guardian,sentinel,light'],
            'endpoint' => ['nullable', 'url', 'max:255'],
            'country_code' => ['required', 'string', 'size:2'],
            'country_name' => ['required', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_telegram' => ['nullable', 'string', 'max:100'],
            'hardware_specs' => ['nullable', 'string', 'max:1000'],
            'motivation' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['wallet_address'] = strtolower($validated['wallet_address']);

        // Check for existing pending application from same wallet
        $existing = ValidatorApplication::where('wallet_address', $validated['wallet_address'])
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'DUPLICATE_APPLICATION',
                    'message' => 'An application already exists for this wallet address.',
                ],
            ], 409);
        }

        try {
            $application = ValidatorApplication::create([
                'wallet_address' => $validated['wallet_address'],
                'tier' => $validated['tier'],
                'endpoint' => $validated['endpoint'] ?? null,
                'country_code' => $validated['country_code'],
                'country_name' => $validated['country_name'],
                'latitude' => $validated['latitude'] ?? 0,
                'longitude' => $validated['longitude'] ?? 0,
                'contact_email' => $validated['contact_email'] ?? null,
                'contact_telegram' => $validated['contact_telegram'] ?? null,
                'hardware_specs' => $validated['hardware_specs'] ?? null,
                'motivation' => $validated['motivation'] ?? null,
                'status' => 'pending',
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $application->id,
                    'status' => 'pending',
                    'message' => 'Validator application submitted successfully.',
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Validator application submission failed', [
                'wallet' => $validated['wallet_address'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SUBMISSION_FAILED',
                    'message' => 'Failed to submit application. Please try again.',
                ],
            ], 500);
        }
    }

    /**
     * Check rewards for a specific validator address.
     */
    public function checkRewards(Request $request): JsonResponse
    {
        $wallet = strtolower($request->input('address', $request->input('wallet_address', '')));

        // Validate wallet address format
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'INVALID_ADDRESS', 'message' => 'Invalid wallet address format.'],
            ], 422);
        }

        try {
            $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');

            // Get current balance as proxy for accumulated rewards
            $balanceResponse = Http::timeout(5)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_getBalance',
                'params' => [$wallet, 'latest'],
                'id' => 1,
            ]);

            $balance = '0';
            if ($balanceResponse->successful() && ! $balanceResponse->json('error')) {
                $balance = $this->weiToEther($balanceResponse->json('result', '0x0'));
            }

            // Check if this address is an active validator and get tier info
            $validators = $this->getValidatorList();
            $matchedValidator = null;
            foreach ($validators as $validator) {
                if (strtolower($validator['address']) === $wallet) {
                    $matchedValidator = $validator;
                    break;
                }
            }

            // Check application data for tier/stake info
            $application = ValidatorApplication::where('wallet_address', $wallet)
                ->whereIn('status', ['approved', 'active'])
                ->first();

            $tier = $application->tier ?? ($matchedValidator ? 'validator' : null);
            $stakeAmounts = ['validator' => '10000000', 'guardian' => '1000000', 'sentinel' => '100000', 'light' => '10000'];

            return response()->json([
                'success' => true,
                'data' => [
                    'wallet_address' => $wallet,
                    'active' => $matchedValidator !== null,
                    'tier' => $tier,
                    'pending_rewards' => $balance,
                    'total_earned' => $balance,
                    'stake_amount' => $stakeAmounts[$tier] ?? '0',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Validator rewards check failed', [
                'wallet' => $wallet,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'QUERY_FAILED',
                    'message' => 'Failed to check rewards. Please try again.',
                ],
            ], 500);
        }
    }

    /**
     * Get validator statistics.
     * Reads from IBFT2 block data or cache.
     */
    private function getValidatorStats(): array
    {
        return cache()->remember('validator:stats', 60, function () {
            try {
                $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');

                // Get latest block
                $blockResponse = Http::timeout(5)->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getBlockByNumber',
                    'params' => ['latest', false],
                    'id' => 1,
                ]);

                if (! $blockResponse->successful() || $blockResponse->json('error')) {
                    return $this->getDefaultStats();
                }

                $block = $blockResponse->json('result');
                if (! $block) {
                    return $this->getDefaultStats();
                }

                $blockHeight = hexdec($block['number'] ?? '0x0');
                $validators = $this->extractValidatorsFromExtraData($block['extraData'] ?? '');

                $stakePerValidator = 10000000;

                return [
                    'total_validators' => count($validators),
                    'active_validators' => count($validators),
                    'block_height' => $blockHeight,
                    'consensus' => 'IBFT2',
                    'chain_id' => 4289,
                    'block_time' => 2,
                    'gas_price' => '0',
                    'last_block_timestamp' => hexdec($block['timestamp'] ?? '0x0'),
                    'total_staked' => count($validators) * $stakePerValidator,
                    'current_year' => 1,
                ];
            } catch (\Throwable $e) {
                Log::error('Validator stats query failed', ['error' => $e->getMessage()]);

                return $this->getDefaultStats();
            }
        });
    }

    /**
     * Default stats when chain is unreachable.
     */
    private function getDefaultStats(): array
    {
        return [
            'total_validators' => 0,
            'active_validators' => 0,
            'block_height' => 0,
            'consensus' => 'IBFT2',
            'chain_id' => 4289,
            'block_time' => 2,
            'gas_price' => '0',
            'last_block_timestamp' => 0,
        ];
    }

    /**
     * Get list of all validators from chain.
     */
    private function getValidatorList(): array
    {
        return cache()->remember('validator:list', 30, function () {
            try {
                $rpcUrl = config('blockchain.tpix_rpc_url', 'https://rpc.tpix.online');

                // Get latest block to extract validator set from IBFT2 extraData
                $blockResponse = Http::timeout(5)->post($rpcUrl, [
                    'jsonrpc' => '2.0',
                    'method' => 'eth_getBlockByNumber',
                    'params' => ['latest', false],
                    'id' => 1,
                ]);

                if (! $blockResponse->successful() || $blockResponse->json('error')) {
                    return [];
                }

                $block = $blockResponse->json('result');
                if (! $block) {
                    return [];
                }

                $validatorAddresses = $this->extractValidatorsFromExtraData($block['extraData'] ?? '');

                // Default geo spread for chain validators without application data
                // Placed across Thailand (TPIX HQ region)
                $defaultGeo = [
                    ['lat' => 13.7563, 'lng' => 100.5018, 'cc' => 'TH', 'cn' => 'Thailand'],  // Bangkok
                    ['lat' => 18.7883, 'lng' => 98.9853,  'cc' => 'TH', 'cn' => 'Thailand'],  // Chiang Mai
                    ['lat' => 7.8804,  'lng' => 98.3923,  'cc' => 'TH', 'cn' => 'Thailand'],  // Phuket
                    ['lat' => 14.8830, 'lng' => 100.5876, 'cc' => 'TH', 'cn' => 'Thailand'],  // Nakhon Sawan
                    ['lat' => 16.4419, 'lng' => 102.8360, 'cc' => 'TH', 'cn' => 'Thailand'],  // Khon Kaen
                ];

                // Build validator info list
                $validators = [];
                foreach ($validatorAddresses as $index => $address) {
                    $geo = $defaultGeo[$index % count($defaultGeo)];
                    $validators[] = [
                        'address' => $address,
                        'tier' => 'validator',
                        'status' => 'active',
                        'online' => true,
                        'uptime' => 99.5,
                        'country_code' => $geo['cc'],
                        'country_name' => $geo['cn'],
                        'latitude' => $geo['lat'],
                        'longitude' => $geo['lng'],
                        'endpoint' => null,
                        'stake_amount' => 10000000,
                        'rewards' => '0',
                        'index' => $index,
                    ];
                }

                // Enrich with balances (batch-friendly, but sequential for safety)
                foreach ($validators as &$validator) {
                    try {
                        $balanceResponse = Http::timeout(3)->post($rpcUrl, [
                            'jsonrpc' => '2.0',
                            'method' => 'eth_getBalance',
                            'params' => [$validator['address'], 'latest'],
                            'id' => 1,
                        ]);

                        if ($balanceResponse->successful() && ! $balanceResponse->json('error')) {
                            $validator['rewards'] = $this->weiToEther(
                                $balanceResponse->json('result', '0x0')
                            );
                        }
                    } catch (\Throwable $e) {
                        // Balance query failed for this validator, continue with default
                    }
                }
                unset($validator);

                // Enrich with application data if available
                try {
                    $applications = ValidatorApplication::where('status', 'approved')
                        ->whereIn('wallet_address', array_map('strtolower', $validatorAddresses))
                        ->get()
                        ->keyBy(fn ($app) => strtolower($app->wallet_address));

                    foreach ($validators as &$validator) {
                        $app = $applications->get(strtolower($validator['address']));
                        if ($app) {
                            $validator['tier'] = $app->tier;
                            $validator['country_code'] = $app->country_code;
                            $validator['country_name'] = $app->country_name;
                            $validator['latitude'] = $app->latitude;
                            $validator['longitude'] = $app->longitude;
                            $validator['endpoint'] = $app->endpoint;
                            $validator['online'] = true;
                            $validator['uptime'] = 99.5;
                        }
                    }
                    unset($validator);
                } catch (\Throwable $e) {
                    // ValidatorApplication model may not exist yet, continue without enrichment
                }

                return $validators;
            } catch (\Throwable $e) {
                Log::error('Validator list query failed', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Extract validator addresses from IBFT2 extraData.
     *
     * IBFT2 extraData format:
     *   - 32 bytes vanity (64 hex chars)
     *   - RLP-encoded list: [validators, vote, round, seals]
     *   - validators = list of 20-byte addresses
     */
    private function extractValidatorsFromExtraData(string $extraData): array
    {
        if (empty($extraData) || strlen($extraData) < 66) {
            return [];
        }

        // Remove 0x prefix
        $data = substr($extraData, 2);

        // Skip 32 bytes vanity (64 hex chars)
        $rlpData = substr($data, 64);

        if (empty($rlpData)) {
            return [];
        }

        try {
            // Decode the outer RLP list
            $decoded = $this->rlpDecodeList($rlpData);

            if (empty($decoded) || ! is_array($decoded[0])) {
                return [];
            }

            // First element is the validators list
            // Supports both IBFT2 (flat: [addr, addr, ...]) and
            // QBFT (nested: [[addr, blsPubKey], [addr, blsPubKey], ...])
            $validators = [];
            foreach ($decoded[0] as $validatorEntry) {
                if (is_array($validatorEntry)) {
                    // QBFT format: [address, blsPublicKey]
                    $addr = $validatorEntry[0] ?? '';
                    if (is_string($addr) && strlen($addr) === 40) {
                        $validators[] = '0x'.strtolower($addr);
                    }
                } elseif (is_string($validatorEntry) && strlen($validatorEntry) === 40) {
                    // IBFT2 format: plain address
                    $validators[] = '0x'.strtolower($validatorEntry);
                }
            }

            return $validators;
        } catch (\Throwable $e) {
            Log::error('Failed to extract validators from extraData', [
                'error' => $e->getMessage(),
                'extraData_length' => strlen($extraData),
            ]);

            return [];
        }
    }

    /**
     * Minimal RLP decoder for IBFT2 extraData.
     * Decodes the top-level list and first nested list (validators).
     *
     * @return array Decoded elements
     */
    private function rlpDecodeList(string $hex): array
    {
        $bytes = hex2bin($hex);
        if ($bytes === false || strlen($bytes) === 0) {
            return [];
        }

        $offset = 0;
        $result = $this->rlpDecodeItem($bytes, $offset);

        return is_array($result) ? $result : [];
    }

    /**
     * Decode a single RLP item from bytes at the given offset.
     *
     * @return mixed Decoded item (string for single values, array for lists)
     */
    private function rlpDecodeItem(string $bytes, int &$offset): mixed
    {
        if ($offset >= strlen($bytes)) {
            return '';
        }

        $prefix = ord($bytes[$offset]);

        // Single byte [0x00, 0x7f]
        if ($prefix < 0x80) {
            $offset++;

            return bin2hex(chr($prefix));
        }

        // Short string [0x80, 0xb7] — length = prefix - 0x80
        if ($prefix <= 0xb7) {
            $length = $prefix - 0x80;
            $offset++;
            $data = substr($bytes, $offset, $length);
            $offset += $length;

            return bin2hex($data);
        }

        // Long string [0xb8, 0xbf] — length of length = prefix - 0xb7
        if ($prefix <= 0xbf) {
            $lengthOfLength = $prefix - 0xb7;
            $offset++;
            $length = 0;
            for ($i = 0; $i < $lengthOfLength; $i++) {
                $length = ($length << 8) | ord($bytes[$offset + $i]);
            }
            $offset += $lengthOfLength;
            $data = substr($bytes, $offset, $length);
            $offset += $length;

            return bin2hex($data);
        }

        // Short list [0xc0, 0xf7] — length = prefix - 0xc0
        if ($prefix <= 0xf7) {
            $length = $prefix - 0xc0;
            $offset++;
            $end = $offset + $length;
            $items = [];
            while ($offset < $end) {
                $items[] = $this->rlpDecodeItem($bytes, $offset);
            }

            return $items;
        }

        // Long list [0xf8, 0xff] — length of length = prefix - 0xf7
        $lengthOfLength = $prefix - 0xf7;
        $offset++;
        $length = 0;
        for ($i = 0; $i < $lengthOfLength; $i++) {
            $length = ($length << 8) | ord($bytes[$offset + $i]);
        }
        $offset += $lengthOfLength;
        $end = $offset + $length;
        $items = [];
        while ($offset < $end) {
            $items[] = $this->rlpDecodeItem($bytes, $offset);
        }

        return $items;
    }

    /**
     * Execute eth_call via JSON-RPC.
     */
    private function ethCall(string $rpcUrl, string $to, string $data): ?string
    {
        $response = Http::timeout(5)->post($rpcUrl, [
            'jsonrpc' => '2.0',
            'method' => 'eth_call',
            'params' => [
                ['to' => $to, 'data' => $data],
                'latest',
            ],
            'id' => 1,
        ]);

        if ($response->successful() && ! $response->json('error')) {
            return $response->json('result');
        }

        return null;
    }

    /**
     * Convert wei hex string to ether string.
     */
    private function weiToEther(string $hexWei): string
    {
        $wei = gmp_init($hexWei, 16);
        $ether = gmp_div_q($wei, gmp_init('1000000000000000000'));

        return gmp_strval($ether);
    }
}
