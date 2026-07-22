<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CredentialUpdateRequest;
use App\Models\MarketplaceCredential;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CredentialController extends Controller
{
    public function index(): View
    {
        $credentials = MarketplaceCredential::forUser(auth()->id())
            ->orderBy('marketplace')->orderBy('account')->get();
        $marketplaces = array_keys(config('credentials', []));

        return view('credentials.index', [
            'credentials' => $credentials->map(fn (MarketplaceCredential $c) => $c->summaryRow()),
            'marketplaces' => $marketplaces,
            // marketplace => known accounts (may be []), so the "Add an account" form
            // can swap between a dropdown and free text per marketplace client-side.
            'accountsByMarketplace' => array_combine(
                $marketplaces,
                array_map(fn (string $m) => config("credentials.{$m}.accounts", []), $marketplaces),
            ),
        ]);
    }

    public function newForm(Request $request): RedirectResponse
    {
        // A marketplace with a known account roster (config('credentials.*.accounts'))
        // must pick one of those — free text there is exactly how a typo'd account
        // (e.g. "Dows") ends up as a dead credential row no script will ever match.
        // A marketplace with no roster configured yet falls back to free text.
        $knownAccounts = config('credentials.'.$request->input('marketplace').'.accounts', []);

        $validated = $request->validate([
            'marketplace' => ['required', 'string', 'in:'.implode(',', array_keys(config('credentials', [])))],
            'account' => $knownAccounts !== []
                ? ['required', 'string', 'in:'.implode(',', $knownAccounts)]
                : ['required', 'string', 'alpha_dash'],
        ]);

        return redirect()->route('credentials.edit', $validated);
    }

    public function edit(string $marketplace, string $account): View
    {
        $credential = MarketplaceCredential::forUser(auth()->id())
            ->forAccount($marketplace, $account)->first();
        $knownFields = config('credentials.'.$marketplace.'.fields', []);
        $storedKeys = array_keys($credential?->credentials ?? []);

        return view('credentials.edit', [
            'marketplace' => $marketplace,
            'account' => $account,
            'knownFields' => $knownFields,
            // field => bool, never the decrypted value itself.
            'isSet' => array_combine($knownFields, array_map(
                fn (string $field) => in_array($field, $storedKeys, true),
                $knownFields,
            )),
            // Plain-language steps for getting these values — see
            // config/credentials.php's own docblock for why this lives in config
            // rather than being hardcoded per-marketplace in this controller.
            'instructions' => config('credentials.'.$marketplace.'.instructions', []),
            // Whether to offer the one-click in-browser OAuth connect button
            // (OAuthController) alongside the manual paste form.
            'supportsOAuth' => (bool) config('credentials.'.$marketplace.'.oauth', false),
        ]);
    }

    public function update(CredentialUpdateRequest $request, string $marketplace, string $account): RedirectResponse
    {
        $userId = auth()->id();
        $existing = MarketplaceCredential::forUser($userId)->forAccount($marketplace, $account)->first();

        // Blank means "leave unchanged," not "clear it" — see plan for why. Only
        // non-blank submitted fields overwrite the stored value for that key.
        $submitted = array_filter($request->validated(), fn (mixed $v): bool => filled($v));
        $merged = array_merge($existing?->credentials ?? [], $submitted);

        MarketplaceCredential::updateOrCreate(
            ['user_id' => $userId, 'marketplace' => $marketplace, 'account' => $account],
            ['credentials' => $merged],
        );

        return redirect()->route('credentials.index')->with('status', 'credential-updated');
    }

    public function destroy(string $marketplace, string $account): RedirectResponse
    {
        $credential = MarketplaceCredential::forUser(auth()->id())
            ->forAccount($marketplace, $account)->first();
        abort_unless($credential !== null, 404);

        $credential->delete();

        return redirect()->route('credentials.index')->with('status', 'credential-deleted');
    }
}
