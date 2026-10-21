<?php

namespace Tests\Unit\MasterNode;

use App\Services\MasterNode\UptimeOracleService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * calldata ของ updateUptimeBatch ต้องตรงกับที่ ethers v6 encode ทุกไบต์ —
 * ผิดไบต์เดียวคือตั้ง uptime ให้โหนดผิดตัว หรือธุรกรรม revert ทั้งชุด.
 */
class UptimeBatchEncodeTest extends TestCase
{
    public function test_matches_ethers_encoding(): void
    {
        // new Interface(['function updateUptimeBatch(address[],uint256[])'])
        //   .encodeFunctionData('updateUptimeBatch', [[0x90f8…c9c1, 0xffcf…09f0], [10000, 5000]])
        $expected = '0xb949fb14'
            .'0000000000000000000000000000000000000000000000000000000000000040'
            .'00000000000000000000000000000000000000000000000000000000000000a0'
            .'0000000000000000000000000000000000000000000000000000000000000002'
            .'00000000000000000000000090f8bf6a479f320ead074411a4b0e7944ea8c9c1'
            .'000000000000000000000000ffcf8fdee72ac11b5c542428b35eef5769c409f0'
            .'0000000000000000000000000000000000000000000000000000000000000002'
            .'0000000000000000000000000000000000000000000000000000000000002710'
            .'0000000000000000000000000000000000000000000000000000000000001388';

        $this->assertSame($expected, UptimeOracleService::encodeBatch(
            ['0x90F8bf6A479f320ead074411a4B0e7944Ea8c9C1', '0xffcf8fdee72ac11b5c542428b35eef5769c409f0'],
            [10000, 5000],
        ));
    }

    public function test_rejects_out_of_range_uptime(): void
    {
        $this->expectException(RuntimeException::class);
        UptimeOracleService::encodeBatch(['0xffcf8fdee72ac11b5c542428b35eef5769c409f0'], [10001]);
    }

    public function test_rejects_length_mismatch(): void
    {
        $this->expectException(RuntimeException::class);
        UptimeOracleService::encodeBatch(['0xffcf8fdee72ac11b5c542428b35eef5769c409f0'], [1, 2]);
    }
}
