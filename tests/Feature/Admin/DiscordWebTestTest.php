<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Article;
use App\Models\DiscordPost;
use App\Services\ChatbotService;
use App\Services\Discord\DiscordContent;
use App\Services\Discord\DiscordPermissions;
use App\Services\Discord\DiscordSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * TPIX TRADE — ทดสอบบอท Discord ผ่านหน้าเว็บ (เจ้าของ: "ต้องทดสอบบอทผ่านหน้าเว็บได้เลย").
 *
 * ห้ามหลุด:
 *   1. ทุกปุ่มทดสอบไม่ส่งอะไรเข้า Discord (ไม่มี POST/PATCH ไป discord.com)
 *   2. ถามทดสอบใช้สมองตัวเดียวกับ /ถาม และจัดหน้าเหมือนของจริง (รวมการซ่อนลิงก์ภายนอก)
 *   3. ตรวจสิทธิ์ห้องจับห้องที่ @everyone ห้ามส่งข้อความได้ — เคสจริงที่ทำให้โพสต์ไม่ขึ้นตอนเปิดบอทครั้งแรก
 *
 * Developed by Xman Studio.
 */
class DiscordWebTestTest extends TestCase
{
    // ห้ามแต่งให้หน้าตาเหมือนโทเค็น Discord จริง — GitHub push protection บล็อกทั้ง push
    private const TOKEN = 'test_only.not_a_real_discord_token.0123456789abcdefghijklmnop';

    private const GUILD = '1485523516957659179';

    private const BOT = '1548972414015373382';

    private const BOT_ROLE = '700000000000000001';

    private const ANNOUNCEMENTS = '1485530965139918859';

    private const TUTORIALS = '1485531447304654919';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Sleep::fake();
        $this->withoutVite();

