<?php

namespace Tests\Unit\AiBot;

use App\Models\AiBotWallet;
use App\Services\AiBot\Wallet\BotWalletRemoteSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * TPIX TRADE — ลูกข่ายของตัวเซ็นกระเป๋าบอทแยกเครื่อง: ลายเซ็นคำขอ รูปของคำขอ และการปฏิเสธคำตอบที่ไม่น่าเชื่อ.
 *
 * ตัวเซ็นจริงทดสอบที่ tests/js/bot-signer/signer.test.js — ที่นี่พิสูจน์ฝั่งเครื่องเว็บ
 *
 * Developed by Xman Studio.
 */
class BotWalletRemoteSignerTest extends TestCase
{
    private const OWNER = '0x1111111111111111111111111111111111111111';

    private const ADDRESS = '0x2222222222222222222222222222222222222222';

    private const SECRET = 'ssssssssssssssssssssssssssssssssssssssss';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'aibot.bot_wallet.signer.url' => 'http://10.8.0.2:8787/',
            'aibot.bot_wallet.signer.secret' => self::SECRET,
        ]);
    }

    private function wallet(): AiBotWallet
    {
        return new AiBotWallet(['owner_address' => self::OWNER, 'address' => self::ADDRESS]);
    }

    private function sealed(): string
    {
        return base64_encode(str_repeat('x', 60));
    }

    #[Test]
    public function สูตรลายเซ็นตรงกับฝั่งตัวเซ็น(): void
    {
        // เวกเตอร์เดียวกับ tests/js/bot-signer/signer.test.js — ถ้าฝั่งใดเปลี่ยนสูตร เทสต์ของอีกฝั่งยังเขียวอยู่
        // แต่ค่าคงที่นี้จะไม่ตรง จึงต้องแก้ทั้งสองที่พร้อมกัน
        $body = '{"owner":"0x1111111111111111111111111111111111111111"}';
        $signature = hash_hmac('sha256', "1800000000\nabcdef0123456789abcdef0123456789\n/v1/wallets\n".hash('sha256', $body), self::SECRET);

        $this->assertSame('021948bc1c61062b7837417b17514ec0a624dad32497aa9c55ed05649ce2c5d4', $signature);
    }

    #[Test]
    public function คำขอมีลายเซ็นที่คำนวณจากเวลา_nonce_เส้นทาง_และเนื้อคำขอ(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'address' => self::ADDRESS, 'sealed' => $this->sealed(), 'keyVersion' => 2])]);

        app(BotWalletRemoteSigner::class)->createWallet(strtoupper(self::OWNER));

        Http::assertSent(function (Request $request) {
            $timestamp = $request->header('X-Signer-Timestamp')[0];
            $nonce = $request->header('X-Signer-Nonce')[0];
            $expected = hash_hmac('sha256', $timestamp."\n".$nonce."\n/v1/wallets\n".hash('sha256', $request->body()), self::SECRET);

            return $request->url() === 'http://10.8.0.2:8787/v1/wallets'
                && $request->method() === 'POST'
                && abs(time() - (int) $timestamp) <= 5
                && preg_match('/^[0-9a-f]{32}$/', $nonce) === 1
                && hash_equals($expected, $request->header('X-Signer-Signature')[0])
                && $request->body() === '{"owner":"'.self::OWNER.'"}';
        });
    }

    #[Test]
    public function สร้างกระเป๋าคืนที่อยู่กับ_ciphertext_และคำตอบผิดรูปถูกปฏิเสธ(): void
    {
        Http::fakeSequence()
            ->push(['ok' => true, 'address' => self::ADDRESS, 'sealed' => $this->sealed()])
            ->push(['ok' => true, 'address' => 'not-an-address', 'sealed' => $this->sealed()])
            ->push(['ok' => true, 'address' => self::ADDRESS, 'sealed' => 'short'])
            ->push(['ok' => false, 'code' => 'policy_rate', 'error' => 'x'], 422);

        $remote = app(BotWalletRemoteSigner::class);

        $this->assertSame(['address' => self::ADDRESS, 'sealed' => $this->sealed()], $remote->createWallet(self::OWNER));

        foreach ([BotWalletRemoteSigner::ERR_BAD_REPLY, BotWalletRemoteSigner::ERR_BAD_REPLY, BotWalletRemoteSigner::ERR_UNAVAILABLE] as $expected) {
            try {
                $remote->createWallet(self::OWNER);
                $this->fail('ต้องโยน '.$expected);
            } catch (RuntimeException $e) {
                $this->assertSame($expected, $e->getMessage());
            }
        }
    }

    #[Test]
    public function ถอนไม่ส่งปลายทางไปในคำขอ(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'address' => self::ADDRESS, 'nonce' => 3, 'txHash' => '0xhash', 'raw' => '0xraw'])]);

        $signed = app(BotWalletRemoteSigner::class)->signWithdraw($this->wallet(), $this->sealed(), '25000000000000000000', '0x55D398326f99059fF775485246999027B3197955');

        $this->assertTrue($signed['ok']);
        $this->assertSame('0xraw', $signed['raw']);

        Http::assertSent(function (Request $request) {
            $payload = $request->data();

            return str_ends_with($request->url(), '/v1/sign/withdraw')
                && ! array_key_exists('to', $payload)
                && $payload['owner'] === self::OWNER
                && $payload['address'] === self::ADDRESS
                && $payload['sealed'] === $this->sealed()
                && $payload['token'] === '0x55d398326f99059ff775485246999027b3197955'
                && $payload['amountWei'] === '25000000000000000000'
                && $payload['nonce'] === null;
        });
    }

    #[Test]
    public function สวอปและ_approve_ส่งเจตนาครบและไม่มีที่อยู่ของ_router(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'address' => self::ADDRESS, 'nonce' => 0, 'txHash' => '0xhash', 'raw' => '0xraw'])]);

        $remote = app(BotWalletRemoteSigner::class);
        $usdt = '0x55D398326f99059fF775485246999027B3197955';
        $btcb = '0x7130d2A12B9BCbFAe4f2634d864A1Ee1Ce3Ead9c';

        $remote->signApprove($this->wallet(), $this->sealed(), $usdt, 'v3', '100');
        $remote->signSwap($this->wallet(), $this->sealed(), $usdt, $btcb, '100', '9', 1800000300, ['kind' => 'v3', 'tokens' => [$usdt, $btcb], 'fees' => ['500']]);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data())->all();

        $this->assertSame(['sealed', 'owner', 'address', 'token', 'router', 'amountWei'], array_keys($sent[0]));
        $this->assertSame('v3', $sent[0]['router']);
        $this->assertSame(['kind' => 'v3', 'tokens' => [strtolower($usdt), strtolower($btcb)], 'fees' => [500]], $sent[1]['route']);
        $this->assertSame(1800000300, $sent[1]['deadline']);
        $this->assertSame('9', $sent[1]['minOutWei']);
    }

    #[Test]
    public function ธุรกรรมที่ได้กลับมาไม่ใช่ของกระเป๋านี้ถูกทิ้ง(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'address' => '0x3333333333333333333333333333333333333333', 'txHash' => '0xhash', 'raw' => '0xraw'])]);

        $signed = app(BotWalletRemoteSigner::class)->signWithdraw($this->wallet(), $this->sealed(), '1');

        $this->assertFalse($signed['ok']);
        $this->assertSame('address_mismatch', $signed['code']);
        $this->assertArrayNotHasKey('raw', $signed);
    }

    #[Test]
    public function ตัวเซ็นปฏิเสธ_ติดต่อไม่ได้_หรือตอบผิดรูป_คืน_ok_false_พร้อมรหัส(): void
    {
        $remote = app(BotWalletRemoteSigner::class);

        Http::fake(['*' => Http::response(['ok' => false, 'code' => 'policy_min_out', 'error' => 'ต่ำเกิน'], 422)]);
        $this->assertSame('policy_min_out', $remote->signWithdraw($this->wallet(), $this->sealed(), '1')['code']);

        Http::swap(new Factory());
        Http::fake(fn () => throw new ConnectionException('timed out'));
        $this->assertSame('signer_unreachable', $remote->signWithdraw($this->wallet(), $this->sealed(), '1')['code']);

        Http::swap(new Factory());
        Http::fake(['*' => Http::response('<html>502</html>', 502)]);
        $this->assertSame('signer_bad_reply', $remote->signWithdraw($this->wallet(), $this->sealed(), '1')['code']);
    }

    #[Test]
    public function ยังไม่ตั้งค่าตัวเซ็นแล้วไม่ยิงคำขอออกไปเลย(): void
    {
        Http::fake();

        foreach ([['url' => null], ['secret' => 'short'], ['url' => 'ftp://10.8.0.2']] as $override) {
            config([
                'aibot.bot_wallet.signer.url' => 'http://10.8.0.2:8787',
                'aibot.bot_wallet.signer.secret' => self::SECRET,
            ]);
            config(collect($override)->mapWithKeys(fn ($v, $k) => ["aibot.bot_wallet.signer.{$k}" => $v])->all());

            $remote = app(BotWalletRemoteSigner::class);

            $this->assertFalse($remote->configured());
            $this->assertFalse($remote->healthy());
            $this->assertSame('signer_not_configured', $remote->signWithdraw($this->wallet(), $this->sealed(), '1')['code']);
        }

        Http::assertNothingSent();
    }
}
