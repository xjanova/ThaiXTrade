<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TPIX TRADE — โควตาการเชื่อมกระเป๋าต้องแยกรายคน.
 *
 * ⚠️ อาการที่เกิดจริง 2026-08-18: ผู้ใช้กด "ผูกบัญชีอีเมล" แล้วเจอ 429 ทันที
 *    ทั้งที่เพิ่งกดครั้งแรก — เพราะโควตาถูกคนอื่นใช้หมดไปก่อน
 *
 * ต้นเหตุคือแอปอ่าน IP ไม่ออก (ดู TrustedProxyTest) ทำให้ทุกคนกลายเป็น IP เดียวกัน
 * แก้แล้วสองชั้น: อ่าน IP จริงได้ + นับโควตาต่อกระเป๋าแยกจากต่อ IP
 *
 * ชุดนี้คุมว่า "คนละกระเป๋าต้องไม่แย่งโควตากัน" ซึ่งเป็นสิ่งที่ผู้ใช้เจอโดยตรง
 *
 * Developed by Xman Studio.
 */
class WalletRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function connect(string $wallet): int
    {
        return $this->postJson('/api/v1/wallet/connect', [
            'wallet_address' => $wallet,
            'chain_id' => 56,
        ])->getStatusCode();
    }

    private function wallet(string $char): string
    {
        return '0x'.str_repeat($char, 40);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * ⚠️ ข้อที่ตรงกับอาการที่ผู้ใช้เจอที่สุด.
     *
     * กระเป๋า A ใช้โควตาจนหมด กระเป๋า B ต้องยังเชื่อมได้ตามปกติ
     * ถ้าข้อนี้แดง แปลว่ากลับไปเป็นโควตารวมของทั้งเว็บอีกแล้ว
     */
    #[Test]
    public function กระเป๋าคนละใบไม่แย่งโควตากัน(): void
    {
        $busy = $this->wallet('a');

        // ยิงจนโดนจำกัด
        $hitLimit = false;
        for ($i = 0; $i < 40; $i++) {
            if ($this->connect($busy) === 429) {
                $hitLimit = true;
                break;
            }
        }

        $this->assertTrue($hitLimit, 'ควรมีเพดานต่อกระเป๋า ไม่ใช่ยิงได้ไม่จำกัด');

        // กระเป๋าอื่นต้องไม่ได้รับผลกระทบ
        $this->assertSame(200, $this->connect($this->wallet('b')));
    }

    /** ต้องเชื่อมได้หลายรอบจริง — หนึ่งครั้งที่เชื่อมกินสามคำขอ (connect + sign + verify) */
    #[Test]
    public function เชื่อมกระเป๋าซ้ำได้หลายรอบก่อนโดนจำกัด(): void
    {
        $wallet = $this->wallet('c');

        // 5 รอบของการเชื่อม = 15 คำขอ ต้องยังผ่านหมด
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->connect($wallet), "รอบที่ {$i} ไม่ควรโดนจำกัด");
            $this->postJson('/api/v1/wallet/sign', ['wallet_address' => $wallet])->assertOk();
            $this->postJson('/api/v1/wallet/verify-signature', [
                'wallet_address' => $wallet,
                'signature' => '0x'.str_repeat('1', 130),
                'nonce' => 'ไม่ถูกต้อง',
            ]);
        }
    }

    /** เพดานต้องมีจริง — ไม่ใช่เปิดให้ยิงไม่จำกัดเพราะกลัว 429 */
    #[Test]
    public function ยังมีเพดานกันยิงรัวอยู่(): void
    {
        $wallet = $this->wallet('d');
        $blocked = 0;

        for ($i = 0; $i < 40; $i++) {
            if ($this->connect($wallet) === 429) {
                $blocked++;
            }
        }

        $this->assertGreaterThan(0, $blocked, 'ไม่มีเพดานเลย = เปิดช่องให้ยิงรัว');
    }
}
