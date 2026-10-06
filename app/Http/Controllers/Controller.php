<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

abstract class Controller
{
    /**
     * Turn a ported action result (see PORTING-CONTRACT.md §2) into a response.
     *
     * The offline queue replays mutations with fetch(), so JSON clients get
     * JSON; regular forms get a redirect with a flash message — the same text
     * either way, because the messages are copied from the original.
     *
     * @param  array{ok: bool, error?: string, id?: string, warnings?: array<int, string>, warning?: string}  $result
     * @param  string|null  $redirectTo  explicit target for success (the original pushed to the shop page after recording); null keeps the referrer
     */
    protected function respond(Request $request, array $result, string $successMessage = '', ?string $redirectTo = null): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        if ($result['ok']) {
            if ($successMessage !== '') {
                Session::flash('success', $successMessage);
            }
            if (! empty($result['warnings'])) {
                Session::flash('warnings', $result['warnings']);
            } elseif (! empty($result['warning'])) {
                Session::flash('warnings', [$result['warning']]);
            }

            if ($request->expectsJson()) {
                return response()->json($result);
            }

            if ($redirectTo !== null) {
                return redirect()->to($redirectTo);
            }

            return redirect()
                ->back()
                ->withInput($request->except('_token'));
        }

        $error = $result['error'] ?? 'Something went wrong.';

        if ($request->expectsJson()) {
            return response()->json($result, 422);
        }

        return redirect()
            ->back()
            ->withInput($request->except('_token'))
            ->withErrors(['action' => $error]);
    }
}
