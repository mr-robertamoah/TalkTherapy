<?php

use App\Actions\Request\CreateRequestAction;
use App\DTOs\CreateRequestDTO;
use App\Enums\RequestTypeEnum;
use App\Models\Administrator;
use App\Models\Counsellor;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;

// TT-7.7c/SCRUM-251: platform-admin-only review queue -- mirrors
// AdminOrganizationBillingController's own coverage shape.

function aPendingRefundRequestForAdminQueue(): array
{
    $client = User::factory()->create();
    $therapy = Therapy::factory()->create(['name' => 'Admin Queue Test Therapy', 'addedby_type' => User::class, 'addedby_id' => $client->id]);
    $transaction = Transaction::factory()->create([
        'for_type' => Therapy::class,
        'for_id' => $therapy->id,
        'user_id' => $client->id,
        'status' => 'SUCCESS',
        'amount' => 5000,
        'currency' => 'GHS',
    ]);

    $request = CreateRequestAction::new()->execute(
        CreateRequestDTO::new()->fromArray([
            'from' => $client,
            'to' => null,
            'for' => $transaction,
            'type' => RequestTypeEnum::refund->value,
            'data' => ['reason' => 'The counsellor never joined the session.'],
        ])
    );

    return [$request, $transaction, $client];
}

test('a platform admin can load the refund-requests page', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    [$request, $transaction] = aPendingRefundRequestForAdminQueue();

    $this->actingAs($admin);

    $response = $this->get(route('administrator.refund_requests'));

    $response->assertOk()->assertInertia(fn ($page) => $page
        ->component('Admin/RefundRequests')
        ->has('refundRequests.data', 1)
        ->where('refundRequests.data.0.id', $request->id)
        ->where('refundRequests.data.0.reason', 'The counsellor never joined the session.')
        ->where('refundRequests.data.0.transaction.id', $transaction->id)
        ->where('refundRequests.data.0.transaction.subjectName', 'Admin Queue Test Therapy')
    );
});

test('a non-admin is redirected home rather than seeing the refund-requests page', function () {
    $outsider = User::factory()->create();

    $this->actingAs($outsider);

    $response = $this->get(route('administrator.refund_requests'));

    $response->assertRedirect(route('home'));
});

test('an already-resolved refund request does not appear in the queue', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    [$request] = aPendingRefundRequestForAdminQueue();
    $request->update(['status' => 'ACCEPTED']);

    $this->actingAs($admin);

    $response = $this->get(route('administrator.refund_requests'));

    $response->assertInertia(fn ($page) => $page->has('refundRequests.data', 0));
});

test('a non-refund pending request does not leak into the refund queue', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    aPendingRefundRequestForAdminQueue();
    // A completely unrelated pending request of a different type must never leak in here.
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory()]);
    Request::query()->create([
        'from_type' => Counsellor::class,
        'from_id' => $counsellor->id,
        'for_type' => Counsellor::class,
        'for_id' => $counsellor->id,
        'type' => RequestTypeEnum::counsellor->value,
        'status' => 'PENDING',
        'data' => [],
    ]);

    $this->actingAs($admin);

    $response = $this->get(route('administrator.refund_requests'));

    $response->assertInertia(fn ($page) => $page->has('refundRequests.data', 1));
});

test('a guest is redirected to login', function () {
    $response = $this->get(route('administrator.refund_requests'));

    $response->assertRedirect(route('login'));
});

test('an admin can approve a pending refund request via the shared respond endpoint', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    [$request] = aPendingRefundRequestForAdminQueue();

    $this->actingAs($admin);

    $response = $this->postJson(route('requests.respond', ['requestId' => $request->id]), ['response' => 'accepted']);

    $response->assertStatus(201);
    expect($request->fresh()->status)->toBe('ACCEPTED');
});

test('an admin rejecting without a reason via the shared respond endpoint gets a clean error', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    [$request] = aPendingRefundRequestForAdminQueue();

    $this->actingAs($admin);

    $response = $this->postJson(route('requests.respond', ['requestId' => $request->id]));

    $response->assertStatus(422);
    expect($request->fresh()->status)->toBe('PENDING');
});

test('an admin can reject a pending refund request with a reason via the shared respond endpoint', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    [$request] = aPendingRefundRequestForAdminQueue();

    $this->actingAs($admin);

    $response = $this->postJson(route('requests.respond', ['requestId' => $request->id]), [
        'response' => 'rejected',
        'reason' => 'This session was completed as scheduled; no refund is warranted.',
    ]);

    $response->assertStatus(201);
    expect($request->fresh()->status)->toBe('REJECTED');
    expect($request->fresh()->data['rejectionReason'])->toBe('This session was completed as scheduled; no refund is warranted.');
});
