<?php

namespace Tests\Feature\Discord;

use App\Models\Chain;
use App\Models\DiscordModAction;
use App\Models\DiscordPost;
use App\Models\FactoryToken;
use App\Models\SiteSetting;
use App\Models\Token;
use App\Models\TradingPair;
use App\Services\Discord\DiscordContent;
use App\Services\Discord\DiscordLiveData;
use App\Services\Discord\DiscordPermissions;
use App\Services\Discord\DiscordPublisher;
use App\Services\Discord\DiscordSettings;
use App\Services\Discord\DiscordSetup;
use App\Services\TpixDexService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TPIX TRADE — บอท Discord ชุดอัปเกรด (เจ้าของ: "บอทเราเซ็ทให้เทพเลย" 2026-09-14).
 *
 * ห้ามหลุด:
 *   1. /ราคา /เชน /ลิงก์ ใช้ข้อมูลชุดเดียวกับหน้าเว็บ และบอกตรง ๆ เมื่ออ่านไม่ได้ / ยังไม่มีตลาดจริง
 *   2. /ลิงก์ แจกเฉพาะที่อยู่สัญญาที่มีโค้ดบนเชนจริง (เชนเคย regenesis แล้วที่อยู่ค้าง)
 *   3. รายงานข้อความ: เนื้อหาในการ์ดกดลิงก์ไม่ได้/ปิงไม่ได้ · รายงานซ้ำไม่สร้างการ์ดใหม่ · รายงานตัวเองไม่ได้
 *   4. ปุ่มตัดสินกดได้เฉพาะทีมงานที่มีสิทธิ์ · ไม่ลงโทษทีมงาน · กดรัวทำครั้งเดียว · ปุ่มปลอม/ต่างเซิร์ฟเวอร์ถูกปฏิเสธ
 *   5. การ์ดราคา/คู่มือโพสต์และปักหมุดครั้งเดียว · อ่านข้อมูลไม่ได้ = คงการ์ดเดิม
 *   6. แอปรุ่นใหม่ประกาศครั้งเดียวต่อรุ่น · คู่เทรดที่มีอยู่ก่อนเปิดฟีดไม่ถูกประกาศว่า "ใหม่"
 *
 * Developed by Xman Studio.
 */
class DiscordBotUpgradeTest extends TestCase
{
    // ห้ามแต่งให้หน้าตาเหมือนโทเค็น Discord จริง — GitHub push protection บล็อกทั้ง push
    private const TOKEN = 'test_only.not_a_real_discord_token.0123456789abcdefghijklmnop';

    private const APP_ID = '333333333333333333';

    private const GUILD = '1485523516957659179';

    private const OWNER = '600000000000000001';

    private const STAFF_ROLE = '700000000000000001';

    private const LOG = '800000000000000001';

    private const ROOM = '1485531040515883018';

    private const MSG = '1549000000000000001';

    private const AUTHOR = '900000000000000001';

    private const REPORTER = '900000000000000002';

    private const STAFF = '900000000000000003';

    private const ADMIN = '900000000000000009';

    private const INTERACTION_TOKEN = 'interaction-token-abcdefghijklmnopqrstuvwxyz';

    private string $secretKey;

    /** ข้อมูลสดที่ DiscordLiveData ปลอมจะคืน (เปลี่ยนระหว่างเทสต์ได้) */
    private array $live = [];

    private int $messageSeq = 1549100000000000000;

