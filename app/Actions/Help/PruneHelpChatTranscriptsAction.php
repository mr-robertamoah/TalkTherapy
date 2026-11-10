<?php

namespace App\Actions\Help;

use App\Actions\Action;
use App\Models\HelpRequest;
use App\Models\Message;
use App\Models\MessageNote;
use App\Models\Session;
use App\Models\SessionNote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// SCRUM-33 (TT-4.4c, the user's decision): a help chat is kept for a year after it ended, then its
// messages (and any counsellor's notes on them or on the chat's session) are deleted for good, along with the person's one-line
// note and an administrator's close note. The request row stays without them (who, when, how it ended
// -- for the audit trail), and the chat page then says the messages were deleted.
// A chat with an open report (on the chat, its session or one of its messages) is kept until the report
// is resolved -- the evidence mustn't disappear while it is reviewed. Daily, in batches.
class PruneHelpChatTranscriptsAction extends Action
{
    private const BATCH = 200;

    // Deleting can't be undone, so a missing or mistyped setting must never shorten the year (devops
    // review): anything under this is refused and the run skipped, with an error logged.
    public const MINIMUM_DAYS = 30;

    public function execute(): int
    {
        $days = config('help.transcript_retention_days');
        if (! is_numeric($days) || (int) $days < self::MINIMUM_DAYS) {
            Log::error('Help chat transcripts not pruned: HELP_TRANSCRIPT_RETENTION_DAYS must be at least '.self::MINIMUM_DAYS.' days.', ['configured' => $days]);

            return 0;
        }

        $before = now()->subDays((int) $days);
        $pruned = 0;
        $held = 0;

        HelpRequest::query()
            ->whereNull('transcript_deleted_at')
            ->whereNotNull('ended_at')
            ->where('ended_at', '<=', $before)
            ->chunkById(self::BATCH, function ($requests) use (&$pruned, &$held) {
                foreach ($requests as $request) {
                    // An open report, or (SCRUM-32) a safety flag still being dealt with, keeps it.
                    if ($request->hasOpenReport() || $request->hasUnresolvedSafetyFlag()) {
                        $held++;

                        continue;
                    }

                    $pruned += DB::transaction(function () use ($request) {
                        // Help chats are text only. If attachments are ever allowed, their files must go here too.
                        if ($request->session_id) {
                            $messages = Message::withTrashed()->where('for_type', Session::class)->where('for_id', $request->session_id);
                            // Notes for good too, including ones already deleted (they're soft-deleted, and the
                            // messages' deletion would otherwise only unlink them -- review).
                            MessageNote::withTrashed()->whereIn('message_id', (clone $messages)->select('id'))->forceDelete();
                            $messages->forceDelete();
                            // And any session notes the counsellor wrote on the chat (security review).
                            SessionNote::withTrashed()->where('session_id', $request->session_id)->forceDelete();
                        }

                        return HelpRequest::query()->whereKey($request->id)->whereNull('transcript_deleted_at')
                            ->update(['note' => null, 'closed_note' => null, 'transcript_deleted_at' => now()]);
                    });
                }
            });

        // A record of every permanent deletion run (devops review).
        Log::info('Help chat transcripts pruned.', ['pruned' => $pruned, 'heldForReports' => $held, 'endedBefore' => $before->toIso8601String()]);

        return $pruned;
    }
}
