<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Actions\Notification\PruneNotificationsAction;
use App\Actions\Request\DeleteExpiredIdentityDocumentsAction;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\NotificationCategoryEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\License;
use App\Models\OrganizationMember;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LicenseService;
use App\Support\MaskedEmail;
use App\Support\YourDataScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

// SCRUM-50 (TT-8.2): the facts behind "What we hold about you" -- counts, yes/no, dates and the person's own profile
// values, built from $user alone. The words (what, why, who sees it) live in the page, for legal to change.
//
// Only what the person OWNS is counted -- YourDataScope says what that is (shared with the copy of their data,
// SCRUM-432). Nothing about anyone else: no names, no counsellor details, no counsellor notes or safety records, no
// administrator. Urgent help is never counted at all (not the chats, their sessions or their messages): the page gives
// it one fixed sentence, as the user decided, so a count can't tip off someone watching the account. A fixed number of
// queries whatever the person holds (lists are of their own few organisations and documents, capped).
class GetYourDataAction extends Action
{
    private const LIST_LIMIT = 50;

    public const TEAM_ACCESS_LATEST = 10;

    public function execute(User $user): array
    {
        $row = $this->userRow($user);
        $counsellor = Counsellor::query()->where('user_id', $user->id)
            ->withCount([
                'sentMessages' => fn (Builder $q) => YourDataScope::sentInSessions($q),
                'sentMessages as discussion_messages_count' => fn (Builder $q) => YourDataScope::sentInDiscussions($q),
                'notifications',
            ])
            ->first();

        return [
            'account' => $this->account($row),
            'security' => $this->security($row),
            'therapies' => $this->therapies($row),
            'sessions' => ['count' => $this->sessionsCount($user)],
            'messages' => [
                'sent' => (int) $row->sent_messages_count,
                'sentAsCounsellor' => $counsellor ? (int) $counsellor->sent_messages_count : null,
                // SCRUM-436: in case discussions, apart from the sessions (the rule team access and the copy follow).
                'inCaseDiscussions' => $counsellor ? (int) $counsellor->discussion_messages_count : null,
            ],
            // SCRUM-36: how many, and since when -- never the words (those are in the copy, for its owner only).
            'journal' => [
                'entries' => (int) $row->journal_entries_count,
                'since' => $row->journal_first_written_on ? Carbon::parse($row->journal_first_written_on)->toDateString() : null,
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
                // SCRUM-40: the kinds they switched off, and how.
                'switchedOff' => collect(NotificationCategoryEnum::cases())
                    ->map(fn (NotificationCategoryEnum $category) => [
                        'kind' => $category->label(),
                        'email' => ! $user->wantsNotification($category, 'mail'),
                        'inApp' => ! $user->wantsNotification($category, 'database'),
                    ])
                    ->filter(fn (array $choice) => $choice['email'] || $choice['inApp'])
                    ->values()
                    ->all(),
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
                // Deleted therapies stay held (and in ownTherapyIds()).
                'addedTherapies as therapies_deleted_count' => fn ($q) => $q->onlyTrashed()->withoutHelpChats(),
                'groupTherapies as groups_current_count',
                'groupTherapies as groups_anonymous_count' => fn ($q) => $q->where('group_therapy_user.anonymous', true),
                'allGroupTherapyMemberships as groups_left_count' => fn ($q) => $q->whereNotNull('group_therapy_user.left_at'),
                'addedGroupTherapies as groups_started_count',
                'sentMessages' => fn (Builder $q) => YourDataScope::sentInSessions($q),
                'guardians as active_guardians_count' => fn ($q) => $q->active(),
                'wards as active_wards_count' => fn ($q) => $q->active(),
                'notifications',
                // SCRUM-36
                'journalEntries',
            ])
            ->withMin('journalEntries as journal_first_written_on', 'written_on')
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
            'deleted' => (int) $row->therapies_deleted_count,
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

    // Sessions in the person's own therapies (not urgent help chats) and in groups while they were in them: for a group
    // they left, only the sessions that started before they left.
    private function sessionsCount(User $user): int
    {
        return YourDataScope::sessions($user)->count();
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
        // SCRUM-432 security review: what they paid themselves -- an organisation's charges aren't theirs to total.
        return $this->moneyByCurrency(Transaction::query()->where('user_id', $user->id)->whereNull('organization_id'), TransactionStatusEnum::success->value);
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
        $query = YourDataScope::teamAccess($user, $counsellor);

        return [
            'total' => (clone $query)->count(),
            'latest' => $query->latest('id')->limit(self::TEAM_ACCESS_LATEST)->get(['resource', 'created_at'])
                ->map(fn (AdminContentAccess $access) => ['at' => $access->created_at?->toIso8601String(), 'resource' => $access->resource])
                ->all(),
        ];
    }
}
