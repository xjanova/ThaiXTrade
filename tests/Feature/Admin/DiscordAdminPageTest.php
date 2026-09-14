<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\SiteSetting;
use App\Services\Discord\DiscordGateway;
use App\Services\Discord\DiscordSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * TPIX TRADE — หลังบ้านบอท Discord (เจ้าของ: "โทเค็นให้ใส่ในหลังบ้านได้").
 *
 * ห้ามหลุด:
 *   1. โทเค็นเก็บแบบเข้ารหัส และไม่เคยถูกส่งกลับไปหน้าเว็บทั้งตัว
 *   2. เว้นช่องโทเค็นว่างตอนบันทึก = ใช้โทเค็นเดิม (ไม่ลบทิ้ง)
 *   3. เฉพาะ super_admin — โทเค็นบอท = โพสต์ในนามทีมงานถึงสมาชิกทั้งเซิร์ฟเวอร์
 *   4. "ทดสอบการเชื่อมต่อ" ดึงห้องจริงแล้วจัดห้องให้เอง
 *
 * Developed by Xman Studio.
 */
class DiscordAdminPageTest extends TestCase
{
    // ห้ามแต่งให้หน้าตาเหมือนโทเค็น Discord จริง (MTI…xxxxxx.xxx) — GitHub push protection บล็อกทั้ง push
    private const TOKEN = 'test_only.not_a_real_discord_token.0123456789abcdefghijklmnop';

    private const GUILD = '1485523516957659179';

    private const APP_ID = '666666666666666666';

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Sleep::fake();
        // หน้าแอดมินเรนเดอร์ผ่าน @vite — เทสต์ไม่ควรขึ้นกับว่า build หน้าเว็บไว้หรือยัง
        $this->withoutVite();

        $this->admin = AdminUser::create([
            'name' => 'เจ้าของ',
            'email' => 'owner@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function asOwner(): self
    {
        $this->actingAs($this->admin, 'admin');

        return $this;
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'enabled' => false,
            'ask_enabled' => true,
            'application_id' => self::APP_ID,
            'public_key' => str_repeat('ab', 32),
            'guild_id' => self::GUILD,
            'bot_token' => '',
            'rules_text' => '1. ห้ามสแปม',
            'ask_daily_cap' => 100,
            'ask_user_per_hour' => 5,
        ], $overrides);
    }

    public function test_the_token_is_stored_encrypted_and_only_its_last_four_characters_are_shown(): void
    {
        $this->asOwner()->put('/admin/discord', $this->form(['bot_token' => self::TOKEN]))->assertRedirect()->assertSessionHasNoErrors();

        $raw = SiteSetting::where('group', 'discord')->where('key', 'discord_bot_token')->value('value');
        $this->assertNotSame(self::TOKEN, $raw);
        $this->assertSame(self::TOKEN, Crypt::decryptString($raw));

        $this->asOwner()->get('/admin/discord')->assertInertia(fn ($page) => $page
            ->component('Admin/Discord/Index')
            ->where('settings.bot_token_masked', str_repeat('•', 12).substr(self::TOKEN, -4))
            ->where('settings.has_token', true)
            ->where('settings.guild_id', self::GUILD)
            ->where('interactionsUrl', rtrim((string) config('app.url'), '/').'/api/v1/discord/interactions'));

        $this->assertStringNotContainsString(self::TOKEN, $this->asOwner()->get('/admin/discord')->getContent());
    }

    public function test_saving_with_an_empty_token_field_keeps_the_existing_token(): void
    {
        app(DiscordSettings::class)->saveBotToken(self::TOKEN);

        $this->asOwner()->put('/admin/discord', $this->form(['rules_text' => 'กฎใหม่']))->assertSessionHasNoErrors();

        $this->assertSame(self::TOKEN, app(DiscordSettings::class)->botToken());
        $this->assertSame('กฎใหม่', app(DiscordSettings::class)->rulesText());
    }

    public function test_malformed_ids_are_rejected_with_a_thai_explanation(): void
    {
        $this->asOwner()
            ->put('/admin/discord', $this->form(['guild_id' => 'abc', 'public_key' => 'not-hex']))
            ->assertSessionHasErrors([
                'guild_id' => 'Guild ID ต้องเป็นตัวเลข 17-20 หลัก (คลิกขวาที่ชื่อเซิร์ฟเวอร์ → Copy Server ID)',
                'public_key',
            ]);
    }

