<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ThaiXTrade - Version Tests
 * Developed by Xman Studio.
 */
class VersionTest extends TestCase
{
    private string $versionFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->versionFile = dirname(__DIR__, 2).'/version.json';
    }

    /**
     * Test version.json file exists.
     */
    public function test_version_file_exists(): void
    {
        $this->assertFileExists($this->versionFile);
    }

    /**
     * Test version.json is valid JSON.
     */
    public function test_version_file_is_valid_json(): void
    {
        $content = file_get_contents($this->versionFile);
        $json = json_decode($content, true);

        $this->assertNotNull($json);
        $this->assertIsArray($json);
    }

    /**
     * Test version.json has required fields.
     */
    public function test_version_file_has_required_fields(): void
    {
        $content = file_get_contents($this->versionFile);
        $json = json_decode($content, true);

        $this->assertArrayHasKey('name', $json);
        $this->assertArrayHasKey('version', $json);
        $this->assertArrayHasKey('build', $json);
        $this->assertArrayHasKey('developer', $json);
    }

    /**
     * Test version follows semver format.
     */
    public function test_version_follows_semver(): void
    {
        $content = file_get_contents($this->versionFile);
        $json = json_decode($content, true);

        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            $json['version'],
            'Version should follow semver format (X.Y.Z)'
        );
    }

    /**
     * Test build is a positive integer.
     */
    public function test_build_is_positive_integer(): void
    {
        $content = file_get_contents($this->versionFile);
        $json = json_decode($content, true);

        $this->assertIsInt($json['build']);
        $this->assertGreaterThan(0, $json['build']);
    }

    /**
     * Test app name is ThaiXTrade.
     */
    public function test_app_name_is_correct(): void
    {
        $content = file_get_contents($this->versionFile);
        $json = json_decode($content, true);

        $this->assertEquals('ThaiXTrade', $json['name']);
    }
}
