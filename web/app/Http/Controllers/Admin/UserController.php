<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        return view('admin.users.index', [
            'users' => User::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'], // hashed by the model's 'hashed' cast
            'is_admin' => $request->boolean('is_admin'),
            'is_active' => true,
        ]);

        // An admin vouches for accounts they create directly, so skip the
        // email-verification gate the self-service signup flow enforces.
        $user->markEmailAsVerified();

        return redirect()->route('admin.users.index')->with('status', 'user-created');
    }

    public function toggleAdmin(Request $request, User $user): RedirectResponse
    {
        // An admin can't change their OWN role — this is what guarantees at least
        // one admin always remains (you can only ever demote someone else), and
        // prevents an accidental self-lockout.
        abort_if($user->is($request->user()), 403, 'You cannot change your own admin role.');

        $user->update(['is_admin' => ! $user->is_admin]);

        return redirect()->route('admin.users.index')->with('status', 'user-updated');
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        // Same self-protection: you can't deactivate yourself, so the acting admin
        // is always left with a working account.
        abort_if($user->is($request->user()), 403, 'You cannot deactivate your own account.');

        $user->update(['is_active' => ! $user->is_active]);

        return redirect()->route('admin.users.index')->with('status', 'user-updated');
    }

    /**
     * Manually confirm a user's email — the escape hatch for when a verification
     * email never arrives (bad address, flaky mailer). Without this, a broken
     * mailer would permanently lock people out of the whole app, since every route
     * is behind the `verified` gate.
     */
    public function markVerified(User $user): RedirectResponse
    {
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return redirect()->route('admin.users.index')->with('status', 'user-updated');
    }
}
