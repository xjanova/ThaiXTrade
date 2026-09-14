<?php

namespace Tests\Feature\Discord;

use App\Models\AdminUser;
use App\Models\DiscordModAction;
use App\Models\DiscordModStrike;
use App\Services\Discord\DiscordAutoMod;
use App\Services\Discord\DiscordModerator;
use App\Services\Discord\DiscordSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * TPIX TRADE — บอทดูแลห้อง (เจ้าของ: "บอทควบคุมห้อง จัดการ แบน เตะ คนได้หากมีแนวโน้มไม่ดี").
 *
 * ห้ามหลุด:
 *   1. คนทำผิดซ้ำถูกไล่ระดับ ปิดเสียง → เตะ → แบน พร้อมเหตุผลใน audit log ของ Discord
 *   2. ทีมงาน / เจ้าของเซิร์ฟเวอร์ / บอทตัวอื่น ไม่ถูกลงโทษอัตโนมัติเด็ดขาด
 *   3. อ่าน audit log ซ้อนกันไม่นับความผิดซ้ำ ไม่แบนซ้ำ · ไม่ย้อนลงโทษของเก่าก่อนเปิดระบบ
 *   4. โหมดแจ้งเตือนไม่แตะสมาชิก — และเมื่อเปลี่ยนเป็นลงโทษจริง คนที่เคยถูกแจ้งไว้ต้องโดนจริง
 *   5. เพดานแบนต่อวันหยุดกฎที่ทำงานผิดก่อนแบนคนดีทั้งห้อง
 *   6. ติดตั้ง AutoMod แตะเฉพาะกฎ "TPIX •" ไม่แตะกฎของแอดมิน
 *
 * Developed by Xman Studio.
 */
class DiscordModerationTest extends TestCase
{
    // ห้ามแต่งให้หน้าตาเหมือนโทเค็น Discord จริง — GitHub push protection บล็อกทั้ง push
    private const TOKEN = 'test_only.not_a_real_discord_token.0123456789abcdefghijklmnop';

    private const GUILD = '1485523516957659179';

    private const OWNER = '600000000000000001';

    private const STAFF_ROLE = '700000000000000001';

    private const MEMBER_ROLE = '700000000000000002';

    private const LOG = '800000000000000001';

    private const BAD = '900000000000000001';

    private const BAD2 = '900000000000000002';

    private const STAFF = '900000000000000003';

    private const OTHER_BOT = '900000000000000004';

    private const SCAM = 'TPIX • กันมิจฉาชีพ'; // น้ำหนัก 5

    private const PROFANITY = 'TPIX • คำหยาบภาษาไทย'; // น้ำหนัก 1

    /** audit log ที่ Discord จะตอบรอบถัดไป (เปลี่ยนระหว่างเทสต์ได้ — Http::fake ซ้อนกันแล้วตัวแรกชนะเสมอ) */
    private array $auditEntries = [];

    /** @var array<string, array{roles: list<string>, bot?: bool}> สมาชิกที่ยังอยู่ในเซิร์ฟเวอร์ */
    private array $members = [];

    /** Discord ล่ม/ตอบช้า ตอนถามข้อมูลสมาชิก */
    private bool $membersDown = false;

    private int $auditSeq;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Sleep::fake();

        // audit id เป็น snowflake — เวลาต้องเป็น "ตอนนี้" ไม่งั้นหลุดช่วงนับคะแนน 7 วัน
        $this->auditSeq = $this->snowflakeAt(now());

        $this->members = [
            self::BAD => ['roles' => [self::MEMBER_ROLE]],
            self::BAD2 => ['roles' => [self::MEMBER_ROLE]],
            self::STAFF => ['roles' => [self::STAFF_ROLE]],
            self::OWNER => ['roles' => []],
            self::OTHER_BOT => ['roles' => [], 'bot' => true],
        ];

