<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Notification\PruneNotificationsAction;
use App\Actions\Request\DeleteExpiredIdentityDocumentsAction;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\GroupTherapy;
use App\Models\License;
use App\Models\Message;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LicenseService;
use App\Support\MaskedEmail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

// SCRUM-50 (TT-8.2): the facts behind "What we hold about you" -- counts, yes/no, dates and the person's own profile
// values, built from $user alone. The words (what, why, who sees it) live in the page, for legal to change.
//
// Only what the person OWNS is counted: therapies they started (`addedby`), sessions in those and in groups they are
// or were in, messages they sent. Never the visibility scopes (TherapyTrait), which also reach a ward's or a client's
// therapies. Nothing about anyone else: no names, no counsellor details, no counsellor notes or safety records, no
// administrator. A fixed number of queries whatever the person holds (lists are of their own few organisations and
// documents, capped).
class GetYourDataAction extends Action
{
    private const LIST_LIMIT = 50;

    public const TEAM_ACCESS_LATEST = 10;

    // SCRUM-360's resources the person owns -- an allow-list, so a resource added later stays off until chosen here.
    // Never: who asked for urgent help or who a safety flag is about (and its reason), a case discussion's
    // messages, or a group's page (shared with its other members).
    private const THERAPY_RESOURCES = [AdminContentAccess::THERAPY];

    private const SESSION_RESOURCES = [AdminContentAccess::SESSION, AdminContentAccess::SESSION_MESSAGES];

    private const TOPIC_RESOURCES = [AdminContentAccess::TOPIC_MESSAGES];

    private const MESSAGE_RESOURCES = [AdminContentAccess::MESSAGE, AdminContentAccess::MESSAGE_REPLIES, AdminContentAccess::MESSAGE_ATTACHMENT];

    public function execute(User $user): array
    {
        $row = $this->userRow($user);
        $counsellor = Counsellor::query()->where('user_id', $user->id)->withCount(['sentMessages', 'notifications'])->first();

        return [
            'account' => $this->account($row),
            'security' => $this->security($row),
            'therapies' => $this->therapies($row),
            'sessions' => ['count' => $this->sessionsCount($user)],
            'messages' => [
                'sent' => (int) $row->sent_messages_count,
                'sentAsCounsellor' => $counsellor ? (int) $counsellor->sent_messages_count : null,
            ],
            'groups' => $this->groups($row),
            'organizations' => $this->organizations($user),
            'guardianship' => [
                'guardians' => (int) $row->active_guardians_count,
                'wards' => (int) $row->active_wards_count,
            ],
            'payments' => [
                'transactions' => $this->transactions($user),
                'payouts' => $counsellor ? $this->payouts($counsellor) : null,
            ],
            'documents' => [
                'identity' => $this->identityDocuments($user),
                'licenses' => $counsellor ? $this->licenses($counsellor) : null,
            ],
            'notifications' => [
                'count' => (int) $row->notifications_count,
                'counsellorCount' => $counsellor ? (int) $counsellor->notifications_count : null,
                // As the profile page shows it (ProfileController): their own choice, else the default.
                'retentionDays' => $user->notification_retention_days ?? PruneNotificationsAction::defaultDays(),
            ],
            'teamAccess' => $this->teamAccess($user, $counsellor),
            'counsellor' => $counsellor ? $this->counsellor($counsellor) : null,
        ];
    }

