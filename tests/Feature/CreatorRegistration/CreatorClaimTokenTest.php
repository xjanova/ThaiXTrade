<?php

namespace Tests\Feature\CreatorRegistration;

use App\Models\CreatorRegistration;
use App\Models\FactoryToken;
use App\Services\CreatorRegistration\CreatorPublicProfile;
use App\Services\CreatorRegistration\CreatorRegistrationService;
use App\Services\TokenFactory\TpixRpcClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * ลงทะเบียนผู้สร้างให้เหรียญที่ขึ้นเชนแล้ว (เช่น IDTC) ผ่านลิงก์เชิญ.
 *
 * ผู้แทนเซ็นด้วยกระเป๋ารับเหรียญของโครงการ — กระเป๋าเจ้าของเหรียญไม่เปลี่ยน
 * ใบผูกกับเหรียญด้วย factory_token_id และเหรียญนั้นอยู่ในข้อความข้อตกลงที่เซ็น
 *
 * Developed by Xman Studio.
 */
class CreatorClaimTokenTest extends TestCase
{
    use CreatorRegistrationHelpers;
    use RefreshDatabase;

    private const OWNER = '0x3da3776e0ab0f442c181aa031f47fa83696859af';

    private FactoryToken $token;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->token = $this->makeToken();
    }

    private function makeToken(array $overrides = []): FactoryToken
    {
        return FactoryToken::create(array_merge([
            'name' => 'IDTC Token',
            'symbol' => 'IDTC',
            'decimals' => 18,
            'total_supply' => '1100000000000',
            'creator_address' => self::OWNER,
            'contract_address' => '0x798923aeb2692bbd27cfb0505bf7c94f6cf4e288',
            'chain_id' => 4289,
            'token_type' => 'standard',
            'status' => 'deployed',
        ], $overrides));
    }

    private function claimUrl(?FactoryToken $token = null, int $days = 30): string
    {
        return URL::temporarySignedRoute('token-creator.claim', now()->addDays($days), ['token' => ($token ?? $this->token)->id], absolute: false);
    }

    /** เปิดลิงก์เชิญแล้วเซ็นเริ่มใบด้วยกระเป๋าของผู้แทน — คืน [private key, wallet, response] */
    private function startViaClaim(int $keyN = 2, ?FactoryToken $token = null): array
    {
        $token ??= $this->token;
        $this->get($this->claimUrl($token))->assertOk();

        $priv = $this->testKey($keyN);
        $wallet = $this->addressOf($priv);
        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $wallet])->assertOk()->json('data');

        $res = $this->postJson('/token-creator/start', [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
            'claim_token_id' => $token->id,
        ])->assertOk();

        return [$priv, $wallet, $res];
    }

    // =========================================================================
    // ลิงก์เชิญ (หลังบ้าน)
    // =========================================================================

    #[Test]
    public function super_admin_ออกลิงก์เชิญได้(): void
    {
        $res = $this->asAdmin($this->makeAdmin())
            ->postJson('/admin/creator-registrations/claim-link', ['factory_token_id' => $this->token->id])
            ->assertOk();

        $url = (string) $res->json('data.url');
        $this->assertStringContainsString('/token-creator/claim/'.$this->token->id, $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertNotNull($res->json('data.expires_at'));
    }

    #[Test]
    public function แอดมินระดับต่ำกว่า_super_admin_ออกลิงก์เชิญไม่ได้(): void
    {
        $this->asAdmin($this->makeAdmin('admin', 'ops@tpix.test'))
            ->postJson('/admin/creator-registrations/claim-link', ['factory_token_id' => $this->token->id])
            ->assertForbidden();
    }

    #[Test]
    public function ออกลิงก์ให้เหรียญที่ยังไม่ขึ้นเชนไม่ได้(): void
    {
        $pending = $this->makeToken(['symbol' => 'PEND', 'status' => 'pending', 'contract_address' => null]);

        $this->asAdmin($this->makeAdmin())
            ->postJson('/admin/creator-registrations/claim-link', ['factory_token_id' => $pending->id])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'TOKEN_NOT_FOUND');
    }

    #[Test]
    public function หน้าคิวหลังบ้านแสดงเหรียญที่ขึ้นเชนพร้อมสถานะใบ(): void
    {
        $this->asAdmin($this->makeAdmin())
            ->get('/admin/creator-registrations')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/CreatorRegistrations/Index')
                ->where('claimTokens.0.symbol', 'IDTC')
                ->where('claimTokens.0.owner_wallet', self::OWNER)
                ->where('claimTokens.0.registration', null));
    }

    // =========================================================================
    // หน้าลิงก์เชิญ
    // =========================================================================

    #[Test]
    public function เปิดลิงก์เชิญเห็นเหรียญและกระเป๋าเจ้าของแบบล็อก(): void
    {
        $this->get($this->claimUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('TokenCreator/Register')
                ->where('claim.token_id', $this->token->id)
                ->where('claim.symbol', 'IDTC')
                ->where('claim.owner_wallet', self::OWNER)
                ->where('claim.error', null)
                ->where('registration', null));
    }

    #[Test]
    public function ลิงก์ที่ถูกแก้หรือหมดอายุเปิดไม่ได้(): void
    {
        $url = $this->claimUrl();
        $other = $this->makeToken(['symbol' => 'OTHER', 'contract_address' => '0x'.str_repeat('22', 20)]);

        // เปลี่ยนเลขเหรียญในลิงก์ — ลายเซ็นไม่ตรง
        $this->get(str_replace('/claim/'.$this->token->id, '/claim/'.$other->id, $url))->assertForbidden();
        $this->get('/token-creator/claim/'.$this->token->id)->assertForbidden();

        $expired = $this->claimUrl(days: 1);
        $this->travel(2)->days();
        $this->get($expired)->assertForbidden();
    }

    // =========================================================================
    // ลงทะเบียนครบขั้น
    // =========================================================================

    #[Test]
    public function ผู้แทนลงทะเบียนผ่านลิงก์_ผูกเหรียญ_เซ็นข้อตกลงที่ระบุเหรียญ_แอดมินอนุมัติ(): void
    {
        [$priv, $wallet, $res] = $this->startViaClaim();

        $res->assertJsonPath('data.registration.factory_token_id', $this->token->id)
            ->assertJsonPath('data.claim_error', null);
        $this->assertNotSame(self::OWNER, strtolower($wallet));

        // ผู้แทนพิมพ์ชื่อ/สัญลักษณ์อื่นมา — เซิร์ฟเวอร์ทับด้วยของเหรียญจริง
        $this->saveForm(['token_symbol' => 'FAKE', 'token_name' => 'Fake', 'planned_supply' => '5'])->assertOk();
        $r = $this->registrationOf($wallet);
        $this->assertSame('IDTC', $r->token_symbol);
        $this->assertSame('IDTC', $r->form_data['token_symbol']);
        $this->assertSame('IDTC Token', $r->form_data['token_name']);
        $this->assertNull($r->form_data['planned_supply']);

        $this->approveKyc($this->userOf($wallet));
        $this->signAgreement($priv)->assertOk()->assertJsonPath('data.registration.status', 'submitted');

        $r->refresh();
        $this->assertStringContainsString('Token contract: 0x798923aeb2692bbd27cfb0505bf7c94f6cf4e288', $r->agreement_message);
        $this->assertStringContainsString('Token owner wallet (unchanged): '.self::OWNER, $r->agreement_message);
        $this->assertStringContainsString('Wallet: '.strtolower($wallet), $r->agreement_message);
        $this->assertTrue(app(CreatorRegistrationService::class)->verifyEvidence($r)['token_binding_matches']);

        Auth::guard('web')->logout();
        $this->asAdmin($this->makeAdmin())
            ->post("/admin/creator-registrations/{$r->uuid}/approve", ['starter_allocation_bps' => 1000])
            ->assertSessionHas('success');
        $this->assertSame(CreatorRegistration::STATUS_APPROVED, $r->refresh()->status);

        // ไวท์เปเปอร์ของเหรียญหาใบเจอด้วย id ของเหรียญ แม้กระเป๋าเจ้าของเหรียญต่างจากกระเป๋าของใบ
        $profiles = app(CreatorPublicProfile::class);
        $this->assertSame($r->id, $profiles->forToken($this->token)?->id);
        $tokens = $profiles->build($r->load(['documents', 'user']))['tokens'];
        $this->assertSame('IDTC', $tokens[0]['symbol']);
        $this->assertTrue($tokens[0]['claimed']);
        $this->assertSame(self::OWNER, $tokens[0]['owner_wallet']);

        $this->mock(TpixRpcClient::class, fn ($m) => $m->shouldReceive('call')->andThrow(new RuntimeException('down')));
        Http::fake(['*/v2/smart-contracts/*' => Http::response(['is_verified' => true])]);
        $this->get("/token-factory/{$this->token->id}/whitepaper")
            ->assertOk()
            ->assertSee('กระเป๋ารับเหรียญของโครงการ')
            ->assertSee(strtolower($wallet))
            ->assertSee(route('token-creator.projects.show', $r->uuid), false);
    }

    #[Test]
    public function ส่ง_id_เหรียญมาโดยไม่ได้เปิดลิงก์เชิญ_ไม่ผูก(): void
    {
        $priv = $this->testKey(2);
        $wallet = $this->addressOf($priv);
        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $wallet])->json('data');

        $this->postJson('/token-creator/start', [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
            'claim_token_id' => $this->token->id,
        ])->assertOk()->assertJsonPath('data.registration.factory_token_id', null);
    }

    #[Test]
    public function ใบร่างที่ล็อกอินอยู่แล้ว_เปิดลิงก์เชิญแล้วผูกทันที_ลายเซ็นเดิมถูกล้าง(): void
    {
        [, $wallet] = $this->startRegistration(2);
        $this->saveForm()->assertOk();

        $this->get($this->claimUrl())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('claim.error', null)
                ->where('registration.factory_token_id', $this->token->id));

        $r = $this->registrationOf($wallet);
        $this->assertSame('IDTC', $r->form_data['token_symbol']);
        $this->assertSame(CreatorRegistrationService::formHash($r->form_data), $r->form_hash);
        $this->assertNull($r->agreement_signature);
    }

    #[Test]
    public function เหรียญที่มีใบส่งตรวจแล้ว_ใบที่สองผูกไม่ได้(): void
    {
        [$priv, $wallet] = $this->startViaClaim(2);
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($wallet));
        $this->signAgreement($priv)->assertOk();
        Auth::guard('web')->logout();

        [, $second, $res] = $this->startViaClaim(3);
        $res->assertJsonPath('data.registration.factory_token_id', null);
        $this->assertNotNull($res->json('data.claim_error'));
        $this->assertNull($this->registrationOf($second)->factory_token_id);
    }

    #[Test]
    public function กระเป๋าที่เป็นเจ้าของเหรียญอื่นอยู่แล้ว_ใช้เป็นกระเป๋าโครงการไม่ได้(): void
    {
        $wallet = $this->addressOf($this->testKey(2));
        $this->makeToken(['symbol' => 'MINE', 'creator_address' => strtolower($wallet), 'contract_address' => '0x'.str_repeat('33', 20)]);

        [, , $res] = $this->startViaClaim(2);

        $res->assertJsonPath('data.registration.factory_token_id', null);
        $this->assertNotNull($res->json('data.claim_error'));
    }

    #[Test]
    public function ย้ายใบไปผูกเหรียญอื่นในฐานข้อมูลหลังเซ็น_หลักฐานไม่ผ่าน_อนุมัติไม่ได้(): void
    {
        [$priv, $wallet] = $this->startViaClaim();
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($wallet));
        $this->signAgreement($priv)->assertOk();
        Auth::guard('web')->logout();

        $other = $this->makeToken(['symbol' => 'OTHER', 'contract_address' => '0x'.str_repeat('44', 20)]);
        $r = $this->registrationOf($wallet);
        CreatorRegistration::whereKey($r->id)->update(['factory_token_id' => $other->id]);

        $this->assertFalse(app(CreatorRegistrationService::class)->verifyEvidence($r->refresh())['token_binding_matches']);

        $this->asAdmin($this->makeAdmin())
            ->post("/admin/creator-registrations/{$r->uuid}/approve", ['starter_allocation_bps' => 1000])
            ->assertSessionHasErrors('registration');
        $this->assertSame(CreatorRegistration::STATUS_SUBMITTED, $r->refresh()->status);
    }

    #[Test]
    public function ใบปกติไม่มีบรรทัดเหรียญในข้อความ_และหลักฐานเหรียญผ่าน(): void
    {
        [, $wallet] = $this->submittedRegistration(1);

        $r = $this->registrationOf($wallet);
        $this->assertNull($r->factory_token_id);
        $this->assertStringNotContainsString('Token contract:', (string) $r->agreement_message);
        $this->assertTrue(app(CreatorRegistrationService::class)->verifyEvidence($r)['token_binding_matches']);
    }
}
