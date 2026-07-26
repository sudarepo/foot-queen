<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // followingRedirects() because "/" now A/B-splits real visitors between
        // the grid and a redirect to "/feed" — this just checks the app boots.
        $response = $this->followingRedirects()->get('/');

        $response->assertStatus(200);
    }
}
