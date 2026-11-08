<?php

namespace App\Actions\Safety;

use App\Actions\Action;
use App\Enums\SafetyFlagActionEnum;
use App\Exceptions\SafetyFlagException;
use App\Models\SafetyFlag;
use App\Models\SafetyFlagEvent;
use App\Models\SafetyFlagNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// SCRUM-32 (TT-4.3b): the administrator handling a safety flag adds a note, or records an action they
// took (from a fixed list; "other" needs a few words). The text goes in safety_flag_notes (clearable on
// account anonymisation); the history only points at it. While it's being handled -- withdrawn ones too.
class AddSafetyFlagNoteAction extends Action
{
    public const NOT_HANDLED = 'Notes and actions can be added while you are handling it.';

    public function note(?User $admin, ?SafetyFlag $flag, string $content): SafetyFlagNote
    {
        return $this->add($admin, $flag, SafetyFlagNote::NOTE, null, $content);
    }

    public function action(?User $admin, ?SafetyFlag $flag, SafetyFlagActionEnum $action, ?string $content): SafetyFlagNote
    {
        if ($action === SafetyFlagActionEnum::other && ! trim((string) $content)) {
            throw new SafetyFlagException('Please say what you did.', 422);
        }

        return $this->add($admin, $flag, SafetyFlagNote::ACTION, $action, $content);
    }

    // A note as the handling administrator sees it (also RevealSafetyFlagAction::reason).
    public static function present(SafetyFlagNote $note): array
    {
        return [
            'kind' => $note->kind,
            'action' => match ($note->kind) {
                SafetyFlagNote::HOLD_PERSON => 'Why the notice to the person was held',
                SafetyFlagNote::WITHHOLD_PERSON => 'Why the notice to the person was withheld',
                SafetyFlagNote::HOLD_GUARDIANS => 'Why the notice to their guardians was held',
                SafetyFlagNote::WITHHOLD_GUARDIANS => 'Why the notice to their guardians was withheld',
                default => $note->action_type?->label(),
            },
            'content' => $note->content,
            'by' => $note->author?->name,
            'at' => $note->created_at?->toIso8601String(),
        ];
    }

    private function add(?User $admin, ?SafetyFlag $flag, string $kind, ?SafetyFlagActionEnum $action, ?string $content): SafetyFlagNote
    {
        $flag = EnsureCanHandleSafetyFlagAction::new()->execute($admin, $flag, handler: true);
        $note = DB::transaction(function () use ($admin, $flag, $kind, $action, $content) {
            // Locked and re-read, so a note can't land after it was resolved or handed on (reviews).
            $flag = SafetyFlag::query()->lockForUpdate()->find($flag->id);
            if (! $flag->isBeingHandled() || ! $flag->isHandledBy($admin)) {
                throw new SafetyFlagException(self::NOT_HANDLED, 409);
            }

            $note = SafetyFlagNote::query()->create([
                'safety_flag_id' => $flag->id, 'author_user_id' => $admin->id, 'kind' => $kind,
                'action_type' => $action, 'content' => trim((string) $content) ?: null,
            ]);

            SafetyFlagEvent::query()->create([
                'safety_flag_id' => $flag->id, 'actor_user_id' => $admin->id,
                'action' => $kind === SafetyFlagNote::ACTION ? SafetyFlagEvent::ACTION_TAKEN : SafetyFlagEvent::NOTE,
                'data' => ['noteId' => $note->id, ...($action ? ['actionType' => $action->value] : [])],
            ]);

            return $note;
        });

        $flag->broadcastChanged();

        return $note;
    }
}
