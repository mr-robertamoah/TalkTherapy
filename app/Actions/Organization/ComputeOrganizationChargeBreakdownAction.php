<?php

namespace App\Actions\Organization;

use App\Actions\Action;
use App\Enums\OrganizationCounsellorCompensationBasisEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Models\Counsellor;
use App\Models\OrganizationCounsellorCompensation;
use App\Support\PlatformFeePolicy;
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
// - TT-7.10a/SCRUM-467: $fees is the fee as it stood when this bill is decided (required, so no caller can forget a
//   fee-free period). A line whose counsellor is covered by a waiver pays no fee and records what it would have
//   been on its own part; the pooled fee is over the other lines' parts only, split across them as before. With no
//   waiver in force every number is what it was before waivers existed.
//
// A counsellor with no terms, or whose share can't be computed, is returned in `skipped` rather than thrown: the
// invoice bills the others, the card charge refuses.
class ComputeOrganizationChargeBreakdownAction extends Action
{
    /**
     * @param  Collection<int, Counsellor>  $counsellors
     * @param  Closure(Counsellor): ?OrganizationCounsellorCompensation  $termsFor
     * @param  array{0: int, 1: int}  $seat
     * @return array{lines: list<array{counsellor: Counsellor, net: int, fee: int, platform_fee_waiver_id: ?int, waived_fee_amount: int}>, skipped: list<array{counsellor: Counsellor, reason: string, message: ?string}>}
     */
    public function execute(Collection $counsellors, Closure $termsFor, ?int $listedAmount, array $seat, PlatformFeePolicy $fees): array
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

        // The fee on the parts billed: a counsellor this organisation doesn't pay takes their part's fee with them, and
        // so does one whose fee is waived.
        $charged = array_keys(array_filter($billed, fn (array $entry) => $fees->waiverFor($entry['counsellor']) === null));
        $fee = $listedAmount !== null && $charged
            ? $fees->feeFor(array_sum(array_map(fn (int $index) => $billed[$index]['part'], $charged)))['fee']
            : 0;
        $chargedFees = $charged ? array_combine($charged, self::split($fee, count($charged))) : [];

        $lines = array_map(function (array $entry, int $index) use ($chargedFees, $fees) {
            // A waived line: no fee, and what its own part's fee would have been is recorded (0 with no listed price).
            $waiver = array_key_exists($index, $chargedFees)
                ? ['fee' => $chargedFees[$index], 'waiverId' => null, 'waived' => 0]
                : $fees->feeFor($entry['part'] ?? 0, $entry['counsellor']);

            return [
                'counsellor' => $entry['counsellor'],
                'net' => $entry['net'],
                'fee' => $waiver['fee'],
                'platform_fee_waiver_id' => $waiver['waiverId'],
                'waived_fee_amount' => $waiver['waived'],
            ];
        }, $billed, array_keys($billed));

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
