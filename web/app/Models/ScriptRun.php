<?php

namespace App\Models;

use App\Scripts\ScriptDefinition;
use App\Scripts\ScriptType;
use Database\Factories\ScriptRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScriptRun extends Model
{
    /** @use HasFactory<ScriptRunFactory> */
    use HasFactory;

    protected $fillable = [
        'script_slug',
        'user_id',
        'preview_run_id',
        'params',
        'status',
        'exit_code',
        'pid',
        'stdout',
        'stderr',
        'started_at',
        'finished_at',
        'cancel_requested_at',
        'confirmation_text',
        'confirmed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'status' => ScriptRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function previewRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'preview_run_id');
    }

    /**
     * Single source of truth for "is this preview run allowed to be promoted to a
     * live write" — used by both RunConfirmationController's actual gate and the
     * runs.show view's "Promote to live" link, so they can never drift apart.
     */
    public function isEligibleForLiveConfirmation(ScriptDefinition $definition): bool
    {
        return $definition->type === ScriptType::Write
            && $this->status === ScriptRunStatus::Succeeded
            && filter_var($this->params['verify'] ?? null, FILTER_VALIDATE_BOOL);
    }

    /**
     * Where this run's copied-on-completion output files live — one directory per
     * run, so a later run overwriting a script's source output file never changes
     * what an earlier run's download shows. Single source of truth: RunScriptJob
     * (writes here), RunController::show/download (read here).
     */
    public function storageDirectory(): string
    {
        return "script-runs/{$this->id}";
    }

    /**
     * Every long-running script in ebay/scripts/ already prints its own
     * progress as a plain "N/M" tick (see e.g. audit_shipping_policy.php,
     * check_upc_category_support.php, build_gtin_report.php — the de facto
     * convention across that directory, not something invented for this
     * feature). Reusing it here — the last such match in stdout so far —
     * means every script that already reports progress this way gets a
     * real percentage for free, with zero changes to any CLI script.
     * Scripts with no such output just get null, a fine degrade.
     *
     * Single source of truth: RunController::output() (the live poll
     * target) and the dashboard/runs-index list views both call this,
     * rather than each re-implementing the same regex.
     */
    public function progressPercent(): ?int
    {
        $stdout = $this->stdout ?? '';

        if (! preg_match_all('/(\d+)\s*\/\s*(\d+)\b/', $stdout, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $last = end($matches);
        $numerator = (int) $last[1];
        $denominator = (int) $last[2];

        if ($denominator <= 0 || $numerator > $denominator) {
            return null;
        }

        return (int) round($numerator / $denominator * 100);
    }
}