    public function test_moving_to_another_server_clears_the_old_room_map(): void
    {
        $settings = app(DiscordSettings::class);
        $settings->set('discord_guild_id', self::GUILD);
        $settings->saveChannelMap(['rules' => '100000000000000001']);
        $settings->mergeState(['channels' => [['id' => '100000000000000001', 'name' => 'กฎ', 'category' => null]], 'guild_name' => 'เซิร์ฟเวอร์เก่า']);

        $this->asOwner()->put('/admin/discord', $this->form(['guild_id' => '999999999999999999']))->assertSessionHasNoErrors();

        $fresh = app(DiscordSettings::class);
        $this->assertSame([], $fresh->channelMap());
        $this->assertSame([], $fresh->state()['channels']);
        $this->assertSame('999999999999999999', $fresh->guildId());
    }

    public function test_only_the_super_admin_can_open_the_bot_settings(): void
    {
        $staff = AdminUser::create([
            'name' => 'แอดมินทั่วไป',
            'email' => 'staff@tpix.test',
            'password' => bcrypt('secret-password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($staff, 'admin')->get('/admin/discord')->assertForbidden();
        $this->actingAs($staff, 'admin')->put('/admin/discord', $this->form(['bot_token' => self::TOKEN]))->assertForbidden();
    }

    public function test_connecting_reads_the_real_channels_and_assigns_rooms_by_name(): void
    {
        app(DiscordSettings::class)->saveBotToken(self::TOKEN);
        app(DiscordSettings::class)->set('discord_guild_id', self::GUILD);

        $this->mock(DiscordGateway::class, fn ($mock) => $mock->shouldReceive('identify')->once()->andReturn(['ok' => true, 'username' => 'TPIX', 'guilds' => 1]));
        Http::fake([
            'discord.com/api/v10/users/@me' => Http::response(['id' => '777777777777777777', 'username' => 'TPIX Bot']),
            'discord.com/api/v10/guilds/'.self::GUILD.'/channels' => Http::response([
                ['id' => '100000000000000001', 'name' => '📜กฎ', 'type' => 0, 'position' => 1],
                ['id' => '100000000000000002', 'name' => '📢ประกาศ', 'type' => 5, 'position' => 2],
                ['id' => '100000000000000003', 'name' => 'ขายเหรียญ', 'type' => 0, 'position' => 3],
            ]),
            'discord.com/api/v10/guilds/'.self::GUILD => Http::response(['id' => self::GUILD, 'name' => 'TPIX Community']),
        ]);

        $this->asOwner()->post('/admin/discord/connect')->assertSessionHas('success');

        $settings = app(DiscordSettings::class);
        $this->assertSame('TPIX Bot', $settings->state()['bot_name']);
        $this->assertSame('TPIX Community', $settings->state()['guild_name']);
        $this->assertNotEmpty($settings->state()['identified_at']);
        $this->assertSame('100000000000000001', $settings->channelFor('rules'));
        $this->assertSame('100000000000000003', $settings->channelFor('sale'));
        $this->assertSame('100000000000000002', $settings->channelFor('news'));
    }

    public function test_registering_commands_creates_the_thai_slash_commands_in_the_server(): void
    {
        app(DiscordSettings::class)->saveBotToken(self::TOKEN);
        app(DiscordSettings::class)->set('discord_guild_id', self::GUILD);
        app(DiscordSettings::class)->set('discord_application_id', self::APP_ID);
        Http::fake(['discord.com/*' => Http::response([])]);

        $this->asOwner()->post('/admin/discord/commands')->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), '/applications/'.self::APP_ID.'/guilds/'.self::GUILD.'/commands')
            && $r[0]['name_localizations']['th'] === 'ถาม'
            && $r[1]['name_localizations']['th'] === 'ขายเหรียญ');
    }

    public function test_sync_now_refuses_while_the_bot_is_switched_off(): void
    {
        app(DiscordSettings::class)->saveBotToken(self::TOKEN);
        Http::fake();

        $this->asOwner()->post('/admin/discord/sync')->assertSessionHas('error');

        Http::assertNothingSent();
    }
}
