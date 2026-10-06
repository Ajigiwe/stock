<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Models\User;
use App\Support\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function setupForm(): View
    {
        return view('auth.setup');
    }

    /**
     * One-time owner bootstrap — port of setupOwner(). The secret guard and
     * the "an owner already exists" refusal are kept verbatim: without them
     * anyone reaching /setup could mint an owner.
     */
    public function setup(Request $request): RedirectResponse
    {
        $secret = (string) config('mrjeff.setup_secret');

        if ($secret === '') {
            return $this->respond($request, [
                'ok' => false,
                'error' => 'OWNER_SETUP_SECRET is not configured on the server.',
            ]);
        }

        $name = Input::trimmed($request->input('name'));
        $email = Input::trimmed($request->input('email'));
        $password = (string) $request->input('password');
        $provided = (string) $request->input('secret');

        if (! hash_equals($secret, $provided)) {
            return $this->respond($request, ['ok' => false, 'error' => 'Invalid setup secret.']);
        }

        if ($name === '' || ! Input::email($email) || strlen($password) < 8) {
            return $this->respond($request, [
                'ok' => false,
                'error' => 'Name and a valid email are required; password must be at least 8 characters.',
            ]);
        }

        if (User::where('role', User::ROLE_OWNER)->exists()) {
            return $this->respond($request, [
                'ok' => false,
                'error' => 'An owner account already exists.',
            ]);
        }

        $owner = DB::transaction(function () use ($name, $email, $password): User {
            // Re-check inside the transaction: two parallel /setup posts must
            // not both win the race and create two owners.
            if (User::where('role', User::ROLE_OWNER)->exists()) {
                abort(409, 'An owner account already exists.');
            }

            return User::create([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
                'role' => User::ROLE_OWNER,
                'active' => true,
            ]);
        });

        Auth::login($owner, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function loginForm(Request $request): View|RedirectResponse
    {
        return view('auth.login', [
            'next' => Input::safeNextPath($request->query('next')),
        ]);
    }

    /**
     * Port of login(): sliding-window rate limit keyed by IP + email, then a
     * credential check, then a deactivation check, then an audit row that must
     * never block the sign-in itself.
     */
    public function login(Request $request): RedirectResponse
    {
        $email = Input::trimmed($request->input('email'));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return $this->respond($request, [
                'ok' => false,
                'error' => 'Enter your email and password.',
            ]);
        }

        $ip = $this->clientIp($request);
        $bucket = sha1(($ip ?? 'noip').'|'.mb_strtolower($email));

        if (RateLimiter::tooManyAttempts('mrjeff:login:'.$bucket, 8)) {
            return $this->respond($request, [
                'ok' => false,
                'error' => 'Too many sign-in attempts. Wait 10 minutes and try again.',
            ]);
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password], remember: true)) {
            RateLimiter::hit('mrjeff:login:'.$bucket, 10 * 60);

            return $this->respond($request, [
                'ok' => false,
                'error' => 'Invalid login credentials',
            ]);
        }

        $user = Auth::user();

        if ($user === null || ! $user->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->respond($request, [
                'ok' => false,
                'error' => 'This account is deactivated. Ask the owner to restore access.',
            ]);
        }

        RateLimiter::clear('mrjeff:login:'.$bucket);
        $request->session()->regenerate();

        $this->logLogin($request, $user, $email, $ip);

        return redirect()->intended(Input::safeNextPath($request->input('next')));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Rate-limit identity. X-Forwarded-For is only honoured when the request
     * actually came through a proxy we trust — otherwise a client could send a
     * fake header on every attempt and reset its own lockout.
     */
    private function clientIp(Request $request): ?string
    {
        if ($request->isFromTrustedProxy()) {
            $forwarded = (string) $request->headers->get('x-forwarded-for', '');
            $first = trim(explode(',', $forwarded)[0] ?? '');

            return $first !== '' ? $first : $request->ip();
        }

        return $request->ip();
    }

    /** Recording who signed in, from where, and when — never blocks login. */
    private function logLogin(Request $request, User $user, string $email, ?string $ip): void
    {
        try {
            LoginLog::create([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'email' => $email,
                'name' => $user->name,
                'ip' => $ip,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'device' => $this->deviceLabel((string) $request->userAgent()),
            ]);
        } catch (\Throwable) {
            // Logging must never block login.
        }
    }

    /** Coarse device label from a user-agent string — port of parseDevice(). */
    private function deviceLabel(string $ua): ?string
    {
        $ua = mb_strtolower($ua);

        foreach (['iphone' => 'iPhone', 'ipad' => 'iPad', 'android' => 'Android', 'windows' => 'Windows', 'mac os' => 'Mac', 'linux' => 'Linux'] as $needle => $label) {
            if (str_contains($ua, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
