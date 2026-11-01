<?php

namespace App\Http\Controllers;

use App\Models\AdminContentAccess;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\License;
use App\Models\Message;
use App\Models\Request as ModelsRequest;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

// SCRUM-360: the log of administrators reading content they could only reach as administrators.
// Super administrators only -- it shows who read what.
class AdminContentAccessController extends Controller
{
    // The kinds of thing a read can be about, by the short name the page filters with.
    private const SUBJECTS = [
        'therapy' => Therapy::class,
        'group' => GroupTherapy::class,
        'session' => Session::class,
        'message' => Message::class,
        'discussion' => Discussion::class,
        'topic' => TherapyTopic::class,
        'license' => License::class,
        'request' => ModelsRequest::class,
    ];

    public function index(Request $request)
    {
        if (! $request->user()?->isSuperAdmin()) {
            return Redirect::route('home')->with('message', 'You are not authorized to visit this page.');
        }

        $filters = $request->validate([
            'resource' => ['nullable', Rule::in(AdminContentAccess::RESOURCES)],
            'admin' => ['nullable', 'integer'],
            // SCRUM-366: everything read about one therapy, session, message or document.
            'subject' => ['nullable', Rule::in(array_keys(self::SUBJECTS))],
            'subject_id' => ['nullable', 'integer', 'required_with:subject'],
        ]);

        $accesses = AdminContentAccess::query()
            ->with('admin')
            ->when($filters['resource'] ?? null, fn ($q, $resource) => $q->where('resource', $resource))
            ->when($filters['admin'] ?? null, fn ($q, $admin) => $q->where('admin_user_id', $admin))
            ->when($filters['subject'] ?? null, fn ($q, $subject) => $q->where('subject_type', self::SUBJECTS[$subject])->where('subject_id', $filters['subject_id']))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AdminContentAccess $access) => [
                'id' => $access->id,
                'admin' => ['id' => $access->admin_user_id, 'name' => $access->admin?->name ?? 'a deleted account'],
                'resource' => $access->resource,
                'subject' => class_basename($access->subject_type).' #'.$access->subject_id,
                'subjectKey' => array_search($access->subject_type, self::SUBJECTS, true) ?: null,
                'subjectId' => $access->subject_id,
                'fileId' => $access->file_id,
                'page' => $access->page,
                'ip' => $access->ip,
                'at' => $access->created_at,
            ]);

        return Inertia::render('Admin/ContentAccesses', [
            'accesses' => $accesses,
            'filters' => [
                'resource' => $filters['resource'] ?? null,
                'admin' => $filters['admin'] ?? null,
                'subject' => $filters['subject'] ?? null,
                'subject_id' => $filters['subject_id'] ?? null,
            ],
            'resources' => AdminContentAccess::RESOURCES,
        ]);
    }
}
