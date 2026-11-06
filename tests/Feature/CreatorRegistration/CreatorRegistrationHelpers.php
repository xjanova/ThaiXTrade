<?php

namespace Tests\Feature\CreatorRegistration;

use App\Models\AdminUser;
use App\Models\CreatorRegistration;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Support\SignsWithTestKeys;

/**
 * ขั้นตอนลงทะเบียนผู้สร้างเหรียญแบบเซ็นจริง (secp256k1) — ใช้ร่วมกันหลายไฟล์เทสต์.
 */
trait CreatorRegistrationHelpers
{
    use SignsWithTestKeys;

    protected function validForm(array $overrides = []): array
    {
        return array_merge([
            'applicant_type' => 'individual',
            'full_name' => 'สมชาย ใจดี',
            'full_name_en' => 'Somchai Jaidee',
            'date_of_birth' => '1990-01-15',
            'nationality' => 'TH',
            'occupation' => 'นักพัฒนาซอฟต์แวร์',
            'signatory_name' => 'สมชาย ใจดี',
            'signatory_position' => 'เจ้าของโครงการ',
            'email' => 'somchai@example.com',
            'phone' => '+66812345678',
            'address' => '99 ถนนสุขุมวิท แขวงคลองเตย เขตคลองเตย กรุงเทพฯ 10110',
            'country' => 'TH',
            'project_name' => 'Siam Farm',
            'token_name' => 'Siam Farm Token',
            'token_symbol' => 'sfarm',
            'planned_supply' => '1000000000',
            'category' => 'utility',
            'description' => 'เหรียญสะสมแต้มสำหรับเกษตรกรในเครือข่าย ใช้แลกปุ๋ยและเมล็ดพันธุ์ในร้านค้าที่ร่วมรายการทั่วประเทศ',
            'website' => 'https://siamfarm.example',
            'team_description' => 'ทีมงานสามคน มีประสบการณ์ด้านเกษตรและซอฟต์แวร์',
            'use_of_funds' => 'พัฒนาแอปแลกแต้มและทำการตลาดกับร้านค้าในเครือข่าย',
            'source_of_funds' => 'own_funds',
            'is_pep' => false,
            'declare_not_sanctioned' => true,
            'declare_true' => true,
            'consent_publish_project' => true,
            'consent_publish_name' => false,
        ], $overrides);
    }

    /** เซ็นยืนยันกระเป๋าแล้วเริ่มใบลงทะเบียน — คืน [private key, wallet] */
    protected function startRegistration(int $keyN = 1): array
    {
        $priv = $this->testKey($keyN);
        $wallet = $this->addressOf($priv);

        $challenge = $this->postJson('/token-creator/challenge', ['wallet_address' => $wallet])
            ->assertOk()
            ->json('data');

        $this->postJson('/token-creator/start', [
            'wallet_address' => $wallet,
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
        ])->assertOk()->assertJsonPath('data.registration.status', 'draft');

        return [$priv, $wallet];
    }

    protected function saveForm(array $overrides = []): TestResponse
    {
        return $this->putJson('/token-creator/form', $this->validForm($overrides));
    }

    protected function approveKyc(User $user, string $level = 'basic'): KycSubmission
    {
        return KycSubmission::create([
            'user_id' => $user->id,
            'level' => $level,
            'status' => KycSubmission::STATUS_APPROVED,
            'consent_version' => '1.0',
            'consented_at' => now(),
            'submitted_at' => now(),
            'reviewed_at' => now(),
        ]);
    }

    protected function userOf(string $wallet): User
    {
        return User::where('wallet_address', strtolower($wallet))->firstOrFail();
    }

    protected function registrationOf(string $wallet): CreatorRegistration
    {
        return CreatorRegistration::where('wallet_address', strtolower($wallet))->firstOrFail();
    }

    protected function signAgreement(string $priv): TestResponse
    {
        $challenge = $this->postJson('/token-creator/agreement/challenge')->assertOk()->json('data');

        return $this->postJson('/token-creator/submit', [
            'nonce' => $challenge['nonce'],
            'signature' => $this->personalSign($priv, $challenge['message']),
        ]);
    }

    /** ใบที่ส่งตรวจแล้ว พร้อม KYC ผ่าน — คืน [private key, wallet] */
    protected function submittedRegistration(int $keyN = 1): array
    {
        [$priv, $wallet] = $this->startRegistration($keyN);
        $this->saveForm()->assertOk();
        $this->approveKyc($this->userOf($wallet));
        $this->signAgreement($priv)->assertOk()->assertJsonPath('data.registration.status', 'submitted');

        return [$priv, $wallet];
    }

    protected function makeAdmin(string $role = 'super_admin', string $email = 'admin@tpix.test'): AdminUser
    {
        return AdminUser::create([
            'name' => 'ผู้ตรวจ '.$role,
            'email' => $email,
            'password' => bcrypt('secret-password'),
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** เข้าหลังบ้านผ่าน session ของ guard admin — actingAs() สลับ guard ปริยายแล้วซ่อนบั๊กจริง */
    protected function asAdmin(AdminUser $admin): static
    {
        return $this->withSession([Auth::guard('admin')->getName() => $admin->id]);
    }
}
