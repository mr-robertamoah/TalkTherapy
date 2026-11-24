<?php

namespace App\Actions\Recognition;

use App\Actions\Action;
use App\Enums\CounsellorRecognitionConsentEnum as Consent;
use App\Enums\CounsellorRecognitionDecisionEnum;
use App\Models\Counsellor;
use App\Models\CounsellorRecognition;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

// TT-7.11f/SCRUM-476: THE rule for which recognitions $viewer may see -- the Home showcase, Home's "Counsellors of the
// month" section and the profile badge all read this, nothing else decides it.
//
// - Approved only.
// - Consent: a guest sees `accepted`; a signed-in user `accepted` and `pending` (the user's decision, 2026-11-22); the
//   counsellor themselves also their own `declined` and `withdrawn`.
// - Hidden, for everyone, while the counsellor isn't recognisable now (Counsellor::whereRecognisableNow(): verified now,
//   not suspended, neither profile nor account deleted) or is the reported party of an open or under-review report
//   that may be shown against them (publiclyHiddenIds()). It comes back once that clears (nothing is stored). Nothing
//   returned says why a row is missing.
//
// Without $for: the showcase -- the latest month that has an approved recognition (whoever is visible in it), at most
// recognition.per_month, with what RecognisedCounsellorResource reads eager-loaded. With $for: every month of that
// counsellor's (the profile badge; nothing extra loaded).
//
// Home asks for candidates() and withoutHidden() separately, so the showcase and "Leading" share one report pass
// (GetHomeCounsellorsAction); execute() is both for one caller.
class GetVisibleRecognitionsAction extends Action
{
    public const CASES_SHOWN = 3;

    /** @return Collection<int, CounsellorRecognition> */
    public function execute(?User $viewer, ?Counsellor $for = null): Collection
    {
        $candidates = $this->candidates($viewer, $for);

        return $this->withoutHidden($candidates, self::publiclyHiddenIds($candidates->pluck('counsellor_id')->all()), ! $for);
    }

    // Every row the rule allows, open reports aside.
    /** @return Collection<int, CounsellorRecognition> */
    public function candidates(?User $viewer, ?Counsellor $for = null): Collection
    {
        return CounsellorRecognition::query()
            ->approved()
            ->where(fn (Builder $query) => $this->consentVisibleTo($query, $viewer))
            ->whereIn('counsellor_id', Counsellor::query()->select('id')->whereRecognisableNow())
            ->when($for, fn (Builder $query) => $query->where('counsellor_id', $for->id))
            ->when(! $for, fn (Builder $query) => $query
                ->where('month', fn (QueryBuilder $latest) => $latest
                    ->from('counsellor_recognitions')
                    ->selectRaw('max(month)')
                    ->where('decision', CounsellorRecognitionDecisionEnum::approved->value))
                ->with([
                    'counsellor.user',
                    'counsellor.avatarFile',
                    'counsellor.profession',
                    'counsellor.cases' => fn ($query) => $query->orderBy('therapy_cases.id')->limit(self::CASES_SHOWN),
                ]))
            // Approval order: never the stars snapshot or rank (no star standing is shown publicly).
            ->orderByDesc('month')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, CounsellorRecognition>  $candidates
     * @param  array<int, int>  $hiddenIds  from publiclyHiddenIds()
     * @return Collection<int, CounsellorRecognition>
     */
    public function withoutHidden(Collection $candidates, array $hiddenIds, bool $showcase = true): Collection
    {
        $visible = $candidates->reject(fn (CounsellorRecognition $recognition) => in_array((int) $recognition->counsellor_id, $hiddenIds, true));

        return ($showcase ? $visible->take(GetRecognitionSuggestionsAction::perMonth()) : $visible)->values();
    }

    // Which of these counsellors an open or under-review report hides from public view: every such report EXCEPT one
    // about an urgent help request (`flaggableCounsellorIds`, the user's decision of 2026-11-23). If those hid a
    // counsellor, a reporter watching Home could see who disappeared, and so learn who asked for urgent help. They
    // still block a new recognition and a new waiver (the admin side reads the full `counsellorIds`). Reads only the
    // reports that could be about these counsellors. Also Home's "Leading".
    /**
     * @param  array<int, int|string>  $counsellorIds
     * @return array<int, int>
     */
    public static function publiclyHiddenIds(array $counsellorIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $counsellorIds)));

        return GetCounsellorIdsWithOpenReportsAction::new()->execute($ids)['flaggableCounsellorIds'];
    }

    private function consentVisibleTo(Builder $query, ?User $viewer): void
    {
        $query->where('consent', Consent::accepted->value);

        if (! $viewer) {
            return;
        }

        $query->orWhere('consent', Consent::pending->value)
            ->orWhere(fn (Builder $own) => $own
                ->whereIn('consent', [Consent::declined->value, Consent::withdrawn->value])
                ->whereIn('counsellor_id', Counsellor::withTrashed()->select('id')->where('user_id', $viewer->id)));
    }
}
