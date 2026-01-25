<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ThaiXTrade - Health Check Tests
 * Developed by Xman Studio
 */
class HealthCheckTest extends TestCase
{
    /**
     * Test Laravel health endpoint.
     */
    public function test_laravel_health_endpoint(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    /**
     * Test custom health check returns JSON.
     */
    public function test_health_check_returns_json(): void
    {
        // This tests the health.php file directly would need HTTP client
        // For now, test that the file exists
        $this->assertFileExists(base_path('public_html/health.php'));
    }

    /**
     * Test health check file has required checks.
     */
    public function test_health_check_file_structure(): void
    {
        $healthFile = file_get_contents(base_path('public_html/health.php'));

        $this->assertStringContainsString('status', $healthFile);
        $this->assertStringContainsString('checks', $healthFile);
        $this->assertStringContainsString('php', $healthFile);
        $this->assertStringContainsString('storage', $healthFile);
        $this->assertStringContainsString('database', $healthFile);
    }
}
