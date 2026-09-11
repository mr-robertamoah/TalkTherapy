<?php

namespace Database\Factories;

use App\Models\Therapy;
use App\Models\User;
use App\Models\VideoConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoConsent>
 */
class VideoConsentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ward_id' => User::factory(),
            'guardian_id' => User::factory(),
            'consentable_type' => Therapy::class,
            'consentable_id' => Therapy::factory(),
            'granted_at' => now(),
        ];
    }

    // Review finding (2026-09-11): a `state()` closure only sees attributes accumulated from
    // definition() + prior chained states, never attributes passed to the eventual create() call
    // -- `$attributes['guardian_id'] ?? User::factory()` here would silently resolve against this
    // factory's OWN definition()-generated guardian_id, not a guardian_id overridden at the call
    // site (e.g. `VideoConsent::factory()->revoked()->create(['guardian_id' => $x->id])` would
    // still get an unrelated, wrong revoker). afterCreating() reads the model's actual persisted
    // guardian_id instead, after all overrides have already been applied.
    // Review finding (2026-09-11): a `state()` closure only sees attributes accumulated from
    // definition() + prior chained states, never attributes passed to the eventual create() call
    // -- `$attributes['guardian_id'] ?? User::factory()` here would silently resolve against this
    // factory's OWN definition()-generated guardian_id, not a guardian_id overridden at the call
    // site (e.g. `VideoConsent::factory()->revoked()->create(['guardian_id' => $x->id])` would
    // still get an unrelated, wrong revoker). afterCreating() reads the model's actual persisted
    // guardian_id instead, after all overrides have already been applied.
    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()])
            ->afterCreating(fn (VideoConsent $consent) => $consent->update([
                'revoked_by_guardian_id' => $consent->guardian_id,
            ]));
    }
}
