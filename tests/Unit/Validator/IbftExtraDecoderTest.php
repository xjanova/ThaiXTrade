<?php

namespace Tests\Unit\Validator;

use App\Services\Validator\IbftExtraDecoder;
use App\Services\Validator\Rlp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * IbftExtraDecoder — ถอด extraData ของบล็อกจริงจากเชน TPIX (4289).
 *
 * fixture คือบล็อก 3,207,988–3,207,999 ที่ดึงจาก rpc1.tpix.online วันที่ 2026-10-22
 * ตอนนั้นมี validator 4 ตัว (ยืนยันกับ `polygon-edge ibft snapshot` บนเครื่องเชน)
 * ผู้เสนอบล็อกที่ถูกต้องต้องวนลำดับ 3-0-1-2 ไม่ขาด — ถ้าสูตรกู้ลายเซ็นผิด
 * จะได้ที่อยู่สุ่มที่ไม่อยู่ในชุด validator เลย
 */
class IbftExtraDecoderTest extends TestCase
{
    private const VALIDATORS = [
        '0x00affa6379fbd969cbe5906cdeee793960a500da',
        '0xfe0dd72399145f54b5882ea3620f106bafdd5ad5',
        '0xa7d8ea159a59c82effcef88ccd92cf47048b2fde',
        '0xdeff2d109b52ddc574f6edc374e7e081e4198e20',
    ];

    /** @return list<array<string,string>> */
    private function blocks(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/ibft/tpix-blocks-3207988.json'), true);
    }

    public function test_reads_the_four_bls_validators_in_set_order(): void
    {
        $extra = (new IbftExtraDecoder())->decode($this->blocks()[0]['extraData']);

        $this->assertNotNull($extra);
        $this->assertSame(self::VALIDATORS, array_column($extra['validators'], 'address'));
        $this->assertSame(
            '0x8f5c6db7b7f32fb48d9f3b1e86eec5b3821ada3ca35e110aa258c1aa2e55c3fc11f7fd1b48027240667e9c8e806c18d5',
            $extra['validators'][0]['bls'],
        );
        $this->assertSame(0, $extra['round']);
    }

    public function test_recovers_the_proposer_of_every_block_in_round_robin_order(): void
    {
        $decoder = new IbftExtraDecoder();
        $order = [];
        foreach ($this->blocks() as $block) {
            $summary = $decoder->summarize($block);
            $this->assertNotNull($summary, 'block '.$block['number']);
            $order[] = array_search($summary['proposer'], self::VALIDATORS, true);
        }

        $this->assertSame([3, 0, 1, 2, 3, 0, 1, 2, 3, 0, 1, 2], $order);
    }

    public function test_committed_seal_bitmap_names_the_signers(): void
    {
        $decoder = new IbftExtraDecoder();
        $blocks = $this->blocks();

        // 3207988: bitmap 0x0e = ตัวที่ 1,2,3 · บล็อกก่อนหน้า 0x0b = ตัวที่ 0,1,3
        $extra = $decoder->decode($blocks[0]['extraData']);
        $this->assertSame([1, 2, 3], $extra['signers']);
        $this->assertSame([0, 1, 3], $extra['parent_signers']);

        $summary = $decoder->summarize($blocks[1]); // bitmap 0x07 = ตัวที่ 0,1,2
        $this->assertSame(array_slice(self::VALIDATORS, 0, 3), $summary['signers']);
    }

    public function test_parent_seals_of_the_next_block_repeat_this_blocks_signers(): void
    {
        $decoder = new IbftExtraDecoder();
        $blocks = $this->blocks();
        for ($i = 1; $i < count($blocks); $i++) {
            $this->assertSame(
                $decoder->decode($blocks[$i - 1]['extraData'])['signers'],
                $decoder->decode($blocks[$i]['extraData'])['parent_signers'],
            );
        }
    }

    public function test_reads_a_non_zero_round(): void
    {
        $block = $this->blocks()[0];
        // round อยู่ 8 ไบต์ท้ายสุด — แทนเป็น 2 (= ผู้เสนอ 2 คิวแรกพลาด)
        $block['extraData'] = substr($block['extraData'], 0, -16).'0000000000000002';

        $summary = (new IbftExtraDecoder())->summarize($block);

        $this->assertSame(2, $summary['round']);
        // hash ของบล็อกไม่เปลี่ยน → ผู้เสนอยังกู้ได้เหมือนเดิม
        $this->assertSame(self::VALIDATORS[3], $summary['proposer']);
    }

    public function test_garbage_is_rejected_instead_of_guessed(): void
    {
        $decoder = new IbftExtraDecoder();

        $this->assertNull($decoder->decode(null));
        $this->assertNull($decoder->decode('0x'));
        $this->assertNull($decoder->decode('0x'.str_repeat('00', 32)));
        $this->assertNull($decoder->decode('0xzz'));
        // ตัดท้ายทิ้ง = RLP ขาด
        $this->assertNull($decoder->decode(substr($this->blocks()[0]['extraData'], 0, -20)));
        // ลายเซ็นยาวผิด
        $this->assertNull($decoder->proposer($this->blocks()[0]['hash'], str_repeat("\x01", 64)));
    }

    public function test_rlp_rejects_trailing_and_truncated_bytes(): void
    {
        $this->assertSame(['dog', ['cat']], Rlp::decode("\xc9\x83dog\xc4\x83cat"));
        $this->assertSame("\x05", Rlp::decode("\x05"));

        $this->expectException(InvalidArgumentException::class);
        Rlp::decode("\x83do");
    }

    public function test_rlp_rejects_bytes_after_the_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rlp::decode("\x83dog\x00");
    }
}
