<?php

namespace Tests\Unit\AiBot;

use App\Models\SiteSetting;
use App\Services\AiBot\Advisor\AdvisorFactory;
use App\Services\AiBot\Advisor\AdvisorSettings;
use App\Services\AiBot\Advisor\GeminiAdvisor;
use App\Services\AiBot\Advisor\NullAdvisor;
use App\Services\AiBot\Advisor\OpenAiAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — คีย์ที่ปรึกษา AI ที่กรอกจากหลังบ้าน.
 *
 * เจ้าของสั่งว่า "ฟังก์ชันกรอก api ai ต้องกรอกได้ที่หลังบ้านได้ด้วย"
 * — คือไม่ต้อง ssh เข้าเซิร์ฟเวอร์ไปแก้ `.env` แล้วสั่ง config:cache ใหม่ทุกครั้ง
 *
 * สิ่งที่ต้องคุมให้แน่น: ลำดับความสำคัญของแหล่งที่มา และการล้มแบบไม่พังทั้งระบบ
 * เพราะที่ปรึกษาเป็นของแถม ตัวตัดสินใจเทรดจริงคือกฎของกลยุทธ์
 *
 * Developed by Xman Studio.
 */
class AdvisorSettingsTest extends TestCase
{
    use RefreshDatabase;

    private AdvisorSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = app(AdvisorSettings::class);