    // One query: the person's row with every count that has a relation.
    private function userRow(User $user): User
    {
        $active = fn (Builder $q) => $q->where('status', '!=', TherapyStatusEnum::ended->value);
        $ended = fn (Builder $q) => $q->where('status', TherapyStatusEnum::ended->value);

        return User::query()
            ->whereKey($user->id)
            ->withExists('avatarFile as has_avatar')
            ->withMax('pendingTwoFactorReset as two_factor_reset_due_at', 'due_at')
            ->withCount([
                'addedTherapies as therapies_active_count' => fn ($q) => $active($q->withoutHelpChats()->where('anonymous', false)),
                'addedTherapies as therapies_ended_count' => fn ($q) => $ended($q->withoutHelpChats()->where('anonymous', false)),
                'addedTherapies as anonymous_active_count' => fn ($q) => $active($q->withoutHelpChats()->where('anonymous', true)),
                'addedTherapies as anonymous_ended_count' => fn ($q) => $ended($q->withoutHelpChats()->where('anonymous', true)),
                'addedTherapies as help_chats_count' => fn ($q) => $q->where('is_help_chat', true),
                'groupTherapies as groups_current_count',
                'groupTherapies as groups_anonymous_count' => fn ($q) => $q->where('group_therapy_user.anonymous', true),
                'allGroupTherapyMemberships as groups_left_count' => fn ($q) => $q->whereNotNull('group_therapy_user.left_at'),
                'addedGroupTherapies as groups_started_count',
                'sentMessages',
                'guardians as active_guardians_count' => fn ($q) => $q->active(),
                'wards as active_wards_count' => fn ($q) => $q->active(),
                'notifications',
            ])
            ->firstOrFail();
    }

    private function account(User $row): array
    {
        return [
            'name' => $row->name,
            'username' => $row->username,
            'email' => $row->email,
            'emailVerified' => $row->email_verified_at !== null,
            // SCRUM-427: a new address waiting to be confirmed, shown only partly (as everywhere else).
            'pendingEmail' => $row->pending_email ? MaskedEmail::of($row->pending_email) : null,
            'dob' => $row->dob?->toDateString(),
            'gender' => $row->gender,
            'country' => $row->country,
            'hasAvatar' => (bool) $row->has_avatar,
        ];
    }

    // Whether things are set -- never the password, secret or recovery codes themselves.
    private function security(User $row): array
    {
        $twoFactor = $row->hasTwoFactorEnabled();

        return [
            'passwordSet' => filled($row->getRawOriginal('password')),
            'twoFactorEnabled' => $twoFactor,
            // As UserResource shows it: only while two-factor is on (a reset of nothing means nothing).
            'twoFactorResetDueAt' => $twoFactor && $row->two_factor_reset_due_at
                ? Carbon::parse($row->two_factor_reset_due_at)->toIso8601String()
                : null,
        ];
    }

    private function therapies(User $row): array
    {
        return [
            'active' => (int) $row->therapies_active_count,
            'ended' => (int) $row->therapies_ended_count,
            'anonymousActive' => (int) $row->anonymous_active_count,
            'anonymousEnded' => (int) $row->anonymous_ended_count,
            'helpChats' => (int) $row->help_chats_count,
        ];
    }

    private function groups(User $row): array
    {
        return [
            'current' => (int) $row->groups_current_count,
            'anonymous' => (int) $row->groups_anonymous_count,
            'left' => (int) $row->groups_left_count,
            'started' => (int) $row->groups_started_count,
        ];
    }

