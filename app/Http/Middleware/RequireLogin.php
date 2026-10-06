<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces Laravel's default `auth` alias: instead of bouncing straight to
 * /login, the intended path is stored (redirect()->guest) so the original
 * `?next=` behaviour of src/proxy.ts survives the round trip.
 */
class RequireLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return redirect()->guest(route('login'));
        }

        return $next($request);
    }
}
