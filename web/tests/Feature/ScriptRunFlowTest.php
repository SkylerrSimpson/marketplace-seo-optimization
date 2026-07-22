<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunScriptJob;
use App\Models\MarketplaceCredential;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Models\User;
use App\Scripts\CredentialEnvMapper;
use App\Scripts\ScriptRegistry;
use App\Scripts\WriteConfirmationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ScriptRunFlowTest extends TestCase
{
    use RefreshDatabase;

    private ?User $actingUser = null;

    /** Memoized signed-in user; runs/credentials default to being owned by them
     * so the per-user scoping and ownership guards resolve their own data. */
    private function actingUser(): User
    {
        return $this->actingUser ??= User::factory()->create();
    }

    public function test_guest_is_redirected_to_login_for_all_script_and_run_routes(): void
    {
        $this->get(route('scripts.index'))->assertRedirect('/login');
        $this->get(route('scripts.show', 'ebay.enrich-listings'))->assertRedirect('/login');
        $this->post(route('scripts.run', 'ebay.enrich-listings'))->assertRedirect('/login');
        $this->get(route('runs.index'))->assertRedirect('/login');
    }

    public function test_index_lists_both_registered_scripts(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));

        $response->assertOk();
        $response->assertSee('ebay.enrich-listings', false);
        $response->assertSee('ebay.apply-aspects', false);
    }

    public function test_index_filtered_by_marketplace_still_shows_both_ebay_scripts(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.index', ['marketplace' => 'ebay']));

        $response->assertOk();
        $response->assertSee('ebay.enrich-listings', false);
        $response->assertSee('ebay.apply-aspects', false);
    }

    public function test_index_filtered_by_type_read_shows_only_read_scripts(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.index', ['type' => 'read']));

        $response->assertOk();
        $response->assertSee('ebay.enrich-listings', false);
        $response->assertDontSee('ebay.apply-aspects', false);
    }

    public function test_index_filtered_by_type_write_shows_only_write_scripts(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.index', ['type' => 'write']));

        $response->assertOk();
        $response->assertSee('ebay.apply-aspects', false);
        $response->assertDontSee('ebay.enrich-listings', false);
    }

    public function test_index_shows_a_type_filter_control_with_all_three_options(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));

        $response->assertOk();
        $response->assertSee('All');
        $response->assertSee('Read-only');
        $response->assertSee('Write');
    }

    public function test_index_type_filter_control_preserves_the_active_marketplace(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.index', ['marketplace' => 'ebay', 'type' => 'read']));

        $response->assertOk();
        // The "Write" pill, clicked from here, must stay scoped to ebay rather
        // than resetting the marketplace filter back to "every marketplace".
        // Blade HTML-escapes the href, so & becomes &amp; in the response body.
        $response->assertSee(
            str_replace('&', '&amp;', route('scripts.index', ['marketplace' => 'ebay', 'type' => 'write'])),
            false,
        );
    }

    public function test_index_type_filter_control_highlights_the_active_option(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.index', ['type' => 'write']));

        $response->assertOk();
        // Active pill gets the solid indigo treatment; the "Write" link text
        // is wrapped in that class somewhere before the next pill starts.
        $response->assertSee('bg-indigo-600 text-white', false);
    }

    public function test_write_script_form_never_renders_live_or_confirm_fields(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-aspects'));

        $response->assertOk();
        $response->assertDontSee('name="live"', false);
        $response->assertDontSee('name="confirm"', false);
    }

    public function test_connection_widget_renders_only_for_marketplaces_with_a_connection_check(): void
    {
        // eBay has a registered connection-check script -> widget renders.
        $ebay = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.export-listings'));
        $ebay->assertOk();
        $ebay->assertSee('/connection-check/ebay', false);

        // Shopify has none -> the widget (and its wasted fetch) must not render
        // at all, rather than flashing "Pinging..." and 404ing client-side.
        $shopify = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'shopify.audit-products'));
        $shopify->assertOk();
        $shopify->assertDontSee('/connection-check/shopify', false);
        $shopify->assertDontSee('Pinging...', false);
    }

    public function test_script_page_flags_accounts_with_no_credentials(): void
    {
        // No credential rows at all -> both eBay accounts flagged for the banner.
        $none = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-aspects'));
        $none->assertOk();
        $none->assertViewHas('accountsMissingCredentials', ['dows', 'ige']);

        // Set dows -> only ige remains flagged.
        MarketplaceCredential::create([
            'user_id' => $this->actingUser()->id,
            'marketplace' => 'ebay',
            'account' => 'dows',
            'credentials' => ['app_id' => 'x'],
        ]);

        $some = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-aspects'));
        $some->assertViewHas('accountsMissingCredentials', ['ige']);
    }

    public function test_write_script_page_gates_run_button_per_selected_account(): void
    {
        // A write script carries isWriteScript:true and binds the account picker
        // to selectedAccount, so connectionBlocked() can scope the Run gate to
        // the chosen account instead of blocking on any failed account.
        $write = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-aspects'));
        $write->assertOk();
        $write->assertSee('isWriteScript: true', false);
        $write->assertSee('x-model="selectedAccount"', false);

        // A read script is never gated on the connection check.
        $read = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.export-listings'));
        $read->assertOk();
        $read->assertSee('isWriteScript: false', false);
    }

    public function test_crafted_live_and_confirm_fields_never_reach_the_stored_run(): void
    {
        Bus::fake();

        $this->actingAs($this->actingUser())->post(
            route('scripts.run', 'ebay.apply-aspects'),
            ['account' => 'dows', 'live' => '1', 'confirm' => 'WRITE'],
        )->assertRedirect();

        $run = ScriptRun::first();

        $this->assertNotNull($run);
        $this->assertArrayNotHasKey('live', $run->params);
        $this->assertArrayNotHasKey('confirm', $run->params);
    }

    public function test_store_creates_a_pending_run_and_dispatches_the_job(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $response = $this->actingAs($user)->post(
            route('scripts.run', 'ebay.enrich-listings'),
            ['account' => 'dows', 'limit' => '1'],
        );

        $run = ScriptRun::first();

        $this->assertNotNull($run);
        $this->assertSame(ScriptRunStatus::Pending, $run->status);
        $this->assertSame($user->id, $run->user_id);
        $response->assertRedirect(route('runs.show', $run));

        Bus::assertDispatched(RunScriptJob::class, fn ($job) => $job->scriptRun->is($run));
    }

    public function test_store_with_accept_json_returns_run_id_instead_of_redirecting(): void
    {
        Bus::fake();
        $user = $this->actingUser();

        $response = $this->actingAs($user)->postJson(
            route('scripts.run', 'ebay.enrich-listings'),
            ['account' => 'dows', 'limit' => '1'],
        );

        $run = ScriptRun::first();

        $response->assertCreated();
        $response->assertExactJson(['run_id' => $run->id]);
        Bus::assertDispatched(RunScriptJob::class, fn ($job) => $job->scriptRun->is($run));
    }

    public function test_store_with_accept_json_and_missing_required_param_returns_422(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->actingUser())
            ->postJson(route('scripts.run', 'ebay.enrich-listings'), []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('account');
        $this->assertSame(0, ScriptRun::count());
        Bus::assertNotDispatched(RunScriptJob::class);
    }

    public function test_missing_required_param_is_rejected_and_no_run_is_created(): void
    {
        Bus::fake();

        $this->actingAs($this->actingUser())
            ->post(route('scripts.run', 'ebay.enrich-listings'), [])
            ->assertSessionHasErrors('account');

        $this->assertSame(0, ScriptRun::count());
        Bus::assertNotDispatched(RunScriptJob::class);
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.does-not-exist'))
            ->assertNotFound();
    }

    public function test_run_show_renders_status_and_output(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Succeeded,
            'exit_code' => 0,
            'stdout' => 'fetched 3 items',
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.show', $run));

        $response->assertOk();
        $response->assertSee('succeeded');
        $response->assertSee('fetched 3 items');
    }

    public function test_run_show_404s_for_an_unknown_id(): void
    {
        $this->actingAs($this->actingUser())
            ->get(route('runs.show', 999))
            ->assertNotFound();
    }

    public function test_auto_refresh_meta_present_while_non_terminal(): void
    {
        $run = ScriptRun::factory()->create(['user_id' => $this->actingUser()->id, 'status' => ScriptRunStatus::Running]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.show', $run))
            ->assertSee('http-equiv="refresh"', false);
    }

    public function test_auto_refresh_meta_absent_once_terminal(): void
    {
        $run = ScriptRun::factory()->create(['user_id' => $this->actingUser()->id, 'status' => ScriptRunStatus::Succeeded]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.show', $run))
            ->assertDontSee('http-equiv="refresh"', false);
    }

    public function test_merge_handoff_form_renders_a_file_input_with_multipart_encoding_and_required_columns(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.merge-handoff-approvals'));

        $response->assertOk();
        $response->assertSee('enctype="multipart/form-data"', false);
        $response->assertSee('type="file"', false);
        $response->assertSee('item_id, sku, aspect, final_value');
    }

    public function test_uploading_a_file_stores_it_and_passes_a_path_string_not_the_upload_object(): void
    {
        Storage::fake('local');
        Bus::fake();
        $csv = UploadedFile::fake()->createWithContent(
            'changes.csv',
            "item_id,sku,aspect,final_value\n123,SKU1,Color,Red\n",
        );

        $this->actingAs($this->actingUser())->post(
            route('scripts.run', 'ebay.merge-handoff-approvals'),
            ['account' => 'dows', 'input' => $csv],
        )->assertRedirect();

        $run = ScriptRun::first();

        $this->assertNotNull($run);
        $this->assertIsString($run->params['input']);
        $this->assertFileExists($run->params['input']);
        $this->assertStringContainsString(
            "item_id,sku,aspect,final_value\n123,SKU1,Color,Red\n",
            file_get_contents($run->params['input']),
        );
    }

    public function test_output_files_are_copied_into_the_runs_own_storage_after_completion(): void
    {
        Storage::fake('local');
        Process::fake(['*' => Process::result(output: 'ok', exitCode: 0)]);

        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.enrich-listings',
            'params' => ['account' => 'dows'],
        ]);

        (new RunScriptJob($run))->handle(
            app(ScriptRegistry::class),
            app(CredentialEnvMapper::class),
            app(WriteConfirmationResolver::class),
        );

        // ebay.enrich-listings' real config output_files entry — a real file that
        // already exists on disk from earlier real runs against this repo.
        Storage::disk('local')->assertExists($run->storageDirectory().'/enriched_summary.csv');
    }

    public function test_runs_show_lists_a_download_link_when_output_files_exist(): void
    {
        Storage::fake('local');
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Succeeded,
        ]);
        Storage::disk('local')->put($run->storageDirectory().'/example.csv', "a,b\n1,2\n");

        $response = $this->actingAs($this->actingUser())->get(route('runs.show', $run));

        $response->assertOk();
        $response->assertSee(route('runs.download', ['run' => $run, 'filename' => 'example.csv']), false);
    }

    public function test_download_streams_a_real_output_file(): void
    {
        Storage::fake('local');
        $run = ScriptRun::factory()->create(['user_id' => $this->actingUser()->id]);
        Storage::disk('local')->put($run->storageDirectory().'/example.csv', "a,b\n1,2\n");

        $this->actingAs($this->actingUser())
            ->get(route('runs.download', ['run' => $run, 'filename' => 'example.csv']))
            ->assertOk();
    }

    public function test_download_404s_for_a_path_traversal_attempt(): void
    {
        Storage::fake('local');
        $victim = ScriptRun::factory()->create(['user_id' => $this->actingUser()->id]);
        Storage::disk('local')->put($victim->storageDirectory().'/secret.csv', 'secret');
        $run = ScriptRun::factory()->create(['user_id' => $this->actingUser()->id]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.download', ['run' => $run, 'filename' => '../'.$victim->id.'/secret.csv']))
            ->assertNotFound();
    }

    public function test_download_404s_for_a_filename_that_does_not_exist_in_this_runs_directory(): void
    {
        Storage::fake('local');
        $run = ScriptRun::factory()->create(['user_id' => $this->actingUser()->id]);

        $this->actingAs($this->actingUser())
            ->get(route('runs.download', ['run' => $run, 'filename' => 'nope.csv']))
            ->assertNotFound();
    }

    public function test_merge_handoff_page_shows_review_sheet_reference_file_for_both_accounts(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.merge-handoff-approvals'));

        $response->assertOk();
        $response->assertSee('Review Sheet');
        // The real file exists for both accounts in this repo — both download links
        // should render, not just a generic mention of the file.
        $response->assertSee(route('scripts.reference.download', [
            'slug' => 'ebay.merge-handoff-approvals', 'index' => 0, 'account' => 'dows',
        ]), false);
        $response->assertSee(route('scripts.reference.download', [
            'slug' => 'ebay.merge-handoff-approvals', 'index' => 0, 'account' => 'ige',
        ]), false);
    }

    public function test_merge_handoff_page_shows_the_real_review_sheet_columns_inline(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.merge-handoff-approvals'));

        $response->assertOk();
        $response->assertSee('item_id, sku, varied_by');
    }

    public function test_author_prompt_reference_file_renders_as_a_single_link_no_account(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.author-descriptions-ai'));

        $response->assertOk();
        $response->assertSee(route('scripts.reference.download', [
            'slug' => 'ebay.author-descriptions-ai', 'index' => 2,
        ]), false);
    }

    public function test_reference_download_streams_the_real_file(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.reference.download', [
            'slug' => 'ebay.merge-handoff-approvals', 'index' => 0, 'account' => 'dows',
        ]));

        $response->assertOk();
    }

    public function test_reference_download_404s_for_an_out_of_range_index(): void
    {
        $this->actingAs($this->actingUser())->get(route('scripts.reference.download', [
            'slug' => 'ebay.merge-handoff-approvals', 'index' => 99, 'account' => 'dows',
        ]))->assertNotFound();
    }

    public function test_reference_download_404s_for_an_account_not_on_the_allowlist(): void
    {
        // Proves the allowlist actually runs before the path substitution — not just
        // that the happy path works. A path-traversal payload here must 404, not
        // leak a file outside this script's own reference_files.
        $this->actingAs($this->actingUser())->get(route('scripts.reference.download', [
            'slug' => 'ebay.merge-handoff-approvals', 'index' => 0, 'account' => '..%2f..%2f..%2fetc%2fpasswd',
        ]))->assertNotFound();
    }

    public function test_reference_download_guest_redirected_to_login(): void
    {
        $this->get(route('scripts.reference.download', [
            'slug' => 'ebay.merge-handoff-approvals', 'index' => 0, 'account' => 'dows',
        ]))->assertRedirect('/login');
    }

    public function test_index_shows_the_previously_missing_pipeline_steps(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));

        $response->assertOk();
        $response->assertSee('ebay.export-listings', false);
        $response->assertSee('ebay.build-review-sheet', false);
    }

    public function test_aspects_scripts_render_in_step_order(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));

        $response->assertOk();
        $html = $response->getContent();

        $positions = [];
        foreach ([
            'ebay.export-listings', 'ebay.enrich-listings', 'ebay.build-review-sheet',
            'ebay.merge-handoff-approvals', 'ebay.build-apply-set', 'ebay.apply-aspects',
        ] as $slug) {
            $pos = strpos($html, $slug);
            $this->assertNotFalse($pos, "expected to find {$slug} in the index page");
            $positions[$slug] = $pos;
        }

        $this->assertSame($positions, collect($positions)->sort()->all());
    }

    public function test_build_review_sheet_mode_param_renders_as_a_required_enum_with_both_options(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('id="mode" name="mode"', false);
        $response->assertSee('value="read"', false);
        $response->assertSee('value="worksheet"', false);
        // Both of this script's params are required=true, so no blank option
        // should render for either — the mode choice can't be skipped silently.
        $response->assertDontSee('<option value=""></option>', false);
    }

    public function test_index_shows_the_previously_missing_description_pipeline_steps(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));

        $response->assertOk();
        $response->assertSee('ebay.audit-media', false);
        $response->assertSee('ebay.extract-description-source', false);
        $response->assertSee('ebay.build-description-review', false);
        $response->assertSee('ebay.find-mobile-desc-mismatch', false);
        $response->assertSee('ebay.build-mobile-fix-review', false);
        $response->assertSee('ebay.apply-descriptions', false);
        $response->assertSee('ebay.analyze-descriptions', false);
        $response->assertSee('ebay.audit-description-images', false);
    }

    public function test_descriptions_scripts_render_in_step_order(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));

        $response->assertOk();
        $html = $response->getContent();

        $positions = [];
        foreach ([
            'ebay.audit-media', 'ebay.extract-description-source', 'ebay.author-descriptions-ai',
            'ebay.build-description-review', 'ebay.find-mobile-desc-mismatch',
            'ebay.build-mobile-fix-review', 'ebay.apply-descriptions',
        ] as $slug) {
            $pos = strpos($html, $slug);
            $this->assertNotFalse($pos, "expected to find {$slug} in the index page");
            $positions[$slug] = $pos;
        }

        $this->assertSame($positions, collect($positions)->sort()->all());
    }

    public function test_extract_description_source_has_no_params(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.extract-description-source'));

        $response->assertOk();
        $response->assertDontSee('name="account"', false);
    }

    public function test_apply_descriptions_write_form_never_renders_live_or_confirm_fields(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-descriptions'));

        $response->assertOk();
        $response->assertDontSee('name="live"', false);
        $response->assertDontSee('name="confirm"', false);
    }

    public function test_enum_param_default_is_preselected(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.author-descriptions-ai'));

        $response->assertOk();
        $response->assertSee('<option value="claude-sonnet-4-6" selected>claude-sonnet-4-6</option>', false);
    }

    public function test_int_param_default_is_prefilled(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.author-descriptions-ai'));

        $response->assertOk();
        $response->assertSee('id="chunk-size" name="chunk-size"', false);
        $response->assertSee('value="5"', false);
    }

    public function test_write_confirmation_flow_banner_does_not_claim_it_is_unbuilt(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-aspects'));

        $response->assertOk();
        $response->assertDontSee("isn't built yet", false);
        $response->assertSee('Promote to live', false);
    }

    /**
     * Regression guard for a real bug found 2026-07-16: Blade's component-tag
     * compiler silently fails to compile <x-text-input> when a bare @if(...)@endif
     * sits among its attributes (a pre-existing defect, not something introduced by
     * this app's own code) — every int/string param field on every script page was
     * rendering as literal, non-functional tag text instead of an <input>. Plain
     * substring assertSee() calls (e.g. 'id="X" name="X"') don't catch this, because
     * that exact substring is still present inside the broken raw tag text. This
     * walks every param on every registered script and demands a real <input> or
     * <select> element, and demands no uncompiled Blade component tag ever reaches
     * the response.
     */
    public function test_every_script_page_renders_real_form_controls_for_every_param(): void
    {
        $user = $this->actingUser();
        $registry = app(ScriptRegistry::class);

        foreach ($registry->all() as $definition) {
            $html = $this->actingAs($user)->get(route('scripts.show', $definition->slug))->getContent();

            $this->assertStringNotContainsString('<x-', $html, "{$definition->slug}: an uncompiled Blade component tag leaked into the response");

            foreach ($definition->params as $param) {
                if (in_array($param->name, ['live', 'confirm'], true) && $definition->type->value === 'write') {
                    continue;
                }

                $needle = match ($param->type->value) {
                    'enum' => "<select id=\"{$param->name}\" name=\"{$param->name}\"",
                    default => "id=\"{$param->name}\" name=\"{$param->name}\"",
                };
                $tag = match ($param->type->value) {
                    'enum' => '<select',
                    'bool' => '<input type="checkbox"',
                    'file' => '<input type="file"',
                    default => '<input ',
                };

                $this->assertStringContainsString($needle, $html, "{$definition->slug}: missing form control for param '{$param->name}'");
                // The needle alone isn't enough proof — it's the same substring
                // that appears inside a broken, uncompiled <x-text-input ...> tag.
                // Confirm the actual element tag is present nearby too.
                $pos = strpos($html, $needle);
                $context = substr($html, max(0, $pos - 400), 450);
                $this->assertStringContainsString($tag, $context, "{$definition->slug}: param '{$param->name}' did not render as a real {$tag} element");
            }
        }
    }

    public function test_standalone_scripts_render_in_a_separate_unordered_section(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Not part of a sequence', $html);

        // The standalone scripts (no step) must render strictly after the last
        // ordered pipeline step in their category, never interleaved with it.
        $lastPipelinePos = strpos($html, 'ebay.apply-descriptions');
        $standalonePos = strpos($html, 'ebay.analyze-descriptions');

        $this->assertNotFalse($lastPipelinePos);
        $this->assertNotFalse($standalonePos);
        $this->assertGreaterThan($lastPipelinePos, $standalonePos);
    }

    public function test_pipeline_label_reads_recommended_not_strict(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));
        $response->assertOk();

        $response->assertSee('Recommended order', false);
        $response->assertDontSee('Run top to bottom.', false);
    }

    public function test_optional_step_renders_an_optional_pill(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));
        $response->assertOk();
        $html = $response->getContent();

        // ebay.write-canary-test is marked optional in config — its row should
        // carry the "optional" pill somewhere after its own slug/title, before
        // the next script starts.
        $start = strpos($html, 'ebay.write-canary-test');
        $this->assertNotFalse($start);
        $nextSlugPos = strpos($html, 'ebay.check-connection', $start);
        $segment = substr($html, $start, ($nextSlugPos !== false ? $nextSlugPos : $start + 2000) - $start);
        $this->assertStringContainsString('optional', $segment);
    }

    public function test_same_step_alternates_render_under_a_choose_one_grouping(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));
        $response->assertOk();
        $html = $response->getContent();

        // aspects step 5: Fill Aspects vs. Author Aspects (AI) are alternate
        // paths sharing one step number — both must render, adjacent, under a
        // shared "Choose one:" label rather than as two independent numbered rows.
        $this->assertStringContainsString('Choose one:', $html);

        $fillPos = strpos($html, 'ebay.fill-aspects');
        $authorAiPos = strpos($html, 'ebay.author-aspects-ai');
        $this->assertNotFalse($fillPos);
        $this->assertNotFalse($authorAiPos);
        $this->assertLessThan(1500, abs($fillPos - $authorAiPos), 'expected the step-5 alternates to render adjacent to each other');

        // aspects step 9: same shape, Normalize Units (review sheet) vs. (handoff CSV).
        $reviewSheetUnitsPos = strpos($html, 'ebay.normalize-review-sheet-units');
        $handoffUnitsPos = strpos($html, 'ebay.normalize-handoff-units');
        $this->assertNotFalse($reviewSheetUnitsPos);
        $this->assertNotFalse($handoffUnitsPos);
        $this->assertLessThan(1500, abs($reviewSheetUnitsPos - $handoffUnitsPos), 'expected the step-9 alternates to render adjacent to each other');
    }

    public function test_show_page_lists_columns_created_before_the_run_button(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('What this creates', $html);
        $this->assertStringContainsString('review_sheet.csv', $html);
        $this->assertStringContainsString('Columns created:', $html);
        $this->assertStringContainsString('approved_value', $html);

        // Must appear before the Run button, not after.
        $createsPos = strpos($html, 'What this creates');
        $runButtonPos = strpos($html, 'type="submit"');
        $this->assertNotFalse($runButtonPos);
        $this->assertLessThan($runButtonPos, $createsPos);
    }

    public function test_show_page_omits_creates_section_when_script_has_none(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.extract-description-source'));

        $response->assertOk();
        $response->assertDontSee('What this creates');
    }

    public function test_index_lays_out_categories_in_a_two_column_grid(): void
    {
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index', ['marketplace' => 'ebay']));

        $response->assertOk();
        $response->assertSee('grid grid-cols-1 md:grid-cols-2', false);
    }

    public function test_show_page_has_a_breadcrumb_back_to_the_scripts_index(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('Back to Scripts', false);
        $response->assertSee('aria-label="Breadcrumb"', false);
        $response->assertSee('href="'.route('dashboard').'"', false);
        $response->assertSee('href="'.route('scripts.index', ['marketplace' => 'ebay']).'"', false);
    }

    /**
     * Regression guard for a real bug found 2026-07-17: resumeRun()/cancelRun()
     * called document.querySelector('meta[name="csrf-token"]') — those inner
     * double quotes sit inside the OUTER x-data="..." HTML attribute (also
     * double-quoted), so the browser's HTML parser treated the first '"'
     * before "csrf-token" as the end of x-data, and everything after it
     * (the rest of both methods, through init()) leaked onto the page as
     * literal visible text instead of being parsed as markup. assertSee()
     * substring checks elsewhere in this file wouldn't catch this — they
     * pass whether the surrounding HTML is valid or not — so this asserts
     * the exact broken-HTML signature is absent, and that the safe unquoted
     * selector form is what's actually rendered.
     */
    public function test_script_page_never_leaks_js_as_visible_text_via_unescaped_quotes_in_x_data(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-aspects'));

        $response->assertOk();
        $response->assertDontSee('r.json()).then((data) => this.trackNewRun(data.run_id)); }, cancelRun()', false);
        $response->assertDontSee('meta[name="csrf-token"]', false);
        $response->assertSee('meta[name=csrf-token]', false);
        // A comment inside the cancelling-state field once had a literal "
        // (`which reads as "did that click even register?"`), which broke the
        // x-data="{ ... }" attribute at that quote and leaked the rest of the
        // JS object (startPolling, trackNewRun, submitRun, cancelRun, init...)
        // onto the page as visible text.
        $response->assertDontSee('which reads as "did that click', false);
        $response->assertDontSee('poll() { if (this.runId === null) return; const el = this.$refs.terminal;', false);
    }

    public function test_index_type_filter_all_pill_highlights_when_no_type_is_active(): void
    {
        // A `[null => ...]` array key silently casts to '' (PHP array keys
        // can't be null) — a regression that made the "All" pill's active-state
        // comparison ($activeType?->value === $value) compare null against ''
        // and never match, so "All" rendered as inactive even on the
        // no-type-filter page.
        $response = $this->actingAs($this->actingUser())->get(route('scripts.index'));

        $response->assertOk();
        $html = $response->getContent();
        preg_match('/<a href="[^"]*"\s+class="([^"]*)">\s*All\s*<\/a>/', $html, $matches);
        $this->assertNotEmpty($matches, 'Could not locate the "All" filter pill in the response.');
        $this->assertStringContainsString('bg-indigo-600', $matches[1]);
    }

    public function test_script_page_shows_no_runs_placeholder_when_none_exist(): void
    {
        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('No runs yet', false);
    }

    public function test_script_page_shows_the_latest_runs_output_and_a_link_to_the_full_run(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Succeeded,
            'stdout' => 'fetched 3 items',
            'exit_code' => 0,
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('fetched 3 items', false);
        $response->assertSee('View full run details', false);
        // Terminal panel shrinks for short output instead of a fixed height
        // that leaves empty dark space below a couple of lines — bounded on
        // both ends (min so it still reads as a terminal, max so a long run
        // scrolls instead of growing the page forever).
        $response->assertSee('min-h-[8rem] max-h-[32rem]', false);
        $response->assertSee('href="'.route('runs.show', $run).'"', false);
    }

    public function test_script_page_running_run_carries_live_polling_markup(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Running,
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('data-run-id="'.$run->id.'"', false);
        $response->assertSee('data-terminal="0"', false);
    }

    public function test_script_page_terminal_run_marks_polling_markup_as_terminal(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Succeeded,
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('data-run-id="'.$run->id.'"', false);
        $response->assertSee('data-terminal="1"', false);
    }

    public function test_script_page_shows_promote_to_live_link_for_an_eligible_write_run(): void
    {
        ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.apply-aspects',
            'status' => ScriptRunStatus::Succeeded,
            'params' => ['account' => 'dows', 'item' => '123456789012', 'verify' => true],
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.apply-aspects'));

        $response->assertOk();
        $response->assertSee('Ready to promote to live', false);
    }

    public function test_script_page_shows_a_cancel_button_only_while_non_terminal(): void
    {
        ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Running,
        ]);

        $running = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));
        $running->assertSee('cancelRun()', false);
    }

    public function test_script_page_cancel_button_carries_a_cancelling_transitional_state(): void
    {
        ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Running,
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        // The button disables and relabels itself the instant cancelRun() sets
        // cancelling = true, rather than sitting unchanged until the next poll.
        $response->assertSee('x-bind:disabled="cancelling"', false);
        $response->assertSee("cancelling ? '", false);
        $response->assertSee('this.cancelling = true', false);
    }

    public function test_script_page_shows_run_again_for_a_failed_run_on_a_script_without_a_resume_param(): void
    {
        ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Failed,
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('resumeRun()', false);
        // ebay.build-review-sheet has no `resume` param registered — the
        // label must not overpromise incremental resume it doesn't have.
        $response->assertSee('Run again', false);
        $response->assertDontSee('>Resume<', false);
    }

    public function test_script_page_shows_cancelled_status_pill_color(): void
    {
        ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Cancelled,
        ]);

        $response = $this->actingAs($this->actingUser())
            ->get(route('scripts.show', 'ebay.build-review-sheet'));

        $response->assertOk();
        $response->assertSee('bg-gray-200', false);
        $response->assertSee('resumeRun()', false);
    }

    public function test_runs_index_shows_resolved_title_and_colored_status_pill(): void
    {
        ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'script_slug' => 'ebay.build-review-sheet',
            'status' => ScriptRunStatus::Succeeded,
        ]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.index'));

        $response->assertOk();
        $response->assertSee('Read Aspects (build worksheet)', false);
        $response->assertSee('bg-green-100', false);
    }

    public function test_runs_index_filters_by_status(): void
    {
        ScriptRun::factory()->create(['user_id' => $this->actingUser()->id, 'script_slug' => 'ebay.export-listings', 'status' => ScriptRunStatus::Succeeded]);
        ScriptRun::factory()->create(['user_id' => $this->actingUser()->id, 'script_slug' => 'ebay.apply-aspects', 'status' => ScriptRunStatus::Failed]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.index', ['status' => 'failed']));

        $response->assertOk();
        $response->assertSee($this->titleOf('ebay.apply-aspects'), false);
        $response->assertDontSee($this->titleOf('ebay.export-listings'), false);
    }

    public function test_runs_index_filters_by_marketplace(): void
    {
        ScriptRun::factory()->create(['user_id' => $this->actingUser()->id, 'script_slug' => 'ebay.export-listings', 'status' => ScriptRunStatus::Succeeded]);
        ScriptRun::factory()->create(['user_id' => $this->actingUser()->id, 'script_slug' => 'shopify.audit-products', 'status' => ScriptRunStatus::Succeeded]);

        $response = $this->actingAs($this->actingUser())->get(route('runs.index', ['marketplace' => 'shopify']));

        $response->assertOk();
        $response->assertSee($this->titleOf('shopify.audit-products'), false);
        $response->assertDontSee($this->titleOf('ebay.export-listings'), false);
    }

    private function titleOf(string $slug): string
    {
        return app(ScriptRegistry::class)->find($slug)->title;
    }

    public function test_runs_index_auto_refreshes_only_while_a_run_is_non_terminal(): void
    {
        $run = ScriptRun::factory()->create([
            'user_id' => $this->actingUser()->id,
            'status' => ScriptRunStatus::Running,
        ]);

        $running = $this->actingAs($this->actingUser())->get(route('runs.index'));
        $running->assertSee('http-equiv="refresh"', false);

        $run->update(['status' => ScriptRunStatus::Succeeded]);

        $done = $this->actingAs($this->actingUser())->get(route('runs.index'));
        $done->assertDontSee('http-equiv="refresh"', false);
    }
}
