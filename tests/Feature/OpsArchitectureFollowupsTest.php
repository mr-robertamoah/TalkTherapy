<?php

use App\Actions\Transaction\InitiatePaystackChargeAction;
use App\Actions\Transaction\ReconcileStalePendingOrganizationChargesAction;
use App\DTOs\TransactionDTO;
use App\Enums\TransactionStatusEnum;
use App\Exceptions\TherapyException;
use App\Exceptions\TransactionException;
use App\Models\Administrator;
use App\Models\Organization;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Support\SchedulerHeartbeat;
use App\Support\SchedulerOnce;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

// SCRUM-373 (scheduler store, heartbeat), 374 (JSON rule, AppException rendering), 272 (Paystack
// fails fast), 248 (stale org-charge reconciliation), 193 (admin user avatars).

function oafStalePendingOrgCharge(array $attributes = []): Transaction
{
    $therapy = Therapy::factory()->create(['addedby_type' => User::class, 'addedby_id' => User::factory()->adult()->create()->id]);

    return Transaction::factory()->create(array_merge([
        'for_type' => Therapy::class, 'for_id' => $therapy->id,
        'organization_id' => Organization::factory()->create()->id,
        'amount' => 5000, 'currency' => 'GHS',
        'reference' => 'org_charge_'.Str::uuid(),
        'status' => TransactionStatusEnum::pending->value,
        'created_at' => now()->subHour(),
    ], $attributes));
}

// --- SCRUM-248 -------------------------------------------------------------------------------

test('a stale pending org charge is resolved from Paystack; a recent one is left alone', function () {
    $stale = oafStalePendingOrgCharge();
    $recent = oafStalePendingOrgCharge(['created_at' => now()->subMinutes(5)]);
    $personal = oafStalePendingOrgCharge(['organization_id' => null]);
    Http::fake(['*/transaction/verify/*' => Http::response(['status' => true, 'data' => [
        'status' => 'success', 'amount' => 5000, 'currency' => 'GHS', 'gateway_response' => 'Approved',
    ]])]);

    $counts = ReconcileStalePendingOrganizationChargesAction::new()->execute();

    expect($stale->fresh()->status)->toBe(TransactionStatusEnum::success->value)
        ->and($stale->statusHistories()->latest('id')->first()->source)->toBe('RECONCILE')
        ->and($recent->fresh()->status)->toBe(TransactionStatusEnum::pending->value)
        ->and($personal->fresh()->status)->toBe(TransactionStatusEnum::pending->value)
        ->and($counts['resolved'])->toBe(1);
    Http::assertSentCount(1);
});

test('a charge Paystack can\'t answer for stays pending, and one bad row never stops the rest', function () {
    $first = oafStalePendingOrgCharge();
    $second = oafStalePendingOrgCharge();
    Http::fake(function ($request) use ($first) {
        if (str_contains($request->url(), $first->reference)) {
            throw new ConnectionException('unreachable');
        }

        return Http::response(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Declined']]);
    });

    $counts = ReconcileStalePendingOrganizationChargesAction::new()->execute();

    expect($first->fresh()->status)->toBe(TransactionStatusEnum::pending->value)
        ->and($second->fresh()->status)->toBe(TransactionStatusEnum::failed->value)
        ->and($counts)->toBe(['resolved' => 1, 'stillPending' => 0, 'failed' => 1]);
});

// --- SCRUM-272 -------------------------------------------------------------------------------

test('an unreachable Paystack fails the payment start with the "try again" message, not a generic error', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));
    $client = User::factory()->adult()->create();
    $therapy = Therapy::factory()->create([
        'addedby_type' => User::class, 'addedby_id' => $client->id, 'payment_type' => 'PAID',
        'payment_data' => ['per' => 'PER_THERAPY', 'amount' => 50, 'currency' => 'GHS'],
    ]);

    expect(fn () => InitiatePaystackChargeAction::new()->execute(TransactionDTO::new()->fromArray([
        'user' => $client, 'for' => $therapy, 'callbackUrl' => 'https://example.test/callback',
    ])))->toThrow(TransactionException::class, 'Unable to start the payment right now. Please try again shortly.');
});

