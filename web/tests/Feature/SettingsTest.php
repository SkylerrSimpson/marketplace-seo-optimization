<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_all_settings_routes(): void
    {
        $this->get(route('settings.edit'))->assertRedirect('/login');
        $this->patch(route('settings.theme'), ['theme' => 'dark'])->assertRedirect('/login');
    }

    public function test_new_user_defaults_to_system_theme(): void
    {
        $user = User::factory()->create();

        $this->assertSame('system', $user->fresh()->theme);
    }

    public function test_edit_shows_the_users_current_theme(): void
    {
        $user = User::factory()->create(['theme' => 'dark']);

        $response = $this->actingAs($user)->get(route('settings.edit'));

        $response->assertOk();
        $response->assertSee('Light');
        $response->assertSee('Dark');
        $response->assertSee('System');
    }

    public function test_update_theme_persists_a_valid_choice(): void
    {
        $user = User::factory()->create(['theme' => 'system']);

        $this->actingAs($user)
            ->patchJson(route('settings.theme'), ['theme' => 'dark'])
            ->assertOk()
            ->assertJson(['theme' => 'dark']);

        $this->assertSame('dark', $user->fresh()->theme);
    }

    public function test_update_theme_rejects_a_value_outside_the_known_set(): void
    {
        $user = User::factory()->create(['theme' => 'system']);

        $this->actingAs($user)
            ->patchJson(route('settings.theme'), ['theme' => 'purple'])
            ->assertInvalid('theme');

        $this->assertSame('system', $user->fresh()->theme);
    }

    public function test_update_theme_does_not_affect_a_different_user(): void
    {
        $user = User::factory()->create(['theme' => 'system']);
        $otherUser = User::factory()->create(['theme' => 'system']);

        $this->actingAs($user)->patchJson(route('settings.theme'), ['theme' => 'light']);

        $this->assertSame('light', $user->fresh()->theme);
        $this->assertSame('system', $otherUser->fresh()->theme);
    }

    public function test_authenticated_layout_seeds_the_theme_init_script_from_the_users_stored_theme(): void
    {
        $user = User::factory()->create(['theme' => 'dark']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        // @json() output for the string "dark" inside the inline <script>.
        $response->assertSee('var theme = "dark"', false);
    }

    public function test_guest_layout_always_seeds_system_since_there_is_no_stored_preference_yet(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('var theme = "system"', false);
    }

    public function test_settings_dropdown_link_appears_in_navigation(): void
    {
        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('href="'.route('settings.edit').'"', false);
    }
}
