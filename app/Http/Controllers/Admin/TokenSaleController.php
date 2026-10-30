<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\SalePhase;
use App\Models\SaleTransaction;
use App\Models\TokenSale;
use App\Models\WhitelistEntry;
use App\Rules\SalePaymentChannels;
use App\Services\BankTransferSaleService;
use App\Services\SaleClaimPayoutService;
use App\Services\SaleLaunchService;
use App\Services\TokenSaleService;
use App\Support\MoneySettings;
use App\Support\Wei;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin TokenSaleController — จัดการระบบขายเหรียญ TPIX และ Token Control.
 *
 * ฟีเจอร์:
 * - ดู overview: total supply, sold, raised, master wallet balance
 * - CRUD sale phases (price, allocation, vesting)
 * - ดู transactions ทั้งหมด (search by wallet)
 * - ดู master wallet info (balance, address)
 */
class TokenSaleController extends Controller
{
    /**
     * หน้าหลัก Token Sales + Control — ส่งข้อมูลทั้งหมดไป Vue.
     */
    public function index(BankTransferSaleService $bank, SaleClaimPayoutService $claims): Response
    {
        $sales = TokenSale::with(['phases' => function ($q) {
            $q->orderBy('phase_order');
        }])->latest()->get();

        $transactions = SaleTransaction::with(['tokenSale', 'phase'])
            ->latest()
            ->paginate(20);

        // ยอดของปุ่มเข้าคิวจ่ายใหม่ — ส่งเฉพาะคนที่กดได้จริง (route จำกัด super_admin)
        $canRequeue = auth('admin')->user()?->isSuperAdmin() ?? false;
        $transactions->getCollection()->each(function (SaleTransaction $tx) use ($bank, $claims, $canRequeue) {
            $amountWei = $canRequeue ? $bank->requeueableAmountWei($tx) : null;
            $tx->setAttribute('requeue_payout_tpix', $amountWei === null ? null : Wei::format($amountWei));

            $claimOffer = $canRequeue ? $claims->requeueOffer($tx) : null;
            $tx->setAttribute('requeue_claim_tpix', $claimOffer['amount_tpix'] ?? null);
            $tx->setAttribute('requeue_claim_returned_tpix', $claimOffer['returned_tpix'] ?? null);
        });

        // ดึง master wallet balance จาก TPIX Chain RPC
        $walletInfo = $this->getMasterWalletInfo();

        // สถิติรวม
        $stats = [
            'total_supply' => 7_000_000_000,
            'total_sold' => TokenSale::sum('total_sold'),
            'total_raised_usd' => TokenSale::sum('total_raised_usd'),
            'total_buyers' => SaleTransaction::where('status', 'confirmed')
                ->distinct('wallet_address')->count('wallet_address'),
            'total_transactions' => SaleTransaction::count(),
            'pending_transactions' => SaleTransaction::where('status', 'pending')->count(),
        ];

        return Inertia::render('Admin/TokenSales/Index', [
            'sales' => $sales,
            'transactions' => $transactions,
            'walletInfo' => $walletInfo,
            'stats' => $stats,
            'launch' => $this->launchPanel(app(SaleLaunchService::class)),
            'canManageMoney' => MoneySettings::canManage(),
        ]);
    }

