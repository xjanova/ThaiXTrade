<?php

namespace Tests\Unit\CreatorRegistration;

use App\Services\CreatorRegistration\CreatorAgreement;
use App\Services\CreatorRegistration\CreatorRegistrationService;
use App\Support\LaunchpadRules;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ข้อตกลงผู้สร้างเหรียญ — ฉบับที่ลงนามแล้วห้ามเปลี่ยนเงียบๆ และข้อความที่เซ็นต้องคงที่.
 *
 * Developed by Xman Studio.
 */
class CreatorAgreementTest extends TestCase
{
    #[Test]
    public function แฮชของฉบับปัจจุบันตรงกับที่ตรึงไว้(): void
    {
        $agreement = app(CreatorAgreement::class);
        $version = $agreement->currentVersion();

        $this->assertSame(CreatorAgreement::VERSIONS[$version], $agreement->hash($version));
        $this->assertSame(64, strlen($agreement->hash()));
    }

    #[Test]
    public function ข้อตกลงมีไทยกับอังกฤษครบทุกข้อ_และระบุว่าไทยมีผลเหนือกว่า(): void
    {
        $doc = app(CreatorAgreement::class)->document();

        foreach (['title', 'draft_notice', 'preamble'] as $key) {
            $this->assertNotEmpty($doc["{$key}_th"]);
            $this->assertNotEmpty($doc["{$key}_en"]);
        }

        $this->assertGreaterThanOrEqual(15, count($doc['sections']));
        foreach ($doc['sections'] as $section) {
            $this->assertNotEmpty($section['title_th'] ?? null, $section['id']);
            $this->assertNotEmpty($section['title_en'] ?? null, $section['id']);
            foreach ($section['clauses'] as $clause) {
                $this->assertMatchesRegularExpression('/[\x{0E00}-\x{0E7F}]/u', $clause['th'], $section['id']);
                $this->assertNotEmpty($clause['en'], $section['id']);
            }
        }

        $ids = array_column($doc['sections'], 'id');
        foreach (['pdpa', 'starter-allocation', 'buyer-risk', 'law', 'compliance'] as $required) {
            $this->assertContains($required, $ids);
        }

        $all = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Thai', $all);
        $this->assertStringContainsString('ภาษาไทย', $all);
    }

    #[Test]
    public function แก้ไฟล์ข้อตกลงที่ตรึงไว้แล้ว_ระบบไม่ยอมใช้(): void
    {
        $doc = app(CreatorAgreement::class)->document();
        $doc['sections'][0]['clauses'][0]['th'] .= ' (แก้)';

        $this->assertNotSame(CreatorAgreement::VERSIONS[CreatorAgreement::CURRENT], CreatorAgreement::computeHash($doc));
    }

    #[Test]
    public function ขอฉบับที่ไม่มีอยู่ไม่ได้(): void
    {
        $this->assertFalse(app(CreatorAgreement::class)->exists('1999-01-01'));
        $this->expectException(\RuntimeException::class);
        app(CreatorAgreement::class)->document('1999-01-01');
    }

    #[Test]
    public function แฮชฟอร์มไม่ขึ้นกับลำดับคีย์(): void
    {
        $a = CreatorRegistrationService::formHash(['b' => '2', 'a' => '1', 'thai' => 'สยาม']);
        $b = CreatorRegistrationService::formHash(['thai' => 'สยาม', 'a' => '1', 'b' => '2']);

        $this->assertSame($a, $b);
        $this->assertNotSame($a, CreatorRegistrationService::formHash(['a' => '1', 'b' => '3', 'thai' => 'สยาม']));
    }

