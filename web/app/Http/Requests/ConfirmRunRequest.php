<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ScriptRun;
use App\Scripts\WriteConfirmationMode;
use App\Scripts\WriteConfirmationResolver;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ScriptRun $run */
        $run = $this->route('run');
        $resolver = app(WriteConfirmationResolver::class);
        $mode = $resolver->resolve($run->params);

        if ($mode === WriteConfirmationMode::Bulk) {
            // Exact literal match — mirrors apply_aspects.php's own
            // ($opts['confirm'] ?? '') !== 'WRITE' check (line 87), not a loose
            // case-insensitive "yes I'm sure" checkbox.
            return ['confirmation' => ['required', 'in:WRITE']];
        }

        $itemId = $resolver->singleItemId($run->params);

        return [
            'confirmation' => ['required', 'string', function ($attribute, $value, $fail) use ($itemId) {
                if ($value !== $itemId) {
                    $fail('The retyped item ID does not match.');
                }
            }],
        ];
    }
}