    /** บอทยังไม่มีสิทธิ์ Pin Messages */
    private bool $pinFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Sleep::fake();
        $this->withoutDefer();

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);

        $settings = app(DiscordSettings::class);
        $settings->set('discord_public_key', bin2hex(sodium_crypto_sign_publickey($keypair)));
        $settings->saveBotToken(self::TOKEN);
        $settings->set('discord_guild_id', self::GUILD);
        $settings->set('discord_application_id', self::APP_ID);
        $settings->set('discord_mod_log_channel', self::LOG);

        $this->live = [
            'price' => ['price' => 0.18, 'change_24h' => -3.456, 'high_24h' => 0.19, 'low_24h' => 0.17, 'market_cap' => 1260000000, 'circulating_supply' => 7000000000, 'source' => 'dex'],
            'chain' => ['connected' => true, 'block_height' => 1234567, 'validators' => 4, 'last_block_at' => time() - 2, 'masternodes' => ['total_nodes' => 12, 'validator_nodes' => 4, 'guardian_nodes' => 3, 'sentinel_nodes' => 5, 'light_nodes' => 0, 'registry_deployed' => true], 'chain_id' => 4289, 'rpc' => 'https://rpc.tpix.online', 'explorer' => 'https://explorer.tpix.online'],
            'releases' => [],
            'pairs' => [],
        ];
    }

    // ── ตัวช่วย ───────────────────────────────────────────────────────────

    private function fakeLive(): void
    {
        $this->mock(DiscordLiveData::class, function ($mock) {
            $mock->shouldReceive('price')->andReturnUsing(fn () => $this->live['price']);
            $mock->shouldReceive('chain')->andReturnUsing(fn () => $this->live['chain']);
            $mock->shouldReceive('releases')->andReturnUsing(fn () => $this->live['releases']);
            $mock->shouldReceive('dexPairs')->andReturnUsing(fn () => collect($this->live['pairs'])->map(fn ($s) => (object) ['symbol' => $s]));
            $mock->shouldReceive('network')->andReturn(['chain_id' => 4289, 'rpc' => 'https://rpc.tpix.online', 'explorer' => 'https://explorer.tpix.online']);
        });
    }

    private function fakeDiscord(): void
    {
        Http::fake([
            'discord.com/api/v10/webhooks/*' => Http::response(['id' => '1']),
            'discord.com/api/v10/channels/*/pins/*' => fn () => $this->pinFails ? Http::response(['message' => 'Missing Permissions', 'code' => 50013], 403) : Http::response([], 204),
            'discord.com/api/v10/channels/*/messages/*' => fn (Request $r) => $r->method() === 'DELETE' ? Http::response([], 204) : Http::response(['id' => 'edited']),
            'discord.com/api/v10/channels/*/messages' => fn () => Http::response(['id' => (string) $this->messageSeq++]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/members/*' => function (Request $r) {
                $id = basename((string) parse_url($r->url(), PHP_URL_PATH));

                return $r->method() === 'GET'
                    ? Http::response(['user' => ['id' => $id], 'roles' => $id === self::STAFF ? [self::STAFF_ROLE] : []])
                    : Http::response([], 204);
            },
            'discord.com/api/v10/guilds/'.self::GUILD.'/bans/*' => Http::response([], 204),
            'discord.com/api/v10/guilds/'.self::GUILD.'/roles' => Http::response([['id' => self::STAFF_ROLE, 'name' => 'Admin', 'permissions' => (string) (1 << 3)]]),
            'discord.com/api/v10/guilds/'.self::GUILD => Http::response(['id' => self::GUILD, 'owner_id' => self::OWNER]),
            '*' => Http::response(['result' => '0x0']),
        ]);
    }

    private function interact(array $payload): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        $timestamp = (string) time();

        return $this->call('POST', '/api/v1/discord/interactions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE_ED25519' => bin2hex(sodium_crypto_sign_detached($timestamp.$body, $this->secretKey)),
            'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        ], $body);
    }

    private function command(string $name): array
    {
        return ['type' => 2, 'application_id' => self::APP_ID, 'token' => self::INTERACTION_TOKEN, 'guild_id' => self::GUILD, 'member' => ['user' => ['id' => self::REPORTER]], 'data' => ['name' => $name, 'type' => 1]];
    }

    private function report(string $reporter = self::REPORTER, string $author = self::AUTHOR, string $content = 'free nitro here'): array
    {
        return [
            'type' => 2,
            'application_id' => self::APP_ID,
            'token' => self::INTERACTION_TOKEN,
            'guild_id' => self::GUILD,
            'channel_id' => self::ROOM,
            'member' => ['user' => ['id' => $reporter, 'username' => 'ผู้รายงาน'], 'permissions' => '0'],
            'data' => [
                'type' => 3,
                'name' => DiscordSetup::REPORT_COMMAND,
                'target_id' => self::MSG,
                'resolved' => ['messages' => [self::MSG => [
                    'id' => self::MSG,
                    'channel_id' => self::ROOM,
                    'author' => ['id' => $author, 'username' => 'scammer'],
                    'content' => $content,
                    'attachments' => [['id' => '1', 'filename' => 'qr.png']],
                ]]],
            ],
        ];
    }

    private function click(string $action, string $permissions, string $author = self::AUTHOR, string $guild = self::GUILD, ?string $customId = null): array
    {
        return [
            'type' => 3,
            'application_id' => self::APP_ID,
            'token' => self::INTERACTION_TOKEN,
            'guild_id' => $guild,
            'member' => ['user' => ['id' => self::ADMIN, 'username' => 'แอดมินนก'], 'permissions' => $permissions],
            'data' => ['custom_id' => $customId ?? "mod:{$action}:{$author}:".self::ROOM.':'.self::MSG, 'component_type' => 2],
            'message' => [
                'id' => '1549200000000000000',
                'embeds' => [[
                    'type' => 'rich',
                    'title' => '🚩 สมาชิกรายงานข้อความ',
                    'description' => "```\nfree nitro\n```",
                    'color' => 0xFF1744,
                    'content_scan_version' => 3,
                    'fields' => [['name' => 'ผู้เขียน', 'value' => "<@{$author}>", 'inline' => true]],
                    'footer' => ['text' => 'กดปุ่มด้านล่าง', 'proxy_icon_url' => 'https://x'],
                ]],
                'components' => DiscordContent::reportButtons(array_keys(DiscordContent::REPORT_ACTIONS), $author, self::ROOM, self::MSG),
            ],
        ];
    }

    /** @return Collection<int, Request> */
    private function sent(): Collection
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->values();
    }

    private function editedOriginal(): ?Request
    {
        return $this->sent()->last(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/messages/@original'));
    }

    /** @return list<string> */
    private function buttonIds(array $components): array
    {
        return collect($components)->flatMap(fn ($row) => $row['components'])->pluck('custom_id')->all();
    }

    // ── /ราคา /เชน /ลิงก์ ─────────────────────────────────────────────────

    public function test_price_answers_after_deferring_with_the_market_numbers(): void
    {
        $this->fakeLive();
        $this->fakeDiscord();

        $this->interact($this->command('price'))->assertOk()->assertExactJson(['type' => 5]);

        $embed = $this->editedOriginal()['embeds'][0];
        $this->assertSame('## $0.1800', $embed['description']);
        $fields = collect($embed['fields'])->pluck('value', 'name');
        $this->assertSame('🔴 -3.46%', $fields['เปลี่ยนแปลง 24 ชม.']);
        $this->assertSame('$1.26B', $fields['มูลค่าตามราคาตลาด']);
        $this->assertStringContainsString('TPIX DEX', $embed['footer']['text']);
        $this->assertNotEmpty($embed['timestamp'], '/ราคา ต้องบอกเวลาของข้อมูล');
    }

    public function test_a_reference_price_never_pretends_to_be_a_market(): void
    {
        $this->live['price'] = ['price' => 0.00012, 'change_24h' => 0, 'high_24h' => 0.0001224, 'low_24h' => 0.0001176, 'market_cap' => 840000, 'circulating_supply' => 7000000000, 'source' => 'admin'];
        $this->fakeLive();

        $embed = app(DiscordContent::class)->priceCard()['embeds'][0];

        $this->assertSame('## $0.0001200', $embed['description']);
        $this->assertNotContains('สูง / ต่ำ 24 ชม.', array_column($embed['fields'], 'name'), 'สูง/ต่ำของราคาอ้างอิงเป็นเลข ±2% ที่คำนวณขึ้น ห้ามโชว์');
        $this->assertStringContainsString('ยังไม่มีการซื้อขายจริง', $embed['footer']['text']);
        $this->assertArrayNotHasKey('timestamp', $embed, 'การ์ดประจำห้องห้ามมีเวลาปัจจุบัน ไม่งั้นถูกแก้ทุกรอบ');
    }

    public function test_price_says_so_when_it_cannot_be_read(): void
    {
        $this->live['price'] = null;
        $this->fakeLive();
        $this->fakeDiscord();

        $this->interact($this->command('price'))->assertOk();

        $this->assertStringContainsString('อ่านราคา TPIX ไม่ได้', $this->editedOriginal()['content']);
    }

    public function test_the_bot_reads_the_price_through_the_websites_own_api(): void
    {
        SiteSetting::set('trading', 'tpix_price', '0.25');
        Http::fake(['*' => Http::response(['error' => ['message' => 'down']], 500)]);

        $price = app(DiscordLiveData::class)->price();

        $this->assertSame(0.25, (float) $price['price']);
        $this->assertSame('admin', $price['source']);
    }

    public function test_chain_shows_blocks_validators_and_masternodes(): void
    {
        $this->fakeLive();
        $this->fakeDiscord();

        $this->interact($this->command('chain'))->assertOk()->assertExactJson(['type' => 5]);

        $embed = $this->editedOriginal()['embeds'][0];
        $this->assertStringContainsString('🟢 เชนทำงานปกติ', $embed['description']);
        $fields = collect($embed['fields'])->pluck('value', 'name');
        $this->assertSame('1,234,567', $fields['บล็อกล่าสุด']);
        $this->assertSame('4', $fields['Validator ที่ทำงาน']);
        $this->assertSame('12 (Validator 4 · Guardian 3 · Sentinel 5)', $fields['มาสเตอร์โหนดที่ทำงาน']);
    }

    public function test_chain_is_honest_when_the_chain_cannot_be_read(): void
    {
        Http::fake(['discord.com/*' => Http::response(['id' => '1']), '*' => Http::response('', 500)]);

        $chain = app(DiscordLiveData::class)->chain();
        $this->assertFalse($chain['connected'], 'เลขบล็อก 0 จากหน้าเว็บตอนต่อเชนไม่ได้ ต้องไม่ถูกแสดงว่าเชนหยุด');

        $this->interact($this->command('chain'))->assertOk();
        $this->assertStringContainsString('อ่านข้อมูลจากเชนไม่ได้', $this->editedOriginal()['embeds'][0]['description']);
    }

    public function test_links_only_hand_out_contract_addresses_that_exist_on_chain(): void
    {
        $live = '0x'.str_repeat('a', 40);
        $dead = '0x'.str_repeat('b', 40);
        SiteSetting::set('contracts', 'wtpix', $live);
        SiteSetting::set('contracts', 'dex_router', $dead);
        Http::fake([
            'discord.com/*' => Http::response(['id' => '1']),
            '*' => fn (Request $r) => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => ($r['method'] ?? '') === 'eth_getCode' && strtolower($r['params'][0] ?? '') === $live ? '0x6080604052' : '0x']),
        ]);

        $this->interact($this->command('links'))->assertOk()->assertExactJson(['type' => 5]);

        $embed = $this->editedOriginal()['embeds'][0];
        $contracts = collect($embed['fields'])->firstWhere('name', 'ที่อยู่สัญญาบนเชน TPIX (ตรวจแล้วว่ามีอยู่จริง)')['value'];
        $this->assertStringContainsString($live, $contracts);
        $this->assertStringNotContainsString($dead, $contracts, 'สัญญาที่ไม่มีโค้ดบนเชน = ห้ามแจกที่อยู่ (โอนเข้าแล้วหาย)');
        $this->assertStringContainsString('/swap', $embed['description']);
        $this->assertStringContainsString('กันมิจฉาชีพ', collect($embed['fields'])->pluck('name')->implode(' '));
    }

    public function test_every_new_command_is_registered_with_a_thai_name(): void
    {
        $commands = collect(DiscordSetup::commands())->keyBy('name');

        foreach (['ask' => 'ถาม', 'sale' => 'ขายเหรียญ', 'price' => 'ราคา', 'chain' => 'เชน', 'links' => 'ลิงก์'] as $name => $thai) {
            $this->assertSame($thai, $commands[$name]['name_localizations']['th']);
        }
        $this->assertSame(3, $commands[DiscordSetup::REPORT_COMMAND]['type'], 'รายงานข้อความเป็นเมนูคลิกขวาบนข้อความ');
        $this->assertSame('รายงานให้แอดมิน', $commands[DiscordSetup::REPORT_COMMAND]['name_localizations']['th']);
    }

    // ── รายงานข้อความ ───────────────────────────────────────────────────────

    public function test_a_report_lands_in_the_admin_room_as_a_card_that_cannot_ping_or_link(): void
    {
        $this->fakeDiscord();

        $this->interact($this->report(content: 'Free nitro ``` @everyone https://dlscord.gift/x'))
            ->assertOk()->assertExactJson(['type' => 5, 'data' => ['flags' => 64]]);

        $card = $this->sent()->first(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/channels/'.self::LOG.'/messages'));
        $this->assertNotNull($card, 'ต้องส่งการ์ดเข้าห้องแจ้งเตือนแอดมิน');
        $embed = $card['embeds'][0];
        $this->assertStringStartsWith("```\n", $embed['description']);
        $this->assertSame(2, substr_count($embed['description'], '```'), 'ห้ามให้ข้อความหลุดออกจากกล่องโค้ด (ลิงก์ฟิชชิงจะกดได้)');
        $this->assertStringContainsString('📎 มีไฟล์แนบ 1 ไฟล์', $embed['description']);
        $this->assertStringContainsString('/channels/'.self::GUILD.'/'.self::ROOM.'/'.self::MSG, $embed['url']);
        $this->assertSame(['parse' => []], $card['allowed_mentions']);
        $this->assertContains('mod:ban:'.self::AUTHOR.':'.self::ROOM.':'.self::MSG, $this->buttonIds($card['components']));

        $this->assertStringContainsString('ส่งให้แอดมินตรวจแล้ว', $this->editedOriginal()['content']);
    }

    public function test_a_message_is_reported_once_and_nobody_reports_themselves(): void
    {
        $this->fakeDiscord();

        $this->interact($this->report())->assertOk();
        $this->interact($this->report(reporter: '900000000000000005'))->assertJsonPath('data.content', 'มีคนรายงานข้อความนี้แล้ว แอดมินกำลังดูอยู่ ขอบคุณครับ');
        $this->interact($this->report(reporter: self::AUTHOR))->assertJsonPath('data.flags', 64);

        $this->assertCount(1, $this->sent()->filter(fn (Request $r) => str_ends_with($r->url(), '/channels/'.self::LOG.'/messages')));
    }

    public function test_reporting_waits_for_an_admin_room(): void
    {
        app(DiscordSettings::class)->set('discord_mod_log_channel', '');
        $this->fakeDiscord();

        $this->interact($this->report())->assertJsonPath('data.flags', 64);

        $this->assertFalse($this->sent()->contains(fn (Request $r) => $r->method() === 'POST'));
    }

    // ── ปุ่มตัดสินบนการ์ดรายงาน ──────────────────────────────────────────────

    public function test_only_staff_with_the_right_permission_can_press_a_decision(): void
    {
        $this->fakeDiscord();

        $this->interact($this->click('ban', '0'))->assertJsonPath('data.flags', 64);
        $this->interact($this->click('ban', (string) DiscordPermissions::KICK_MEMBERS))->assertJsonPath('data.flags', 64);

        $this->assertFalse($this->sent()->contains(fn (Request $r) => str_contains($r->url(), '/bans/')));
    }

    public function test_banning_from_the_card_bans_and_closes_the_case(): void
    {
        $this->fakeDiscord();

        $this->interact($this->click('ban', (string) DiscordPermissions::BAN_MEMBERS))->assertOk()->assertExactJson(['type' => 6]);

        $ban = $this->sent()->first(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/bans/'.self::AUTHOR));
        $this->assertNotNull($ban);
        $this->assertStringContainsString('ตัดสินโดย แอดมินนก', rawurldecode($ban->header('X-Audit-Log-Reason')[0]));
        $this->assertSame('manual', DiscordModAction::where('user_id', self::AUTHOR)->value('mode'));

        $edit = $this->editedOriginal();
        $this->assertSame([], $edit['components'], 'แบนแล้ว = จบเรื่อง ไม่เหลือปุ่ม');
        $last = collect($edit['embeds'][0]['fields'])->last();
        $this->assertStringContainsString('✅ แบนแล้ว', $last['value']);
        $this->assertStringContainsString('<@'.self::ADMIN.'>', $last['value']);
        $this->assertArrayNotHasKey('content_scan_version', $edit['embeds'][0], 'ห้ามส่งช่องอ่านอย่างเดียวกลับไป — Discord ปฏิเสธการแก้');
        $this->assertSame(['text' => 'กดปุ่มด้านล่าง'], $edit['embeds'][0]['footer']);
        $this->assertFalse($this->sent()->contains(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/channels/'.self::LOG.'/messages')), 'การ์ดเดิมบอกผลแล้ว ไม่โพสต์ซ้ำ');
    }

    public function test_deleting_the_message_keeps_the_other_decisions_available(): void
    {
        $this->fakeDiscord();

        $this->interact($this->click('delete', (string) DiscordPermissions::MANAGE_MESSAGES))->assertOk();

        $this->assertTrue($this->sent()->contains(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/channels/'.self::ROOM.'/messages/'.self::MSG)));
        $left = $this->buttonIds($this->editedOriginal()['components']);
        $this->assertSame(['timeout', 'kick', 'ban'], array_map(fn ($id) => explode(':', $id)[1], $left));
    }

    public function test_the_card_never_punishes_staff_and_the_buttons_stay_for_another_try(): void
    {
        $this->fakeDiscord();

        $this->interact($this->click('kick', (string) DiscordPermissions::ADMINISTRATOR, author: self::STAFF))->assertOk();

        $this->assertFalse($this->sent()->contains(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/members/')));
        $edit = $this->editedOriginal();
        $this->assertStringContainsString('❌', collect($edit['embeds'][0]['fields'])->last()['value']);
        $this->assertContains('mod:kick:'.self::STAFF.':'.self::ROOM.':'.self::MSG, $this->buttonIds($edit['components']));
    }

    public function test_a_double_click_acts_once(): void
    {
        $this->fakeDiscord();
        $perm = (string) DiscordPermissions::BAN_MEMBERS;

        $this->interact($this->click('ban', $perm))->assertExactJson(['type' => 6]);
        $this->interact($this->click('ban', $perm))->assertJsonPath('data.flags', 64);

        $this->assertCount(1, $this->sent()->filter(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/bans/')));
    }

    public function test_forged_or_foreign_buttons_are_refused(): void
    {
        $this->fakeDiscord();
        $admin = (string) DiscordPermissions::ADMINISTRATOR;

        $this->interact($this->click('ban', $admin, customId: 'mod:ban:'.self::AUTHOR.':../../x:'.self::MSG))->assertJsonPath('data.flags', 64);
        $this->interact($this->click('ban', $admin, guild: '111111111111111111'))->assertJsonPath('data.flags', 64);

        $this->assertFalse($this->sent()->contains(fn (Request $r) => str_contains($r->url(), 'discord.com')));
    }

    public function test_permission_bits_follow_discord_rules(): void
    {
        $this->assertTrue(DiscordPermissions::allows((string) (DiscordPermissions::ADMINISTRATOR | 1), DiscordPermissions::BAN_MEMBERS), 'Administrator ผ่านทุกข้อ');
        $this->assertTrue(DiscordPermissions::allows((string) DiscordPermissions::MODERATE_MEMBERS, DiscordPermissions::MODERATE_MEMBERS), 'บิตสูง (1<<40) ต้องไม่ล้น');
        $this->assertFalse(DiscordPermissions::allows('abc', DiscordPermissions::BAN_MEMBERS));
        $this->assertFalse(DiscordPermissions::allows('', DiscordPermissions::BAN_MEMBERS));
    }

    // ── การ์ดประจำห้อง · คู่มือ · ฟีด ───────────────────────────────────────

    private function mapRooms(array $roles): void
    {
        $settings = app(DiscordSettings::class);
        $settings->set('discord_enabled', true, 'boolean');
        $settings->set('discord_ask_enabled', false, 'boolean');
        $settings->saveChannelMap(collect($roles)->mapWithKeys(fn ($role, $i) => [$role => (string) (1485600000000000000 + $i)])->all());
    }

    private function sync(): array
    {
        app(DiscordSettings::class)->forget();

        return app(DiscordPublisher::class)->sync();
    }

    public function test_price_card_and_guides_are_posted_and_pinned_once(): void
    {
        $this->fakeLive();
        $this->fakeDiscord();
        $this->mapRooms(['price', 'help', 'dex', 'bugs', 'ideas', 'intro']);

        $this->sync();
        $firstPosts = $this->sent()->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/channels/148560'))->count();
        $pins = $this->sent()->filter(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/pins/'));
        $before = count(Http::recorded());

        $this->sync();

        $this->assertSame(6, $firstPosts);
        $this->assertCount(6, $pins, 'การ์ดราคา + คู่มือ 5 ห้องต้องปักหมุด');
        $this->assertSame('', $pins->first()->body(), 'ปักหมุดไม่มี body');
        $this->assertFalse($this->sent()->slice($before)->contains(fn (Request $r) => str_contains($r->url(), 'discord.com')), 'รอบสองข้อมูลเท่าเดิม = ไม่แตะ Discord');
        $this->assertTrue(DiscordPost::where('kind', 'guide_help')->whereNotNull('message_id')->exists());
    }

    public function test_a_pin_that_failed_is_retried_on_the_next_sync_until_it_sticks(): void
    {
        $this->pinFails = true; // Discord ย้ายการปักหมุดไปสิทธิ์ใหม่ บอทยังไม่ได้สิทธิ์นั้น
        $this->fakeLive();
        $this->fakeDiscord();
        $this->mapRooms(['help']);
        $pins = fn () => $this->sent()->filter(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->url(), '/messages/pins/'))->count();

        $this->sync();
        $this->assertSame(1, $pins());
        $this->assertNull(DiscordPost::where('kind', 'guide_help')->value('pinned_at'), 'ปักไม่ติดต้องไม่จดว่าปักแล้ว');

        $this->pinFails = false; // เจ้าของให้สิทธิ์แล้ว
        $this->sync();
        $this->assertSame(2, $pins(), 'รอบถัดไปต้องปักข้อความเดิมให้ แม้เนื้อหาไม่เปลี่ยน');
        $this->assertNotNull(DiscordPost::where('kind', 'guide_help')->value('pinned_at'));

        $this->sync();
        $this->assertSame(2, $pins(), 'ปักแล้วไม่ปักซ้ำทุกรอบ');
        $this->assertSame(1, $this->sent()->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/channels/'))->count(), 'ไม่โพสต์คู่มือซ้ำ');
    }

    public function test_the_price_card_hides_numbers_it_could_not_read(): void
    {
        $this->live['price'] = ['price' => 0.18, 'change_24h' => 0, 'high_24h' => 0.1836, 'low_24h' => 0.1764, 'market_cap' => 0, 'circulating_supply' => 0, 'source' => 'admin'];
        $this->fakeLive();

        $names = array_column(app(DiscordContent::class)->priceCard()['embeds'][0]['fields'], 'name');

        $this->assertNotContains('มูลค่าตามราคาตลาด', $names, 'อ่านอุปทานไม่ได้ = ห้ามประกาศมูลค่า $0.00');
        $this->assertNotContains('อุปทานหมุนเวียน', $names);
    }

    public function test_release_notes_drop_github_tables_and_missing_download_hints(): void
    {
        $this->fakeLive();

        $embed = app(DiscordContent::class)->release([
            'product' => 'trade', 'label' => 'แอป TPIX TRADE (Android)', 'version' => '1.1.169', 'name' => 'v1.1.169', 'published_at' => null,
            'notes' => "Android APK (Flutter)\n| | |\n|---|---|\n| Version | v1.1.169 |\n\nHighlights\n- Thai/English language support\n\nInstall\n- Download TPIX-TRADE-v1.1.169.apk below\n- Open on Android device",
        ])['embeds'][0];

        $this->assertStringNotContainsString('|', $embed['description']);
        $this->assertStringNotContainsString('below', $embed['description']);
        $this->assertStringContainsString('Thai/English language support', $embed['description']);
    }

    public function test_optional_cards_without_a_room_are_skipped_quietly(): void
    {
        $this->fakeLive();
        $this->fakeDiscord();
        $this->mapRooms(['news']);

        $results = collect($this->sync()['results']);

        $this->assertFalse($results->contains(fn ($r) => str_starts_with($r['kind'], 'guide_') || in_array($r['kind'], ['price_card', 'dex_pairs'], true)));
    }

    public function test_the_price_card_stays_put_when_the_price_cannot_be_read(): void
    {
        $this->fakeLive();
        $this->fakeDiscord();
        $this->mapRooms(['price']);
        $this->sync();

        $this->live['price'] = null;
        $before = count(Http::recorded());
        $results = collect($this->sync()['results']);

        $this->assertSame('skipped', $results->firstWhere('kind', 'price_card')['action']);
        $this->assertFalse($this->sent()->slice($before)->contains(fn (Request $r) => in_array($r->method(), ['PATCH', 'POST'], true)), 'ห้ามทับการ์ดราคาด้วยข้อความ error');
    }

    public function test_a_price_move_edits_the_same_card(): void
    {
        $this->fakeLive();
        $this->fakeDiscord();
        $this->mapRooms(['price']);
        $this->sync();

        $this->live['price']['price'] = 0.2;
        $before = count(Http::recorded());
        $this->sync();

        $calls = $this->sent()->slice($before)->filter(fn (Request $r) => str_contains($r->url(), 'discord.com'));
        $this->assertCount(1, $calls);
        $this->assertSame('PATCH', $calls->first()->method());
        $this->assertSame('## $0.2000', $calls->first()['embeds'][0]['description']);
    }

    public function test_each_app_version_is_announced_once(): void
    {
        $this->live['releases'] = [['product' => 'trade', 'label' => 'แอป TPIX TRADE (Android)', 'version' => '1.2.3', 'name' => 'v1.2.3', 'notes' => 'แก้บั๊ก ดู https://evil.example/x', 'published_at' => '2026-09-14T10:00:00Z']];
        $this->fakeLive();
        $this->fakeDiscord();
        $this->mapRooms(['dev']);

        $this->sync();
        $this->sync();
        $this->live['releases'][0]['version'] = '1.2.4';
        $this->sync();

        $posts = $this->sent()->filter(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/channels/1485600000000000000/messages'))->values();
        $this->assertCount(2, $posts);
        $this->assertStringContainsString('เวอร์ชัน 1.2.3', $posts[0]['embeds'][0]['title']);
        $this->assertStringContainsString('[ลิงก์ภายนอกถูกซ่อน]', $posts[0]['embeds'][0]['description'], 'ลิงก์นอกเว็บในบันทึกรุ่นห้ามหลุดไปในนามบอท');
        $this->assertStringEndsWith('/download', $posts[0]['components'][0]['components'][0]['url']);
        $this->assertStringContainsString('เวอร์ชัน 1.2.4', $posts[1]['embeds'][0]['title']);
    }

    public function test_the_bot_never_promotes_unverified_or_lookalike_tokens(): void
    {
        $chain = Chain::create(['chain_id' => 4289, 'name' => 'TPIX Chain', 'symbol' => 'TPIX', 'rpc_url' => 'https://rpc.example', 'native_currency_name' => 'TPIX', 'native_currency_symbol' => 'TPIX', 'is_active' => true]);
        $token = fn (string $symbol, string $address) => Token::create(['chain_id' => $chain->id, 'symbol' => $symbol, 'name' => $symbol, 'decimals' => 18, 'is_active' => true, 'contract_address' => $address]);
        $pair = fn (string $symbol, $base, $quote) => TradingPair::create(['symbol' => $symbol, 'base_token_id' => $base->id, 'quote_token_id' => $quote->id, 'chain_id' => $chain->id, 'is_active' => true, 'execution_mode' => 'onchain']);

        $tpix = $token('TPIX', TpixDexService::ZERO);
        $usdt = $token('USDT', '0x'.str_repeat('1', 40));
        $good = $token('GOOD', '0x'.str_repeat('2', 40));
        $fake = $token('USDT', '0x'.str_repeat('3', 40)); // มีคนสร้างเหรียญชื่อ USDT ปลอม
        $weird = $token('[FREE](X)', '0x'.str_repeat('4', 40));
        FactoryToken::create(['name' => 'Good', 'symbol' => 'GOOD', 'total_supply' => '1000', 'creator_address' => '0x'.str_repeat('9', 40), 'contract_address' => '0x'.str_repeat('2', 40), 'status' => 'deployed', 'is_verified' => true]);
        FactoryToken::create(['name' => 'Weird', 'symbol' => 'W', 'total_supply' => '1000', 'creator_address' => '0x'.str_repeat('9', 40), 'contract_address' => '0x'.str_repeat('4', 40), 'status' => 'deployed', 'is_verified' => true]);

        $pair('TPIX-USDT', $tpix, $usdt);
        $pair('GOOD-TPIX', $good, $tpix);
        $pair('USDT-TPIX', $fake, $tpix);
        $pair('[FREE](X)-TPIX', $weird, $tpix);

        $this->assertSame(['GOOD-TPIX', 'TPIX-USDT'], app(DiscordLiveData::class)->dexPairs()->pluck('symbol')->all());
    }

    public function test_only_pairs_that_appear_after_the_feed_started_are_called_new(): void
    {
        $this->live['pairs'] = ['ABC-TPIX'];
        $this->fakeLive();
        $this->fakeDiscord();
        $this->mapRooms(['listings']);

        $this->sync();
        $firstRound = $this->sent()->filter(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/channels/'))->values();

        $this->live['pairs'] = ['ABC-TPIX', 'XYZ-TPIX'];
        $before = count(Http::recorded());
        $this->sync();
        $secondRound = $this->sent()->slice($before)->filter(fn (Request $r) => str_contains($r->url(), 'discord.com'))->values();

        $this->assertCount(1, $firstRound, 'รอบแรกมีแค่การ์ดรายการคู่เทรด ไม่ประกาศว่าคู่ที่มีอยู่แล้วเป็นคู่ใหม่');
        $this->assertStringContainsString('ABC/TPIX', $firstRound[0]['embeds'][0]['description']);
        $this->assertTrue($secondRound->contains(fn (Request $r) => $r->method() === 'POST' && str_contains($r['embeds'][0]['title'] ?? '', 'XYZ/TPIX')));
        $this->assertTrue($secondRound->contains(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r['embeds'][0]['description'], 'XYZ/TPIX')), 'การ์ดรายการคู่เทรดต้องอัปเดตตาม');
        $this->assertFalse($secondRound->contains(fn (Request $r) => $r->method() === 'POST' && str_contains($r['embeds'][0]['title'] ?? '', 'ABC/TPIX')));
    }
}