        $settings = app(DiscordSettings::class);
        $settings->saveBotToken(self::TOKEN);
        $settings->set('discord_guild_id', self::GUILD);
        $settings->set('discord_mod_mode', DiscordModerator::MODE_ENFORCE);
        $settings->set('discord_mod_log_channel', self::LOG);
        $settings->mergeState(['mod_counting_since' => now()->subHour()->toIso8601String()]);
    }

    private function snowflakeAt(\DateTimeInterface $at): int
    {
        return ((int) ($at->format('U') * 1000) - 1420070400000) << 22;
    }

    private function strike(string $userId, string $rule = self::SCAM, ?int $id = null): array
    {
        return [
            'id' => (string) ($id ?? $this->auditSeq++),
            'action_type' => 143,
            'target_id' => $userId,
            'user_id' => $userId,
            'options' => ['auto_moderation_rule_name' => $rule, 'auto_moderation_rule_trigger_type' => '1', 'channel_id' => '1485531040515883018'],
        ];
    }

    private function fakeDiscord(): void
    {
        Http::fake([
            'discord.com/api/v10/guilds/'.self::GUILD.'/audit-logs*' => fn () => Http::response(['audit_log_entries' => $this->auditEntries]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/members/*' => function (Request $r) {
                $id = basename((string) parse_url($r->url(), PHP_URL_PATH));
                if ($this->membersDown) {
                    return Http::response(['message' => 'Service Unavailable'], 503);
                }
                if (! isset($this->members[$id])) {
                    return Http::response(['message' => 'Unknown Member', 'code' => 10007], 404);
                }

                return $r->method() === 'GET'
                    ? Http::response(['user' => ['id' => $id, 'bot' => $this->members[$id]['bot'] ?? false], 'roles' => $this->members[$id]['roles']])
                    : Http::response([], 204);
            },
            'discord.com/api/v10/guilds/'.self::GUILD.'/roles' => Http::response([
                ['id' => self::GUILD, 'name' => '@everyone', 'permissions' => '0', 'position' => 0],
                ['id' => self::STAFF_ROLE, 'name' => 'Admin', 'permissions' => (string) (1 << 3), 'position' => 5],
                ['id' => self::MEMBER_ROLE, 'name' => 'Member', 'permissions' => '0', 'position' => 1],
            ]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/bans/*' => Http::response([], 204),
            'discord.com/api/v10/guilds/'.self::GUILD => Http::response(['id' => self::GUILD, 'owner_id' => self::OWNER]),
            'discord.com/api/v10/channels/'.self::LOG.'/messages' => Http::response(['id' => '1']),
        ]);
    }

    private function moderate(array $entries): array
    {
        $this->auditEntries = $entries;

        return app(DiscordModerator::class)->run();
    }

    /** @return Collection<int, Request> */
    private function sent(): Collection
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])->values();
    }

    /** คำขอที่แตะตัวสมาชิก (ปิดเสียง / เตะ / แบน) */
    private function punishments(): Collection
    {
        return $this->sent()->filter(fn (Request $r) => in_array($r->method(), ['PUT', 'DELETE', 'PATCH'], true))->values();
    }

    private function isBanOf(string $userId): \Closure
    {
        return fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/bans/'.$userId);
    }

    private function logMessages(): Collection
    {
        return $this->sent()->filter(fn (Request $r) => str_ends_with($r->url(), '/channels/'.self::LOG.'/messages'))->values();
    }

    public function test_a_repeat_scammer_is_banned_with_the_reason_in_the_audit_log(): void
    {
        $this->fakeDiscord();

        $outcome = $this->moderate([$this->strike(self::BAD), $this->strike(self::BAD)]); // 5 + 5 = 10 = แบน

        $this->assertSame(2, $outcome['strikes']);
        $ban = $this->punishments()->first($this->isBanOf(self::BAD));
        $this->assertNotNull($ban, 'ต้องแบนคนหลอกที่ถูกจับซ้ำ');
        $this->assertSame(86400, $ban['delete_message_seconds']);
        // เหตุผลภาษาไทยต้อง URL-encode (Discord ไม่รับ header ที่ไม่ใช่ ASCII)
        $this->assertStringStartsWith('TPIX%20bot%20', $ban->header('X-Audit-Log-Reason')[0]);
        $this->assertStringContainsString('AutoMod', rawurldecode($ban->header('X-Audit-Log-Reason')[0]));
        $this->assertSame('done', DiscordModAction::where('user_id', self::BAD)->value('status'));

        $log = $this->logMessages()->first();
        $this->assertStringContainsString('<@'.self::BAD.'>', $log['content']);
        $this->assertSame(['parse' => []], $log['allowed_mentions'], 'แจ้งห้องแอดมินต้องไม่ปิงใคร');
    }

    public function test_the_ladder_goes_timeout_then_kick(): void
    {
        $this->fakeDiscord();
        $swear = fn () => $this->strike(self::BAD, self::PROFANITY);

        $this->moderate([$swear(), $swear(), $swear()]); // คะแนน 3 → ปิดเสียง

        $timeout = $this->punishments()->first(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertNotNull($timeout, 'คะแนน 3 ต้องปิดเสียง');
        $this->assertTrue(now()->addMinutes(1439)->lt($timeout['communication_disabled_until']), 'ปิดเสียง 24 ชม.');

        $this->moderate([$swear(), $swear(), $swear()]); // คะแนน 6 → เตะ

        $this->assertTrue($this->punishments()->contains(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/members/'.self::BAD)));
        $this->assertSame(['timeout', 'kick'], DiscordModAction::where('user_id', self::BAD)->orderBy('id')->pluck('action')->all());
    }

    public function test_reading_the_same_audit_entries_again_never_counts_or_bans_twice(): void
    {
        $this->fakeDiscord();
        $entries = [$this->strike(self::BAD), $this->strike(self::BAD)];

        $this->moderate($entries);
        $second = $this->moderate($entries); // Discord ส่งรายการเดิมมาอีก

        $this->assertSame(0, $second['strikes']);
        $this->assertSame(2, DiscordModStrike::count());
        $this->assertSame(1, DiscordModAction::where('action', 'ban')->count());
        $this->assertCount(1, $this->punishments()->filter($this->isBanOf(self::BAD)));

        // รอบถัดไปขอเฉพาะรายการที่ใหม่กว่าตัวล่าสุด
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/audit-logs?') && str_contains($r->url(), 'after='.$entries[1]['id']));
    }

    public function test_offences_from_before_moderation_was_switched_on_are_not_punished(): void
    {
        $this->fakeDiscord();
        $twoHoursAgo = $this->snowflakeAt(now()->subHours(2));

        $outcome = $this->moderate([$this->strike(self::BAD, self::SCAM, $twoHoursAgo), $this->strike(self::BAD, self::SCAM, $twoHoursAgo + 1)]);

        $this->assertSame(0, $outcome['strikes']);
        $this->assertCount(0, $this->punishments());
        // แต่ยังเลื่อนตำแหน่งอ่าน — รอบหน้าไม่ต้องอ่านของเก่าอีก
        $this->assertSame((string) ($twoHoursAgo + 1), app(DiscordSettings::class)->state()['mod_last_audit_id']);
    }

    public function test_the_first_run_ever_starts_counting_from_now(): void
    {
        app(DiscordSettings::class)->mergeState(['mod_counting_since' => null]);
        $this->fakeDiscord();
        $tenMinutesAgo = $this->snowflakeAt(now()->subMinutes(10));

        $outcome = $this->moderate([$this->strike(self::BAD, self::SCAM, $tenMinutesAgo), $this->strike(self::BAD, self::SCAM, $tenMinutesAgo + 1)]);

        $this->assertSame(0, $outcome['strikes']);
        $this->assertCount(0, $this->punishments());
        $this->assertNotEmpty(app(DiscordSettings::class)->state()['mod_counting_since']);
    }

    public function test_staff_the_server_owner_and_other_bots_are_never_punished(): void
    {
        $this->fakeDiscord();

        $this->moderate([
            $this->strike(self::STAFF), $this->strike(self::STAFF),
            $this->strike(self::OWNER), $this->strike(self::OWNER),
            $this->strike(self::OTHER_BOT), $this->strike(self::OTHER_BOT),
        ]);

        $this->assertCount(0, $this->punishments());
        foreach ([self::STAFF, self::OWNER, self::OTHER_BOT] as $id) {
            $this->assertSame('skipped', DiscordModAction::where('user_id', $id)->value('status'), "ต้องไม่ลงโทษ {$id}");
        }

        // แอดมินกดเองก็ไม่ได้ — บอทปฏิเสธพร้อมเหตุผลภาษาไทย
        $manual = app(DiscordModerator::class)->manual(self::OWNER, 'ban', 'ทดสอบ');
        $this->assertFalse($manual['ok']);
        $this->assertStringContainsString('เจ้าของเซิร์ฟเวอร์', $manual['message']);
        $this->assertCount(0, $this->punishments());
    }

    public function test_when_discord_cannot_confirm_who_someone_is_nobody_is_punished_until_it_can(): void
    {
        $this->membersDown = true;
        $this->fakeDiscord();

        $this->moderate([$this->strike(self::BAD), $this->strike(self::BAD)]);

        $this->assertCount(0, $this->punishments(), 'ตรวจไม่ได้ว่าเป็นทีมงานไหม = ห้ามลงโทษ');
        $this->assertSame('failed', DiscordModAction::where('user_id', self::BAD)->value('status'));

        // Discord กลับมาแล้ว คนเดิมทำผิดอีก → ต้องไม่รอด (ไม่ใช่ถูกบันทึกว่า "ข้าม" ไป 7 วัน)
        $this->membersDown = false;
        $this->moderate([$this->strike(self::BAD)]);

        $this->assertTrue($this->punishments()->contains($this->isBanOf(self::BAD)));
    }

    public function test_observe_mode_only_announces_and_enforce_later_really_acts(): void
    {
        app(DiscordSettings::class)->set('discord_mod_mode', DiscordModerator::MODE_OBSERVE);
        $this->fakeDiscord();

        $this->moderate([$this->strike(self::BAD), $this->strike(self::BAD)]);

        $this->assertCount(0, $this->punishments());
        $this->assertStringContainsString('โหมดแจ้งเตือน', $this->logMessages()->first()['content']);

        // เจ้าของเปิดลงโทษจริง แล้วคนเดิมทำผิดอีก → ต้องโดนแบนจริง (ไม่ถูกบังด้วยบันทึกของโหมดแจ้งเตือน)
        app(DiscordSettings::class)->set('discord_mod_mode', DiscordModerator::MODE_ENFORCE);
        $this->moderate([$this->strike(self::BAD)]);

        $this->assertTrue($this->punishments()->contains($this->isBanOf(self::BAD)));
    }

    public function test_the_daily_ban_cap_stops_a_misfiring_rule_and_tells_the_admins_once(): void
    {
        app(DiscordSettings::class)->set('discord_mod_max_bans_per_day', 1, 'number');
        $this->fakeDiscord();

        $this->moderate([$this->strike(self::BAD), $this->strike(self::BAD), $this->strike(self::BAD2), $this->strike(self::BAD2)]);
        $this->moderate([$this->strike(self::BAD2)]); // ยังทำผิดต่อระหว่างรอแอดมิน

        $this->assertCount(1, $this->punishments()->filter(fn (Request $r) => str_contains($r->url(), '/bans/')));
        $this->assertSame(2, DiscordModAction::where('user_id', self::BAD2)->where('status', 'capped')->count());
        $this->assertCount(1, $this->logMessages()->filter(fn (Request $r) => str_contains($r['content'], 'เพดาน')), 'แจ้งเพดานคนละครั้งพอ');
    }

    public function test_a_zero_ban_cap_means_the_bot_never_bans_by_itself(): void
    {
        app(DiscordSettings::class)->set('discord_mod_max_bans_per_day', 0, 'number');
        $this->fakeDiscord();

        $this->moderate([$this->strike(self::BAD), $this->strike(self::BAD)]);

        $this->assertFalse($this->punishments()->contains($this->isBanOf(self::BAD)));
        $this->assertSame('capped', DiscordModAction::where('user_id', self::BAD)->value('status'));
    }

    public function test_someone_who_already_left_can_still_be_banned(): void
    {
        unset($this->members[self::BAD]);
        $this->fakeDiscord();

        $this->moderate([$this->strike(self::BAD), $this->strike(self::BAD)]);

        $this->assertTrue($this->punishments()->contains($this->isBanOf(self::BAD)));
    }

    public function test_nothing_happens_while_moderation_is_off(): void
    {
        app(DiscordSettings::class)->set('discord_mod_mode', DiscordModerator::MODE_OFF);
        Http::fake();

        $this->assertFalse(app(DiscordModerator::class)->run()['ran']);
        Http::assertNothingSent();
    }

    public function test_the_scheduled_command_runs_the_moderator(): void
    {
        $this->fakeDiscord();
        $this->auditEntries = [$this->strike(self::BAD), $this->strike(self::BAD)];

        $this->artisan('discord:moderate')->assertSuccessful();

        $this->assertTrue($this->punishments()->contains($this->isBanOf(self::BAD)));
    }

    public function test_automod_install_touches_only_tpix_rules_and_exempts_staff(): void
    {
        Http::fake([
            'discord.com/api/v10/guilds/'.self::GUILD.'/auto-moderation/rules' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response([
                    ['id' => '400000000000000001', 'name' => 'กฎของแอดมิน', 'trigger_type' => 1],
                    ['id' => '400000000000000002', 'name' => 'Owner spam filter', 'trigger_type' => 3],
                    ['id' => '400000000000000003', 'name' => self::PROFANITY, 'trigger_type' => 1], // ของเราที่ติดตั้งไว้แล้ว
                ])
                : Http::response(['id' => (string) $this->auditSeq++]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/auto-moderation/rules/*' => Http::response(['id' => '400000000000000003']),
            'discord.com/api/v10/guilds/'.self::GUILD.'/roles' => Http::response([
                ['id' => self::STAFF_ROLE, 'name' => 'Admin', 'permissions' => (string) (1 << 3)],
                ['id' => self::MEMBER_ROLE, 'name' => 'Member', 'permissions' => '0'],
                ['id' => '700000000000000009', 'name' => 'MEE6', 'permissions' => (string) (1 << 3), 'managed' => true],
            ]),
        ]);

        $result = app(DiscordAutoMod::class)->install();

        $this->assertTrue($result['ok'], $result['message']);
        $posts = $this->sent()->filter(fn (Request $r) => $r->method() === 'POST')->keyBy(fn (Request $r) => $r['name']);

        $scam = $posts[self::SCAM];
        $this->assertContains('*ส่ง seed มา*', $scam['trigger_metadata']['keyword_filter']);
        $this->assertContains('tpix.online', $scam['trigger_metadata']['allow_list']);
        $this->assertSame([self::STAFF_ROLE], $scam['exempt_roles']);
        $this->assertContains(['type' => 3, 'metadata' => ['duration_seconds' => 3600]], $scam['actions']);
        $this->assertContains(['type' => 2, 'metadata' => ['channel_id' => self::LOG]], $scam['actions']);
        foreach ($posts as $post) {
            $this->assertLessThanOrEqual(150, mb_strlen($post['actions'][0]['metadata']['custom_message'] ?? ''), 'Discord รับข้อความบล็อกไม่เกิน 150 ตัว');
        }

        // สแปมมีได้กฎเดียว — ใช้ของแอดมินต่อ ไม่สร้างซ้ำ
        $this->assertArrayNotHasKey('TPIX • สแปม', $posts->all());

        // กฎของเราที่มีอยู่แล้ว → PATCH (ห้ามส่ง trigger_type) · กฎของแอดมินไม่ถูกแตะเลย
        $patch = $this->sent()->first(fn (Request $r) => $r->method() === 'PATCH');
        $this->assertStringEndsWith('/rules/400000000000000003', $patch->url());
        $this->assertArrayNotHasKey('trigger_type', $patch->data());
        $this->assertArrayNotHasKey(self::PROFANITY, $posts->all());
        $this->assertFalse($this->sent()->contains(fn (Request $r) => $r->method() === 'DELETE' || str_ends_with($r->url(), '/rules/400000000000000001')));

        // คำที่เป็นส่วนของคำปกติต้องไม่อยู่ในกฎคำหยาบ ("สัดส่วน" / "หีบห่อ")
        $thai = $patch['trigger_metadata']['keyword_filter'];
        $this->assertNotContains('*สัด*', $thai);
        $this->assertNotContains('*หี*', $thai);

        // น้ำหนักความผิดหาได้จาก id ของกฎที่ติดตั้ง และจากชื่อ · กฎที่ไม่ใช่ของเรานับ 1
        $automod = app(DiscordAutoMod::class);
        $this->assertSame(1, $automod->weightFor('400000000000000003', null));
        $this->assertSame(5, $automod->weightFor(null, self::SCAM));
        $this->assertSame(1, $automod->weightFor(null, 'กฎของแอดมิน'));
    }

    /**
     * จำลองการจับคำของ AutoMod: *คำ* = อยู่ตรงไหนก็ได้ · คำ* / *คำ = ต้นคำ/ท้ายคำ · คำ = ทั้งคำ · ไม่สนตัวพิมพ์.
     *
     * @return string|null ชื่อกฎที่ "บล็อก" ข้อความนี้ (กฎแจ้งแอดมินอย่างเดียวไม่นับ)
     */
    private function blockedBy(string $message): ?string
    {
        $text = mb_strtolower($message);

        foreach ((array) config('discord.moderation.rules') as $rule) {
            if ($rule['trigger_type'] !== 1 || ! empty($rule['alert_only'])) {
                continue;
            }
            foreach ($rule['keywords'] ?? [] as $keyword) {
                $word = preg_quote(mb_strtolower(trim($keyword, '*')), '/');
                $pattern = match (true) {
                    str_starts_with($keyword, '*') && str_ends_with($keyword, '*') => "/{$word}/u",
                    str_ends_with($keyword, '*') => "/(?<![\\p{L}\\p{N}]){$word}/u",
                    str_starts_with($keyword, '*') => "/{$word}(?![\\p{L}\\p{N}])/u",
                    default => "/(?<![\\p{L}\\p{N}]){$word}(?![\\p{L}\\p{N}])/u",
                };
                if (preg_match($pattern, $text)) {
                    return $rule['name'];
                }
            }
            foreach ($rule['regex'] ?? [] as $regex) {
                if (preg_match('/'.str_replace('/', '\/', $regex).'/u', $message)) {
                    return $rule['name'];
                }
            }
        }

        return null;
    }

    public function test_good_members_warning_each_other_are_never_blocked_or_scored(): void
    {
        $warnings = [
            'อย่าส่งวลีกู้คืนให้ใครเด็ดขาดนะครับ',
            'ทีมงานไม่มีวันขอ seed หรือคีย์ส่วนตัวของคุณ ใครขอคือมิจฉาชีพ',
            'ระวังมิจฉาชีพทักแชทส่วนตัวมาหลอกให้ซิงค์กระเป๋า',
            'Never share your seed phrase with anyone, even staff',
            "Don't send your seed phrase to anyone. Never enter your seed phrase on a website.",
            'There is no guaranteed profit in crypto',
            'มีปันผลรายวันไหมครับ แล้วมีการันตีกำไรหรือเปล่า',
            'วิธีเคลมแอร์ดรอป TPIX ทำยังไงครับ',
            'สัดส่วนโทเคนของทีมเท่าไหร่ครับ',
            'ส่งหีบห่อไปแล้ว',
            'ซื้อควายมาหนึ่งตัว',
            'ดูรายละเอียดที่ https://tpix.online/token-sale',
            'ใครเจอลิงก์ discord.com/channels/1/2 แปลก ๆ แจ้งแอดมินนะ',
        ];

        foreach ($warnings as $message) {
            $this->assertNull($this->blockedBy($message), "ข้อความปกติถูกบล็อก: {$message}");
        }
    }

    public function test_real_scam_and_spam_messages_are_blocked(): void
    {
        $scams = [
            'FREE NITRO giveaway click here' => self::SCAM,
            'Airdrop is live! Connect your wallet to claim' => self::SCAM,
            'ส่ง seed มาให้แอดมินตรวจสอบครับ' => self::SCAM,
            'dm me for support, I can fix your wallet' => self::SCAM,
            'claim now: dlscord.gift/abc' => self::SCAM,
            'steamcommunlty.com/gift/123' => self::SCAM,
            'join us discord.gg/freecoins' => 'TPIX • ลิงก์เชิญเซิร์ฟเวอร์อื่น',
            'ไอ้สัตว์ ไปตายซะ' => self::PROFANITY,
        ];

        foreach ($scams as $message => $rule) {
            $this->assertSame($rule, $this->blockedBy($message), "ต้องบล็อก: {$message}");
        }
    }

    public function test_the_rule_set_fits_discord_limits(): void
    {
        $rules = collect((array) config('discord.moderation.rules'));

        $this->assertLessThanOrEqual(6, $rules->where('trigger_type', 1)->count(), 'Discord ให้กฎคำต้องห้ามได้ 6 กฎ');
        $this->assertLessThanOrEqual(1, $rules->where('trigger_type', 3)->count());
        $this->assertLessThanOrEqual(1, $rules->where('trigger_type', 4)->count());
        foreach ($rules as $key => $rule) {
            $this->assertStringStartsWith(DiscordAutoMod::PREFIX, $rule['name'], $key);
            $this->assertLessThanOrEqual(100, mb_strlen($rule['name']), $key);
            $this->assertLessThanOrEqual(10, count($rule['regex'] ?? []), $key);
            $this->assertLessThanOrEqual(1000, count($rule['keywords'] ?? []), $key);
            $this->assertLessThanOrEqual(150, mb_strlen($rule['block_message'] ?? ''), $key);
            foreach ($rule['keywords'] ?? [] as $keyword) {
                $this->assertLessThanOrEqual(60, mb_strlen($keyword), $keyword);
            }
            foreach ($rule['regex'] ?? [] as $regex) {
                $this->assertLessThanOrEqual(260, mb_strlen($regex), $regex);
                $this->assertNotFalse(@preg_match('/'.str_replace('/', '\/', $regex).'/u', ''), "regex เสีย: {$regex}");
            }
        }
    }

    public function test_the_suspicious_phrase_rule_only_alerts_admins_and_needs_a_log_channel(): void
    {
        Http::fake([
            'discord.com/api/v10/guilds/'.self::GUILD.'/auto-moderation/rules' => fn (Request $r) => $r->method() === 'GET'
                ? Http::response([])
                : Http::response(['id' => (string) $this->auditSeq++]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/roles' => Http::response([]),
        ]);

        app(DiscordAutoMod::class)->install();

        $watch = $this->sent()->first(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'TPIX • ส่อหลอกลวง (แจ้งแอดมิน)');
        $this->assertSame([['type' => 2, 'metadata' => ['channel_id' => self::LOG]]], $watch['actions'], 'ต้องไม่บล็อก ไม่ปิดเสียง — แจ้งแอดมินอย่างเดียว');
        $this->assertContains('*ขอ seed*', $watch['trigger_metadata']['keyword_filter']);

        // ยังไม่เลือกห้องแจ้งเตือน → ข้ามกฎนี้ (Discord ไม่รับกฎที่ไม่มี action) แต่กฎอื่นติดตั้งได้ปกติ
        app(DiscordSettings::class)->set('discord_mod_log_channel', '');
        $before = count(Http::recorded());
        $result = app(DiscordAutoMod::class)->install();

        $this->assertTrue($result['ok'], $result['message']);
        $names = $this->sent()->slice($before)->filter(fn (Request $r) => $r->method() === 'POST')->map(fn (Request $r) => $r['name'])->all();
        $this->assertNotContains('TPIX • ส่อหลอกลวง (แจ้งแอดมิน)', $names);
        $this->assertContains(self::SCAM, $names);
        $this->assertStringContainsString('เลือกห้องแจ้งเตือน', collect($result['rules'])->firstWhere('key', 'scam_watch')['note']);
    }

    // ── หน้าหลังบ้าน ───────────────────────────────────────────────────────

    private function owner(): AdminUser
    {
        return AdminUser::create(['name' => 'เจ้าของ', 'email' => 'owner@tpix.test', 'password' => bcrypt('secret-password'), 'role' => 'super_admin', 'is_active' => true]);
    }

    public function test_the_owner_can_ban_from_the_admin_page_but_never_staff(): void
    {
        $owner = $this->owner();
        $this->fakeDiscord();

        $this->actingAs($owner, 'admin')
            ->post('/admin/discord/moderation/member', ['user_id' => self::BAD, 'action' => 'ban', 'reason' => 'สแปมลิงก์หลอก'])
            ->assertSessionHas('success');
        $ban = $this->punishments()->first($this->isBanOf(self::BAD));
        $this->assertSame('TPIX bot · แอดมิน: สแปมลิงก์หลอก', rawurldecode($ban->header('X-Audit-Log-Reason')[0]));
        $this->assertSame('manual', DiscordModAction::where('user_id', self::BAD)->value('mode'));

        $this->actingAs($owner, 'admin')
            ->post('/admin/discord/moderation/member', ['user_id' => self::STAFF, 'action' => 'kick', 'reason' => 'ทดสอบ'])
            ->assertSessionHas('error');
        $this->assertFalse($this->punishments()->contains(fn (Request $r) => str_ends_with($r->url(), '/'.self::STAFF)));

        $this->actingAs($owner, 'admin')
            ->post('/admin/discord/moderation/member', ['user_id' => '1 OR 1=1', 'action' => 'nuke', 'reason' => ''])
            ->assertSessionHasErrors(['user_id', 'action', 'reason']);
    }

    public function test_moderation_settings_are_validated_and_switching_on_starts_counting_fresh(): void
    {
        $owner = $this->owner();
        $settings = app(DiscordSettings::class);
        $settings->set('discord_mod_mode', DiscordModerator::MODE_OFF);
        $settings->mergeState(['mod_counting_since' => '2020-01-01T00:00:00+00:00', 'channels' => [['id' => self::LOG, 'name' => 'mod-log', 'category' => 'STAFF']]]);
        Http::fake();

        $this->actingAs($owner, 'admin')
            ->put('/admin/discord/moderation', ['mode' => 'enforce', 'log_channel' => '123456789012345678', 'timeout_at' => 5, 'kick_at' => 3, 'ban_at' => 2, 'max_bans_per_day' => 5])
            ->assertSessionHasErrors(['log_channel', 'kick_at', 'ban_at']);
        $this->assertSame(DiscordModerator::MODE_OFF, app(DiscordModerator::class)->mode());

        $this->actingAs($owner, 'admin')
            ->put('/admin/discord/moderation', ['mode' => 'enforce', 'log_channel' => self::LOG, 'timeout_at' => 2, 'kick_at' => 4, 'ban_at' => 8, 'max_bans_per_day' => 0])
            ->assertSessionHas('success');

        $moderator = app(DiscordModerator::class);
        $this->assertSame(DiscordModerator::MODE_ENFORCE, $moderator->mode());
        $this->assertSame(8, $moderator->threshold('ban_at'));
        $this->assertSame(0, $moderator->threshold('max_bans_per_day'));
        $this->assertTrue(now()->subMinute()->lt(app(DiscordSettings::class)->state()['mod_counting_since']), 'เปิดจากปิด ต้องเริ่มนับใหม่');
    }

    public function test_moderation_endpoints_are_for_the_super_admin_only(): void
    {
        $staff = AdminUser::create(['name' => 'แอดมินทั่วไป', 'email' => 'staff@tpix.test', 'password' => bcrypt('secret-password'), 'role' => 'admin', 'is_active' => true]);
        Http::fake();

        $this->actingAs($staff, 'admin')->put('/admin/discord/moderation', ['mode' => 'off'])->assertForbidden();
        $this->actingAs($staff, 'admin')->post('/admin/discord/moderation/automod')->assertForbidden();
        $this->actingAs($staff, 'admin')->post('/admin/discord/moderation/member', ['user_id' => self::BAD, 'action' => 'ban', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($staff, 'admin')->getJson('/admin/discord/moderation/permissions')->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(DiscordModerator::MODE_ENFORCE, app(DiscordModerator::class)->mode());
    }

    public function test_the_permission_check_names_what_the_bot_still_lacks(): void
    {
        $owner = $this->owner();
        $botId = '1548972414015373382';
        Http::fake([
            'discord.com/api/v10/users/@me' => Http::response(['id' => $botId]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/roles' => Http::response([
                ['id' => self::GUILD, 'name' => '@everyone', 'permissions' => '0', 'position' => 0],
                ['id' => '700000000000000010', 'name' => 'TPIX TRADE', 'permissions' => (string) ((1 << 5) | (1 << 7)), 'position' => 2, 'managed' => true],
                ['id' => self::STAFF_ROLE, 'name' => 'Admin', 'permissions' => (string) (1 << 3), 'position' => 5],
            ]),
            'discord.com/api/v10/guilds/'.self::GUILD.'/members/'.$botId => Http::response(['user' => ['id' => $botId], 'roles' => ['700000000000000010']]),
        ]);

        $json = $this->actingAs($owner, 'admin')->getJson('/admin/discord/moderation/permissions')->assertOk()->json();

        $this->assertSame(['Timeout Members (ปิดเสียง)', 'Kick Members (เตะ)', 'Ban Members (แบน)', 'Manage Messages (ลบข้อความจากการ์ดรายงาน · ปักหมุดคู่มือ)'], $json['missing']);
        $this->assertSame(['Admin'], $json['above_bot'], 'ยศบอทอยู่ต่ำกว่า Admin — ต้องบอกให้ลากขึ้น');
    }
}
