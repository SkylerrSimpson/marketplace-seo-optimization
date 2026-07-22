<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * The home page holds live marketplace credentials/script-run access — a guest
     * must never reach it directly (PLAN.md §3).
     */
    public function test_a_guest_is_redirected_to_login_from_the_home_page(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
