<?php

namespace Database\Seeders;

use App\Actions\Transaction\ChargeOrganizationForModelAction;
use App\Enums\CounsellorEarningStatusEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapyPerPaymentEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
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
// other features would be fee-free. Dates are relative to when it runs; each is skipped once it exists (by reason).
//
// And, made during the ended period so the labels can be seen:
// - commission_free_demo_counsellor ("password"): a pending earning with no fee ("Commission-free") next to one that
//   paid the usual fee, on their profile's Payouts section;
// - the demo organisation (org_demo_admin): a card charge whose fee was waived ("Waived") on its reconciliation page.
class CommissionFreePeriodSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public const ENDED_REASON = 'Demo: launch month thank-you (ended)';

    public const UPCOMING_REASON = 'Demo: Mental Health Awareness week (upcoming)';

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $superAdmin = User::query()->where('username', 'mr_robertamoah')->first();

        $ended = $this->period(self::ENDED_REASON, now()->subDays(60)->startOfDay(), now()->subDays(30)->startOfDay(), $superAdmin);
        $this->period(self::UPCOMING_REASON, now()->addDays(20)->startOfDay(), now()->addDays(27)->startOfDay(), $superAdmin);

        if ($ended) {
            $this->counsellorEarnings($ended);
            $this->organizationCharge($ended);
        }
    }

    // The new period, or null when it already exists.
    private function period(string $reason, CarbonInterface $startsAt, CarbonInterface $endsAt, ?User $creator): ?PlatformFeeWaiver
    {
        $waiver = PlatformFeeWaiver::query()->whereNull('counsellor_id')->firstOrNew(['reason' => $reason]);

        if ($waiver->exists) {
            return null;
        }

        $waiver->fill(['starts_at' => $startsAt, 'ends_at' => $endsAt])
            ->forceFill(['created_by_id' => $creator?->id])
            ->save();

        return $waiver;
    }

    private function counsellorEarnings(PlatformFeeWaiver $ended): void
    {
        $client = User::query()->where('username', 'payout_demo_client')->first();

        if (! $client || User::query()->where('username', 'commission_free_demo_counsellor')->exists()) {
            return;
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

        // GHS 100 paid during the period (no fee, GHS 10 waived), and GHS 50 paid after it (the usual 10%).
        $payments = [
            ['at' => $ended->starts_at->copy()->addDays(3), 'gross' => 10000, 'fee' => 0, 'waived' => 1000, 'waiver' => $ended->id],
            ['at' => $ended->ends_at->copy()->addDays(2), 'gross' => 5000, 'fee' => 500, 'waived' => 0, 'waiver' => null],
        ];

        foreach ($payments as $index => $payment) {
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

    // A pay-per-use charge to the demo organisation's card during the period: USD 50 to the counsellor, no fee on
    // top (USD 10 waived) -- the "Org Pay-Per-Use Demo Therapy" charged a second time.
    private function organizationCharge(PlatformFeeWaiver $ended): void
    {
        $organization = Organization::query()->where('name', 'Org Demo Wellness Collective')->first();
        $therapy = Therapy::query()->where('name', 'Org Pay-Per-Use Demo Therapy')->first();

        if (! $organization || ! $therapy?->counsellor_id) {
            return;
        }

        $at = $ended->starts_at->copy()->addDays(5);
        $transaction = new Transaction([
            'for_type' => Therapy::class,
            'for_id' => $therapy->id,
            'user_id' => $therapy->addedby_id,
            'organization_id' => $organization->id,
            'reference' => ChargeOrganizationForModelAction::REFERENCE_PREFIX.Str::uuid(),
            'amount' => 5000,
            'currency' => 'USD',
            'status' => TransactionStatusEnum::success->value,
            'breakdown' => ['lines' => [[
                'counsellor_id' => $therapy->counsellor_id,
                'net_amount' => 5000,
                'fee_amount' => 0,
                'platform_fee_waiver_id' => $ended->id,
                'waived_fee_amount' => 1000,
            ]]],
        ]);
        $transaction->created_at = $at;
        $transaction->save();

        $earning = new CounsellorEarning([
            'transaction_id' => $transaction->id,
            'counsellor_id' => $therapy->counsellor_id,
            'gross_amount' => 5000,
            'fee_amount' => 0,
            'net_amount' => 5000,
            'currency' => 'USD',
            'status' => CounsellorEarningStatusEnum::pending->value,
            'platform_fee_waiver_id' => $ended->id,
            'waived_fee_amount' => 1000,
        ]);
        $earning->created_at = $at;
        $earning->save();
    }
}