    #[Test]
    public function ข้อความยืนยันกระเป๋าเป็น_asci_i_และมีครบทุกช่อง(): void
    {
        $wallet = '0x'.str_repeat('ab', 20);
        $message = CreatorRegistrationService::ownershipMessage($wallet, 'tpix.online', str_repeat('c', 32), '2026-11-06T00:00:00Z');

        $this->assertTrue(mb_check_encoding($message, 'ASCII'));
        $this->assertStringContainsString("Wallet: {$wallet}", $message);
        $this->assertStringContainsString('Site: tpix.online', $message);
        $this->assertStringContainsString('Nonce: '.str_repeat('c', 32), $message);
        $this->assertStringContainsString('does not send a transaction', $message);
    }

    #[Test]
    public function ข้อความข้อตกลงผูกทุกแฮช(): void
    {
        $message = CreatorRegistrationService::agreementMessage(
            '0x'.str_repeat('ab', 20),
            '11111111-2222-3333-4444-555555555555',
            '2026-11-06',
            str_repeat('a', 64),
            str_repeat('f', 64),
            str_repeat('c', 32),
            '2026-11-06T00:00:00Z',
        );

        $this->assertTrue(mb_check_encoding($message, 'ASCII'));
        $this->assertStringContainsString('Agreement SHA-256: '.str_repeat('a', 64), $message);
        $this->assertStringContainsString('Application SHA-256: '.str_repeat('f', 64), $message);
        $this->assertStringContainsString('Registration: 11111111-2222-3333-4444-555555555555', $message);
        $this->assertStringContainsString('Thai text prevails', $message);
        $this->assertStringNotContainsString('Documents SHA-256', $message);   // ไม่มีเอกสาร = ข้อความแบบรอบแรกเป๊ะ
    }

    #[Test]
    public function ข้อความข้อตกลงผูกแฮชรายการเอกสารเมื่อมีเอกสาร(): void
    {
        $args = ['0x'.str_repeat('ab', 20), '11111111-2222-3333-4444-555555555555', '2026-11-06.2',
            str_repeat('a', 64), str_repeat('f', 64), str_repeat('c', 32), '2026-11-06T00:00:00Z'];

        $with = CreatorRegistrationService::agreementMessage(...[...$args, str_repeat('d', 64)]);
        $without = CreatorRegistrationService::agreementMessage(...$args);

        $this->assertTrue(mb_check_encoding($with, 'ASCII'));
        $this->assertStringContainsString('Documents SHA-256: '.str_repeat('d', 64), $with);
        $this->assertSame($without, CreatorRegistrationService::agreementMessage(...[...$args, null]));
    }

    #[Test]
    public function ฉบับสองมีหน้าที่ครบ_ใช้ตัวเลขกติกากันรักจากโค้ด_และไม่อ้างว่าอายัดบนเชนได้(): void
    {
        $agreement = app(CreatorAgreement::class);
        $this->assertSame('2026-11-06.2', CreatorAgreement::CURRENT);
        $this->assertTrue($agreement->exists('2026-11-06'));   // ฉบับเก่ายังเปิดได้ — ใบที่ลงนามไว้ตรวจย้อนได้

        $doc = $agreement->document('2026-11-06.2');
        $ids = array_column($doc['sections'], 'id');
        foreach (['information', 'buyer-duties', 'prohibited', 'fees', 'liability', 'compliance',
            'platform-rights', 'onchain-rules', 'web-rules', 'documents-publication', 'term', 'signature-record'] as $required) {
            $this->assertContains($required, $ids);
        }

        $all = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString((string) LaunchpadRules::MIN_LP_LOCK_DAYS, $all);
        $this->assertStringContainsString(LaunchpadRules::percent(LaunchpadRules::MIN_LOCKED_SUPPLY_BPS), $all);

        // ข้อ platform-rights ต้องบอกชัดว่าแพลตฟอร์มอายัด/ยึดเหรียญบนเชนไม่ได้ — ห้ามมีข้อไหนอ้างว่าทำได้
        $rights = json_encode($doc['sections'][array_search('platform-rights', $ids, true)], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('no right and no ability to stop, halt, freeze or seize', $rights);
        $this->assertStringContainsString('ไม่มีสิทธิ์และไม่สามารถ', $rights);
    }
}
