<?php

namespace App\Http\Controllers;

use App\Support\Input;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(Request $request): View
    {
        return view('account.index', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Port of changePassword(). Re-authentication comes first — without it any
     * live session on a shared shop device could silently take over the
     * account. Error strings are verbatim.
     */
    public function changePassword(Request $request): RedirectResponse
    {
        $current = (string) $request->input('currentPassword');
        $password = (string) $request->input('password');
        $confirm = (string) $request->input('confirm');

        if ($current === '') {
            return $this->respond($request, ['ok' => false, 'error' => 'Enter your current password.']);
        }
        if (strlen($password) < 8) {
            return $this->respond($request, ['ok' => false, 'error' => 'New password must be at least 8 characters.']);
        }
        if ($password !== $confirm) {
            return $this->respond($request, ['ok' => false, 'error' => 'Passwords do not match.']);
        }
        if ($password === $current) {
            return $this->respond($request, ['ok' => false, 'error' => 'The new password must be different.']);
        }

        $user = $request->user();

        if ($user === null || $user->email === null) {
            return $this->respond($request, ['ok' => false, 'error' => 'You are not signed in.']);
        }

        if (! Hash::check($current, $user->password)) {
            return $this->respond($request, ['ok' => false, 'error' => 'Your current password is incorrect.']);
        }

        $user->forceFill(['password' => Hash::make($password)])->save();

        return $this->respond($request, ['ok' => true], 'Password updated.');
    }
}
