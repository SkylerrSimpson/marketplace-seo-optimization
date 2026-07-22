<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Scripts\ParamType;
use App\Scripts\ScriptRegistry;
use App\Scripts\ScriptType;
use Illuminate\Foundation\Http\FormRequest;

class ScriptRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Rules are the allowlist, same trick as CredentialUpdateRequest: a Write-type
     * script's 'live'/'confirm' params are never added here, so a crafted POST
     * containing them can never survive into validated() no matter what the
     * rendered form does or doesn't show.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $definition = app(ScriptRegistry::class)->find((string) $this->route('slug'));
        $excluded = $definition->type === ScriptType::Write ? ['live', 'confirm'] : [];

        $rules = [];
        foreach ($definition->params as $param) {
            if (in_array($param->name, $excluded, true)) {
                continue;
            }

            $typeRules = match ($param->type) {
                ParamType::Enum => ['in:'.implode(',', $param->options ?? [])],
                ParamType::Bool => ['boolean'],
                ParamType::Int => ['integer'],
                ParamType::String => ['string'],
                // 5MB cap: generous for these CSVs, small enough to reject an
                // obviously wrong upload fast rather than let it hang on a
                // multi-hundred-listing subprocess run.
                ParamType::File => ['file', 'mimes:csv,txt', 'max:5120'],
            };

            $rules[$param->name] = array_merge([$param->required ? 'required' : 'nullable'], $typeRules);
        }

        return $rules;
    }
}
