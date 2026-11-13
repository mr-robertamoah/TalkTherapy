<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Actions\Transaction\ComputePlatformFeeAction;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\Counsellor;
use App\Models\OrganizationCounsellorCompensation;
use Closure;
use Illuminate\Support\Collection;
use InvalidArgumentException;

// SCRUM-420: the ONE place an organisation's bill for one member's place in a therapy or group is worked out --
// shared by the card charge (ChargeOrganizationForModelAction) and the retainer invoice
// (RecordOrganizationInvoiceLineForSessionAction), so the two can't drift. All amounts are minor units.
//
// - The listed price is split equally between the counsellors (any remainder to the first); each is paid their own
//   terms with the organisation on their part.
// - A rate that isn't a share of the price -- fixed, or a percentage of a negotiated rate -- is the counsellor's pay
//   for the whole unit (the user's decision, 2026-11-12), so each member carries an equal share of it: $seat is
//   [this member's place, how many places] -- who was in a held session for the retainer, the group's maximum size
//   for a card charge (paid before anyone knows who'll come) -- any remainder on the first. A therapy has one client,
//   so its one line is what it always was.
// - The platform fee is on the price of the parts billed, shared across the lines the same way.
//
// A counsellor with no terms, or whose share can't be computed, is returned in `skipped` rather than thrown: the
// invoice bills the others, the card charge refuses.
class ComputeOrganizationChargeBreakdownAction extends Action
{
    /**
     * @param  Collection<int, Counsellor>  $counsellors
     * @param  Closure(Counsellor): ?OrganizationCounsellorCompensation  $termsFor
     * @param  array{0: int, 1: int}  $seat
     * @return array{lines: list<array{counsellor: Counsellor, net: int, fee: int}>, skipped: list<array{counsellor: Counsellor, reason: string, message: ?string}>}
     */
    public function execute(Collection $counsellors, Closure $termsFor, ?int $listedAmount, array $seat = [0, 1]): array
    {
        $counsellors = $counsellors->values();
        $parts = self::split($listedAmount, max(1, $counsellors->count()));
        $billed = [];
        $skipped = [];

        foreach ($counsellors as $index => $counsellor) {
            $compensation = $termsFor($counsellor);

            if (! $compensation) {
                $skipped[] = ['counsellor' => $counsellor, 'reason' => 'no_terms', 'message' => null];

                continue;
            }

            try {
                $net = ComputeCounsellorCompensationShareAction::new()->execute($compensation, $parts[$index]);

                if (self::isForTheWholeUnit($compensation)) {
                    $net = self::split($net, max(1, $seat[1]))[$seat[0]]
                        ?? throw new InvalidArgumentException('This member\'s place is outside the unit\'s places.');
                }
            } catch (InvalidArgumentException $exception) {
                $skipped[] = ['counsellor' => $counsellor, 'reason' => 'unable_to_compute', 'message' => $exception->getMessage()];

                continue;
            }

            $billed[] = [
                'counsellor' => $counsellor,
                'part' => $parts[$index],
                'net' => $net,
            ];
        }

        if (! $billed) {
            return ['lines' => [], 'skipped' => $skipped];
        }

        // The fee on the parts billed: a counsellor this organisation doesn't pay takes their part's fee with them.
        $fee = $listedAmount !== null ? ComputePlatformFeeAction::new()->execute(array_sum(array_column($billed, 'part'))) : 0;
        $fees = self::split($fee, count($billed));

        $lines = array_map(
            fn (array $entry, int $index) => ['counsellor' => $entry['counsellor'], 'net' => $entry['net'], 'fee' => $fees[$index]],
            $billed,
            array_keys($billed)
        );

        return ['lines' => $lines, 'skipped' => $skipped];
    }

    // Whether these terms pay for the unit whatever its price -- not a percentage of the price itself.
    private static function isForTheWholeUnit(OrganizationCounsellorCompensation $compensation): bool
    {
        return $compensation->type === OrganizationCounsellorCompensationTypeEnum::fixed->value
            || ($compensation->type === OrganizationCounsellorCompensationTypeEnum::percentage->value
                && $compensation->basis === OrganizationCounsellorCompensationBasisEnum::negotiatedRate->value);
    }

    // $amount in $count whole parts, any remainder on the first; null stays null (no listed price).
    private static function split(?int $amount, int $count): array
    {
        if ($amount === null) {
            return array_fill(0, $count, null);
        }

        $parts = array_fill(0, $count, intdiv($amount, $count));
        $parts[0] += $amount % $count;

        return $parts;
    }
}
