<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of src/proxy.ts's deactivated-user branch.
 *
 * The original mirrored deactivation into app_metadata and signed the user out
 * on every request, because a ban alone only killed refresh tokens while an
 * already-issued access token kept passing until expiry. Here the flag lives on
 * the row itself, so we check it on every request and force a logout when the
 * owner has revoked access.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'This account is deactivated. Ask the owner to restore access.']);
        }

        return $next($request);
    }
}
