<?php

namespace App\Services\Launchpad;

use App\Models\BridgeDeposit;
use App\Models\LaunchpadAgentTx;
use App\Models\LaunchpadOrder;
use App\Models\LaunchpadSale;
use App\Models\SystemAlert;
use App\Services\Bridge\Abi;
use App\Services\Bridge\BridgeConfig;
use App\Services\Bridge\BridgeSigner;
use App\Services\Bridge\EvmRpc;
use App\Support\Wei;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ตัวแทนขายของ Launchpad — เอา USDT_TPIX ที่สะพาน mint ให้ ไปซื้อเหรียญแทนผู้ซื้อ (หรือคืนเงิน) แล้วเติมพูล.
 *
 * ═══ วินัยเดียวกับ relayer ของสะพาน (ห้ามสลับ) ═══
 *   เซ็น → บันทึก hash + raw ลง launchpad_agent_txs พร้อมเปลี่ยนสถานะคำสั่ง (ธุรกรรม DB เดียว) → ค่อย broadcast
 *   มีธุรกรรมที่ยังไม่รู้ผลแม้แต่ตัวเดียว = ไม่เซ็นอะไรใหม่ (nonce ถูกถามแบบ pending — เซ็นซ้อนจะทับกัน)
 *   เงียบนาน = ส่ง raw เดิมซ้ำ · ค้างนาน/พิสูจน์ไม่ได้ = stuck + แจ้งเตือน + หยุดคิว ไม่เดา
 *
 * ต่อหนึ่งคำสั่ง (paid + เงินฝาก minted ถึงตัวแทนแล้ว):
 *   หน้าขายไม่อนุมัติแล้ว / ซื้อไม่ผ่านครบ N ครั้ง                   → คืน USDT_TPIX ให้ผู้ซื้อ
 *   allowance ไม่พอ                                                 → approve(sale, ยอดนี้พอดี)
 *   จำลอง buyFor(ผู้ซื้อ, ยอด) จากกระเป๋าตัวแทน: ผ่าน                → ส่ง buyFor
 *                                              : สัญญาปฏิเสธ (revert) → คืนเงิน
 *                                              : อ่านไม่ได้            → รอรอบหน้า (ไม่ตัดสินอะไร)
 *
 * buyFor ส่งเหรียญตรงถึงผู้ซื้อในธุรกรรมเดียว และนับเพดานต่อกระเป๋าที่ผู้ซื้อ — ตัวแทนไม่เคยถือเหรียญขาย
 */
class LaunchpadAgent
{
    public const ALERT_NODE = 'launchpad';

    public const ALERT_STUCK = 'launchpad_agent_stuck';

    public const PURCHASED_EVENT = 'Purchased(address,uint256,uint256)';

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, true> หน้าขายที่มีการซื้อสำเร็จในรอบนี้ — ไปดูว่าเติมพูลได้หรือยัง */
    private array $feedQueue = [];

    public function __construct(
        private BridgeConfig $config,
        private BridgeSigner $signer,
        private LaunchpadDepositMatcher $matcher,
    ) {}

