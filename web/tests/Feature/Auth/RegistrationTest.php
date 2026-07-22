<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_login_page_links_to_registration(): void
    {
        $this->get('/login')->assertOk()->assertSee(route('register'), false);
    }

    public function test_new_users_can_register_and_are_logged_in_pending_verification(): void
    {
        Event::fake();

        $response = $this->post('/register', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'new@example.com')->first();
        $this->assertNotNull($user);
        // Self-registered accounts start as plain, active, unverified members.
        $this->assertFalse($user->is_admin);
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at);

        // The Registered event is what sends the verification email.
        Event::assertDispatched(Registered::class);
    }

    public function test_registration_requires_a_unique_email_and_confirmed_password(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'name' => 'Dupe',
            'email' => 'taken@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
        ])->assertSessionHasErrors('email');

        $this->post('/register', [
            'name' => 'Mismatch',
            'email' => 'fresh@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'nope',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_an_unverified_user_is_bounced_from_the_app_to_the_verification_prompt(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('verification.notice'));
        $this->actingAs($user)->get(route('scripts.index'))->assertRedirect(route('verification.notice'));
    }

    public function test_a_verified_user_reaches_the_app_normally(): void
    {
        $user = User::factory()->create(); // factory verifies by default

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_registration_is_rate_limited(): void
    {
        // Open signup must be throttled per IP (8/min). The throttle middleware
        // runs before validation, so even these invalid posts count as attempts.
        for ($i = 0; $i < 8; $i++) {
            $this->post('/register', ['email' => "spam{$i}@example.com"]);
        }

        $this->post('/register', ['email' => 'spam9@example.com'])->assertStatus(429);
    }
}