    // Sessions in the person's own therapies (not urgent help chats, counted on their own) and in the groups they
    // are or were in.
    private function sessionsCount(User $user): int
    {
        return Session::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('for_type', (new Therapy)->getMorphClass())->whereIn('for_id', $this->ownTherapyIds($user)))
                ->orWhere(fn (Builder $q) => $q->where('for_type', (new GroupTherapy)->getMorphClass())->whereIn('for_id', $this->groupIds($user))))
            ->count();
    }

    private function organizations(User $user): array
    {
        return [
            'memberships' => OrganizationMember::query()
                ->join('organizations', 'organizations.id', '=', 'organization_members.organization_id')
                ->whereNull('organizations.deleted_at')
                ->where('organization_members.user_id', $user->id)
                ->orderBy('organizations.name')
                ->limit(self::LIST_LIMIT)
                ->get(['organizations.name', 'organization_members.status'])
                ->map(fn ($m) => ['name' => $m->name, 'status' => $m->status])
                ->all(),
            'administered' => $user->administeredOrganizations()
                ->orderBy('organizations.name')
                ->limit(self::LIST_LIMIT)
                ->get(['organizations.name'])
                ->map(fn ($o) => ['name' => $o->name, 'role' => $o->pivot->role])
                ->all(),
        ];
    }

    // Per currency: how many payments, and the total that went through (minor units).
    private function transactions(User $user): array
    {
        return $this->moneyByCurrency(Transaction::query()->where('user_id', $user->id), TransactionStatusEnum::success->value);
    }

    private function payouts(Counsellor $counsellor): array
    {
        return $this->moneyByCurrency(CounsellorPayout::query()->where('counsellor_id', $counsellor->id), CounsellorPayoutStatusEnum::succeeded->value);
    }

    private function moneyByCurrency(Builder $query, string $paidStatus): array
    {
        return $query
            ->selectRaw('currency, count(*) as aggregate_count, sum(case when status = ? then amount else 0 end) as paid_amount', [$paidStatus])
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'count' => (int) $row->aggregate_count, 'paidAmount' => (int) $row->paid_amount])
            ->all();
    }

    // Age and identity checks the person sent, with when its document goes -- the sweep's own rule.
    private function identityDocuments(User $user): array
    {
        return Request::query()
            ->where('type', RequestTypeEnum::ageVerification->value)
            ->where('for_type', $user->getMorphClass())
            ->where('for_id', $user->id)
            ->withCount('files')
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (Request $request) => [
                'submittedAt' => $request->created_at?->toIso8601String(),
                'status' => $request->status,
                'hasDocument' => $request->files_count > 0,
                'deletesAt' => $request->files_count > 0 ? DeleteExpiredIdentityDocumentsAction::deletesAt($request)?->toIso8601String() : null,
            ])
            ->all();
    }

    // The counsellor's licences and ID, with when their documents go -- LicenseService's own rule, never recomputed.
    private function licenses(Counsellor $counsellor): array
    {
        $licenses = License::query()
            ->where('for_type', $counsellor->getMorphClass())
            ->where('for_id', $counsellor->id)
            ->with('licensingAuthority:id,name')
            ->withCount('files')
            ->latest('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $retention = LicenseService::new()->documentRetentionForCounsellor($counsellor, $licenses);

        return $licenses->map(fn (License $license) => [
            'authority' => $license->licensingAuthority?->name,
            'isNationalId' => $license->isNationalId(),
            'validated' => (bool) $license->validated,
            'hasDocument' => $license->files_count > 0,
            'documentsRemovedAt' => $license->documents_removed_at?->toIso8601String(),
            'deletesAt' => $license->files_count > 0 ? ($retention->get($license->id)['at'] ?? null)?->toIso8601String() : null,
        ])->all();
    }

    private function counsellor(Counsellor $counsellor): array
    {
        return [
            'verification' => $counsellor->verificationState()->value,
            'verificationExpiresAt' => $counsellor->verification_expires_at?->toIso8601String(),
            // How many people, never who.
            'clients' => Therapy::query()
                ->withoutHelpChats()
                ->where('counsellor_id', $counsellor->id)
                ->where('addedby_type', (new User)->getMorphClass())
                ->distinct()
                ->count('addedby_id'),
        ];
    }

    // TT-4.16: administrators opening the person's own content -- when and what, never who or from where. Two queries.
    private function teamAccess(User $user, ?Counsellor $counsellor): array
    {
        $query = AdminContentAccess::query()->where(fn (Builder $q) => $q
            ->orWhere(fn (Builder $q) => $this->accessTo($q, self::THERAPY_RESOURCES, Therapy::class, $this->ownTherapyIds($user)))
            ->orWhere(fn (Builder $q) => $this->accessTo($q, self::SESSION_RESOURCES, Session::class, $this->ownSessionIds($user)))
            ->orWhere(fn (Builder $q) => $this->accessTo($q, self::TOPIC_RESOURCES, TherapyTopic::class, $this->ownTopicIds($user)))
            ->orWhere(fn (Builder $q) => $this->accessTo($q, self::MESSAGE_RESOURCES, Message::class, $this->sentMessageIds($user, $counsellor)))
            ->orWhere(fn (Builder $q) => $this->accessTo($q, [AdminContentAccess::IDENTITY_DOCUMENT], Request::class, $this->identityRequestIds($user)))
            ->when($counsellor, fn (Builder $q) => $q
                ->orWhere(fn (Builder $q) => $this->accessTo($q, [AdminContentAccess::LICENSE_DOCUMENT], License::class, $this->licenseIds($counsellor)))));

        return [
            'total' => (clone $query)->count(),
            'latest' => $query->latest('id')->limit(self::TEAM_ACCESS_LATEST)->get(['resource', 'created_at'])
                ->map(fn (AdminContentAccess $access) => ['at' => $access->created_at?->toIso8601String(), 'resource' => $access->resource])
                ->all(),
        ];
    }

    private function accessTo(Builder $q, array $resources, string $subjectType, QueryBuilder $subjectIds): Builder
    {
        return $q->whereIn('resource', $resources)->where('subject_type', $subjectType)->whereIn('subject_id', $subjectIds);
    }

    // The person's own individual therapies, deleted ones included -- never urgent help chats (whose reads may be
    // about a report the person isn't told of).
    private function ownTherapyIds(User $user): QueryBuilder
    {
        return DB::table('therapies')->select('id')
            ->where('addedby_type', $user->getMorphClass())
            ->where('addedby_id', $user->id)
            ->where('is_help_chat', false);
    }

    private function helpChatSessionIds(): QueryBuilder
    {
        return DB::table('sessions')->select('id')
            ->where('for_type', (new Therapy)->getMorphClass())
            ->whereIn('for_id', DB::table('therapies')->select('id')->where('is_help_chat', true));
    }

    private function ownSessionIds(User $user): QueryBuilder
    {
        return DB::table('sessions')->select('id')
            ->where('for_type', (new Therapy)->getMorphClass())
            ->whereIn('for_id', $this->ownTherapyIds($user));
    }

    private function ownTopicIds(User $user): QueryBuilder
    {
        return DB::table('therapy_topics')->select('id')
            ->where('topicable_type', (new Therapy)->getMorphClass())
            ->whereIn('topicable_id', $this->ownTherapyIds($user));
    }

    private function groupIds(User $user): QueryBuilder
    {
        return DB::table('group_therapy_user')->select('group_therapy_id')->where('user_id', $user->id);
    }

    // Messages the person sent, as themselves or as a counsellor -- not in urgent help chats (see ownTherapyIds()).
    private function sentMessageIds(User $user, ?Counsellor $counsellor): QueryBuilder
    {
        return DB::table('messages')->select('id')
            ->where(fn (QueryBuilder $q) => $q
                ->where(fn (QueryBuilder $q) => $q->where('from_type', $user->getMorphClass())->where('from_id', $user->id))
                ->when($counsellor, fn (QueryBuilder $q) => $q
                    ->orWhere(fn (QueryBuilder $q) => $q->where('from_type', $counsellor->getMorphClass())->where('from_id', $counsellor->id))))
            ->whereNot(fn (QueryBuilder $q) => $q
                ->where('for_type', (new Session)->getMorphClass())
                ->whereIn('for_id', $this->helpChatSessionIds()));
    }

    private function identityRequestIds(User $user): QueryBuilder
    {
        return DB::table('requests')->select('id')
            ->where('type', RequestTypeEnum::ageVerification->value)
            ->where('for_type', $user->getMorphClass())
            ->where('for_id', $user->id);
    }

    private function licenseIds(Counsellor $counsellor): QueryBuilder
    {
        return DB::table('licenses')->select('id')
            ->where('for_type', $counsellor->getMorphClass())
            ->where('for_id', $counsellor->id);
    }
}
