<?php

namespace App\Http\Controllers;

use App\Models\AdminContentAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// SCRUM-360: the log of administrators reading content they could only reach as administrators.
// Super administrators only -- it shows who read what.
class AdminContentAccessController extends Controller
{
    public function index(Request $request)
    {
        if (! $request->user()?->isSuperAdmin()) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        $filters = $request->validate([
            'resource' => ['nullable', Rule::in(AdminContentAccess::RESOURCES)],
            'admin' => ['nullable', 'integer'],
        ]);

        $accesses = AdminContentAccess::query()
            ->with('admin')
            ->when($filters['resource'] ?? null, fn ($q, $resource) => $q->where('resource', $resource))
            ->when($filters['admin'] ?? null, fn ($q, $admin) => $q->where('admin_user_id', $admin))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AdminContentAccess $access) => [
                'id' => $access->id,
                'admin' => ['id' => $access->admin_user_id, 'name' => $access->admin?->name ?? 'a deleted account'],
                'resource' => $access->resource,
                'subject' => class_basename($access->subject_type).' #'.$access->subject_id,
                'fileId' => $access->file_id,
                'page' => $access->page,
                'ip' => $access->ip,
                'at' => $access->created_at,
            ]);

        return Inertia::render('Admin/ContentAccesses', [
            'accesses' => $accesses,
            'filters' => ['resource' => $filters['resource'] ?? null, 'admin' => $filters['admin'] ?? null],
            'resources' => AdminContentAccess::RESOURCES,
        ]);
    }
}
