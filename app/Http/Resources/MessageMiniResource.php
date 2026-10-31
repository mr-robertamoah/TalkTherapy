<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageMiniResource extends JsonResource
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

    // SCRUM-195 (security review): the preview of the message being replied to obeys the same
    // confidentiality rule as the message itself -- otherwise replying to a confidential message
    // re-published its content to everyone who could see the reply. Request-free for the same
    // reason as MessageResource::present() (a broadcast passes no viewer).
    public static function present(Message $message, ?User $viewer): array
    {
        if ($message->confidential && $message->isNotParty($viewer)) {
            return [
                'id' => $message->id,
                'confidential' => true,
                'updatedAt' => $message->updated_at->diffForHumans(),
            ];
        }

        return [
            'id' => $message->id,
            'content' => $message->content,
            'files' => MessageResource::attachmentsOf($message),
            'updatedAt' => $message->updated_at->diffForHumans(),
        ];
    }
}
