<?php

namespace Database\Seeders;

use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Actions\Transaction\ResolvePlatformFeePolicyAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\Counsellor;
use App\Models\CounsellorEarning;
use App\Models\Organization;
use App\Models\PlatformFeeWaiver;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// TT-7.10b/SCRUM-468: two platform-wide commission-free periods, created by the super admin (mr_robertamoah) -- one
// that ended (60 to 30 days ago) and one coming up (20 to 27 days ahead, inside the 30-day notice horizon, so
// counsellors and organisations see the notice). Never one running now: every payment made while seeding or trying
// other features would be fee-free. Dates are relative to the first run; each is skipped once it exists (by reason).
//
// And, made during the ended period so the labels can be seen, all for a counsellor of its own (nothing is added to
// another demo account's balance):
// - commission_free_demo_counsellor ("password"): a pending earning with no fee ("Commission-free") next to one that
//   paid the usual fee, on their profile's Payouts section;
// - a therapy of theirs with org_demo_payperuse_member, charged to the demo organisation's card with the fee waived
//   ("Waived" on org_demo_admin's reconciliation page).
// Each part is skipped once it exists, so the seeder can be run again on its own.
class CommissionFreePeriodSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public const ENDED_REASON = 'Demo: launch month thank-you (ended)';

    public const UPCOMING_REASON = 'Demo: Mental Health Awareness week (upcoming)';

    public const ORG_THERAPY = 'Commission-Free Demo Org Therapy';

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $superAdmin = User::query()->where('username', 'mr_robertamoah')->first();

        $ended = $this->period(self::ENDED_REASON, now()->subDays(60)->startOfDay(), now()->subDays(30)->startOfDay(), $superAdmin);
        $this->period(self::UPCOMING_REASON, now()->addDays(20)->startOfDay(), now()->addDays(27)->startOfDay(), $superAdmin);

        $counsellor = $this->counsellor();

        if ($counsellor) {
            $this->counsellorEarnings($ended, $counsellor);
            $this->organizationCharge($ended, $counsellor);
        }
    }

    // The period -- made the first time, as it is after that.
    private function period(string $reason, CarbonInterface $startsAt, CarbonInterface $endsAt, ?User $creator): PlatformFeeWaiver
    {
        $waiver = PlatformFeeWaiver::query()->whereNull('counsellor_id')->firstOrNew(['reason' => $reason]);

        if ($waiver->exists) {
            return $waiver;
        }

        $waiver->fill(['starts_at' => $startsAt, 'ends_at' => $endsAt])
            ->forceFill(['created_by_id' => $creator?->id])
            ->save();

        return $waiver;
    }

    private function counsellor(): ?Counsellor
    {
        $existing = User::query()->where('username', 'commission_free_demo_counsellor')->first();

        if ($existing) {
            return $existing->counsellor;
        }

        $counsellorUser = User::factory()->create([
            'firstName' => 'CommissionFree',
            'lastName' => 'DemoCounsellor',
            'email' => 'commission.free.demo.counsellor@example.com',
            'username' => 'commission_free_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. CommissionFree DemoCounsellor',
            'about' => 'Seeded counsellor with a commission-free earning next to an ordinary one (SCRUM-468).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        return $counsellor;
    }

    private function counsellorEarnings(PlatformFeeWaiver $ended, Counsellor $counsellor): void
    {
        $client = User::query()->where('username', 'payout_demo_client')->first();

        if (! $client || Therapy::query()->where('counsellor_id', $counsellor->id)->where('name', 'Commission-Free Demo Therapy 1')->exists()) {
            return;
        }

        // GHS 100 paid during the period (no fee, the usual fee waived), and GHS 50 paid after it (the usual fee). The
        // fee and waiver come from the real fee decision at each payment's moment, so they match the current percentage.
        $payments = [
            ['at' => $ended->starts_at->copy()->addDays(3), 'gross' => 10000],
            ['at' => $ended->ends_at->copy()->addDays(2), 'gross' => 5000],
        ];

        foreach ($payments as $index => $payment) {
            $decision = ResolvePlatformFeePolicyAction::new()->execute($payment['at'])->feeFor($payment['gross'], $counsellor);
            $payment += ['fee' => $decision['fee'], 'waived' => $decision['waived'], 'waiver' => $decision['waiverId']];

            $therapy = $client->addedTherapies()->create([
                'name' => 'Commission-Free Demo Therapy '.($index + 1),
                'background_story' => 'Seeded PAID therapy whose payment backs a demo counsellor earning (SCRUM-468).',
                'counsellor_id' => $counsellor->id,
                'session_type' => TherapySessionTypeEnum::once->value,
                'payment_type' => TherapyPaymentTypeEnum::paid->value,
                'allow_in_person' => false,
                'anonymous' => false,
                'public' => false,
                'status' => TherapyStatusEnum::in_session->value,
                'payment_data' => ['amount' => $payment['gross'] / 100, 'currency' => 'GHS', 'per' => TherapyPerPaymentEnum::therapy->value],
            ]);

            $transaction = new Transaction([
                'for_type' => Therapy::class,
                'for_id' => $therapy->id,
                'user_id' => $client->id,
                'reference' => 'commission_free_demo_'.Str::uuid(),
                'amount' => $payment['gross'],
                'currency' => 'GHS',
                'status' => TransactionStatusEnum::success->value,
            ]);
            $transaction->created_at = $payment['at'];
            $transaction->save();

            $earning = new CounsellorEarning([
                'transaction_id' => $transaction->id,
                'counsellor_id' => $counsellor->id,
                'gross_amount' => $payment['gross'],
                'fee_amount' => $payment['fee'],
                'net_amount' => $payment['gross'] - $payment['fee'],
                'currency' => 'GHS',
                'status' => CounsellorEarningStatusEnum::pending->value,
                'platform_fee_waiver_id' => $payment['waiver'],
                'waived_fee_amount' => $payment['waived'],
            ]);
            $earning->created_at = $payment['at'];
            $earning->save();
        }
    }

    // A pay-per-use charge to the demo organisation's card during the period, for a therapy of this counsellor's with
    // org_demo_payperuse_member: GHS 50 to the counsellor, no fee on top (the usual fee waived). GHS, like their other
    // earnings, so their available balance stays in one currency.
    private function organizationCharge(PlatformFeeWaiver $ended, Counsellor $counsellor): void
    {
        $organization = Organization::query()->where('name', 'Org Demo Wellness Collective')->first();
        $member = User::query()->where('username', 'org_demo_payperuse_member')->first();

        if (! $organization || ! $member || Therapy::query()->where('name', self::ORG_THERAPY)->exists()) {
            return;
        }

        $therapy = $member->addedTherapies()->create([
            'name' => self::ORG_THERAPY,
            'background_story' => 'Seeded PAID therapy charged to the demo organisation\'s card during a commission-free period (SCRUM-468).',
            'counsellor_id' => $counsellor->id,
            'session_type' => TherapySessionTypeEnum::once->value,
            'payment_type' => TherapyPaymentTypeEnum::paid->value,
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => TherapyStatusEnum::in_session->value,
            'payment_data' => ['amount' => 50, 'currency' => 'GHS', 'per' => TherapyPerPaymentEnum::therapy->value],
        ]);

        $at = $ended->starts_at->copy()->addDays(5);
        $decision = ResolvePlatformFeePolicyAction::new()->execute($at)->feeFor(5000, $counsellor);
        $transaction = new Transaction([
            'for_type' => Therapy::class,
            'for_id' => $therapy->id,
            'user_id' => $member->id,
            'organization_id' => $organization->id,
            'reference' => ChargeOrganizationForModelAction::REFERENCE_PREFIX.Str::uuid(),
            'amount' => 5000,
            'currency' => 'GHS',
            'status' => TransactionStatusEnum::success->value,
            'breakdown' => ['lines' => [[
                'counsellor_id' => $counsellor->id,
                'net_amount' => 5000,
                'fee_amount' => $decision['fee'],
                'platform_fee_waiver_id' => $decision['waiverId'],
                'waived_fee_amount' => $decision['waived'],
            ]]],
        ]);
        $transaction->created_at = $at;
        $transaction->save();

        $earning = new CounsellorEarning([
            'transaction_id' => $transaction->id,
            'counsellor_id' => $counsellor->id,
            'gross_amount' => 5000,
            'fee_amount' => $decision['fee'],
            'net_amount' => 5000,
            'currency' => 'GHS',
            'status' => CounsellorEarningStatusEnum::pending->value,
            'platform_fee_waiver_id' => $decision['waiverId'],
            'waived_fee_amount' => $decision['waived'],
        ]);
        $earning->created_at = $at;
        $earning->save();
    }
}