        // ตั้ง .env จำลองให้เป็นค่าฐาน — เทสต์ที่ตามมาจะเขียนทับด้วยค่าจากหลังบ้าน
        config([
            'aibot_advisor.enabled' => true,
            'aibot_advisor.providers.gemini.api_key' => 'key-from-env',
            'aibot_advisor.providers.gemini.model' => 'gemini-2.0-flash',
            'aibot_advisor.providers.gemini.max_calls_per_day' => 40,
            'aibot_advisor.providers.openai.api_key' => '',
        ]);
    }

    #[Test]
    public function ยังไม่ได้กรอกที่หลังบ้านก็ใช้ค่าจาก_env(): void
    {
        $config = $this->settings->providerConfig('gemini');

        $this->assertSame('key-from-env', $config['api_key']);
        $this->assertSame('gemini-2.0-flash', $config['model']);
    }

    #[Test]
    public function คีย์ที่กรอกหลังบ้านชนะค่าใน_env(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_gemini_api_key', 'key-from-admin');
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_gemini_model', 'gemini-1.5-pro');
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_gemini_max_calls', '99');

        $config = app(AdvisorSettings::class)->providerConfig('gemini');

        $this->assertSame('key-from-admin', $config['api_key']);
        $this->assertSame('gemini-1.5-pro', $config['model']);
        $this->assertSame(99, $config['max_calls_per_day']);
    }

    /**
     * ช่องว่างไม่ถือว่าเป็นค่า — ไม่งั้นแอดมินที่เผลอเคาะ space ในช่องกรอก
     * จะปิดคีย์ใน `.env` ทิ้งโดยไม่รู้ตัว แล้วที่ปรึกษาก็เงียบไปเฉยๆ.
     */
    #[Test]
    public function ช่องว่างในหลังบ้านไม่ทับค่าใน_env(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_gemini_api_key', '   ');

        $this->assertSame('key-from-env', app(AdvisorSettings::class)->providerConfig('gemini')['api_key']);
    }

    /** คีย์ Gemini ตัวเดียวใช้ได้ทั้งช่องสร้างรูปและที่ปรึกษา — ไม่ต้องกรอกซ้ำ */
    #[Test]
    public function ตกไปใช้คีย์_gemini_ของช่องสร้างรูปได้(): void
    {
        config(['aibot_advisor.providers.gemini.api_key' => '']);
        SiteSetting::set('ai', 'gemini_api_key', 'key-shared-with-image-gen');

        $this->assertSame(
            'key-shared-with-image-gen',
            app(AdvisorSettings::class)->providerConfig('gemini')['api_key'],
        );
    }

    /**
     * ชื่อคีย์ต้องไม่ชนกับกลุ่มอื่น.
     *
     * หน้า /admin/settings แบน settings ทุกกลุ่มลงเป็น map ชั้นเดียวโดยใช้ key
     * เป็นดัชนี ถ้าตั้งชื่อซ้ำกับกลุ่มอื่นค่าจะทับกันเงียบๆ — คีย์สร้างรูปกับคีย์
     * ที่ปรึกษาจะกลายเป็นตัวเดียวกันโดยที่ไม่มีอะไรฟ้อง
     */
    #[Test]
    public function ชื่อคีย์ของที่ปรึกษาไม่ชนกับกลุ่ม_ai(): void
    {
        $aiGroupKeys = ['groq_api_key', 'groq_default_model', 'ai_chatbot_enabled', 'ai_content_enabled',
            'cloudflare_image_url', 'cloudflare_image_key',
            'together_api_key', 'huggingface_api_key', 'gemini_api_key'];

        $this->assertEmpty(array_intersect(AdvisorSettings::KEYS, $aiGroupKeys));
    }

    #[Test]
    public function ปิดสวิตช์ที่หลังบ้านแล้วได้_null_advisor(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_enabled', '0', 'boolean');

        $advisor = app(AdvisorFactory::class)->make('gemini');

        $this->assertInstanceOf(NullAdvisor::class, $advisor);
        $this->assertSame('ปิดระบบที่ปรึกษาไว้ชั่วคราว', $advisor->advise([])['reason']);
    }

    /** ปุ่มทดสอบต้องยิงได้แม้ปิดบริการ ไม่งั้นแอดมินตั้งค่าไม่ได้เลย */
    #[Test]
    public function ปิดบริการแล้วยังทดสอบคีย์ได้(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_enabled', '0', 'boolean');

        $this->assertInstanceOf(GeminiAdvisor::class, app(AdvisorFactory::class)->makeForTest('gemini'));
    }

    #[Test]
    public function ไม่มีคีย์ได้_null_advisor_พร้อมเหตุผลที่ตรง(): void
    {
        $advisor = app(AdvisorFactory::class)->make('openai');

        $this->assertInstanceOf(NullAdvisor::class, $advisor);
        $this->assertStringContainsString('ยังไม่ได้ตั้งคีย์', $advisor->advise([])['reason']);
    }

    /**
     * ใช้โควตาหมดต้องไม่ถูกรายงานว่า "ยังไม่ได้ตั้งคีย์".
     *
     * เดิมโรงงานตัดสินจาก isAvailable() ซึ่งเป็นเท็จได้สองสาเหตุ แล้วเหมารวมเป็น
     * สาเหตุเดียว — แอดมินจะไปนั่งแก้คีย์ที่ถูกอยู่แล้ว ทั้งที่ต้องรอวันใหม่เฉยๆ
     */
    #[Test]
    public function โควตาหมดยังคืนที่ปรึกษาตัวจริงไม่ใช่_null(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_gemini_max_calls', '1');
        cache()->put('aibot:advisor:gemini:'.now()->toDateString(), 5, now()->endOfDay());

        $advisor = app(AdvisorFactory::class)->make('gemini');

        $this->assertInstanceOf(GeminiAdvisor::class, $advisor);
        $this->assertFalse($advisor->isAvailable());
        $this->assertStringContainsString('โควตา', $advisor->advise([])['reason']);
    }

    #[Test]
    public function แพลนเสียเงินไปที่_openai_แพลนฟรีไปที่_gemini(): void
    {
        SiteSetting::set(AdvisorSettings::GROUP, 'advisor_openai_api_key', 'sk-test');

        $factory = app(AdvisorFactory::class);

        $this->assertInstanceOf(GeminiAdvisor::class, $factory->make($this->settings->providerForTier('free')));
        $this->assertInstanceOf(OpenAiAdvisor::class, $factory->make(app(AdvisorSettings::class)->providerForTier('pro')));
    }

    /**
     * ฐานข้อมูลอ่านไม่ได้ต้องไม่ทำให้บอทหยุดเทรด.
     *
     * ที่ปรึกษาเป็นของแถม ปล่อย exception ขึ้นไปเมื่อไหร่ = บอททั้งตัวหยุดเพราะ
     * ของแถมพัง ซึ่งเป็นความเสียหายที่ใหญ่กว่าการไม่มีคำแนะนำมาก
     */
    #[Test]
    public function ฐานข้อมูลล่มแล้วยังตกไปใช้_env_ได้(): void
    {
        Cache::flush();
        Schema::drop('site_settings');

        $config = app(AdvisorSettings::class)->providerConfig('gemini');

        $this->assertSame('key-from-env', $config['api_key']);
    }
}
