<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bootstrap + guest-page routing — port of the decisions in src/proxy.ts:
 *
 *  - no owner yet  -> everything that is not /setup goes to /setup
 *  - owner exists  -> /setup is dead, send it to /login
 *  - signed in and on an auth page -> send to /
 *
 * Accounts are created by the owner only; /signup never existed and must stay
 * gone (the original redirected it to /login).
 */
class EnsureSetupState
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();

        // /signup no longer exists: accounts are created by the owner only.
        if ($path === 'signup') {
            return redirect()->route('login');
        }

        $ownerExists = User::where('role', 'owner')->exists();

        if (! $ownerExists) {
            return $path === 'setup'
                ? $next($request)
                : redirect()->route('setup');
        }

        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return $path === 'setup'
            ? redirect()->route('login')
            : $next($request);
    }
}
