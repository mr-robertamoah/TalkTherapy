<?php

use App\Models\Administrator;
use App\Models\Testimonial;
use App\Models\User;

// SCRUM-126: `markTestimonial` used `(bool) $request->use` on a plain Request with no
// 'boolean' validation rule at all, so a string "false" silently flipped to PHP true -- a live
// bug, not just a defense-in-depth cleanup.

test('marking a testimonial with a string "false" use value correctly persists it as false', function () {
    $admin = User::factory()->has(Administrator::factory())->create();
    $testimonial = Testimonial::create(['content' => 'Great platform!', 'use' => true]);

    $response = $this
        ->actingAs($admin)
        ->postJson(route('api.testimonials.mark', ['testimonialId' => $testimonial->id]), [
            'use' => 'false',
        ]);

    $response->assertOk();
    expect((bool) $testimonial->refresh()->use)->toBeFalse();
});