test('Paystack calls have short timeouts', function () {
    expect(config('services.paystack.connect_timeout'))->toBeLessThanOrEqual(10)
        ->and(config('services.paystack.timeout'))->toBeLessThanOrEqual(30);
});

// --- SCRUM-373 -------------------------------------------------------------------------------

test('the scheduler\'s once-only keys survive clearing the default cache store', function () {
    config(['cache.stores.oaf_scheduler' => ['driver' => 'array'], 'cache.scheduler_store' => 'oaf_scheduler']);
    $session = Session::factory()->create(['start_time' => now()->addMinutes(20), 'end_time' => now()->addHour()]);

    expect(SchedulerOnce::first('session-due', $session))->toBeTrue();

    Cache::flush(); // what the deploy's cache:clear does, to the default store

    expect(SchedulerOnce::first('session-due', $session))->toBeFalse();
});

test('the scheduler health route reports when the scheduler last ran', function () {
    $this->getJson(route('health.scheduler'))->assertStatus(503)->assertJsonPath('healthy', false);

    SchedulerHeartbeat::beat();
    $this->getJson(route('health.scheduler'))->assertOk()->assertJsonPath('healthy', true);

    $this->travel(SchedulerHeartbeat::STALE_AFTER_MINUTES + 1)->minutes();
    $this->getJson(route('health.scheduler'))->assertStatus(503);
});

// --- SCRUM-374 -------------------------------------------------------------------------------

test('one JSON-or-redirect rule: JSON for scripts, a redirect for Inertia visits and browser navigations', function () {
    $make = function (array $headers) {
        $request = Request::create('/x');
        $request->headers->remove('Accept'); // Request::create() adds a browser-like default
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        return $request->wantsJsonResponse();
    };

    expect($make(['Accept' => 'application/json, text/plain, */*', 'X-Requested-With' => 'XMLHttpRequest']))->toBeTrue()
        ->and($make([]))->toBeTrue()
        ->and($make(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html, application/xhtml+xml']))->toBeFalse()
        ->and($make(['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']))->toBeFalse();
});

test('an AppException thrown outside any controller catch answers like returnFailure', function () {
    Route::middleware('web')->get('/_oaf/refusal', fn () => throw new TherapyException('Not allowed here.', 422));

    $this->getJson('/_oaf/refusal')->assertStatus(422)->assertJsonPath('message', 'Not allowed here.');

    $this->from('/home')->withHeader('Accept', 'text/html,application/xhtml+xml,*/*;q=0.8')
        ->get('/_oaf/refusal')
        ->assertRedirect('/home')
        ->assertSessionHasErrors(['alert' => 'Not allowed here.'])
        ->assertSessionHas('failureAlert', 'Not allowed here.');
});

// --- SCRUM-193 -------------------------------------------------------------------------------

test('the admin users list includes each user\'s avatar', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    User::factory()->adult()->create();

    $first = $this->actingAs($admin)->getJson(route('admin.users'))->assertOk()->json('data.0');

    expect($first)->toHaveKey('avatar');
});

test('the sweep fails an old org charge Paystack never received, and ignores a member\'s own card checkout', function () {
    $neverReceived = oafStalePendingOrgCharge();
    $memberCheckout = oafStalePendingOrgCharge(['reference' => 'hosted_checkout_ref']);
    Http::fake(['*/transaction/verify/*' => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400)]);

    ReconcileStalePendingOrganizationChargesAction::new()->execute();

    expect($neverReceived->fresh()->status)->toBe(TransactionStatusEnum::failed->value)
        ->and($memberCheckout->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    Http::assertSentCount(1);
});

test('a charge still unresolved after the alert period is logged for manual follow-up', function () {
    Log::spy();
    $old = oafStalePendingOrgCharge(['created_at' => now()->subHours(config('organization.stale_charge_alert_after_hours') + 1)]);
    Http::fake(['*/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'ongoing']])]);

    ReconcileStalePendingOrganizationChargesAction::new()->execute();

    expect($old->fresh()->status)->toBe(TransactionStatusEnum::pending->value);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context) => str_contains($message, 'follow up by hand') && $context['transactionId'] === $old->id);
});
