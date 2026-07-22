<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login already blocks a deactivated account (see LoginRequest), but a user
 * deactivated mid-session still holds a valid session cookie — this signs them
 * out on their very next request so revoking access takes effect immediately,
 * not only at their next login.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() !== null && ! $request->user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'This account has been deactivated. Contact an administrator.']);
        }

        return $next($request);
    }
}
