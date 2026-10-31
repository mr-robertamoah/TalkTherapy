<?php

namespace App\Http\Resources;

use App\Models\Counsellor;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return self::present($this->resource, $request->user());
    }

    // SCRUM-195: the ONE place a message's shape is decided for a given viewer. Request-free so
    // MessageBroadcastTrait can call it with no viewer at all -- which, by construction, is the
    // most restricted view (no confidential content, every anonymous id masked) -- instead of
    // keeping a hand-copied second shape that could drift from this one.
    public static function present(Message $message, ?User $viewer): array
    {
        $fromCounsellor = $message->from_type == Counsellor::class;

        $fromId = $fromCounsellor ? $message->from?->user?->id : $message->from_id;

        // Anonymity only ever applies to a client/User sender (never a counsellor -- confirmed
        // Discussion messages can *only* ever come from a counsellor, so `$message->for instanceof
        // Session` also naturally excludes Discussion without needing an extra type check), is
        // computed live from the current Therapy/GroupTherapy anonymity flag(s) rather than a
        // stored snapshot, and never applies when the viewer is the sender themselves (mirrors
        // TherapyResource's `$this->addedby?->is($user) || ! $this->anonymous` precedent).
        $fromUser = $fromCounsellor ? null : $message->from;
        $isAnonymousSender = $fromUser
            && $message->for instanceof Session
            && $message->for->isAnonymousFor($fromUser)
            && ! $fromUser->is($viewer);

        // fromUserId is never displayed as a name in the frontend -- it only drives equality
        // checks against the viewer's own id (own-message layout/edit/delete). Nulling it for a
        // masked message is therefore safe for every viewer except the sender themselves (who
        // must keep seeing their own real id so they can still edit/delete their own message).
        $maskedFromId = $isAnonymousSender ? null : $fromId;

        // SCRUM-195 (security review): the recipient's id gets the same masking as the sender's --
        // a counsellor writing to an anonymous member must not reveal that member's real user id to
        // the rest of the group. `recipientMasked` lets the recipient's own client still recognise
        // a redacted confidential broadcast as possibly theirs (see resolveBroadcastMessage.js).
        $toUser = $message->to_type === User::class ? $message->to : null;
        $isAnonymousRecipient = $toUser
            && $message->for instanceof Session
            && $message->for->isAnonymousFor($toUser)
            && ! $toUser->is($viewer);
        $toId = $isAnonymousRecipient ? null : $message->recipientUserId();
        $recipient = $isAnonymousRecipient
            ? ['toUserId' => null, 'recipientMasked' => true]
            : ['toUserId' => $toId];

        if ($message->deleted_at) {
            return [
                'id' => $message->id,
                'status' => 'deleted for everyone',
                'topicId' => $message->therapy_topic_id,
                'fromUserId' => $maskedFromId,
                'type' => $message->type,
                'updatedAt' => $message->updated_at,
            ];
        }

        if ($viewer && str_contains($message->deleted_for ?: '', $viewer->id)) {
            return [
                'id' => $message->id,
                'fromUserId' => $maskedFromId,
                'status' => 'deleted for me',
                'type' => $message->type,
                'updatedAt' => $message->updated_at,
            ];
        }

        if ($message->confidential && $message->isNotParty($viewer)) {
            return [
                'id' => $message->id,
                'fromUserId' => $maskedFromId,
                'fromCounsellor' => $fromCounsellor,
                ...$recipient,
                'topicId' => $message->therapy_topic_id,
                'confidential' => $message->confidential,
                'status' => $message->status,
                'type' => $message->type,
                'updatedAt' => $message->updated_at,
            ];
        }

        // Only ever an actual counsellor account (security re-review): taking a User recipient's
        // own counsellor profile here would show an anonymous member's avatar to the whole group.
        $counsellor = match (true) {
            $fromCounsellor => $message->from,
            $message->to_type === Counsellor::class => $message->to,
            default => null,
        };
        $forType = str_replace('App\Models\\', '', $message->for_type);

        $data = [
            'id' => $message->id,
            'fromUserId' => $maskedFromId,
            ...$recipient,
            'fromCounsellor' => $fromCounsellor,
            'counsellorAvatar' => $counsellor?->avatar?->url,
            'content' => $message->content,
            'confidential' => $message->confidential,
            'type' => $message->type,
            'topicId' => $message->therapy_topic_id,
            'forType' => $forType,
            'status' => $message->status,
            'files' => self::attachmentsOf($message),
            'updatedAt' => $message->updated_at,
            'createdAt' => $message->created_at,
        ];

        if ($message->replying) {
            $data['replying'] = MessageMiniResource::present($message->replying, $viewer);
        }

        // Only present when the caller (MessageService) eager-loaded `notes` scoped to the
        // requesting counsellor's own id -- omitted entirely (not just null) for a client viewer,
        // since that eager-load never runs for them (SCRUM-203/TT-2.3b). Never for no viewer (a
        // broadcast) and never someone else's note, whatever happens to be loaded (SCRUM-195).
        $viewerCounsellorId = $viewer?->counsellor?->id;
        if ($viewerCounsellorId && $message->relationLoaded('notes')) {
            $note = $message->notes->firstWhere('counsellor_id', $viewerCounsellorId);
            $data['note'] = $note ? new MessageNoteResource($note) : null;
        }

        if ($forType == 'Discussion') {
            $data['counsellorName'] = $counsellor?->getName();
        }

        return $data;
    }

    // SCRUM-336: never File::url -- chat attachments live on a private disk, and getUrlFor()
    // refuses them. Always the authorized retrieval route, in FileResource's own shape.
    public static function attachmentsOf(Message $message): array
    {
        return $message->files->map(fn ($file) => [
            'id' => $file->id,
            'name' => $file->name,
            'mime' => $file->mime,
            'url' => route('messages.attachments.show', ['message' => $message->id, 'file' => $file->id]),
        ])->values()->all();
    }
}