    /**
     * หนึ่งรอบ: ปิดคำสั่งที่หมดเวลา → ตามผลธุรกรรมเดิม → ทำคำสั่งที่จ่ายแล้ว → เติมพูล.
     *
     * @return array{expired: int, cancelled: int, tracked: int, sent: int, delivered: int, refunded: int, fed: int, blocked: ?string}
     */
    public function run(int $limit = 5): array
    {
        $this->counts = ['expired' => 0, 'cancelled' => 0, 'tracked' => 0, 'sent' => 0, 'delivered' => 0, 'refunded' => 0, 'fed' => 0];
        $this->feedQueue = [];
        $blocked = null;

        $this->closeStale();

        // ไม่มีงาน = ไม่ถามเชน (rpc.tpix.online อยู่หลัง Cloudflare ที่ตอบ 429 เมื่อยิงถี่)
        if (! $this->unresolved()->exists() && ! $this->nextOrderQuery()->exists() && ! $this->feedSweepDue()) {
            return $this->summary(null);
        }

        $rpc = $this->config->tpixRpc();
        $this->counts['tracked'] = $this->track($rpc);

        if ($this->unresolved()->exists()) {
            return $this->summary('awaiting_previous_tx');
        }

        $agent = $this->matcher->agentAddress();
        $usdt = $this->config->usdtTpixAddress();

        if ($agent === null || $usdt === null || ! $this->config->hasPassphrase('launchpad_agent')) {
            return $this->summary('agent_not_configured');
        }

        if (! $this->signer->isAvailable()) {
            return $this->summary('not_cli');
        }

        for ($i = 0; $i < max(1, $limit); $i++) {
            $order = $this->nextOrderQuery()->first();
            if (! $order) {
                break;
            }

            $outcome = $this->step($rpc, $order, $agent, strtolower($usdt));

            if ($outcome === 'sent' && ! $this->unresolved()->exists()) {
                continue; // ลงบล็อกในรอบนี้แล้ว — ทำต่อได้เลย
            }

            if ($outcome === 'review') {
                continue;
            }

            $blocked = $outcome === 'sent' ? 'awaiting_tx' : $outcome;
            break;
        }

        if ($blocked === null && ! $this->unresolved()->exists()) {
            $this->feed($rpc, $agent);
        }

        return $this->summary($blocked);
    }

    private function summary(?string $blocked): array
    {
        return $this->counts + ['blocked' => $blocked];
    }

    /** คำสั่งที่ถึงคิวส่ง: จ่ายแล้ว และสะพาน mint USDT_TPIX ถึงตัวแทนแล้ว */
    private function nextOrderQuery()
    {
        return LaunchpadOrder::query()
            ->where('status', LaunchpadOrder::STATUS_PAID)
            ->whereHas('deposit', fn ($q) => $q->where('status', BridgeDeposit::STATUS_MINTED))
            ->orderBy('id');
    }

    private function unresolved()
    {
        return LaunchpadAgentTx::query()->whereIn('status', LaunchpadAgentTx::UNRESOLVED);
    }

    // ── ปิดคำสั่งที่จบโดยไม่มีเงินผ่านตัวแทน (ไม่ต้องถามเชน) ─────────────────────

    private function closeStale(): void
    {
        $grace = max(0, (int) config('launchpad.expire_grace_minutes', 30));

        // หมดเวลาและเลยช่วงที่ตัวสแกนจะตามบล็อกทันแล้ว — โอนมาหลังจากนี้ = เงินฝากสะพานปกติ (ได้ USDT_TPIX)
        $this->counts['expired'] += LaunchpadOrder::query()
            ->where('status', LaunchpadOrder::STATUS_AWAITING)
            ->where('expires_at', '<', now()->subMinutes($grace))
            ->update(['status' => LaunchpadOrder::STATUS_EXPIRED, 'settled_at' => now(), 'updated_at' => now()]);

        // แอดมินสะพานปฏิเสธเงินฝากที่จับคู่ไว้ (เงินไม่เคยถึงตัวแทน — สะพานคืนเงินผู้ฝากตามขั้นตอนของสะพานเอง)
        foreach (LaunchpadOrder::query()
            ->where('status', LaunchpadOrder::STATUS_PAID)
            ->whereHas('deposit', fn ($q) => $q->where('status', BridgeDeposit::STATUS_REJECTED))
            ->get() as $order) {
            $this->counts['cancelled'] += LaunchpadOrder::whereKey($order->id)
                ->where('status', LaunchpadOrder::STATUS_PAID)
                ->update([
                    'status' => LaunchpadOrder::STATUS_CANCELLED,
                    'settled_at' => now(),
                    'last_error' => 'เงินฝากถูกปฏิเสธที่สะพาน — ไม่มีเงินถึงตัวแทน คืนเงินตามขั้นตอนของสะพาน',
                    'updated_at' => now(),
                ]);
        }
    }

    // ── หนึ่งคำสั่ง ─────────────────────────────────────────────────────────

