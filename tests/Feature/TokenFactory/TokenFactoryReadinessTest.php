<?php

namespace Tests\Feature\TokenFactory;

use App\Models\SiteSetting;
use App\Services\ContractRegistry;
use App\Services\TokenFactoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakesTokenFactory;
use Tests\TestCase;

/**
 * ความพร้อมของโรงงาน — ต้องตรงกับสิ่งที่ deploy ได้จริงบนเชน.
 *
 * create* ของแฟกทอรีเป็น onlyOwner · ERC-20 ต้องใช้แฟกทอรี ERC-20 · NFT ต้องใช้แฟกทอรี NFT
 */
class TokenFactoryReadinessTest extends TestCase
{
    use FakesTokenFactory;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTokenFactory();
    }

    private function service(): TokenFactoryService
    {
        return app(TokenFactoryService::class);
    }

    #[Test]
    public function พร้อมเมื่อกระเป๋าของเซิร์ฟเวอร์เป็นเจ้าของแฟกทอรี(): void
    {
        $state = $this->service()->isFactoryReady();

        $this->assertTrue($state['ready'], implode(' · ', $state['issues']));
        $this->assertTrue($this->service()->readyFor('standard')['ok']);
    }

    #[Test]
    public function ไม่พร้อมถ้าเจ้าของแฟกทอรีเป็นกระเป๋าอื่น(): void
    {
        $this->chain['owner'] = '0x'.str_repeat('5', 40);

        $state = $this->service()->isFactoryReady(withDetails: true);

        $this->assertFalse($state['ready']);
        $this->assertFalse($state['erc20_factory_ready']);
        $this->assertContains('กระเป๋าที่เซิร์ฟเวอร์ใช้ส่งธุรกรรมไม่ใช่เจ้าของ (owner) ของแฟกทอรี ERC-20', $state['issues']);
        $this->assertSame('0x'.str_repeat('5', 40), $state['details']['erc20_factory']['owner']);
        $this->assertSame($this->deployer(), $state['details']['deployer_address']);
        $this->assertFalse($this->service()->readyFor('standard')['ok']);
    }

    #[Test]
    public function มีแค่แฟกทอรี_nft_ไม่พอสำหรับเหรียญ_erc20(): void
    {
        SiteSetting::set(ContractRegistry::GROUP, 'token_factory_v2', '');
        $this->setPolicy(['types' => ['nft' => ['state' => 'on']]]);

        $this->assertFalse($this->service()->readyFor('standard')['ok']);
        $this->assertTrue($this->service()->readyFor('nft')['ok']);
    }

    #[Test]
    public function ข้อมูลสาธารณะไม่บอกที่อยู่กระเป๋าของเซิร์ฟเวอร์(): void
    {
        $state = $this->service()->isFactoryReady();

        $this->assertArrayNotHasKey('details', $state);
        $this->assertStringNotContainsString(substr($this->deployer(), 2, 10), json_encode($state));
    }
}
