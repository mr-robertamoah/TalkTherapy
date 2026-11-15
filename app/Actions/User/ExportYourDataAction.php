<?php

namespace App\Actions\User;

use App\Actions\Action;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\GroupTherapy;
use App\Models\JournalEntry;
use App\Models\Message;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Support\RetentionPeriods;
use App\Support\YourDataScope;
use App\Support\YourDataWording;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// SCRUM-432: a copy of the person's data, as JSON, written piece by piece (nothing is stored; messages are read in
// chunks, so a long history never sits in memory). Built from $user alone, on the same rules as "What we hold about
// you" (YourDataScope; its facts are the `summary`), and in more detail:
//
//  - their therapies (deleted ones too, still held), the groups they're or were in, and the sessions in those -- times
//    and status only (never a session's place, nor its name or notes, which the counsellor may have written);
//  - the text of the messages they sent as themselves -- never anyone else's, nor the text of one they replied to.
//    Messages sent as a counsellor are about clients: counted (in the summary), never given (the user's decision);
//  - their payments and (a counsellor's) payouts -- never a breakdown, transfer code or who started it, nor the amount
//    of a charge an organisation paid;
//  - their journal, in full (SCRUM-36);
//  - every time the team opened their content: when and what, never who;
//  - urgent help and safety records: the one fixed sentence (YourDataWording), nothing else.
//
// Every row is an explicit list of columns -- never toArray() or a Resource, which carry the other person.
class ExportYourDataAction extends Action
{
    private const CHUNK = 200;

    // One bad character must never cut a download short (the headers are already sent).
    private const JSON = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @return Generator<int, string> the JSON document, in pieces
     */
    public function execute(User $user): Generator
    {
        $counsellor = Counsellor::query()->where('user_id', $user->id)->first();
        $groupMorph = (new GroupTherapy)->getMorphClass();

        yield '{';
        yield $this->field('about', [
            'service' => 'TalkTherapy',
            'username' => $user->username,
            'generatedAt' => now()->toIso8601String(),
            'amounts' => 'Amounts are in minor units (pesewas, cents): 15000 GHS is GHS 150.00.',
            'note' => 'A copy of the data TalkTherapy holds about you. It has only what is yours: messages are the ones you sent, never anyone else\'s.',
        ]).',';
        yield $this->field('summary', GetYourDataAction::new()->execute($user)).',';
        yield $this->field('therapies', $this->therapies($user)).',';
        yield $this->field('groups', $this->groups($user)).',';
        yield '"sessions":';
        yield from $this->list(YourDataScope::sessions($user)->select(['id', 'for_type', 'for_id', 'type', 'status', 'start_time', 'end_time', 'ended_at', 'deleted_at']), fn (Session $session) => [
            'id' => $session->id,
            'for' => $session->for_type === $groupMorph ? 'group' : 'therapy',
            'forId' => $session->for_id,
            'type' => $session->type,
            'status' => $session->status,
            'startsAt' => $session->start_time?->toIso8601String(),
            'endsAt' => $session->end_time?->toIso8601String(),
            'endedAt' => $session->ended_at?->toIso8601String(),
            // SCRUM-436: deleted, still held.
            'deleted' => $session->deleted_at !== null,
        ]);
        yield ',"messages":';
        yield from $this->list(YourDataScope::ownClientMessages($user)
            ->select(['id', 'for_id', 'type', 'message_id', 'content', 'confidential', 'created_at', 'deleted_at'])
            ->with('files:files.id,name,mime,size'), fn (Message $message) => [
                'id' => $message->id,
                'sessionId' => $message->for_id,
                'sentAt' => $message->created_at?->toIso8601String(),
                'type' => $message->type,
                'content' => $message->content,
                'confidential' => (bool) $message->confidential,
                'replyToId' => $message->message_id,
                'deleted' => $message->deleted_at !== null,
                // What was attached -- not the file itself.
                'attachments' => $message->files->map(fn ($file) => ['name' => $file->name, 'type' => $file->mime, 'size' => (int) $file->size])->values()->all(),
            ]);
        yield ','.$this->field('payments', [
            // Security review: a charge an organisation paid (or settled) is listed without its amount -- that's the
            // organisation's and the counsellor's terms, not the person's.
            'transactions' => Transaction::query()->where('user_id', $user->id)->orderBy('id')
                ->get(['reference', 'amount', 'currency', 'status', 'organization_id', 'created_at'])
                ->map(fn (Transaction $t) => $t->organization_id
                    ? ['reference' => $t->reference, 'paidBy' => 'your organisation', 'status' => $t->status, 'at' => $t->created_at?->toIso8601String()]
                    : ['reference' => $t->reference, 'paidBy' => 'you', 'amount' => (int) $t->amount, 'currency' => $t->currency, 'status' => $t->status, 'at' => $t->created_at?->toIso8601String()])
                ->all(),
            'payouts' => $counsellor ? CounsellorPayout::query()->where('counsellor_id', $counsellor->id)->orderBy('id')
                ->get(['reference', 'amount', 'currency', 'status', 'created_at'])
                ->map(fn (CounsellorPayout $p) => ['reference' => $p->reference, 'amount' => (int) $p->amount, 'currency' => $p->currency, 'status' => $p->status, 'at' => $p->created_at?->toIso8601String()])
                ->all() : null,
        ]).',';
        // SCRUM-36: their journal, in full -- it's theirs.
        yield '"journal":';
        yield from $this->list(YourDataScope::journalEntries($user)->select(['id', 'title', 'content', 'written_on', 'created_at', 'updated_at']), fn (JournalEntry $entry) => [
            'id' => $entry->id,
            'writtenOn' => $entry->written_on?->toDateString(),
            'title' => $entry->title,
            'content' => $entry->content,
            'createdAt' => $entry->created_at?->toIso8601String(),
            'updatedAt' => $entry->updated_at?->toIso8601String(),
        ]);
        yield ',"teamAccess":';
        yield from $this->list(YourDataScope::teamAccess($user, $counsellor)->select(['id', 'resource', 'created_at']), fn (AdminContentAccess $access) => [
            'at' => $access->created_at?->toIso8601String(),
            'what' => $access->resource,
        ]);
        yield ','.$this->field('otherRecords', YourDataWording::otherRecords()).',';
        yield $this->field('retentionDays', RetentionPeriods::days());
        yield '}';
    }

