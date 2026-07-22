<?php

declare(strict_types=1);

namespace Ige\Amazon\Ai\Concurrent;

/**
 * A completion-driven progress line for a ParallelRunner run, suitable as the
 * runner's $onProgress callback. Each call reflects one request that actually
 * finished, so the bar only moves when real work does — not a time-based spinner
 * that animates whether or not anything is happening.
 *
 * On a TTY it redraws a single in-place line (carriage return); when output is
 * piped or redirected it degrades to one newline-terminated update per ~10%
 * milestone, so logs stay readable. Writes to STDERR so it never mixes into the
 * data the script emits on STDOUT.
 */
final class ProgressReporter
{
    private const SPIN = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private bool $tty;
    private int $lastMilestone = -1;

    public function __construct(private int $barWidth = 24)
    {
        $this->tty = @stream_isatty(STDERR);
    }

    public function __invoke(int $done, int $total, int $ok, int $err): void
    {
        if ($total <= 0) {
            return;
        }
        $pct = (int) floor(100 * $done / $total);

        if ($this->tty) {
            $filled = (int) round($this->barWidth * $done / $total);
            $bar    = str_repeat('█', $filled) . str_repeat('░', $this->barWidth - $filled);
            $spin   = self::SPIN[$done % count(self::SPIN)];
            fwrite(STDERR, sprintf(
                "\r  %s [%s] %d/%d (%d%%)  ok=%d err=%d ",
                $spin, $bar, $done, $total, $pct, $ok, $err,
            ));

            return;
        }

        // Non-TTY: emit only when a new 10% milestone (or the final tick) is hit.
        $milestone = $done === $total ? 100 : $pct - ($pct % 10);
        if ($milestone > $this->lastMilestone) {
            $this->lastMilestone = $milestone;
            fwrite(STDERR, sprintf("  … %d/%d (%d%%) ok=%d err=%d\n", $done, $total, $pct, $ok, $err));
        }
    }

    /** Close the in-place line so subsequent output starts fresh. */
    public function finish(): void
    {
        if ($this->tty) {
            fwrite(STDERR, PHP_EOL);
        }
    }
}
