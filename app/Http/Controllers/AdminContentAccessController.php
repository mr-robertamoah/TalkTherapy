<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Models\AdminContentAccess;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\HelpRequest;
use App\Models\License;
use App\Models\Message;
use App\Models\Request as ModelsRequest;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Support\AdminSearch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
        'help' => HelpRequest::class,
    ];

    public function index(Request $request)
    {
        if (! $request->user()?->isSuperAdmin()) {
            return Redirect::route('home')->with('message', EnsureUserIsAdmin::MESSAGE);
        }

        $filters = $request->validate([
            'resource' => ['nullable', Rule::in(AdminContentAccess::RESOURCES)],
            'admin' => ['nullable', 'integer'],
            // SCRUM-366: everything read about one therapy, session, message or document.
            'subject' => ['nullable', Rule::in(array_keys(self::SUBJECTS))],
            'subject_id' => ['nullable', 'integer', 'required_with:subject'],
        ]);

        $search = AdminSearch::term($request);

        $accesses = AdminContentAccess::query()
            // SCRUM-385: the subject (and a message's session or discussion) and the file, for their
            // names -- eager-loaded so the labels below cost no query per row.
            ->with([
                'admin',
                'file',
                'subject' => fn (MorphTo $morphTo) => $morphTo->morphWith([Message::class => ['for']]),
            ])
            ->when($filters['resource'] ?? null, fn ($q, $resource) => $q->where('resource', $resource))
            ->when($filters['admin'] ?? null, fn ($q, $admin) => $q->where('admin_user_id', $admin))
            // SCRUM-390: the administrator's names and username.
            ->when($search, fn ($q, $term) => $q->whereHas('admin', fn ($q) => AdminSearch::person($q, $term)))
            ->when($filters['subject'] ?? null, fn ($q, $subject) => $q->where('subject_type', self::SUBJECTS[$subject])->where('subject_id', $filters['subject_id']))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AdminContentAccess $access) => [
                'id' => $access->id,
                'admin' => ['id' => $access->admin_user_id, 'name' => $access->admin?->name ?? 'a deleted account'],
                'resource' => $access->resource,
                'subject' => $this->subjectLabel($access->subject_type, $access->subject),
                'subjectKey' => array_search($access->subject_type, self::SUBJECTS, true) ?: null,
                'subjectId' => $access->subject_id,
                'fileId' => $access->file_id,
                'fileName' => $access->file?->name,
                'page' => $access->page,
                'ip' => $access->ip,
                'at' => $access->created_at,
            ]);

        return Inertia::render('Admin/ContentAccesses', [
            // SCRUM-390
            'search' => $search ?? '',
            'accesses' => $accesses,
            'filters' => [
                'resource' => $filters['resource'] ?? null,
                'admin' => $filters['admin'] ?? null,
                'subject' => $filters['subject'] ?? null,
                'subject_id' => $filters['subject_id'] ?? null,
                'subjectLabel' => isset($filters['subject']) ? $this->filteredSubjectLabel($filters['subject'], $filters['subject_id']) : null,
            ],
            'resources' => AdminContentAccess::RESOURCES,
        ]);
    }

    private function filteredSubjectLabel(string $subject, int $subjectId): string
    {
        $type = self::SUBJECTS[$subject];
        $model = $type::query()->when($type === Message::class, fn ($q) => $q->with('for'))->find($subjectId);

        return $this->subjectLabel($type, $model);
    }

    // SCRUM-385: what was read, by name rather than "Therapy #16" -- only super administrators
    // see this page, and they may see these names. A subject that has since been deleted (or
    // soft-deleted) is no longer loaded, so it falls back to "a deleted <type>".
    private function subjectLabel(?string $type, ?Model $subject): string
    {
        if (! $subject) {
            return 'a deleted '.match ($type) {
                Therapy::class => 'therapy',
                GroupTherapy::class => 'group therapy',
                Session::class => 'session',
                Message::class => 'message',
                Discussion::class => 'discussion',
                TherapyTopic::class => 'topic',
                License::class => 'licence',
                ModelsRequest::class => 'request',
                HelpRequest::class => 'help request',
                default => 'item',
            };
        }

        return match (true) {
            $subject instanceof Therapy => "Therapy: {$subject->name}",
            $subject instanceof GroupTherapy => "Group therapy: {$subject->name}",
            $subject instanceof Session => "Session: {$subject->name}",
            $subject instanceof Message => $subject->for?->name ? "A message in {$subject->for->name}" : 'A message',
            $subject instanceof Discussion => "Discussion: {$subject->name}",
            $subject instanceof TherapyTopic => "Topic: {$subject->name}",
            $subject instanceof License => 'A licence',
            $subject instanceof ModelsRequest => 'A verification request',
            $subject instanceof HelpRequest => "Urgent help request #{$subject->id}",
            default => 'A document',
        };
    }
}
