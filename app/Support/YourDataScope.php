<?php

namespace App\Support;

use App\Enums\RequestTypeEnum;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\Discussion;
use App\Models\GroupTherapy;
use App\Models\License;
use App\Models\Message;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\TherapyTopic;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

// SCRUM-432 (from SCRUM-50): what counts as the person's OWN, in one place -- "What we hold about you"
// (GetYourDataAction) counts it and the copy of their data (ExportYourDataAction) lists it, so the two can't drift.
//
// Therapies they started (`addedby`, deleted ones too -- still held), sessions in those and in groups while they were in
// them, messages they sent in sessions. Never the visibility scopes (TherapyTrait), which also reach a ward's or a
// client's therapies. Never urgent help chats (their sessions or messages): the page and the copy give them one fixed
// sentence (YourDataWording), so nothing about them can tip off someone watching the account.
class YourDataScope
{
    // SCRUM-360's resources the person owns -- an allow-list, so a resource added later stays off until chosen here.
    // Never: who asked for urgent help or who a safety flag is about (and its reason), a case discussion's
    // messages, or a group's page (shared with its other members).
    private const THERAPY_RESOURCES = [AdminContentAccess::THERAPY];

    private const SESSION_RESOURCES = [AdminContentAccess::SESSION, AdminContentAccess::SESSION_MESSAGES];

    private const TOPIC_RESOURCES = [AdminContentAccess::TOPIC_MESSAGES];

    private const MESSAGE_RESOURCES = [AdminContentAccess::MESSAGE, AdminContentAccess::MESSAGE_REPLIES, AdminContentAccess::MESSAGE_ATTACHMENT];

    // The person's own individual therapies, deleted ones included -- never urgent help chats (whose reads may be
    // about a report the person isn't told of).
    public static function ownTherapyIds(User $user): QueryBuilder
    {
        return DB::table('therapies')->select('id')
            ->where('addedby_type', $user->getMorphClass())
            ->where('addedby_id', $user->id)
            ->where('is_help_chat', false);
    }

    private static function helpChatSessionIds(): QueryBuilder
    {
        return DB::table('sessions')->select('id')
            ->where('for_type', (new Therapy)->getMorphClass())
            ->whereIn('for_id', DB::table('therapies')->select('id')->where('is_help_chat', true));
    }

    private static function ownSessionIds(User $user): QueryBuilder
    {
        return DB::table('sessions')->select('id')
            ->where('for_type', (new Therapy)->getMorphClass())
            ->whereIn('for_id', self::ownTherapyIds($user));
    }

