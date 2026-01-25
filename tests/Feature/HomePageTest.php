<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * ThaiXTrade - Home Page Tests
 * Developed by Xman Studio
 */
class HomePageTest extends TestCase
{
    /**
     * Test home page loads successfully.
     */
    public function test_home_page_returns_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    /**
     * Test home page contains app name.
     */
    public function test_home_page_contains_app_name(): void
    {
        $response = $this->get('/');

        $response->assertSee('ThaiXTrade');
    }

    /**
     * Test home page has trading link.
     */
    public function test_home_page_has_trade_link(): void
    {
        $response = $this->get('/');

        $response->assertSee('/trade');
    }
}
