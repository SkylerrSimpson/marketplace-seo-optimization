<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScriptRun;
use App\Models\ScriptRunStatus;
use App\Scripts\ScriptRegistry;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The admin audit view: every user's runs, read-only. Non-admins never reach it
 * (the 'admin' middleware) and their own /runs list stays strictly per-user —
 * this is the one place cross-user activity is visible, so an admin can see what
 * hit the live stores across the whole team.
 */
class ActivityController extends Controller
{
    public function index(Request $request, ScriptRegistry $registry): View
    {
        $query = ScriptRun::query()->with('user')->latest();

        $activeStatus = $request->query('status');
        if (in_array($activeStatus, array_map(fn (ScriptRunStatus $s) => $s->value, ScriptRunStatus::cases()), true)) {
            $query->where('status', $activeStatus);
        } else {
            $activeStatus = null;
        }

        return view('admin.runs.index', [
            'runs' => $query->paginate(50)->withQueryString(),
            'registry' => $registry,
            'statuses' => ScriptRunStatus::cases(),
            'activeStatus' => $activeStatus,
        ]);
    }
}
