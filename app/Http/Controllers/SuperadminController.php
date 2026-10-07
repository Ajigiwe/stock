<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Queries\SuperadminQueries;
use App\Services\StaffService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
