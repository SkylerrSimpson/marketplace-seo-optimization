<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_route_renders_the_branded_404_page(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/definitely-not-a-real-route');

        $response->assertNotFound();
        $response->assertSee('Page not found');
        $response->assertSee('Back to Dashboard');
    }
}
