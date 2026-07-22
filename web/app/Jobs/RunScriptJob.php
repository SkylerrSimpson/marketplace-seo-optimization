<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketplaceCredential;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Scripts\CredentialEnvMapper;
use App\Scripts\ParamType;
use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptRegistry;
use App\Scripts\ScriptType;
use App\Scripts\WriteConfirmationMode;
use App\Scripts\WriteConfirmationResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process as SymfonyProcess;

class RunScriptJob implements ShouldQueue
{
    use Queueable;

    // Hard ceiling on a single run. Shared with the stuck-run reaper
    // (App\Console\Commands\ReapStuckRuns): a run still marked Running long past
    // this can only mean the worker died mid-process, since the process itself
    // could never outlive the timeout.
    public const TIMEOUT_SECONDS = 3600;

    public function __construct(public readonly ScriptRun $scriptRun) {}

    public function handle(
        ScriptRegistry $registry,
        CredentialEnvMapper $envMapper,
        WriteConfirmationResolver $confirmationResolver,
    ): void {
        // Cancelled while still Pending in the queue — a real race, not
        // hypothetical, since a job can sit queued for a moment. Checked
        // BEFORE the child process is ever spawned, so a write-type run can
        // never slip a live call through a cancel-before-start race.
        if ($this->scriptRun->fresh()->cancel_requested_at !== null) {
            $this->scriptRun->update(['status' => ScriptRunStatus::Cancelled, 'finished_at' => now()]);

            return;
        }

        $this->scriptRun->update(['status' => ScriptRunStatus::Running, 'started_at' => now()]);

        try {
            $definition = $registry->find($this->scriptRun->script_slug);
            $params = $this->scriptRun->params;

            $pending = Process::path(config('paths.repo_root'))
                ->env($this->buildEnv($definition, $params, $envMapper))
                // These scripts can legitimately run 20-30+ min against a full
                // catalog, so a short default timeout would kill a healthy run.
                ->timeout(self::TIMEOUT_SECONDS);

            $stdin = $this->buildStdin($definition, $params, $confirmationResolver);
            if ($stdin !== null) {
                $pending->input($stdin);
            }

            // start() (not run()) so we get the real OS PID immediately,
            // before the process has finished — a Cancel request arrives as
            // a totally separate HTTP request/PHP process and has no way to
            // reach this one except by signaling that PID directly.
            $invoked = $pending->start(
                $this->buildArgv($definition, $params),
                fn (string $type, string $output) => $this->appendOutput($type, $output),
            );
            $this->scriptRun->update(['pid' => $invoked->id()]);

            // Closes the other race: cancel arrived in the gap between
            // start() and the pid landing in the DB above. This is the only
            // moment a same-process ->signal() call is possible — after
            // this we only have the PID for cross-process signaling.
            if ($this->scriptRun->fresh()->cancel_requested_at !== null) {
                $invoked->signal(SIGTERM);
            }

            try {
                $result = $invoked->wait();
            } catch (ProcessSignaledException $e) {
                // A signalled process is our own cancel() landing — Symfony throws
                // instead of returning a result when a process dies by signal. It's
                // an expected cancellation, not an infra failure: rebuild a normal
                // ProcessResult so the rest of this method runs like any completion.
                $result = new ProcessResult($e->getProcess());
            }

            // The cancel flag only ever exists in the DB, set by a different
            // PHP process — re-read fresh rather than trust anything held
            // in memory since start().
            $cancelled = $this->scriptRun->fresh()->cancel_requested_at !== null;
            $status = $cancelled
                ? ScriptRunStatus::Cancelled
                : ($result->successful() ? ScriptRunStatus::Succeeded : ScriptRunStatus::Failed);

            $this->scriptRun->update([
                'status' => $status,
                'exit_code' => $result->exitCode(),
                'stdout' => $result->output(),
                'stderr' => $result->errorOutput(),
                'finished_at' => now(),
            ]);

            // Copied regardless of status — a failed/cancelled run's own audit
            // CSVs are often the most useful output (the partial record of what a
            // stopped write already sent). Skipped only on the catch below, where
            // the process never ran.
            $this->copyOutputFiles($definition, $params);
        } catch (\Throwable $e) {
            // Job/infra failure (process never ran) — exit_code stays null to
            // distinguish this from "the script ran and returned nonzero."
            $this->scriptRun->update([
                'status' => ScriptRunStatus::Failed,
                'stderr' => $e->getMessage(),
                'finished_at' => now(),
            ]);
        }
    }