    /**
     * ข้อมูลกล่อง "เปิดรอบขาย" — ความพร้อม + ตารางที่จะได้ถ้ากดเปิดวันนี้.
     *
     * ส่งตารางตัวอย่างไปด้วยเสมอ เพราะคนกดต้องเห็นก่อนว่ากดแล้วเฟสไหนเปิดถึงวันไหน
     * ปุ่มที่ไม่บอกผลลัพธ์ล่วงหน้าคือปุ่มที่คนไม่กล้ากด — แล้วรอบขายก็ไม่เคยเปิด
     *
     * @return array<string,mixed>
     */
    private function launchPanel(SaleLaunchService $launcher): array
    {
        $sale = $launcher->targetSale();

        if ($sale === null) {
            return [
                'sale_id' => null,
                'armed' => $launcher->autoLaunchArmed(),
                'round_ends_at' => $launcher->roundEndsAt()?->toIso8601String(),
                'tiered' => $launcher->roundEndsAt() !== null,
                'blocked' => null,
                'launched_at' => null,
                'readiness' => $launcher->readiness(null),
                'preview' => [],
            ];
        }

        $plan = $launcher->plan($sale, now());

        return [
            'sale_id' => $sale->id,
            'sale_name' => $sale->name,
            'armed' => $launcher->autoLaunchArmed(),
            'round_ends_at' => $plan['until']?->toIso8601String(),
            'tiered' => $plan['tiered'],
            'blocked' => $plan['blocked'],
            'launched_at' => $sale->launched_at?->toIso8601String(),
            'readiness' => $launcher->readiness($sale),
            'preview' => collect($plan['rows'])->map(fn ($r) => [
                'order' => $r['phase']->phase_order,
                'name' => $r['phase']->name,
                'price_usd' => (string) $r['phase']->price_usd,
                'days' => $r['days'],
                'status' => $r['status'],
                'starts_at' => $r['starts_at']->toIso8601String(),
                'ends_at' => $r['ends_at']->toIso8601String(),
            ])->all(),
            'skipped' => collect($plan['skipped'])->map(fn ($s) => [
                'name' => $s['phase']->name,
                'reason' => $s['reason'],
            ])->all(),
        ];
    }

    /**
     * เปิดรอบขาย — เฟสแรกเริ่มนับจากวันที่กด (หรือวันที่ระบุ).
     *
     * ⚠️ จุดเดียวบนหน้าเว็บที่ทำให้รอบขายเริ่มเดิน
     *    ตรรกะการตั้งวันอยู่ที่ SaleLaunchService ที่เดียว ห้ามเขียนซ้ำที่นี่
     */
    public function launch(Request $request, SaleLaunchService $launcher)
    {
        $validated = $request->validate([
            'sale_id' => 'nullable|integer|exists:token_sales,id',
            'start_at' => 'nullable|date',
            // ข้ามด่านความพร้อม — ต้องส่งมาอย่างตั้งใจเท่านั้น
            'force' => 'nullable|boolean',
        ]);

        $sale = $launcher->targetSale(isset($validated['sale_id']) ? (int) $validated['sale_id'] : null);

        if ($sale === null) {
            return redirect()->back()->with('error', 'ไม่พบรอบขายที่จะเปิด');
        }

        $result = $launcher->launch(
            $sale,
            isset($validated['start_at']) ? Carbon::parse($validated['start_at']) : null,
            null,
            (bool) ($validated['force'] ?? false)
        );

        $this->forgetSaleCache();

        return $result['ok']
            ? redirect()->back()->with('success', $result['message'])
            : redirect()->back()->with('error', $result['message']);
    }

    /**
     * เปิด/ปิดสวิตช์ "เปิดขายอัตโนมัติเมื่อระบบพร้อม".
     *
     * ปิดไว้เป็นค่าเริ่มต้น — ระบบที่เปิดรับเงินตัวเองโดยไม่มีคนกดเป็นสิ่งที่
     * ต้องเลือกเอง ไม่ใช่สิ่งที่ได้มาฟรีจากการอัปเดตโค้ด
     */
    public function autoLaunch(Request $request, SaleLaunchService $launcher)
    {
        $validated = $request->validate(['armed' => 'required|boolean']);

        $launcher->setAutoLaunch((bool) $validated['armed']);

        return redirect()->back()->with(
            'success',
            $validated['armed']
                ? 'เปิดขายอัตโนมัติแล้ว — ระบบจะเริ่มรอบขายเองภายใน 1 ชั่วโมงหลังพร้อมครบทุกข้อ'
                : 'ปิดการเปิดขายอัตโนมัติแล้ว — ต้องกดเปิดเอง'
        );
    }

