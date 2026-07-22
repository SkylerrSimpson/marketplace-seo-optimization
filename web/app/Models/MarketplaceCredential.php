<?php

namespace App\Models;

use Database\Factories\MarketplaceCredentialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceCredential extends Model
{
    /** @use HasFactory<MarketplaceCredentialFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'marketplace',
        'account',
        'credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Credentials are private to their owner. Every read/write path scopes by
     * user first, so one teammate can never see or use another's tokens — chain
     * this ahead of forAccount() at every call site.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForAccount(Builder $query, string $marketplace, string $account): Builder
    {
        return $query->where('marketplace', $marketplace)->where('account', $account);
    }

    /**
     * Never the decrypted values — just how many of this marketplace's known fields
     * (config/credentials.php) are currently set, computed against key presence
     * only. Shared by CredentialController::index() and the dashboard so the two
     * can't drift on what "configured" means.
     *
     * @return array{marketplace: string, account: string, setCount: int, fieldCount: int}
     */
    public function summaryRow(): array
    {
        $knownFields = config('credentials.'.$this->marketplace.'.fields', []);

        return [
            'marketplace' => $this->marketplace,
            'account' => $this->account,
            'setCount' => count(array_intersect($knownFields, array_keys($this->credentials ?? []))),
            'fieldCount' => count($knownFields),
        ];
    }
}
