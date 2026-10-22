<?php

namespace Tests\Unit\MasterNode;

use App\Services\MasterNode\PeerId;
use PHPUnit\Framework\TestCase;

/**
 * PeerId — ถอด peer ID ของ polygon-edge กลับเป็นกุญแจโหนด.
 *
 * เวกเตอร์หลักมาจากสเปก libp2p (peer-ids.md, "secp256k1 private key/public key")
 * ที่อยู่ Ethereum คิดแยกด้วย ethers v6 (computeAddress) — ไม่ได้คิดด้วยโค้ดที่กำลังทดสอบ
 */
class PeerIdTest extends TestCase
{
    // libp2p spec: private 0x53DADF1D…D56CED8FB → public 08021221 037777e9…30bfca99
    private const SPEC_PEER_ID = '16Uiu2HAmLhLvBoYaoZfaMUKuibM6ac163GwKY74c5kiSLg5KvLpY';

    private const SPEC_COMPRESSED = '037777e994e452c21604f91de093ce415f5432f701dd8cd1a7a6fea0e630bfca99';

    private const SPEC_ADDRESS = '0x8b805bf52e9ce863375c5105e1158d519537f5b3';

    public function test_spec_vector_decodes_to_the_published_public_key(): void
    {
        $this->assertSame(self::SPEC_COMPRESSED, PeerId::compressedPublicKey(self::SPEC_PEER_ID));
    }

    public function test_spec_vector_maps_to_the_ethereum_address_of_the_same_key(): void
    {
        $this->assertSame(self::SPEC_ADDRESS, PeerId::nodeAddress(self::SPEC_PEER_ID));
    }

    public function test_second_key_vector(): void
    {
        // ethers: SigningKey(0x6cbed15c…59b2a1) — กุญแจเดียวกับ DELEGATE_PRIV ในเทสต์ heartbeat รุ่น 1
        $this->assertSame(
            '0xffcf8fdee72ac11b5c542428b35eef5769c409f0',
            PeerId::nodeAddress('16Uiu2HAm5SczivB6BNkSBw9svc1ejopedyTYvoT6Brw9QkDudayt'),
        );
    }

    public function test_live_validator_peer_id_is_a_valid_secp256k1_point(): void
    {
        // tpix-validator-1 (docs/MASTERNODE_PUBLIC_BOOTNODE.md) — ข้อมูลสาธารณะ
        $addr = PeerId::nodeAddress('16Uiu2HAmSmFLK2z9HhZifQ3xaZmbZkZbT3hcEodRvVdX9tNnB8xv');

        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{40}$/', (string) $addr);
    }

    public function test_rejects_ed25519_and_garbage(): void
    {
        $this->assertNull(PeerId::nodeAddress('12D3KooWD3eckifWpRn9wQpMG9R9hX3sD158z7EqHWmweQAJU5SA'));
        $this->assertNull(PeerId::nodeAddress('QmYyQSo1c1Ym7orWxLYvCrM2EmxFTANf8wXmmE7DWjhx5N'));
        $this->assertNull(PeerId::nodeAddress('16Uiu2HAm0OIl'));
        $this->assertNull(PeerId::nodeAddress(''));
        $this->assertFalse(PeerId::isValid(substr(self::SPEC_PEER_ID, 0, -1)));
    }

    public function test_one_changed_character_never_yields_the_same_key(): void
    {
        $tampered = substr(self::SPEC_PEER_ID, 0, -1).(str_ends_with(self::SPEC_PEER_ID, 'Y') ? 'Z' : 'Y');

        $this->assertNotSame(self::SPEC_ADDRESS, PeerId::nodeAddress($tampered));
    }
}