    // Therapies they started, deleted ones too -- never urgent help chats. The person's own words (background story)
    // are theirs; the counsellor is never named.
    private function therapies(User $user): array
    {
        return Therapy::query()->withTrashed()->whereIn('id', YourDataScope::ownTherapyIds($user))->orderBy('id')
            ->get(['id', 'name', 'background_story', 'status', 'session_type', 'payment_type', 'anonymous', 'created_at', 'deleted_at'])
            ->map(fn (Therapy $therapy) => [
                'id' => $therapy->id,
                'name' => $therapy->name,
                'backgroundStory' => $therapy->background_story,
                'status' => $therapy->status,
                'sessionType' => $therapy->session_type,
                'paymentType' => $therapy->payment_type,
                'anonymous' => (bool) $therapy->anonymous,
                'startedAt' => $therapy->created_at?->toIso8601String(),
                'deletedAt' => $therapy->deleted_at?->toIso8601String(),
            ])
            ->all();
    }

    // Their own membership of each group: its name, when they joined and left, and what they wrote -- never its other
    // members.
    private function groups(User $user): array
    {
        return DB::table('group_therapy_user')
            ->join('group_therapies', 'group_therapies.id', '=', 'group_therapy_user.group_therapy_id')
            ->where('group_therapy_user.user_id', $user->id)
            ->orderBy('group_therapy_user.id')
            ->get(['group_therapies.id', 'group_therapies.name', 'group_therapy_user.anonymous', 'group_therapy_user.background_story', 'group_therapy_user.created_at', 'group_therapy_user.left_at'])
            ->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'anonymous' => (bool) $row->anonymous,
                'backgroundStory' => $row->background_story,
                'joinedAt' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
                'leftAt' => $row->left_at ? Carbon::parse($row->left_at)->toIso8601String() : null,
            ])
            ->all();
    }

    private function field(string $name, mixed $value): string
    {
        return json_encode($name).':'.json_encode($value, self::JSON);
    }

    // A JSON array of $query's rows, read in chunks by id.
    private function list(Builder $query, callable $row): Generator
    {
        yield '[';
        $first = true;

        foreach ($query->lazyById(self::CHUNK, $query->getModel()->getQualifiedKeyName(), $query->getModel()->getKeyName()) as $model) {
            yield ($first ? '' : ',').json_encode($row($model), self::JSON);
            $first = false;
        }

        yield ']';
    }
}
