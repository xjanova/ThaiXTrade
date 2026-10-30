<?php

namespace Tests\Feature;

use App\Models\Kline;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — เวลาทางธุรกิจต้องไม่ขยับเองเมื่ออัปเดตแถว.
 *
 * prod เป็น MariaDB 10.6 ตั้ง explicit_defaults_for_timestamp=0 — TIMESTAMP NOT NULL คอลัมน์แรกของตาราง
 * ได้ ON UPDATE current_timestamp() แถมมาเอง SQLite ไม่มีพฤติกรรมนี้ เทสต์ปกติจึงมองไม่เห็นเลย
 *
 * เทสต์ engine จริงจะข้ามถ้าไม่ได้ตั้ง REAL_DB_DATABASE (job mariadb-migrations ใน ci.yml ตั้งให้)
 * รันเองได้ด้วย REAL_DB_HOST/PORT/USERNAME/PASSWORD/DATABASE — ฐานต้องลงท้าย _test เพราะถูกล้างทั้งฐาน
 *
 * Developed by Xman Studio.
 */
class BusinessTimeColumnsTest extends TestCase
{
    private const FIX = '2026_10_30_230000_stop_business_times_auto_updating.php';

    private const OLD_TIME = '2026-01-02 03:04:00';

    /** คอลัมน์ timestamp เปล่าที่ deploy ไปแล้ว — ทุกตัวถูกแก้ใน migration FIX ห้ามเพิ่มตัวใหม่ในรายการนี้ */
    private const FIXED_BARE_COLUMNS = [
        '2026_03_19_220000_create_staking_positions_table.php:staked_at',
        '2026_03_20_100000_create_food_passport_tables.php:recorded_at',
        '2026_03_25_200002_create_klines_table.php:open_time',
        '2026_08_28_100000_create_ai_market_views_table.php:expires_at',
        '2026_09_14_180000_create_discord_moderation_tables.php:occurred_at',
        '2026_10_11_010000_harden_factory_tokens.php:expires_at',
    ];

    /** ตาราง => [คอลัมน์เวลา, ค่าที่ต้องมีตอน insert, การอัปเดตแบบที่โค้ดทำจริง] */
    private const ROWS = [
        'staking_positions' => [
            'staked_at',
            ['staking_pool_id' => 1, 'wallet_address' => '0xabc', 'amount' => 1],
            ['last_reward_at' => '2026-01-03 00:00:00', 'reward_earned' => 1],
        ],
        'klines' => [
            'open_time',
            ['trading_pair_id' => 1, 'interval' => '1m', 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1],
            ['close' => 2],
        ],
        'token_factory_fee_quotes' => [
            'expires_at',
            ['id' => 'quote-1', 'creator_address' => '0xabc', 'chain_id' => 4289, 'token_type' => 'standard', 'params_hash' => 'hash', 'amount' => 50, 'amount_wei' => '50'],
            ['used_at' => '2026-01-03 00:00:00'],
        ],
        'ai_market_views' => [
            'expires_at',
            ['scope' => 'strategic', 'provider' => 'openai', 'model' => 'test'],
            ['regime' => 'risk_off'],
        ],
        'discord_mod_strikes' => [
            'occurred_at',
            ['audit_id' => '1', 'user_id' => '2'],
            ['weight' => 2],
        ],
        'food_traces' => [
            'recorded_at',
            ['food_product_id' => 1, 'recorder_address' => '0xabc', 'stage' => 'farm'],
            ['notes' => 'edited'],
        ],
    ];

    #[Test]
    public function migration_ใหม่ห้ามประกาศ_timestamp_เปล่า(): void
    {
        $bare = [];

        foreach (File::glob(database_path('migrations/*.php')) as $file) {
            preg_match_all('/->timestamp(?:Tz)?\(\s*[\'"](\w+)[\'"][^;]*;/', File::get($file), $declared, PREG_SET_ORDER);

            foreach ($declared as [$statement, $column]) {
                if (! preg_match('/->(nullable|useCurrent|default)\(/', $statement)) {
                    $bare[] = basename($file).':'.$column;
                }
            }
        }

        sort($bare);

        $this->assertSame(
            self::FIXED_BARE_COLUMNS,
            $bare,
            'timestamp() เปล่าบน prod จะเขียนทับตัวเองทุกครั้งที่อัปเดตแถว — ใช้ dateTime() หรือ timestamp()->nullable()'
        );
    }

