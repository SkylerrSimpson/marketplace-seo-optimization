{{-- Same 4-color treatment as scripts/_output-panel.blade.php's live pill —
kept as its own tiny partial so the dashboard and /runs list (both plain
server-rendered tables, no Alpine reactivity needed for a glance-at-a-list
view) don't duplicate the color mapping a third time. --}}
<span @class([
    'px-2 py-0.5 rounded text-xs font-medium',
    'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' => in_array($run->status->value, ['pending', 'running'], true),
    'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' => $run->status->value === 'succeeded',
    'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' => $run->status->value === 'failed',
    'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $run->status->value === 'cancelled',
])>{{ $run->status->value }}</span>
@if (! $run->status->isTerminal() && $run->progressPercent() !== null)
    <span class="text-gray-400 dark:text-gray-500 text-xs">{{ $run->progressPercent() }}%</span>
@endif
