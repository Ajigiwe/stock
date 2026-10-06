<?php

namespace App\Http\Controllers;

use App\Services\Queries\LogQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LogController extends Controller
{
    /**
     * GET /logs — port of logs/page.tsx (owner). The query throws 403 for
     * attendants; the view also renders a permission notice as a fallback.
     */
    public function index(Request $request): View
    {
        return view('logs.index', LogQueries::index($request->user()));
    }
}
