<?php

namespace Tests\Unit\MasterNode;

use App\Services\MasterNode\GeoIpService;
use Tests\TestCase;

/**
 * GeoIP → ช่องที่เปิดเผยได้ (ประเทศ/เมือง/พิกัดเมืองปัด 0.1°) เท่านั้น.
 */
class GeoIpPresentTest extends TestCase
{
    private const BANGKOK = [
        'city' => ['names' => ['en' => 'Bangkok', 'th' => 'กรุงเทพมหานคร']],
        'country' => ['iso_code' => 'TH', 'names' => ['en' => 'Thailand']],
        'location' => ['latitude' => 13.7540, 'longitude' => 100.5014, 'accuracy_radius' => 20, 'time_zone' => 'Asia/Bangkok'],
        'postal' => ['code' => '10200'],
        'subdivisions' => [['iso_code' => '10', 'names' => ['en' => 'Bangkok']]],
    ];

    public function test_city_record_is_reduced_to_country_city_and_rounded_coordinates(): void
    {
        $this->assertSame([
            'country_code' => 'TH',
            'country_name' => 'Thailand',
            'city' => 'Bangkok',
            'latitude' => 13.8,
            'longitude' => 100.5,
        ], GeoIpService::present(self::BANGKOK));
    }

    public function test_country_only_record_has_no_coordinates(): void
    {
        // ไม่รู้เมือง → พิกัดในฐานคือกลางประเทศ ห้ามปักหมุด
        $r = GeoIpService::present([
            'country' => ['iso_code' => 'jp', 'names' => ['en' => 'Japan']],
            'location' => ['latitude' => 35.69, 'longitude' => 139.69],
        ]);

        $this->assertSame('JP', $r['country_code']);
        $this->assertNull($r['city']);
        $this->assertNull($r['latitude']);
        $this->assertNull($r['longitude']);
    }

    public function test_registered_country_is_used_when_country_is_missing(): void
    {
        $r = GeoIpService::present(['registered_country' => ['iso_code' => 'SG', 'names' => ['en' => 'Singapore']]]);

        $this->assertSame('SG', $r['country_code']);
    }

    public function test_record_without_country_is_dropped(): void
    {
        $this->assertNull(GeoIpService::present(['continent' => ['code' => 'AS']]));
    }

    public function test_private_and_reserved_ips_are_never_looked_up(): void
    {
        $calls = 0;
        $geo = new GeoIpService(function () use (&$calls) {
            $calls++;

            return self::BANGKOK;
        });

        $this->assertNull($geo->locate('10.0.0.5'));
        $this->assertNull($geo->locate('127.0.0.1'));
        $this->assertNull($geo->locate('192.168.1.10'));
        $this->assertNull($geo->locate('not-an-ip'));
        $this->assertSame(0, $calls);

        $this->assertSame('TH', $geo->locate('1.46.0.1')['country_code']);
        $this->assertSame(1, $calls);
    }

    public function test_missing_database_returns_null_without_error(): void
    {
        config(['masternode.geoip.database_path' => storage_path('app/geoip/does-not-exist.mmdb')]);
        $geo = new GeoIpService();

        $this->assertFalse($geo->available());
        $this->assertNull($geo->locate('1.46.0.1'));
    }
}
