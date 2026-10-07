<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Queries\SuperadminQueries;
use App\Services\StaffService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The superadmin command center. Every payload is superadmin-only
 * (SuperadminQueries throws 403 otherwise); owner management and the
 * dashboard itself never touch the owner paths.
 */
class SuperadminController extends Controller
{
    /** GET /superadmin */
    public function index(Request $request): View
    {
        return view('superadmin.index', SuperadminQueries::dashboard($request->user()));
    }

    /** POST /superadmin/owners — StaffService::createOwner */
    public function createOwner(Request $request, StaffService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $result = $service->createOwner($input, $request->user());

        $name = trim((string) ($input['name'] ?? ''));

        return $this->respond($request, $result, "Owner account for {$name} created.");
    }

    /**
     * POST /superadmin/impersonate/{user} — see the app exactly as that
     * account sees it. Owners and attendants only, active only, never
     * another superadmin and never yourself. The audit trail keeps working
     * because every write is still attributed to the impersonated id.
     */
    public function impersonate(Request $request, User $user): RedirectResponse|JsonResponse
    {
        $me = $request->user();
        if ($me === null || ! $me->isSuperAdmin()) {
            abort(403);
        }

        $target = User::query()->whereKey($user->getKey())->first();
        if ($target === null || ! $target->active
            || $target->id === $me->id
            || $target->role === User::ROLE_SUPERADMIN) {
            return $this->respond($request, ['ok' => false, 'error' => 'That account cannot be impersonated.']);
        }

        session(['impersonator' => ['id' => $me->id, 'name' => $me->name]]);
        Auth::login($target);

        return redirect()->route('dashboard');
    }

    /** POST /impersonate/exit — hand the session back to the superadmin. */
    public function exitImpersonation(Request $request): RedirectResponse|JsonResponse
    {
        $impersonator = $request->session()->get('impersonator');
        if (! is_array($impersonator) || ! isset($impersonator['id'])) {
            abort(403);
        }

        $back = User::query()->whereKey($impersonator['id'])->first();
        $request->session()->forget('impersonator');
        if ($back === null || ! $back->isSuperAdmin() || ! $back->active) {
            Auth::logout();

            return redirect()->route('login');
        }

        Auth::login($back);

        return redirect()->route('superadmin.index');
    }

    /** POST /superadmin/users/{user}/deactivate — reuse the staff flows */
    public function deactivate(Request $request, User $user, StaffService $service): RedirectResponse|JsonResponse
    {
        $result = $service->deactivate($user->id, $request->user());

        return $this->respond($request, $result, "{$user->name} deactivated.");
    }

    /** POST /superadmin/users/{user}/reactivate */
    public function reactivate(Request $request, User $user, StaffService $service): RedirectResponse|JsonResponse
    {
        $result = $service->reactivate($user->id, $request->user());

        return $this->respond($request, $result, "{$user->name} reactivated.");
    }
}