        $this->admin = AdminUser::create([
            'name' => 'เจ้าของ',
            'email' => 'owner@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $settings = app(DiscordSettings::class);
        $settings->saveBotToken(self::TOKEN);
        $settings->set('discord_guild_id', self::GUILD);
        $settings->saveChannelMap(['sale' => self::ANNOUNCEMENTS, 'news' => self::TUTORIALS]);
        $settings->mergeState(['bot_name' => 'TPIX TRADE', 'channels' => [
            ['id' => self::ANNOUNCEMENTS, 'name' => 'announcements', 'category' => 'INFORMATION'],
            ['id' => self::TUTORIALS, 'name' => 'tutorials', 'category' => 'SUPPORT'],
        ]]);
    }

    private function asOwner(): self
    {
        $this->actingAs($this->admin, 'admin');

        return $this;
    }

    private function assertNothingSentToDiscord(): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'discord.com'));
    }

    public function test_asking_on_the_web_uses_the_same_brain_and_formatting_without_posting(): void
    {
        config(['app.url' => 'https://tpix.online']);
        Http::fake();
        $this->mock(ChatbotService::class, fn ($mock) => $mock->shouldReceive('chat')->once()->with('ตอนนี้เปิดขายหรือยัง', 'th')->andReturn([
            'message' => 'ยังไม่เปิดขายอย่างเป็นทางการ ดูที่ http://fake-airdrop.example/claim',
            'navigation' => '/token-sale',
            'success' => true,
        ]));

        $response = $this->asOwner()->postJson('/admin/discord/test-ask', ['question' => 'ตอนนี้เปิดขายหรือยัง'])->assertOk();

        $content = $response->json('message.content');
        $this->assertTrue($response->json('success'));
        $this->assertStringContainsString('ตอนนี้เปิดขายหรือยัง', $content);
        $this->assertStringContainsString('ยังไม่เปิดขายอย่างเป็นทางการ', $content);
        $this->assertStringNotContainsString('fake-airdrop.example', $content);
        $this->assertSame('https://tpix.online/token-sale', $response->json('message.components.0.components.0.url'));
        $this->assertNothingSentToDiscord();
    }

    public function test_asking_needs_a_question_and_explains_in_thai(): void
    {
        $this->asOwner()->postJson('/admin/discord/test-ask', ['question' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.question.0', 'พิมพ์คำถามก่อน');
    }

    public function test_the_web_test_is_for_the_super_admin_only(): void
    {
        $staff = AdminUser::create([
            'name' => 'แอดมินทั่วไป',
            'email' => 'staff@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($staff, 'admin')->postJson('/admin/discord/test-ask', ['question' => 'ทดสอบ'])->assertForbidden();
        $this->actingAs($staff, 'admin')->getJson('/admin/discord/preview')->assertForbidden();
        $this->actingAs($staff, 'admin')->getJson('/admin/discord/permissions')->assertForbidden();
    }

    public function test_preview_shows_each_room_its_real_content_without_posting(): void
    {
        Http::fake(['*' => Http::response(['result' => '0x0'])]);
        Article::create([
            'title' => 'บทความทดสอบ',
            'summary' => 'สรุป',
            'content' => '<p>เนื้อหา</p>',
            'language' => 'th',
            'category' => 'news',
            'status' => 'published',
            'published_at' => now()->subHour(),
        ]);

        $items = collect($this->asOwner()->getJson('/admin/discord/preview')->assertOk()->json('items'))->keyBy('kind');

        $this->assertSame('new', $items['sale_status']['status']);
        $this->assertSame('announcements', $items['sale_status']['channel_name']);
        $this->assertStringContainsString('การขายเหรียญ', $items['sale_status']['payload']['embeds'][0]['title']);
        $this->assertSame('no_channel', $items['rules']['status']);
        $this->assertSame('tutorials', $items['article']['channel_name']);
        $this->assertSame('บทความทดสอบ', $items['article']['payload']['embeds'][0]['title']);
        $this->assertSame(0, DiscordPost::count());
        $this->assertNothingSentToDiscord();
    }

    public function test_preview_says_current_when_the_room_already_shows_this_content(): void
    {
        Http::fake(['*' => Http::response(['result' => '0x0'])]);
        DiscordPost::create([
            'kind' => 'sale_status',
            'ref_key' => 'main',
            'channel_id' => self::ANNOUNCEMENTS,
            'message_id' => '900000000000000001',
            'content_hash' => DiscordContent::hash(app(DiscordContent::class)->saleStatus()),
        ]);

        $items = collect($this->asOwner()->getJson('/admin/discord/preview')->json('items'))->keyBy('kind');

        $this->assertSame('current', $items['sale_status']['status']);
    }

    public function test_permission_check_finds_read_only_rooms_before_anything_fails(): void
    {
        Http::fake([
            'discord.com/api/v10/users/@me' => Http::response(['id' => self::BOT, 'username' => 'TPIX TRADE']),
            'discord.com/api/v10/guilds/'.self::GUILD.'/roles' => Http::response([
                ['id' => self::GUILD, 'name' => '@everyone', 'permissions' => (string) (1024 | 2048 | 16384 | 65536)],
                ['id' => self::BOT_ROLE, 'name' => 'TPIX TRADE', 'permissions' => (string) (1024 | 2048 | 16384 | 65536)],
            ]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/members/'.self::BOT => Http::response(['roles' => [self::BOT_ROLE]]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/channels' => Http::response([
                // ห้องประกาศ: @everyone ห้ามส่งข้อความ และบอทยังไม่มีข้อยกเว้น — สภาพจริงก่อนแก้
                ['id' => self::ANNOUNCEMENTS, 'name' => 'announcements', 'type' => 5, 'permission_overwrites' => [
                    ['id' => self::GUILD, 'type' => 0, 'allow' => '0', 'deny' => '2048'],
                ]],
                ['id' => self::TUTORIALS, 'name' => 'tutorials', 'type' => 0, 'permission_overwrites' => []],
            ]),
        ]);

        $rooms = collect($this->asOwner()->getJson('/admin/discord/permissions')->assertOk()->json('rooms'))->keyBy('name');

        $this->assertFalse($rooms['announcements']['send']);
        $this->assertSame(['Send Messages'], $rooms['announcements']['missing']);
        $this->assertTrue($rooms['tutorials']['ok']);
        Http::assertNotSent(fn (Request $r) => in_array($r->method(), ['POST', 'PATCH', 'PUT'], true));
    }

    public function test_permission_math_follows_discord_rules(): void
    {
        $perms = app(DiscordPermissions::class);
        $roles = [
            ['id' => self::GUILD, 'permissions' => (string) (1024 | 2048)],
            ['id' => self::BOT_ROLE, 'permissions' => '0'],
            ['id' => '700000000000000002', 'permissions' => (string) DiscordPermissions::ADMINISTRATOR],
        ];
        $everyoneDenySend = [['id' => self::GUILD, 'type' => 0, 'allow' => '0', 'deny' => '2048']];

        // @everyone ห้ามส่ง → บอทก็ส่งไม่ได้ ถ้าไม่มีข้อยกเว้นของยศตัวเอง
        $this->assertSame(0, $perms->compute($roles, [self::BOT_ROLE], self::GUILD, self::BOT, $everyoneDenySend) & DiscordPermissions::SEND_MESSAGES);

        // ข้อยกเว้นของยศบอท (allow) ชนะข้อห้ามของ @everyone — สิ่งที่แก้ไปใน #announcements #roadmap #faq
        $withRoleAllow = [...$everyoneDenySend, ['id' => self::BOT_ROLE, 'type' => 0, 'allow' => (string) (2048 | 16384), 'deny' => '0']];
        $this->assertSame(DiscordPermissions::SEND_MESSAGES, $perms->compute($roles, [self::BOT_ROLE], self::GUILD, self::BOT, $withRoleAllow) & DiscordPermissions::SEND_MESSAGES);

        // ข้อห้ามรายตัวของบอทชนะทุกอย่าง ยกเว้น Administrator
        $memberDeny = [...$withRoleAllow, ['id' => self::BOT, 'type' => 1, 'allow' => '0', 'deny' => '2048']];
        $this->assertSame(0, $perms->compute($roles, [self::BOT_ROLE], self::GUILD, self::BOT, $memberDeny) & DiscordPermissions::SEND_MESSAGES);
        $this->assertSame(DiscordPermissions::SEND_MESSAGES, $perms->compute($roles, ['700000000000000002'], self::GUILD, self::BOT, $memberDeny) & DiscordPermissions::SEND_MESSAGES);
    }
}
