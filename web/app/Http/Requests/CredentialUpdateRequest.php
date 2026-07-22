<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CredentialUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any logged-in user is trusted — PLAN.md §3: 1-2 known accounts, no roles.
        return $this->user() !== null;
    }

    /**
     * Rules are built from config/credentials.php for this route's marketplace —
     * this is also the mass-assignment guard: a field name not listed there is
     * simply never in validated(), so it can never reach the stored credentials
     * array no matter what a crafted request submits.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $knownFields = config('credentials.'.$this->route('marketplace').'.fields', []);

        return array_fill_keys($knownFields, ['nullable', 'string']);
    }
}