    /**
     * Called on every stdout/stderr chunk as the process produces it, so a run's
     * page streams live output instead of blanking until it finishes. $type is
     * Symfony's Process::OUT ('out') or Process::ERR ('err').
     */
    private function appendOutput(string $type, string $output): void
    {
        if ($output === '') {
            return;
        }

        $field = $type === SymfonyProcess::OUT ? 'stdout' : 'stderr';
        $this->scriptRun->{$field} = ($this->scriptRun->{$field} ?? '').$output;
        $this->scriptRun->save();
    }

    /** @param  array<string, mixed>  $params */
    private function buildArgv(ScriptDefinition $definition, array $params): array
    {
        $argv = [$definition->interpreter, $definition->cliPath];

        foreach ($definition->params as $param) {
            $value = $params[$param->name] ?? null;

            if ($param->type === ParamType::Bool) {
                if (filter_var($value, FILTER_VALIDATE_BOOL)) {
                    $argv[] = $param->flag;
                }

                continue;
            }

            if ($value !== null && $value !== '') {
                $argv[] = "{$param->flag}={$value}";
            }
        }

        return $argv;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function buildEnv(ScriptDefinition $definition, array $params, CredentialEnvMapper $envMapper): array
    {
        // Convention, not a formal ScriptDefinition field: a script that needs
        // credentials names its account-selecting param 'account', matching its own
        // --account flag. A script with no such param (e.g. every Shopify script —
        // single flat store, no --account flag exists) falls back to the 'default'
        // sentinel account instead of skipping injection entirely — see
        // config/credentials.php's 'accounts' => ['default'] for that marketplace.
        $account = $params['account'] ?? null;
        if (! is_string($account) || $account === '') {
            $account = 'default';
        }

        // The run owner's OWN credentials, never a shared or another user's row —
        // read from the stored user_id since a queue worker has no auth() context.
        $credential = MarketplaceCredential::forUser($this->scriptRun->user_id)
            ->forAccount($definition->marketplace, $account)
            ->first();

        // Blank baseline for every credential var this marketplace defines, with
        // the user's stored values layered on top. Injecting explicit blanks —
        // rather than returning [] when a user has no (or partial) credentials —
        // stops the subprocess from silently inheriting whatever marketplace creds
        // the repo .env might hold: a credential-less run must fail its own auth,
        // never borrow another user's or the server's tokens.
        $fields = config("credentials.{$definition->marketplace}.fields", []);
        $values = array_merge(array_fill_keys($fields, ''), $credential?->credentials ?? []);

        return $envMapper->envFor($definition->marketplace, $account, $values);
    }

    /**
     * apply_aspects.php-shaped write scripts block on an interactive STDIN retype
     * confirmation for a single-item --live run (their own safety gate, not ours to
     * bypass). DOWScripts' web confirmation already validated the exact same retyped
     * text before this job was ever dispatched — piping it as stdin here answers the
     * script's own prompt honestly, it doesn't route around it. Bulk --live uses
     * --confirm=WRITE as a plain CLI flag instead, so no stdin is needed there.
     *
     * @param  array<string, mixed>  $params
     */
    private function buildStdin(ScriptDefinition $definition, array $params, WriteConfirmationResolver $resolver): ?string
    {
        if ($definition->type !== ScriptType::Write) {
            return null;
        }

        if (! filter_var($params['live'] ?? null, FILTER_VALIDATE_BOOL)) {
            return null;
        }

        if ($resolver->resolve($params) !== WriteConfirmationMode::Single) {
            return null;
        }

        return $resolver->singleItemId($params);
    }

    /**
     * Copies, not references — these scripts write their output files in place
     * with no per-run naming, so a later run overwriting the source must never
     * change what an earlier run's download shows. Each run gets its own storage
     * directory (ScriptRun::storageDirectory() — single source of truth, also read
     * by RunController).
     *
     * @param  array<string, mixed>  $params
     */
    private function copyOutputFiles(ScriptDefinition $definition, array $params): void
    {
        $repoRoot = config('paths.repo_root');

        foreach ($definition->outputFiles as $template) {
            if (str_contains($template, '{account}')) {
                $account = $params['account'] ?? null;
                if (! is_string($account) || $account === '') {
                    continue; // template needs an account we don't have — skip, don't guess
                }
                $template = str_replace('{account}', $account, $template);
            }

            $sourcePath = $repoRoot.'/'.$template;
            if (! is_file($sourcePath)) {
                continue;
            }

            Storage::disk('local')->put(
                $this->scriptRun->storageDirectory().'/'.basename($template),
                (string) file_get_contents($sourcePath),
            );
        }
    }
}
