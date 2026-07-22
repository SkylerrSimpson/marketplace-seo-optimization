<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    // --- access control ------------------------------------------------------

    public function test_guest_is_redirected_to_login_for_admin_routes(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect('/login');
        $this->get(route('admin.runs.index'))->assertRedirect('/login');
    }

    public function test_non_admin_is_forbidden_from_the_admin_area(): void
    {
        $member = User::factory()->create(); // is_admin defaults to false

        $this->actingAs($member)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($member)->get(route('admin.runs.index'))->assertForbidden();
        $this->actingAs($member)->post(route('admin.users.store'), [])->assertForbidden();
    }

    public function test_admin_can_open_the_users_page(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Ada Admin']);
        $member = User::factory()->create(['name' => 'Mel Member']);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('Ada Admin');
        $response->assertSee('Mel Member');
    }

    public function test_users_page_flags_an_unverified_account_for_moderation(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->unverified()->create(['name' => 'Uma Unverified']);

        $response = $this->actingAs($admin)->get(route('admin.users.index'));

        $response->assertOk();
        $response->assertSee('Uma Unverified');
        $response->assertSee('Unverified');
    }

    // --- creating users ------------------------------------------------------

    public function test_admin_can_create_a_member(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Teammate',
            'email' => 'teammate@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
        ])->assertRedirect(route('admin.users.index'));

        $created = User::where('email', 'teammate@example.com')->first();
        $this->assertNotNull($created);
        $this->assertFalse($created->is_admin);
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check('super-secret-pw', $created->password));
    }

    public function test_admin_can_create_another_admin_via_the_flag(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Second Admin',
            'email' => 'admin2@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
            'is_admin' => '1',
        ]);

        $this->assertTrue(User::where('email', 'admin2@example.com')->first()->is_admin);
    }

    public function test_create_rejects_a_duplicate_email_and_a_mismatched_password(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Dupe',
            'email' => 'taken@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'super-secret-pw',
        ])->assertSessionHasErrors('email');

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Mismatch',
            'email' => 'fresh@example.com',
            'password' => 'super-secret-pw',
            'password_confirmation' => 'different-pw',
        ])->assertSessionHasErrors('password');

        $this->assertNull(User::where('email', 'fresh@example.com')->first());
    }

    // --- role + activation toggles ------------------------------------------

    public function test_admin_can_promote_and_demote_another_user(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)->patch(route('admin.users.toggle-admin', $member))
            ->assertRedirect(route('admin.users.index'));
        $this->assertTrue($member->fresh()->is_admin);

        $this->actingAs($admin)->patch(route('admin.users.toggle-admin', $member));
        $this->assertFalse($member->fresh()->is_admin);
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        // The self-lockout guard: since you can only demote someone else, at least
        // one admin always remains.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('admin.users.toggle-admin', $admin))->assertForbidden();
        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_admin_can_deactivate_and_reactivate_another_user(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $member));
        $this->assertFalse($member->fresh()->is_active);

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $member));
        $this->assertTrue($member->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch(route('admin.users.toggle-active', $admin))->assertForbidden();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_manually_verify_a_user_whose_email_never_arrived(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->unverified()->create();

        $this->actingAs($admin)->patch(route('admin.users.verify', $user))
            ->assertRedirect(route('admin.users.index'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_non_admin_cannot_verify_a_user(): void
    {
        $member = User::factory()->create();
        $target = User::factory()->unverified()->create();

        $this->actingAs($member)->patch(route('admin.users.verify', $target))->assertForbidden();
        $this->assertFalse($target->fresh()->hasVerifiedEmail());
    }

    // --- deactivation actually blocks access --------------------------------

    public function test_a_deactivated_user_cannot_log_in(): void
    {
        $user = User::factory()->deactivated()->create([
            'email' => 'off@example.com',
            'password' => Hash::make('the-password'),
        ]);

        $this->post('/login', ['email' => 'off@example.com', 'password' => 'the-password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_user_deactivated_mid_session_is_signed_out_on_the_next_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // --- admin audit view + cross-user run visibility ------------------------

    public function test_admin_activity_view_shows_every_users_runs(): void
    {
        $admin = User::factory()->admin()->create();
        $someoneElse = User::factory()->create();
        ScriptRun::factory()->create([
            'user_id' => $someoneElse->id,
            'script_slug' => 'ebay.export-listings',
            'status' => ScriptRunStatus::Succeeded,
        ]);

        $response = $this->actingAs($admin)->get(route('admin.runs.index'));

        $response->assertOk();
        $response->assertSee('Export Listings (roster)');
        $response->assertSee($someoneElse->name);
    }

    public function test_admin_can_view_another_users_run_detail_but_not_act_on_it(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $run = ScriptRun::factory()->create([
            'user_id' => $owner->id,
            'script_slug' => 'ebay.enrich-listings',
            'status' => ScriptRunStatus::Running,
        ]);

        // View: allowed (audit override).
        $this->actingAs($admin)->get(route('runs.show', $run))->assertOk();
        $this->actingAs($admin)->get(route('runs.output', $run))->assertOk();

        // Mutate: still owner-only, even for an admin.
        Bus::fake();
        $this->actingAs($admin)->postJson(route('runs.cancel', $run))->assertNotFound();
        $this->assertNull($run->fresh()->cancel_requested_at);
    }

    public function test_admin_auditing_an_eligible_run_does_not_see_a_promote_button_that_would_404(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = User::factory()->create();
        $run = ScriptRun::factory()->create([
            'user_id' => $owner->id,
            'script_slug' => 'ebay.apply-aspects',
            'status' => ScriptRunStatus::Succeeded,
            'params' => ['account' => 'dows', 'item' => '123456789012', 'verify' => true],
        ]);

        // The owner is offered the promote button...
        $this->actingAs($owner)->get(route('runs.show', $run))->assertSee('Promote to live', false);
        // ...but an admin only auditing it is not, since promotion is owner-only.
        $this->actingAs($admin)->get(route('runs.show', $run))->assertOk()->assertDontSee('Promote to live', false);
    }
}
