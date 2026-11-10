<?php

namespace App\DTOs;

use App\Models\Counsellor;
use App\Models\Message;
use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Collection;

// SCRUM-340: which confidential messages a report lets an admin read. Built only by
// ResolveReportUnlockScopeAction. Empty (no sessions) means nothing can be unlocked.
final class ReportUnlockScope
{
    /**
     * @param  Collection<int, int>  $sessionIds  sessions of the reported conversation
     * @param  array<int, int>  $partyUserIds  the reported person, as a User
     * @param  array<int, int>  $partyCounsellorIds  the reported person, as a Counsellor
     */
    public function __construct(
        public readonly Collection $sessionIds,
        private readonly array $partyUserIds = [],
        private readonly array $partyCounsellorIds = [],
    ) {}

    public static function none(): self
    {
        return new self(collect());
    }

    public function isEmpty(): bool
    {
        return $this->sessionIds->isEmpty();
    }

    public function allowsSession(?Session $session): bool
    {
        return (bool) $session && $this->sessionIds->contains($session->id);
    }

    // A confidential message is unlocked only when it's in one of the report's sessions and the
    // reported person sent or received it -- a private exchange between two other members of a
    // group stays private.
    public function reveals(Message $message): bool
    {
        return $message->for_type == Session::class
            && $this->sessionIds->contains($message->for_id)
            && ($this->sentByParty($message) || $this->receivedByParty($message));
    }

    public function sentByParty(Message $message): bool
    {
        return $this->isParty($message->from_type, $message->from_id);
    }

    public function receivedByParty(Message $message): bool
    {
        return $this->isParty($message->to_type, $message->to_id);
    }

    private function isParty(?string $type, $id): bool
    {
        return match ($type) {
            User::class => in_array((int) $id, $this->partyUserIds, true),
            Counsellor::class => in_array((int) $id, $this->partyCounsellorIds, true),
            default => false,
        };
    }
}
