<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chain;
use App\Models\SwapConfig;
use App\Models\Token;
use App\Models\TradingPair;
use App\Models\Transaction;
use App\Services\ChainResolver;
use App\Services\DexSwapIndexer;
use App\Services\FeeCalculationService;
use App\Services\Trading\BscSwapRecorder;
use App\Services\Trading\BscSwapVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * SwapApiController.
 *
 * Handles swap-related API endpoints including quotes, execution,
 * and route discovery for the TPIX TRADE DEX platform.
 */
class SwapApiController extends Controller
{
    public function __construct(
        private FeeCalculationService $feeCalculationService,
        private ChainResolver $chains,
        private DexSwapIndexer $swapIndexer,
        private BscSwapRecorder $bscRecorder,
    ) {}

    // =========================================================================
    // Endpoints
    // =========================================================================

    /**
     * Get a swap quote with fee breakdown and estimated output.
     *
     * GET /api/v1/swap/quote
     */
    public function quote(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_token' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'to_token' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'amount' => 'required|numeric|gt:0',
            // chain_id = blockchain chainId (56, 4289) — แปลงเป็น DB PK ภายใน
            'chain_id' => 'required|integer|min:1',
            'slippage' => 'nullable|numeric|min:0.01|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                ],
            ], 422);
        }

        $validated = $validator->validated();

        try {
            // แปลง blockchain chainId → DB chain record
            $chain = $this->resolveChain((int) $validated['chain_id']);
            if (! $chain) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_CHAIN',
                        'message' => 'The specified chain is not active or does not exist.',
                    ],
                ], 404);
            }

            // ใช้ DB chain PK สำหรับ query downstream
            $validated['chain_id'] = $chain->id;

            // Verify tokens exist on this chain
            // (native coin: frontend ใช้ sentinel 0xEeee... แต่ DB เก็บ 0x0000...)
            $fromToken = Token::active()
                ->where('chain_id', $validated['chain_id'])
                ->where('contract_address', $this->normalizeNativeAddress($validated['from_token']))
                ->first();

            $toToken = Token::active()
                ->where('chain_id', $validated['chain_id'])
                ->where('contract_address', $this->normalizeNativeAddress($validated['to_token']))
                ->first();

            if (! $fromToken || ! $toToken) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_TOKEN',
                        'message' => 'One or both tokens are not supported on this chain.',
                    ],
                ], 404);
            }

            /*
             * แอดมินปิดคู่นี้ (ด่านฉุกเฉิน) = ไม่ให้ราคา
             *
             * หน้าเว็บถือว่าไม่มีราคาจาก endpoint นี้ = ห้ามส่งธุรกรรม (fail-closed)
             * เดิมเช็กแค่เชน/เหรียญ ปิดคู่ BTC-USDT แล้วสวอปยังลงเชนได้ตามปกติ
             * คู่ที่ไม่มีในทะเบียนเลย (หน้า Swap สวอปเหรียญอะไรก็ได้) ไม่ถือว่าถูกปิด
             */
            if ($this->pairDisabled($validated['from_token'], $validated['to_token'], (int) $validated['chain_id'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'PAIR_DISABLED',
                        'message' => 'This trading pair is currently disabled.',
                    ],
                ], 404);
            }

            // Get swap quote from the fee calculation service
            $quote = $this->feeCalculationService->getSwapQuote(
                (float) $validated['amount'],
                $validated['from_token'],
                $validated['to_token'],
                (int) $validated['chain_id'],
            );

            // Override slippage if provided by user
            if (isset($validated['slippage'])) {
                $slippage = (float) $validated['slippage'];
                $quote['slippage'] = $slippage;
                $quote['minimum_received'] = round(
                    $quote['to_amount_estimate'] * (1 - ($slippage / 100)),
                    8,
                );
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'quote' => $quote,
                    'from_token' => [
                        'symbol' => $fromToken->symbol,
                        'name' => $fromToken->name,
                        'address' => $fromToken->contract_address,
                        'decimals' => $fromToken->decimals,
                    ],
                    'to_token' => [
                        'symbol' => $toToken->symbol,
                        'name' => $toToken->name,
                        'address' => $toToken->contract_address,
                        'decimals' => $toToken->decimals,
                    ],
                    'chain' => [
                        'id' => $chain->id,
                        'name' => $chain->name,
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Swap quote error', [
                'error' => 'Operation failed. Please try again.',
                'params' => $validated,
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'QUOTE_ERROR',
                    'message' => 'Unable to generate swap quote. Please try again.',
                ],
            ], 500);
        }
    }

    /**
     * Record a completed swap transaction.
     *
     * POST /api/v1/swap/execute
     */
    public function execute(Request $request): JsonResponse
    {
        $onTpixChain = (int) $request->input('chain_id') === (int) config('blockchain.tpix_chain_id', 4289);

        $validator = Validator::make($request->all(), [
            'from_token' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'to_token' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            // ยอดของหน้าเว็บใช้แสดงระหว่างรอลงบล็อกเท่านั้น — ยอดที่บันทึกจริงมาจากใบเสร็จบนเชน
            'from_amount' => 'required|numeric|gt:0',
            'to_amount' => 'required|numeric|gte:0',
            'fee_amount' => 'nullable|numeric|gte:0',
            /*
             * ไม่มี unique:transactions,tx_hash แล้ว — แจ้งซ้ำได้ (คิวในเครื่อง / ลองใหม่) ได้แถวเดิม
             * เดิมใครแจ้ง hash ของคนอื่นก่อน แถวของเจ้าของจริงชน unique แล้วหายจากประวัติ
             * ตอนนี้ตัวบันทึกตรวจผู้ส่งบนเชน และตอบ 409 เมื่อ hash เป็นของกระเป๋าอื่น
             */
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
            // chain_id = blockchain chainId (56, 4289) — แปลงเป็น DB PK ภายใน
            'chain_id' => 'required|integer|min:1',
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            /*
             * ไม้ที่วางจากกระดานเทรด — บอกว่าเป็นคู่ไหน ฝั่งไหน ราคาเท่าไร
             *
             * เดิมไม่มีสามช่องนี้ ประวัติจึงโชว์คู่เป็นที่อยู่สัญญายาวๆ ฝั่งเป็น "SWAP"
             * ราคา $0 และกราฟไม่เคยมีป้ายไม้ BSC เลย (markersFor จับคู่ชื่อคู่ไม่ได้)
             * ไม่บังคับ — หน้า Swap ไม่มีแนวคิด "คู่/ฝั่ง" ฝั่งเซิร์ฟเวอร์อนุมานให้เองตอนอ่าน
             */
            'pair' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{1,15}[\/-][A-Za-z0-9]{1,15}$/'],
            'side' => 'nullable|in:buy,sell',
            'price' => 'nullable|numeric|gt:0',
            // ผู้ใช้ปฏิเสธ/โอนค่าธรรมเนียมไม่ผ่าน — สวอปสำเร็จไปแล้ว ต้องบันทึกไม้ไว้ ไม่ใช่ปัดตก
            'fee_collected' => 'nullable|boolean',
            // ธุรกรรมโอนค่าธรรมเนียม (BSC) — เซิร์ฟเวอร์อ่านบนเชนก่อนนับเป็นรายได้
            'fee_tx_hash' => ['nullable', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
            // ใบอนุญาตวางไม้ที่จ่ายค่าบริการด้วยเครดิต TPIX แล้ว — ไม้นี้ไม่ถูกหัก % จากเหรียญ (fee 0)
            'fee_ticket' => ['nullable', 'string', 'uuid'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                ],
            ], 422);
        }

        $validated = $validator->validated();

        /*
         * ⚠️ เชน TPIX: ห้ามเชื่อจำนวน/ราคาที่หน้าเว็บส่งมา
         *
         * เดิมบันทึก status=confirmed ตามตัวเลขในคำขอเลย — ใครที่เซ็นยืนยันกระเป๋าแล้ว
         * ยิงไม้ปลอมเข้ารายการเทรดสาธารณะของคู่ DEX ได้ (จำนวน/ราคาอะไรก็ได้ + tx hash มั่ว)
         * ตอนนี้ใช้แค่ tx hash ไปอ่านใบเสร็จ + event Swap ของพูลเราบนเชนเอง (DexSwapIndexer)
         * BSC: ใบเสร็จ + ธุรกรรมค่าธรรมเนียมตรวจบนเชนเช่นกัน (BscSwapRecorder)
         */
        if ($onTpixChain) {
            return $this->recordTpixDexSwap($validated['tx_hash'], $validated['wallet_address']);
        }

        // เชนอื่นยังไม่มีตัวตรวจใบเสร็จ — ห้ามกลับไปบันทึกตามคำบอกของหน้าเว็บ
        if ((int) $validated['chain_id'] !== BscSwapVerifier::CHAIN_ID) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'UNSUPPORTED_CHAIN',
                    'message' => 'Swaps on this chain cannot be recorded yet.',
                ],
            ], 422);
        }

        try {
            $chain = $this->resolveChain((int) $validated['chain_id']);
            if (! $chain) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_CHAIN',
                        'message' => 'The specified chain is not active or does not exist.',
                    ],
                ], 404);
            }

            $result = $this->bscRecorder->record($validated, $chain);
        } catch (\Throwable $e) {
            // Throwable ไม่ใช่ Exception — QueryException / ValueError ของ bcmath ต้องตกมาที่นี่
            // ไม่งั้นผู้ใช้ได้หน้า 500 ดิบ (หรือ SQL) แทนข้อความที่อ่านรู้เรื่อง
            Log::error('Swap execution error', [
                'exception' => get_class($e),
                'tx_hash' => $validated['tx_hash'],
                'wallet' => $validated['wallet_address'],
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'EXECUTION_ERROR',
                    'message' => 'Unable to record swap transaction. Please try again.',
                ],
            ], 500);
        }

        return $this->bscRecordResponse($result, $validated['tx_hash']);
    }

    /**
     * Get available swap routes with router addresses.
     *
     * GET /api/v1/swap/routes
     */
    public function routes(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // blockchain chainId (optional) — แปลงเป็น DB PK ก่อนใช้
            'chain_id' => 'nullable|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid request parameters.',
                ],
            ], 422);
        }

        try {
            $query = SwapConfig::active()->with('chain');

            if ($request->filled('chain_id')) {
                $chain = $this->resolveChain((int) $request->input('chain_id'));
                if ($chain) {
                    $query->where('chain_id', $chain->id);
                }
                // ถ้า resolve ไม่ได้ → return empty list แทน error (routes เป็น optional filter)
            }

            $configs = $query->get();

            $routes = $configs->map(function (SwapConfig $config) {
                $feeRate = $this->feeCalculationService->getEffectiveFeeRate(
                    'swap',
                    $config->chain_id,
                );

                return [
                    'id' => $config->id,
                    'name' => $config->name,
                    'protocol' => $config->protocol,
                    'router_address' => $config->router_address,
                    'factory_address' => $config->factory_address,
                    'slippage_tolerance' => (float) $config->slippage_tolerance,
                    'fee_rate' => $feeRate,
                    'chain' => [
                        'id' => $config->chain->id,
                        'name' => $config->chain->name,
                        'symbol' => $config->chain->symbol,
                    ],
                    'metadata' => $config->metadata,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $routes,
            ]);
        } catch (\Exception $e) {
            Log::error('Swap routes error', [
                'error' => 'Operation failed. Please try again.',
                'chain_id' => $request->input('chain_id'),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'ROUTES_ERROR',
                    'message' => 'Unable to retrieve swap routes. Please try again.',
                ],
            ], 500);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * ไม้บนเชน TPIX — บันทึกจากใบเสร็จบนเชน (ดู DexSwapIndexer) ตอบหน้าเว็บตามผลจริง
     * ยังไม่ลงบล็อก/เชนตอบไม่ได้ = 202 ไม่ใช่ error: ตัวอ่าน event ใน dex:sync จะบันทึกให้เองภายในนาที
     */
    private function recordTpixDexSwap(string $txHash, string $wallet): JsonResponse
    {
        try {
            $result = $this->swapIndexer->recordTransaction($txHash, $wallet);
        } catch (\Throwable $e) {
            Log::error('TPIX DEX swap record error', ['exception' => get_class($e), 'tx_hash' => $txHash]);
            $result = ['status' => 'pending', 'transactions' => []];
        }

        $errors = [
            'wallet_mismatch' => ['WALLET_MISMATCH', 'This transaction was not sent by the connected wallet.'],
            'not_swap' => ['NOT_A_DEX_SWAP', 'This transaction is not a swap on TPIX DEX.'],
            'failed' => ['TX_FAILED', 'This transaction failed on-chain.'],
            'unavailable' => ['INVALID_CHAIN', 'The specified chain is not active or does not exist.'],
        ];
        if (isset($errors[$result['status']])) {
            [$code, $message] = $errors[$result['status']];

            return response()->json([
                'success' => false,
                'error' => ['code' => $code, 'message' => $message],
            ], $result['status'] === 'unavailable' ? 404 : 422);
        }

        if ($result['status'] === 'pending') {
            return response()->json([
                'success' => true,
                'data' => ['tx_hash' => strtolower($txHash), 'status' => 'pending'],
            ], 202);
        }

        /** @var Transaction $first */
        $first = $result['transactions'][0];

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $first->uuid,
                'tx_hash' => $first->tx_hash,
                'status' => $first->status,
                'from_token' => $first->from_token,
                'to_token' => $first->to_token,
                'from_amount' => $first->from_amount,
                'to_amount' => $first->to_amount,
                'fee_amount' => $first->fee_amount,
                'created_at' => $first->created_at?->toIso8601String(),
                'source' => 'chain',
            ],
        ], $result['status'] === 'recorded' ? 201 : 200);
    }

    /**
     * ที่อยู่ที่ใช้ค้นเหรียญนี้ในตาราง — พิมพ์เล็ก + รูป native ทั้งสองแบบ
     * (BscMajorPairsSeeder เก็บ BNB เป็น 0xEeee… ส่วน BaseTokensSeeder เก็บเป็น 0x000…)
     *
     * @return list<string>
     */
    private function lookupAddresses(string $address): array
    {
        $lower = strtolower($address);
        $native = ['0x0000000000000000000000000000000000000000', '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee'];

        return in_array($lower, $native, true) ? $native : [$lower];
    }

    /**
     * แอดมินปิดคู่นี้บนเชนนี้ไหม — มีคู่ในทะเบียน (ทิศไหนก็ได้) และไม่มีตัวไหนเปิดอยู่เลย
     * ไม่มีคู่ในทะเบียน = ไม่ถือว่าปิด (หน้า Swap สวอปเหรียญคู่ใดก็ได้ที่เหรียญทั้งสองเปิดอยู่)
     */
    private function pairDisabled(string $fromToken, string $toToken, int $chainPk): bool
    {
        $from = $this->lookupAddresses($fromToken);
        $to = $this->lookupAddresses($toToken);
        $column = DB::raw('LOWER(contract_address)');

        $pairs = TradingPair::query()
            ->where('chain_id', $chainPk)
            ->where(function ($q) use ($from, $to, $column) {
                $q->where(function ($w) use ($from, $to, $column) {
                    $w->whereHas('baseToken', fn ($t) => $t->whereIn($column, $from))
                        ->whereHas('quoteToken', fn ($t) => $t->whereIn($column, $to));
                })->orWhere(function ($w) use ($from, $to, $column) {
                    $w->whereHas('baseToken', fn ($t) => $t->whereIn($column, $to))
                        ->whereHas('quoteToken', fn ($t) => $t->whereIn($column, $from));
                });
            })
            ->get(['id', 'is_active']);

        return $pairs->isNotEmpty() && $pairs->every(fn (TradingPair $p) => ! $p->is_active);
    }

    /**
     * ผลของ BscSwapRecorder → คำตอบ — หน้าเว็บตัดสินจากสถานะ HTTP ว่าเก็บในคิวต่อหรือทิ้ง
     *
     * 201/200 = บันทึกแล้ว · 202 = ลองใหม่ได้ (recorded บอกว่ามีแถวรอแล้วหรือยัง)
     * 409/422 = ปฏิเสธถาวร ส่งซ้ำกี่ครั้งก็ไม่ผ่าน
     */
    private function bscRecordResponse(array $result, string $txHash): JsonResponse
    {
        /** @var Transaction|null $row */
        $row = $result['transaction'] ?? null;
        $status = $result['status'];

        // แถวเดิมของกระเป๋านี้ที่ตัดสินแล้วว่าไม่ลง — ตอบเหมือนบันทึกครั้งแรก
        if ($row !== null && $row->status === 'failed') {
            $status = match ($row->error_message) {
                BscSwapVerifier::WALLET_MISMATCH => BscSwapRecorder::WALLET_MISMATCH,
                BscSwapVerifier::WRONG_TARGET, BscSwapVerifier::NO_TRANSFER => BscSwapRecorder::NOT_SWAP,
                default => BscSwapRecorder::FAILED,
            };
        }

        $errors = [
            BscSwapRecorder::TAKEN => [409, 'TX_ALREADY_RECORDED', 'This transaction has already been recorded.'],
            BscSwapRecorder::FAILED => [422, 'TX_FAILED', 'This transaction failed on-chain.'],
            BscSwapRecorder::WALLET_MISMATCH => [422, 'WALLET_MISMATCH', 'This transaction was not sent by the connected wallet.'],
            BscSwapRecorder::NOT_SWAP => [422, 'NOT_A_SWAP', 'This transaction is not a PancakeSwap swap of these tokens.'],
        ];
        if (isset($errors[$status])) {
            [$http, $code, $message] = $errors[$status];

            return response()->json(['success' => false, 'error' => ['code' => $code, 'message' => $message]], $http);
        }

        if ($row === null || $row->status === 'pending') {
            return response()->json([
                'success' => true,
                'data' => [
                    'tx_hash' => strtolower($txHash),
                    'status' => 'pending',
                    // false = ยังไม่มีแถว (เชนยังไม่เห็นธุรกรรม / มีคำขออื่นกำลังบันทึก) — หน้าเว็บต้องส่งใหม่
                    'recorded' => $row !== null,
                ],
            ], 202);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $row->uuid,
                'tx_hash' => $row->tx_hash,
                'status' => $row->status,
                'recorded' => true,
                'from_token' => $row->from_token,
                'to_token' => $row->to_token,
                'from_amount' => $row->from_amount,
                'to_amount' => $row->to_amount,
                'fee_amount' => $row->fee_amount,
                'fee_status' => $row->metadata['fee_status'] ?? null,
                'created_at' => $row->created_at?->toIso8601String(),
                'source' => 'chain',
            ],
        ], $status === BscSwapRecorder::RECORDED ? 201 : 200);
    }

    /**
     * แปลง sentinel address ของ native coin (0xEeee...EEeE — convention ฝั่ง
     * frontend/ethers) ให้เป็น 0x0000...0000 ที่ BaseTokensSeeder ใช้เก็บ
     * native coin ใน DB — ไม่งั้น quote ของคู่ที่มี BNB จะตอบ INVALID_TOKEN.
     */
    private function normalizeNativeAddress(string $address): string
    {
        if (strtolower($address) === '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee') {
            return '0x0000000000000000000000000000000000000000';
        }

        return $address;
    }

    /**
     * แปลง blockchain chainId (เช่น 56, 4289) เป็น Chain record (DB PK)
     * Frontend ส่ง blockchain chainId มา แต่ DB ใช้ auto-increment ID
     * ลุคอัพผ่าน chain_id_hex เช่น '0x38' (BSC), '0x10c1' (TPIX).
     */
    private function resolveChain(int $blockchainChainId): ?Chain
    {
        /*
         * ตรรกะย้ายไปอยู่ที่ App\Services\ChainResolver แล้ว
         *
         * โค้ดชุดนี้เคยถูกคัดลอกไว้ 4 ที่ (ที่นี่, TradingController สองจุด,
         * และ TokenFactoryApiController ที่คัดลอกผิดจนอ้างคอลัมน์ที่ไม่มีจริง)
         * แต่ละที่เขียนไม่เหมือนกัน — บางที่เช็ค is_active บางที่ไม่เช็ค
         * ปุ่มปิดเชนในหลังบ้านจึงปิดได้แค่บางส่วนของระบบ
         *
         * คงเมธอดนี้ไว้เป็นทางผ่าน เพื่อไม่ต้องแก้จุดเรียกทั้งไฟล์
         */
        return $this->chains->resolveActive($blockchainChainId);
    }
}
