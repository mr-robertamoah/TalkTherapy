<?php

namespace App\Support;

use App\Actions\Transaction\ComputePlatformFeeAction;
use App\Models\Counsellor;
use App\Models\PlatformFeeWaiver;

// TT-7.10a/SCRUM-467: the platform fee as it stood at one moment -- the percentage and the fee-free periods
// (PlatformFeeWaiver) in force then. Every money path decides its fee through one of these, made once per request or
// loop by ResolvePlatformFeePolicyAction, and stores the result (fee, the waiver id, the amount waived); nothing
// decides it again later (a refund, a settlement, an ended period).
//
// A waiver means no fee at all (the user's decision, 2026-11-20): a personal payment's counsellor keeps the whole
// amount, an organisation pays nothing on top. The fee it would have been is recorded as waived. A platform-wide
// waiver wins over a counsellor's own for which one is recorded.
final readonly class PlatformFeePolicy
{
    /**
     * @param  array<int, int>  $counsellorWaiverIds  counsellor id => the id of the waiver covering them
     */
    private function __construct(
        public float $percentage,
        private ?int $platformWaiverId,
        private array $counsellorWaiverIds,
    ) {}

    /**
     * @param  iterable<PlatformFeeWaiver>  $waivers  the waivers in force at the policy's moment
     */
    public static function from(float $percentage, iterable $waivers): self
    {
        $platformWaiverId = null;
        $counsellorWaiverIds = [];

        // Periods don't overlap; if they ever did, the oldest row wins, so the choice is the same every time.
        foreach ($waivers as $waiver) {
            if ($waiver->counsellor_id === null) {
                $platformWaiverId ??= $waiver->id;
            } else {
                $counsellorWaiverIds[$waiver->counsellor_id] ??= $waiver->id;
            }
        }

        return new self($percentage, $platformWaiverId, $counsellorWaiverIds);
    }

    // The waiver covering work for $counsellor (or, with none given, the whole platform), or null: the fee applies.
    public function waiverFor(?Counsellor $counsellor = null): ?int
    {
        return $this->platformWaiverId ?? ($counsellor ? $this->counsellorWaiverIds[$counsellor->id] ?? null : null);
    }

    /**
     * The fee on $baseAmount (minor units) for work by $counsellor. A waiver is recorded only when there was a fee to
     * waive.
     *
     * @return array{fee: int, waiverId: ?int, waived: int}
     */
    public function feeFor(int $baseAmount, ?Counsellor $counsellor = null): array
    {
        $fee = ComputePlatformFeeAction::new()->execute($baseAmount, $this->percentage);
        $waiverId = $fee > 0 ? $this->waiverFor($counsellor) : null;

        return $waiverId === null
            ? ['fee' => $fee, 'waiverId' => null, 'waived' => 0]
            : ['fee' => 0, 'waiverId' => $waiverId, 'waived' => $fee];
    }

    // This policy keeping only the waivers $later still has in force (security review, SCRUM-467): a personal payment
    // paid long after its checkout started keeps the waiver it started under only while that same waiver still runs.
    public function keepingOnlyWaiversStillIn(self $later): self
    {
        return new self(
            $this->percentage,
            $this->platformWaiverId !== null && $this->platformWaiverId === $later->platformWaiverId ? $this->platformWaiverId : null,
            array_intersect_assoc($this->counsellorWaiverIds, $later->counsellorWaiverIds),
        );
    }
}