    /** คืน sent | review | busy | wait:<เหตุ> */
    private function step(EvmRpc $rpc, LaunchpadOrder $candidate, string $agent, string $usdt): string
    {
        $order = LaunchpadOrder::with(['sale', 'deposit'])->find($candidate->id);
        if (! $order || $order->status !== LaunchpadOrder::STATUS_PAID) {
            return 'busy';
        }

        $deposit = $order->deposit;
        if (! $deposit || $deposit->status !== BridgeDeposit::STATUS_MINTED || $deposit->recipient() !== $agent) {
            return $this->toReview($order, LaunchpadOrder::STATUS_PAID, 'เงินฝากของคำสั่งนี้ไม่ได้ mint ถึงกระเป๋าตัวแทนใบปัจจุบัน');
        }

        $pay = (string) $deposit->mint_units;
        $sale = $order->sale;

        // เงินต้องอยู่ที่ตัวแทนจริงก่อนทำอะไร — ขาด = มีอะไรผิดปกติ (อย่าเอาเงินของคำสั่งอื่นมาจ่าย)
        $balance = $rpc->tokenBalance($usdt, $agent);
        if ($balance === null) {
            return 'wait:rpc_unavailable';
        }
        if (bccomp($balance, $pay, 0) < 0) {
            $this->alert('launchpad_agent_balance_short', "Launchpad: กระเป๋าตัวแทนมี USDT_TPIX ไม่พอสำหรับคำสั่ง {$order->public_id} (มี ".Wei::format($balance, 6).' ต้องใช้ '.Wei::format($pay, 6).')', ['order' => $order->public_id]);

            return $this->toReview($order, LaunchpadOrder::STATUS_PAID, 'กระเป๋าตัวแทนมี USDT_TPIX ไม่พอ — ตรวจก่อนทำต่อ');
        }

        if (! $sale || ! $sale->isApproved()) {
            return $this->refund($rpc, $order, $usdt, $pay, 'หน้าขายปิดรับแล้ว — คืนเงิน');
        }

        if ($order->attempts >= max(1, (int) config('launchpad.max_buy_attempts', 3))) {
            return $this->refund($rpc, $order, $usdt, $pay, "ซื้อไม่สำเร็จ {$order->attempts} ครั้ง — คืนเงิน");
        }

        $saleAddress = strtolower($sale->sale_address);

        // ปิดขาย/หยุด/ขายหมดแล้ว — คืนเงินเลย ไม่ต้อง approve ก่อน (isActive = ไม่หยุด · อยู่ในช่วง · ยังเหลือเหรียญ)
        $active = $rpc->callBool($saleAddress, 'isActive()');
        if ($active === null) {
            return 'wait:rpc_unavailable';
        }
        if (! $active) {
            return $this->refund($rpc, $order, $usdt, $pay, 'หน้าขายไม่เปิดรับซื้อบนเชนแล้ว — คืนเงิน');
        }

        $allowance = $rpc->callUint($usdt, 'allowance(address,address)', Abi::encAddress($agent).Abi::encAddress($saleAddress));
        if ($allowance === null) {
            return 'wait:rpc_unavailable';
        }

        if (bccomp($allowance, $pay, 0) < 0) {
            return $this->send($rpc, LaunchpadAgentTx::KIND_APPROVE, $usdt, self::approveCalldata($saleAddress, $pay), $order, null, []);
        }

        $buy = self::buyForCalldata($order->buyer_address, $pay);
        $simulation = $rpc->request('eth_call', [['from' => $agent, 'to' => $saleAddress, 'data' => $buy], 'latest']);

        if ($simulation === null) {
            return 'wait:rpc_unavailable';
        }

        if (isset($simulation['error'])) {
            // สัญญาไม่รับการซื้อนี้ (ปิดขาย/ขายหมด/ต่ำกว่าขั้นต่ำ/เกินเพดานต่อกระเป๋า/หยุดชั่วคราว) — คืนเงิน ไม่ลองซ้ำ
            return $this->refund($rpc, $order, $usdt, $pay, 'สัญญาขายไม่รับการซื้อนี้ — คืนเงิน');
        }

        return $this->send($rpc, LaunchpadAgentTx::KIND_BUY, $saleAddress, $buy, $order, LaunchpadOrder::STATUS_PAID, [
            'status' => LaunchpadOrder::STATUS_BUYING,
        ], 'delivery_tx_hash');
    }

