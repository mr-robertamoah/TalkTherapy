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
// - Consent: a guest sees `accepted`; a signed-in user `accepted` and `pending`; the counsellor themselves also their
//   own `declined` and `withdrawn`.
// - Hidden, for everyone, while the counsellor isn't recognisable now (Counsellor::whereRecognisableNow(): verified now,
//   not suspended, neither profile nor account deleted) or is the reported party of ANY open or under-review report --
//   GetCounsellorIdsWithOpenReportsAction's `counsellorIds`, so a report about an urgent help request counts too. It
//   comes back once that clears (nothing is stored). Nothing returned says why a row is missing.
//
// Without $for: the showcase -- the latest month that has an approved recognition (whoever is visible in it), at most
// recognition.per_month. With $for: every month of that counsellor's (the profile badge). Eager-loads what
// RecognisedCounsellorResource reads.
class GetVisibleRecognitionsAction extends Action
{
    public const CASES_SHOWN = 3;

    /** @return Collection<int, CounsellorRecognition> */
    public function execute(?User $viewer, ?Counsellor $for = null): Collection
    {
        $recognitions = CounsellorRecognition::query()
            ->where('decision', CounsellorRecognitionDecisionEnum::approved->value)
            ->where(fn (Builder $query) => $this->consentVisibleTo($query, $viewer))
            ->whereIn('counsellor_id', Counsellor::query()->select('id')->whereRecognisableNow())
            ->when($for, fn (Builder $query) => $query->where('counsellor_id', $for->id))
            ->when(! $for, fn (Builder $query) => $query->where('month', fn (QueryBuilder $latest) => $latest
                ->from('counsellor_recognitions')
                ->selectRaw('max(month)')
                ->where('decision', CounsellorRecognitionDecisionEnum::approved->value)))
            // Approval order: never the stars snapshot or rank (no star standing is shown publicly).
            ->orderByDesc('month')
            ->orderBy('id')
            ->with([
                'counsellor.user',
                'counsellor.avatarFile',
                'counsellor.profession',
                'counsellor.cases' => fn ($query) => $query->orderBy('therapy_cases.id')->limit(self::CASES_SHOWN),
            ])
            ->get();

        $reported = GetCounsellorIdsWithOpenReportsAction::new()
            ->execute($recognitions->pluck('counsellor_id')->map(fn ($id) => (int) $id)->unique()->values()->all())['counsellorIds'];

        $visible = $recognitions->reject(fn (CounsellorRecognition $recognition) => in_array((int) $recognition->counsellor_id, $reported, true));

        return ($for ? $visible : $visible->take(GetRecognitionSuggestionsAction::perMonth()))->values();
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