    /**
     * ล็อก/ยกเลิกวันจบรอบขาย.
     *
     * ล็อกไว้ = รอบขายแบบขั้นบันได: ทุกเฟสเปิดช่วงเดียวกันจนถึงวันนี้ ราคาขยับ
     * เมื่อขั้นก่อนขายหมด — และตัวเปิดอัตโนมัติจะจบรอบวันนี้เสมอไม่ว่าจะพร้อมช้าแค่ไหน
     * เว้นว่าง = กลับไปแบบต่อกันตามเวลา (เฟสละช่วงตาม duration_days)
     *
     * มีผลกับการ "เปิดครั้งถัดไป" เท่านั้น ไม่แตะตารางของรอบที่เปิดอยู่
     */
    public function roundEnd(Request $request, SaleLaunchService $launcher)
    {
        $validated = $request->validate([
            // วันในอดีต = เปิดไปก็ปิดทันที ไม่มีประโยชน์ให้บันทึก
            'round_ends_at' => 'nullable|date|after:now',
        ]);

        $raw = $validated['round_ends_at'] ?? null;

        if (blank($raw)) {
            $launcher->setRoundEndsAt(null);

            return redirect()->back()->with('success', 'ยกเลิกวันจบรอบแล้ว — กลับไปใช้เฟสต่อกันตามเวลา');
        }

        // เลือกแค่วันที่ (ช่อง date) = จบตอนสิ้นวันนั้น ไม่ใช่เที่ยงคืนต้นวัน
        $at = Carbon::parse($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $raw)) {
            $at = $at->endOfDay();
        }

        $launcher->setRoundEndsAt($at);