    // Sessions in the person's own therapies (not urgent help chats) and in groups while they were in them: from when they
    // joined and, for a group they left, until they left. Deleted ones too: still held (SCRUM-436).
    public static function sessions(User $user): Builder
    {
        return Session::query()
            ->withTrashed()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('for_type', (new Therapy)->getMorphClass())->whereIn('for_id', self::ownTherapyIds($user)))
                ->orWhere(fn (Builder $q) => $q->where('for_type', (new GroupTherapy)->getMorphClass())->whereExists(fn (QueryBuilder $q) => $q
                    ->from('group_therapy_user')
                    ->whereColumn('group_therapy_user.group_therapy_id', 'sessions.for_id')
                    ->where('group_therapy_user.user_id', $user->id)
                    // Security review: from when they joined -- a group's earlier sessions aren't theirs.
                    ->whereColumn('sessions.start_time', '>=', 'group_therapy_user.created_at')
                    ->where(fn (QueryBuilder $q) => $q->whereNull('group_therapy_user.left_at')->orWhereColumn('sessions.start_time', '<', 'group_therapy_user.left_at')))));
    }

    private static function ownTopicIds(User $user): QueryBuilder
    {
        return DB::table('therapy_topics')->select('id')
            ->where('topicable_type', (new Therapy)->getMorphClass())
            ->whereIn('topicable_id', self::ownTherapyIds($user));
    }

    // Messages counted as the person's: the ones sent in sessions, outside urgent help chats, deleted ones too (still
    // held) -- never in a case discussion (SCRUM-436: the same rule as team access and the copy's list).
    public static function sentInSessions(Builder $messages): Builder
    {
        return $messages->withTrashed()
            ->where('for_type', (new Session)->getMorphClass())
            ->whereNotIn('for_id', self::helpChatSessionIds());
    }

    // Messages the person sent in sessions, as themselves or as a counsellor -- never in a case discussion (a
    // counsellor's discussion about a client) or an urgent help chat (see ownTherapyIds()). The same rule as
    // sentInSessions(), as ids for a subquery: keep the two in step.
    private static function sentMessageIds(User $user, ?Counsellor $counsellor): QueryBuilder
    {
        return DB::table('messages')->select('id')
            ->where(fn (QueryBuilder $q) => $q
                ->where(fn (QueryBuilder $q) => $q->where('from_type', $user->getMorphClass())->where('from_id', $user->id))
                ->when($counsellor, fn (QueryBuilder $q) => $q
                    ->orWhere(fn (QueryBuilder $q) => $q->where('from_type', $counsellor->getMorphClass())->where('from_id', $counsellor->id))))
            ->where('for_type', (new Session)->getMorphClass())
            ->whereNotIn('for_id', self::helpChatSessionIds());
    }

    // SCRUM-436: a counsellor's messages in case discussions (about a client, with other counsellors) -- held, so counted
    // on their own line, never listed or given.
    public static function sentInDiscussions(Builder $messages): Builder
    {
        return $messages->withTrashed()->where('for_type', (new Discussion)->getMorphClass());
    }

    // SCRUM-432: what the copy of their data gives in full -- the messages they sent as themselves (never as a
    // counsellor: those are about clients, counted only), in sessions, outside urgent help chats; deleted ones too
    // (still held).
    public static function ownClientMessages(User $user): Builder
    {
        return self::sentInSessions(Message::query()
            ->where('from_type', $user->getMorphClass())
            ->where('from_id', $user->id));
    }

    private static function identityRequestIds(User $user): QueryBuilder
    {
        return DB::table('requests')->select('id')
            ->where('type', RequestTypeEnum::ageVerification->value)
            ->where('for_type', $user->getMorphClass())
            ->where('for_id', $user->id);
    }

    private static function licenseIds(Counsellor $counsellor): QueryBuilder
    {
        return DB::table('licenses')->select('id')
            ->where('for_type', $counsellor->getMorphClass())
            ->where('for_id', $counsellor->id);
    }

    // TT-4.16: administrators opening the person's own content -- when and what; the caller never selects who or from
    // where.
    public static function teamAccess(User $user, ?Counsellor $counsellor): Builder
    {
        return AdminContentAccess::query()->where(fn (Builder $q) => $q
            ->orWhere(fn (Builder $q) => self::accessTo($q, self::THERAPY_RESOURCES, new Therapy, self::ownTherapyIds($user)))
            ->orWhere(fn (Builder $q) => self::accessTo($q, self::SESSION_RESOURCES, new Session, self::ownSessionIds($user)))
            ->orWhere(fn (Builder $q) => self::accessTo($q, self::TOPIC_RESOURCES, new TherapyTopic, self::ownTopicIds($user)))
            ->orWhere(fn (Builder $q) => self::accessTo($q, self::MESSAGE_RESOURCES, new Message, self::sentMessageIds($user, $counsellor)))
            ->orWhere(fn (Builder $q) => self::accessTo($q, [AdminContentAccess::IDENTITY_DOCUMENT], new Request, self::identityRequestIds($user)))
            ->when($counsellor, fn (Builder $q) => $q
                ->orWhere(fn (Builder $q) => self::accessTo($q, [AdminContentAccess::LICENSE_DOCUMENT], new License, self::licenseIds($counsellor)))));
    }

    private static function accessTo(Builder $q, array $resources, Model $subject, QueryBuilder $subjectIds): Builder
    {
        return $q->whereIn('resource', $resources)->where('subject_type', $subject->getMorphClass())->whereIn('subject_id', $subjectIds);
    }
}