    #[Test]
    public function บน_engine_จริง_การแก้หยุดการเขียนทับโดยค่าเดิมไม่เปลี่ยน(): void
    {
        $db = $this->realEngine();

        $this->assertSame(0, (int) $db->selectOne('select @@explicit_defaults_for_timestamp as flag')->flag, 'ต้องตั้งเหมือน prod ไม่งั้นเทสต์นี้พิสูจน์อะไรไม่ได้');

        $this->migrate($this->migrationsBeforeFix());
        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        $stored = [];
        foreach (self::ROWS as $table => [$column, $row, $update]) {
            $this->assertStringContainsStringIgnoringCase('on update', $this->columnInfo($db, $table, $column)->flags);

            $db->table($table)->insert($row + [$column => self::OLD_TIME]);
            $stored[$table] = $this->storedTime($db, $table, $column);

            $db->table($table)->update($update);
            $this->assertNotSame($stored[$table], $this->storedTime($db, $table, $column), "{$table}: บั๊กต้องโผล่ก่อนแก้ ไม่งั้นเทสต์นี้ไม่มีความหมาย");

            // เริ่มแถวใหม่ — ค่าที่ตรงกับ $update อยู่แล้วทำให้รอบหลังไม่เปลี่ยนอะไร แล้ว engine จะไม่แตะเวลาอยู่ดี
            $db->table($table)->delete();
            $db->table($table)->insert($row + [$column => self::OLD_TIME]);
        }

        $this->assertSame(1, $this->tradesInCandle(Carbon::parse('2026-01-02 03:05:00')), 'ก่อนแก้ไม้ที่สามต้องหาแท่งเดิมไม่เจอ');

        // prod รัน ALTER โดยเปิดเช็ก foreign key ไว้
        $db->statement('SET FOREIGN_KEY_CHECKS=1');
        $this->migrate([database_path('migrations/'.self::FIX)]);
        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (self::ROWS as $table => [$column, , $update]) {
            $info = $this->columnInfo($db, $table, $column);

            $this->assertStringNotContainsStringIgnoringCase('on update', $info->flags);
            $this->assertStringContainsStringIgnoringCase('current_timestamp', (string) $info->fallback);
            $this->assertSame('timestamp', $info->kind);
            $this->assertSame('NO', $info->nullable);
            $this->assertSame($stored[$table], $this->storedTime($db, $table, $column), "{$table}: ค่าเดิมต้องไม่เปลี่ยน");

            $db->table($table)->update($update);
            $this->assertSame($stored[$table], $this->storedTime($db, $table, $column), "{$table}: อัปเดตแถวแล้วเวลาต้องไม่ขยับ");
        }

        $this->assertSame('Candle open timestamp', $this->columnInfo($db, 'klines', 'open_time')->note);
        $this->assertNotEmpty($db->select("show index from klines where Key_name = 'uk_kline'"));
        $this->assertSame(3, $this->tradesInCandle(Carbon::parse('2026-01-02 03:06:00')), 'หลังแก้ทุกไม้ในนาทีเดียวต้องลงแท่งเดียว');

        // ย้อนแล้วรันซ้ำต้องไม่พัง และไม่คืนบั๊กกลับมา
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'real_engine', '--path' => [database_path('migrations/'.self::FIX)], '--realpath' => true, '--force' => true]), Artisan::output());
        $this->migrate([database_path('migrations/'.self::FIX)]);
        $this->assertStringNotContainsStringIgnoringCase('on update', $this->columnInfo($db, 'klines', 'open_time')->flags);
    }

    private function realEngine(): Connection
    {
        $database = (string) env('REAL_DB_DATABASE');

        if ($database === '') {
            $this->markTestSkipped('ไม่ได้ตั้ง REAL_DB_DATABASE');
        }

        $this->assertStringEndsWith('_test', $database, 'เทสต์นี้ล้างทั้งฐาน — ห้ามชี้ไปฐานที่ใช้งานจริง');

        config(['database.connections.real_engine' => array_merge(config('database.connections.mysql'), [
            'host' => env('REAL_DB_HOST', '127.0.0.1'),
            'port' => env('REAL_DB_PORT', '3306'),
            'database' => $database,
            'username' => env('REAL_DB_USERNAME', 'root'),
            'password' => env('REAL_DB_PASSWORD', ''),
        ])]);

        $this->assertSame(0, Artisan::call('db:wipe', ['--database' => 'real_engine', '--force' => true]), Artisan::output());

        return DB::connection('real_engine');
    }

    /** @return list<string> */
    private function migrationsBeforeFix(): array
    {
        return array_values(array_filter(
            File::glob(database_path('migrations/*.php')),
            fn (string $file) => basename($file) !== self::FIX,
        ));
    }

    /** @param list<string> $paths */
    private function migrate(array $paths): void
    {
        $exit = Artisan::call('migrate', ['--database' => 'real_engine', '--path' => $paths, '--realpath' => true, '--force' => true]);

        $this->assertSame(0, $exit, Artisan::output());
    }

    private function columnInfo(Connection $db, string $table, string $column): object
    {
        return $db->selectOne(
            'select extra as flags, column_default as fallback, data_type as kind, is_nullable as nullable, column_comment as note
             from information_schema.columns where table_schema = database() and table_name = ? and column_name = ?',
            [$table, $column]
        );
    }

    /** เทียบเป็น epoch — ไม่ขึ้นกับ time zone ของ session */
    private function storedTime(Connection $db, string $table, string $column): int
    {
        return (int) $db->table($table)->orderBy('id')->selectRaw("UNIX_TIMESTAMP(`{$column}`) as epoch")->value('epoch');
    }

    /** ไม้สวอปสามไม้ในนาทีเดียว เดินทางเดียวกับ DexSwapIndexer::addToCandle — คืนจำนวนไม้ที่แท่งของนาทีนั้นนับได้ */
    private function tradesInCandle(Carbon $minute): int
    {
        $candle = ['trading_pair_id' => 1, 'interval' => '1m', 'open_time' => $minute];

        foreach ([1, 2, 3] as $close) {
            $kline = Kline::on('real_engine')->firstOrNew($candle);
            $kline->fill(['open' => 1, 'high' => $close, 'low' => 1, 'close' => $close, 'trade_count' => (int) $kline->trade_count + 1])->save();
        }

        return (int) Kline::on('real_engine')->where($candle)->sum('trade_count');
    }
}