        return redirect()->back()->with('success', 'ล็อกวันจบรอบ '.$at->format('Y-m-d H:i').' แล้ว — ราคาขั้นบันได ขยับเมื่อขั้นก่อนขายหมด');
    }

    /**
     * สร้าง/อัปเดต Token Sale.
     */
    public function store(Request $request)
    {
        $status = $request->input('status', 'draft');
        // กระเป๋านี้รับเงินเฉพาะทาง USDT — บัตรกับโอนเงินไม่มีเงินเข้ากระเป๋า รอบที่ไม่รับ USDT จึงเปิดขายได้โดยไม่ต้องมี
        $needsWallet = $status === 'active' && in_array(TokenSale::CRYPTO_CHANNEL, (array) $request->input('accept_currencies'), true);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'total_supply_for_sale' => 'required|numeric|min:0',
            'accept_currencies' => ['required', 'array', new SalePaymentChannels()],
            'sale_wallet_address' => [
                $needsWallet ? 'required' : 'nullable',
                'string',
                'regex:/^0x[a-fA-F0-9]{40}$/',
            ],
            'status' => 'required|string|in:draft,upcoming,active,paused,completed',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ], [
            'sale_wallet_address.required' => 'เปิดรับ USDT แล้วต้องกรอกกระเป๋ารับเงินก่อนตั้งเป็น Active',
        ]);

        // เรียงตามลำดับเดียวกันเสมอ บันทึกประวัติจะได้ไม่ขึ้นว่าเปลี่ยนทั้งที่ค่าเดิม
        $validated['accept_currencies'] = array_values(array_intersect(TokenSale::PAYMENT_CHANNELS, $validated['accept_currencies']));

        // เชนที่ผู้ซื้อต้องต่อกระเป๋าเพื่อรับเหรียญ ไม่รับจากฟอร์ม เพราะ default ของคอลัมน์ยังเป็น 56 (BSC) จากโมเดลเก่า
        $validated['accept_chain_id'] = (int) config('blockchain.tpix_chain_id', 4289);

        // เงินคริปโตของผู้ซื้อวิ่งเข้า sale_wallet_address — ต้องตอบได้ว่าใครเปลี่ยนจากใบไหนเป็นใบไหน
        // จึงเขียนรอบขายกับบันทึกในธุรกรรมเดียวกัน บันทึกไม่สำเร็จเท่ากับกระเป๋าไม่เปลี่ยน
        DB::transaction(function () use ($request, $validated): void {
            $audited = ['sale_wallet_address', 'accept_currencies', 'accept_chain_id', 'status'];
            $old = [];

            $id = $request->input('id');
            if ($id) {
                $sale = TokenSale::lockForUpdate()->findOrFail($id);
                $old = $sale->only($audited);
                $sale->update($validated);
            } else {
                $sale = TokenSale::create($validated);
            }

            MoneySettings::record('token_sale.save', $old, $sale->only($audited));
        });

        $this->forgetSaleCache();

        return redirect()->back()->with('success', 'Token Sale saved!');
    }

    /**
     * สร้าง/อัปเดต Sale Phase.
     */
    public function updatePhase(Request $request)
    {
        $validated = $request->validate([
            'token_sale_id' => 'required|exists:token_sales,id',
            'name' => 'required|string|max:255',
            'phase_order' => 'required|integer|min:1',
            // ความยาวเฟสเป็นวัน — แหล่งความจริงของตาราง เมื่อกด "เปิดรอบขาย"
            // วันที่จริงถูกคำนวณจากค่านี้ ไม่ใช่จาก starts_at/ends_at ที่กรอกไว้
            'duration_days' => 'nullable|integer|min:1|max:3650',
            'price_usd' => 'required|numeric|min:0.001',
            'allocation' => 'required|numeric|min:0',
            'min_purchase' => 'nullable|numeric|min:0',
            'max_purchase' => 'nullable|numeric|min:0',
            'vesting_tge_percent' => 'nullable|numeric|min:0|max:100',
            'vesting_cliff_days' => 'nullable|integer|min:0',
            'vesting_duration_days' => 'nullable|integer|min:0',
            'status' => 'required|string|in:upcoming,active,completed,cancelled',
            'starts_at' => 'nullable|date',
            // ต้องหลัง starts_at เสมอ — ช่วงเวลากลับหัวทำให้ assertPhaseOpen()
            // ปฏิเสธทุกการซื้อโดยที่หน้าแอดมินยังโชว์ว่าเฟสนี้ active
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        $id = $request->input('id');
        if ($id) {
            $phase = SalePhase::findOrFail($id);

            // ล็อคราคาถ้ามี transaction แล้ว (ป้องกันแก้ราคาหลังขาย)
            if ($phase->sold > 0 && isset($validated['price_usd']) && (float) $validated['price_usd'] !== (float) $phase->price_usd) {
                return redirect()->back()->with('error', 'Cannot change price — this phase already has '.$phase->sold.' TPIX sold. Create a new phase instead.');
            }

            $phase->update($validated);
        } else {
            // min_purchase ต้องมากกว่า 0
            if (isset($validated['min_purchase']) && (float) $validated['min_purchase'] <= 0) {
                $validated['min_purchase'] = null;
            }

            /*
             * ★ slug เป็นคอลัมน์ NOT NULL ที่ไม่มีค่าเริ่มต้น และฟอร์มไม่เคยส่งมา
             *
             * เดิมกด "Add Phase" แล้วตายทันทีด้วย
             *   SQLSTATE[HY000] [1364] Field 'slug' doesn't have a default value
             * ซึ่งแปลว่าแอดมินสร้างเฟสใหม่ไม่ได้เลยสักครั้งตั้งแต่ระบบนี้มีมา
             * — ปิดทางแก้ปัญหาเฟสหมดอายุด้วยตัวเอง เหลือแค่ทางบรรทัดคำสั่ง
             *
             * ผูก phase_order เข้าไปด้วยเพื่อกันชนกันเองเมื่อตั้งชื่อซ้ำในรอบเดียวกัน
             */
            $validated['slug'] = Str::slug($validated['name']).'-'.$validated['phase_order'];

            SalePhase::create($validated);
        }

        $this->forgetSaleCache();

        return redirect()->back()->with('success', 'Phase saved!');
    }

    /**
     * ยืนยันว่าเงินโอนเข้าบัญชีจริง → นับเป็นยอดขาย + เข้าคิวจ่ายเหรียญ.
     *
     * ⚠️ จุดเดียวที่ทำให้เหรียญออกจากคำสั่งซื้อทางโอนเงิน
     *    ทีมงานต้องเปิดดูรายการเดินบัญชีจริงก่อนกดเสมอ ระบบตรวจแทนไม่ได้
     */
    public function confirmBankTransfer(Request $request, int $id, BankTransferSaleService $bank)
    {
        $tx = SaleTransaction::findOrFail($id);

        try {
            $confirmed = $bank->confirm($tx, auth('admin')->user()?->email, $request->boolean('honor_expired'));
            $bank->queueInitialPayout($confirmed);
        } catch (PurchaseException $e) {
            return redirect()->back()->withErrors(['bank' => $e->getMessage()]);
        }

        $this->forgetSaleCache();

        // ห้ามครอบ confirm() ด้วยธุรกรรมชั้นนอก — มันตั้งใจ commit เครื่องหมาย needs_refund ก่อนโยน exception
        $this->recordTransactionChange('token_sale.bank_confirm', $confirmed, 'pending');

        $reference = $confirmed->metadata['reference'] ?? $confirmed->uuid;

        return redirect()->back()->with(
            'success',
            "ยืนยันการโอนเงิน {$reference} แล้ว — เข้าคิวจ่าย ".number_format((float) $confirmed->tpix_amount, 2).' TPIX'
        );
    }

    /**
     * ปฏิเสธคำสั่งซื้อที่ไม่มีเงินเข้า.
     */
    public function rejectBankTransfer(Request $request, int $id, BankTransferSaleService $bank)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            // สถานะกับบันทึกเปลี่ยนพร้อมกัน ล็อกแถวไว้กันกดชนกับการยืนยันรายการเดียวกัน
            DB::transaction(function () use ($id, $validated, $bank): void {
                $tx = SaleTransaction::lockForUpdate()->findOrFail($id);
                $rejected = $bank->reject($tx, $validated['reason'], auth('admin')->user()?->email);

                $this->recordTransactionChange('token_sale.bank_reject', $rejected, 'pending');
            });
        } catch (PurchaseException $e) {
            return redirect()->back()->withErrors(['bank' => $e->getMessage()]);
        }

        return redirect()->back()->with('success', 'ปฏิเสธคำสั่งซื้อแล้ว');
    }

    /** ลงบันทึกพร้อมกระเป๋าและยอด — audit_logs ตอบได้เองว่าเงินก้อนไหนไปถึงใคร ไม่ต้องตามไปดูแถวรายการ */
    private function recordTransactionChange(string $action, SaleTransaction $tx, string $oldStatus): void
    {
        MoneySettings::record($action, ['status' => $oldStatus], $tx->only([
            'id', 'status', 'wallet_address', 'tpix_amount', 'payment_usd_value',
        ]));
    }

    /**
     * เข้าคิวจ่ายส่วนที่ปลดล็อกแล้วใหม่ — ใช้เมื่อคิวล้มตอนยืนยัน หรือรายการเดิมจบโดยเงินไม่ออก.
     *
     * ปุ่มยืนยันหายไปหลังยืนยันแล้ว ถ้าไม่มีทางนี้เหรียญจะค้างอยู่ใน claimed_amount
     * รายการใหม่ยังต้องผ่านการอนุมัติที่หน้าคลังตามปกติ
     */
    public function requeueBankPayout(int $id, BankTransferSaleService $bank)
    {
        $payout = $bank->queueInitialPayout(SaleTransaction::findOrFail($id), auth('admin')->id());

        if (! $payout) {
            return redirect()->back()->withErrors(['bank' => 'ออเดอร์นี้ไม่มียอดให้เข้าคิวจ่าย']);
        }

        if (! $payout->wasRecentlyCreated) {
            return redirect()->back()->withErrors([
                'bank' => "ออเดอร์นี้มีรายการจ่าย #{$payout->id} ที่ยังไม่จบอยู่แล้ว (สถานะ: {$payout->status})",
            ]);
        }

        return redirect()->back()->with('success', "เข้าคิวจ่ายใหม่แล้ว {$payout->amount_tpix} TPIX — รออนุมัติที่หน้าคลัง");
    }

    /**
     * กู้ยอด claim ของแถวที่จบโดยเงินไม่ออก — ใช้เมื่อรายการจ่ายถูกปฏิเสธ ตกกฎ หรือ revert.
     *
     * ไม่มีทางนี้เหรียญจะค้างอยู่ใน claimed_amount: ผู้ซื้อ claim ซ้ำไม่ได้ และไม่มีอะไรจ่ายออก
     * รายการใหม่ยังต้องผ่านการอนุมัติที่หน้าคลังตามปกติ
     */
    public function requeueClaimPayouts(int $id, SaleClaimPayoutService $claims)
    {
        $payouts = $claims->requeueDeadPayouts(SaleTransaction::findOrFail($id), auth('admin')->id());

        if ($payouts->isEmpty()) {
            return redirect()->back()->withErrors(['claim' => 'ออเดอร์นี้ไม่มียอด claim ที่กู้ได้ — รายการที่ยังไม่จบหรืออาจโอนไปแล้วกู้ไม่ได้']);
        }

        $amount = Wei::format(Wei::sum($payouts->pluck('amount_wei')->all()));

        return redirect()->back()->with('success', "เข้าคิว claim ใหม่แล้ว {$amount} TPIX — รออนุมัติที่หน้าคลัง");
    }

    /**
     * ล้างแคชรอบขายทันทีหลังแอดมินแก้ข้อมูล.
     *
     * TokenSaleService::getActiveSale() แคชไว้ 30 วินาที ถ้าไม่ล้าง แอดมินที่
     * เพิ่งแก้วันปิดเฟสจะรีเฟรชหน้า /token-sale แล้วยังเห็นของเก่า สรุปว่า
     * "แก้ไม่ติด" แล้วกดแก้ซ้ำอีกรอบ — เป็นการแก้ซ้ำซ้อนกลางเหตุการณ์จริง
     * ที่อันตรายที่สุดตอนกำลังกู้ระบบขายให้กลับมาขายได้
     */
    private function forgetSaleCache(): void
    {
        Cache::forget('token_sale:active');
    }

    /**
     * ดู master wallet info จาก TPIX Chain RPC.
     */
    private function getMasterWalletInfo(): array
    {
        $address = config('services.tpix_chain.master_wallet', '');
        $rpcUrl = config('services.tpix_chain.rpc_url', 'https://rpc1.tpix.online');

        if (empty($address)) {
            return [
                'address' => 'Not configured',
                'balance' => '0',
                'balance_formatted' => '0 TPIX',
                'chain_id' => 4289,
                'rpc_url' => $rpcUrl,
                'status' => 'not_configured',
            ];
        }

        try {
            // เรียก eth_getBalance จาก RPC
            $response = Http::timeout(5)->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'method' => 'eth_getBalance',
                'params' => [$address, 'latest'],
                'id' => 1,
            ]);

            $balanceHex = $response->json('result', '0x0');
            // แปลงแบบ arbitrary precision กัน float overflow ตอนยอดใหญ่
            // เดิมใช้ gmp_* ซึ่งเซิร์ฟเวอร์ไม่มี ext-gmp → โยน \Error ที่ catch ไม่ติด
            $balanceTpix = (float) Wei::hexToDecimal($balanceHex);

            return [
                'address' => $address,
                'balance' => number_format($balanceTpix, 4, '.', ''),
                'balance_formatted' => number_format($balanceTpix, 2).' TPIX',
                'chain_id' => 4289,
                'rpc_url' => $rpcUrl,
                'status' => 'connected',
            ];
        } catch (\Exception $e) {
            return [
                'address' => $address,
                'balance' => '0',
                'balance_formatted' => 'RPC Error',
                'chain_id' => 4289,
                'rpc_url' => $rpcUrl,
                'status' => 'rpc_error',
                'error' => $e->getMessage(),
            ];
        }
    }

    // =====================================================================
    //  Whitelist Management
    // =====================================================================

    /**
     * ดึงรายการ whitelist ของ phase.
     */
    public function whitelist(int $phaseId)
    {
        $entries = WhitelistEntry::where('sale_phase_id', $phaseId)
            ->orderByDesc('created_at')
            ->get(['id', 'wallet_address', 'max_allocation', 'created_at']);

        return response()->json(['success' => true, 'data' => $entries]);
    }

    /**
     * เพิ่ม wallet เข้า whitelist.
     */
    public function whitelistAdd(Request $request)
    {
        $validated = $request->validate([
            'sale_phase_id' => 'required|exists:sale_phases,id',
            'wallet_address' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{40}$/'],
            'max_allocation' => 'nullable|numeric|min:0',
        ]);

        $validated['wallet_address'] = strtolower($validated['wallet_address']);

        // ป้องกันซ้ำ
        $exists = WhitelistEntry::where('sale_phase_id', $validated['sale_phase_id'])
            ->where('wallet_address', $validated['wallet_address'])
            ->exists();

        if ($exists) {
            return redirect()->back()->with('error', 'This wallet is already whitelisted.');
        }

        WhitelistEntry::create($validated);

        return redirect()->back()->with('success', 'Wallet added to whitelist.');
    }

    /**
     * ลบ wallet ออกจาก whitelist.
     */
    public function whitelistRemove(int $id)
    {
        WhitelistEntry::findOrFail($id)->delete();

        return redirect()->back()->with('success', 'Wallet removed from whitelist.');
    }

    /**
     * Import whitelist จาก CSV (รายการ wallet addresses).
     */
    public function whitelistImport(Request $request)
    {
        $request->validate([
            'sale_phase_id' => 'required|exists:sale_phases,id',
            'wallets' => 'required|string',
        ]);

        $phaseId = $request->input('sale_phase_id');
        $wallets = preg_split('/[\r\n,;]+/', $request->input('wallets'));
        $added = 0;
        $skipped = 0;

        foreach ($wallets as $wallet) {
            $wallet = strtolower(trim($wallet));
            if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $wallet)) {
                $skipped++;

                continue;
            }

            $exists = WhitelistEntry::where('sale_phase_id', $phaseId)
                ->where('wallet_address', $wallet)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            WhitelistEntry::create([
                'sale_phase_id' => $phaseId,
                'wallet_address' => $wallet,
            ]);
            $added++;
        }

        return redirect()->back()->with('success', "Imported {$added} wallets. Skipped {$skipped}.");
    }

    // =====================================================================
    //  Refund / Cancel
    // =====================================================================

    /**
     * ยกเลิก/คืนเงิน transaction (admin only).
     */
    public function refundTransaction(int $id, SaleClaimPayoutService $payouts, TokenSaleService $sales)
    {
        // ยอดขาย สถานะ และบันทึกต้องเปลี่ยนพร้อมกัน ล็อกแถวไว้เพื่อไม่ให้กดซ้ำแล้วหักยอดสองรอบ
        $result = DB::transaction(function () use ($id, $payouts, $sales) {
            $tx = SaleTransaction::lockForUpdate()->findOrFail($id);
            $oldStatus = $tx->status;

            if ($oldStatus === 'refunded') {
                return ['error' => 'This transaction is already refunded.'];
            }

            // แถวจ่ายที่ค้างอยู่ต้องหยุดก่อน ไม่งั้นอนุมัติทีหลังแล้วผู้ซื้อได้ทั้งเงินคืนและเหรียญ
            $tokensLeftWei = $payouts->haltPayouts($tx, "คืนเงินรายการซื้อ {$tx->uuid}");

            // หยุดไม่ทัน = เหรียญออกไปแล้ว คืนโควตาไม่ได้ ไม่งั้นขายเหรียญก้อนเดียวกันซ้ำ
            if (bccomp($tokensLeftWei, '0', 0) > 0) {
                return ['error' => 'เหรียญของรายการนี้ออกจากคลังแล้ว '.Wei::format($tokensLeftWei).' TPIX — บันทึกคืนเงินไม่ได้'];
            }

            // คืนยอดขายกลับไป phase + sale
            $sales->reverseCounters($tx);

            $tx->update(['status' => 'refunded']);

            $this->recordTransactionChange('token_sale.refund', $tx, $oldStatus);

            return ['tx' => $tx];
        });

        if (isset($result['error'])) {
            return redirect()->back()->with('error', $result['error']);
        }

        return redirect()->back()->with('success', "Transaction #{$result['tx']->id} refunded. Allocation returned.");
    }
}