    private function refund(EvmRpc $rpc, LaunchpadOrder $order, string $usdt, string $pay, string $reason): string
    {
        return $this->send($rpc, LaunchpadAgentTx::KIND_REFUND, $usdt, Abi::transferCalldata($order->buyer_address, $pay), $order, LaunchpadOrder::STATUS_PAID, [
            'status' => LaunchpadOrder::STATUS_REFUNDING,
            'last_error' => mb_substr($reason, 0, 300),
        ], 'refund_tx_hash');
    }

    /**
     * เซ็น → บันทึก (ธุรกรรม + สถานะคำสั่ง แบบ compare-and-set) → ส่ง → รอใบเสร็จสั้น ๆ.
     *
     * คำสั่งเปลี่ยนสถานะไประหว่างเซ็น = ทิ้งธุรกรรมที่เซ็นไว้ (ยังไม่เคยส่ง nonce จึงไม่ถูกใช้)
     */
    private function send(EvmRpc $rpc, string $kind, string $to, string $data, ?LaunchpadOrder $order, ?string $expectStatus, array $orderUpdate, ?string $hashColumn = null, ?LaunchpadSale $sale = null): string
    {
        $signed = $this->signer->sign('launchpad_agent', $rpc, $to, $data, ['gas_multiplier_bps' => 12000]);

        if (! ($signed['ok'] ?? false)) {
            $note = mb_substr('เซ็นไม่ผ่าน ('.($signed['code'] ?? 'error').'): '.($signed['error'] ?? ''), 0, 300);
            if ($order) {
                LaunchpadOrder::whereKey($order->id)->update(['last_error' => $note, 'updated_at' => now()]);
            }

            return 'wait:sign_failed';
        }

        $hash = strtolower((string) $signed['txHash']);

        $saved = DB::transaction(function () use ($kind, $to, $data, $order, $expectStatus, $orderUpdate, $hashColumn, $sale, $signed, $hash) {
            if ($order && $expectStatus !== null) {
                $values = $orderUpdate + ['updated_at' => now()];
                if ($hashColumn) {
                    $values[$hashColumn] = $hash;
                }

                if (LaunchpadOrder::whereKey($order->id)->where('status', $expectStatus)->update($values) !== 1) {
                    return null;
                }
            }

            return LaunchpadAgentTx::create([
                'kind' => $kind,
                'launchpad_sale_id' => $sale?->id ?? $order?->launchpad_sale_id,
                'launchpad_order_id' => $order?->id,
                'to_address' => strtolower($to),
                'calldata' => $data,
                'tx_hash' => $hash,
                'raw_tx' => (string) $signed['raw'],
                'nonce' => (int) ($signed['nonce'] ?? 0),
                'status' => LaunchpadAgentTx::STATUS_PENDING,
                'broadcast_at' => now(),
            ]);
        });

        if (! $saved) {
            Log::warning('launchpad: คำสั่งเปลี่ยนสถานะระหว่างเซ็น — ทิ้งธุรกรรมที่เซ็นไว้ ไม่ส่ง', ['order' => $order?->public_id, 'kind' => $kind]);

            return 'busy';
        }

        $sent = $this->signer->send($rpc, (string) $signed['raw']);
        if (! ($sent['ok'] ?? false)) {
            // ไม่เซ็นใหม่ — รอบตามผลจะส่ง raw เดิมซ้ำเอง
            $saved->update(['last_error' => mb_substr('broadcast ไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300)]);
        }

        $this->counts['sent']++;
        Log::info('launchpad: ส่งธุรกรรมตัวแทนแล้ว', ['kind' => $kind, 'order' => $order?->public_id, 'tx' => $hash, 'nonce' => $saved->nonce]);

        $this->waitForReceipt($rpc, $saved);

        return 'sent';
    }

    private function waitForReceipt(EvmRpc $rpc, LaunchpadAgentTx $tx): void
    {
        $deadline = microtime(true) + max(0, (int) config('launchpad.receipt_wait_seconds', 20));

        do {
            $this->trackOne($rpc, $tx);
            $tx->refresh();

            if ($tx->status !== LaunchpadAgentTx::STATUS_PENDING || microtime(true) >= $deadline) {
                return;
            }

            usleep(2_000_000);
        } while (true);
    }

    // ── ตามผลธุรกรรม ───────────────────────────────────────────────────────

    /** ตามผลทุกธุรกรรมที่ยังไม่รู้ผล — คืนจำนวนที่ได้ข้อสรุป */
    public function track(EvmRpc $rpc): int
    {
        $settled = 0;

        foreach ($this->unresolved()->orderBy('nonce')->get() as $tx) {
            if ($this->trackOne($rpc, $tx)) {
                $settled++;
            }
        }

        if (! $this->unresolved()->where('status', LaunchpadAgentTx::STATUS_STUCK)->exists()) {
            SystemAlert::resolveKeys(self::ALERT_NODE, [self::ALERT_STUCK]);
        }

        return $settled;
    }

    private function trackOne(EvmRpc $rpc, LaunchpadAgentTx $tx): bool
    {
        $receipt = $rpc->receiptAny($tx->tx_hash)['receipt'];
        if ($receipt !== null) {
            return $this->settle($tx, $receipt);
        }

        $known = $rpc->knowsTransaction($tx->tx_hash);
        if ($known === null) {
            return false;
        }

        if (! $known) {
            $sinceTouch = $tx->updated_at ? now()->diffInSeconds($tx->updated_at, true) : PHP_INT_MAX;

            if ($sinceTouch >= (int) config('launchpad.rebroadcast_after_seconds', 90) && $this->signer->isAvailable()) {
                $sent = $this->signer->send($rpc, (string) $tx->raw_tx);

                if (($sent['code'] ?? null) === 'nonce_used') {
                    // nonce ถูกใช้แล้ว — อาจเป็นธุรกรรมนี้เองที่ RPC ยังไม่เห็นใบเสร็จ ถามอีกรอบก่อนตัดสิน
                    $again = $rpc->receiptAny($tx->tx_hash)['receipt'];
                    if ($again !== null) {
                        return $this->settle($tx, $again);
                    }

                    return $this->markStuck($tx, "nonce {$tx->nonce} ถูกใช้ไปแล้วแต่ไม่เจอใบเสร็จของ {$tx->tx_hash} — พิสูจน์ไม่ได้ว่าธุรกรรมนี้ทำงานหรือไม่");
                }

                $tx->update(['last_error' => ($sent['ok'] ?? false) ? 'ส่ง raw เดิมซ้ำแล้ว' : mb_substr('ส่งซ้ำไม่ผ่าน: '.($sent['error'] ?? ''), 0, 300)]);
            }
        }

        if ($tx->status === LaunchpadAgentTx::STATUS_PENDING
            && $tx->broadcast_at
            && now()->diffInSeconds($tx->broadcast_at, true) >= max(1, (int) config('launchpad.stuck_after_minutes', 20)) * 60) {
            return $this->markStuck($tx, 'ส่งไปนานแล้วยังไม่ลงบล็อก ('.($known ? 'ค้างใน mempool' : 'เชนไม่รู้จัก').')');
        }

        return false;
    }

    private function markStuck(LaunchpadAgentTx $tx, string $note): bool
    {
        if ($tx->status === LaunchpadAgentTx::STATUS_STUCK) {
            return false; // แจ้งไปแล้ว — ยังตามผลต่อทุกรอบ ลงบล็อกเมื่อไหร่ก็ปิดเอง
        }

        $tx->update(['status' => LaunchpadAgentTx::STATUS_STUCK, 'last_error' => mb_substr($note, 0, 300)]);
        Log::critical('launchpad: ธุรกรรมตัวแทนค้าง', ['tx' => $tx->tx_hash, 'nonce' => $tx->nonce, 'kind' => $tx->kind]);
        $this->alert(self::ALERT_STUCK, "Launchpad: ธุรกรรม {$tx->kind} ของตัวแทน (nonce {$tx->nonce}) ค้าง — คิวหยุดรอ · {$note}", ['tx' => $tx->tx_hash, 'nonce' => $tx->nonce]);

        return true;
    }

    private function settle(LaunchpadAgentTx $tx, array $receipt): bool
    {
        $ok = strtolower((string) ($receipt['status'] ?? '')) === '0x1';
        $block = isset($receipt['blockNumber']) ? (int) Wei::hexToInt((string) $receipt['blockNumber']) : null;

        $changed = LaunchpadAgentTx::whereKey($tx->id)
            ->whereIn('status', LaunchpadAgentTx::UNRESOLVED)
            ->update([
                'status' => $ok ? LaunchpadAgentTx::STATUS_CONFIRMED : LaunchpadAgentTx::STATUS_FAILED,
                'mined_block' => $block,
                'last_error' => $ok ? null : 'ธุรกรรม revert บนเชน',
                'updated_at' => now(),
            ]);

        if ($changed !== 1) {
            return false;
        }

        $tx->refresh();
        $order = $tx->launchpad_order_id ? LaunchpadOrder::find($tx->launchpad_order_id) : null;

        match ($tx->kind) {
            LaunchpadAgentTx::KIND_BUY => $this->settleBuy($tx, $order, $receipt, $ok),
            LaunchpadAgentTx::KIND_REFUND => $this->settleRefund($tx, $order, $ok),
            LaunchpadAgentTx::KIND_APPROVE => $ok || ! $order ? null : LaunchpadOrder::whereKey($order->id)->update([
                'attempts' => $order->attempts + 1,
                'last_error' => 'approve revert บนเชน',
                'updated_at' => now(),
            ]),
            LaunchpadAgentTx::KIND_FEED => $ok ? $this->counts['fed']++ : Log::warning('launchpad: feed revert', ['tx' => $tx->tx_hash]),
            default => null,
        };

        return true;
    }

    private function settleBuy(LaunchpadAgentTx $tx, ?LaunchpadOrder $order, array $receipt, bool $ok): void
    {
        if (! $order) {
            return;
        }

        $match = LaunchpadOrder::whereKey($order->id)
            ->where('status', LaunchpadOrder::STATUS_BUYING)
            ->where('delivery_tx_hash', $tx->tx_hash);

        if (! $ok) {
            // ระหว่างจำลองกับลงบล็อกมีอะไรเปลี่ยน (ขายหมดพอดี ฯลฯ) — กลับไปรอบใหม่ จำลองแล้วไม่ผ่าน = คืนเงิน
            $match->update([
                'status' => LaunchpadOrder::STATUS_PAID,
                'attempts' => $order->attempts + 1,
                'last_error' => "buyFor revert บนเชน ({$tx->tx_hash})",
                'updated_at' => now(),
            ]);

            return;
        }

        $tokensOut = $this->purchasedTokens($receipt, $tx->to_address, $order->buyer_address);

        if ($match->update([
            'status' => LaunchpadOrder::STATUS_DELIVERED,
            'tokens_out' => $tokensOut,
            'settled_at' => now(),
            'last_error' => $tokensOut === null ? 'ซื้อสำเร็จแต่อ่านจำนวนเหรียญจาก Purchased ไม่ได้' : null,
            'updated_at' => now(),
        ]) === 1) {
            $this->counts['delivered']++;
            $this->feedQueue[(int) $order->launchpad_sale_id] = true;
            Log::info('launchpad: ส่งเหรียญถึงผู้ซื้อแล้ว', ['order' => $order->public_id, 'tx' => $tx->tx_hash, 'tokens' => $tokensOut]);
        }
    }

    private function settleRefund(LaunchpadAgentTx $tx, ?LaunchpadOrder $order, bool $ok): void
    {
        if (! $order) {
            return;
        }

        $match = LaunchpadOrder::whereKey($order->id)
            ->where('status', LaunchpadOrder::STATUS_REFUNDING)
            ->where('refund_tx_hash', $tx->tx_hash);

        if ($ok) {
            if ($match->update(['status' => LaunchpadOrder::STATUS_REFUNDED, 'settled_at' => now(), 'updated_at' => now()]) === 1) {
                $this->counts['refunded']++;
            }

            return;
        }

        // โอนคืนไม่ผ่าน — ห้ามเดา ส่งให้คนดู
        $match->update(['status' => LaunchpadOrder::STATUS_NEEDS_REVIEW, 'last_error' => "โอนคืน USDT_TPIX revert ({$tx->tx_hash})", 'updated_at' => now()]);
        $this->alert('launchpad_refund_failed', "Launchpad: โอนคืนคำสั่ง {$order->public_id} ไม่สำเร็จ — ต้องมีคนดู", ['order' => $order->public_id, 'tx' => $tx->tx_hash]);
    }

    /** tokensOut จาก Purchased(buyer, payAmount, tokensOut) ของสัญญาขาย — null = หาไม่เจอ */
    private function purchasedTokens(array $receipt, string $sale, string $buyer): ?string
    {
        $topic = Abi::topic(self::PURCHASED_EVENT);

        foreach ((array) ($receipt['logs'] ?? []) as $log) {
            $topics = array_map('strtolower', (array) ($log['topics'] ?? []));

            if (strtolower((string) ($log['address'] ?? '')) === strtolower($sale)
                && ($topics[0] ?? null) === $topic
                && Abi::topicAddress($topics[1] ?? null) === strtolower($buyer)) {
                $words = Abi::words((string) ($log['data'] ?? ''));

                return isset($words[1]) ? Wei::hexToInt('0x'.$words[1]) : null;
            }
        }

        return null;
    }

    // ── เติมพูล ────────────────────────────────────────────────────────────

    /** ถึงรอบกวาดดูทุกหน้าขายหรือยัง (ทุก 15 นาที) — เผื่อมีคนซื้อตรงบนเชนโดยไม่ผ่านเรา */
    private function feedSweepDue(): bool
    {
        return LaunchpadSale::where('status', LaunchpadSale::STATUS_APPROVED)->exists()
            && ! Cache::has('launchpad:feed-sweep');
    }

    private function feed(EvmRpc $rpc, string $agent): void
    {
        $ids = array_keys($this->feedQueue);

        if (! Cache::has('launchpad:feed-sweep')) {
            Cache::put('launchpad:feed-sweep', 1, now()->addMinutes(15));
            $ids = array_merge($ids, LaunchpadSale::where('status', LaunchpadSale::STATUS_APPROVED)->pluck('id')->all());
        }

        foreach (LaunchpadSale::whereIn('id', array_unique($ids))->orderBy('id')->get() as $sale) {
            if ($this->unresolved()->exists()) {
                return;
            }

            $feeder = strtolower($sale->feeder_address);
            $pending = $rpc->callUint($feeder, 'pendingQuote()');

            if ($pending === null || bccomp($pending, (string) $sale->min_feed, 0) < 0 || Cache::has("launchpad:feed-skip:{$sale->id}")) {
                continue;
            }

            $data = Abi::selector('feed()');
            $simulation = $rpc->request('eth_call', [['from' => $agent, 'to' => $feeder, 'data' => $data], 'latest']);

            if ($simulation === null) {
                continue;
            }

            if (isset($simulation['error'])) {
                // ราคาพูลหลุดช่วง (มีคนเทรดดันราคา) — feed ไม่ได้จนกว่าราคาจะกลับ ลองใหม่ทีหลัง ไม่ยิงถี่
                Cache::put("launchpad:feed-skip:{$sale->id}", 1, now()->addMinutes(10));
                Log::info('launchpad: ยังเติมพูลไม่ได้ (จำลอง feed ไม่ผ่าน)', ['sale' => $sale->id]);

                continue;
            }

            $this->send($rpc, LaunchpadAgentTx::KIND_FEED, $feeder, $data, null, null, [], null, $sale);
        }
    }

    // ── ตัวช่วย ────────────────────────────────────────────────────────────

    private function toReview(LaunchpadOrder $order, string $expect, string $note): string
    {
        LaunchpadOrder::whereKey($order->id)->where('status', $expect)->update([
            'status' => LaunchpadOrder::STATUS_NEEDS_REVIEW,
            'last_error' => mb_substr($note, 0, 300),
            'updated_at' => now(),
        ]);

        return 'review';
    }

    private function alert(string $key, string $message, array $data = []): void
    {
        SystemAlert::raise(self::ALERT_NODE, $key, 'critical', $message, $data);
    }

    public static function approveCalldata(string $spender, string $amount): string
    {
        return Abi::selector('approve(address,uint256)').Abi::encAddress($spender).Abi::encUint($amount);
    }

    public static function buyForCalldata(string $buyer, string $payAmount): string
    {
        return Abi::selector('buyFor(address,uint256)').Abi::encAddress($buyer).Abi::encUint($payAmount);
    }
}
