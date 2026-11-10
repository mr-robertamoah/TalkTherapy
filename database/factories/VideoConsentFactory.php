<?php

namespace Database\Factories;

use App\Models\Guardianship;
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
            // SCRUM-288: a grant only counts while its guardian does (an adult, still their guardian).
            'guardian_id' => User::factory()->adult(),
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
    // SCRUM-288: a grant is made by one of the ward's guardians, and only counts while that
    // guardianship does (HasValidVideoConsentForSessionAction) -- so one is recorded when missing.
    public function configure(): static
    {
        return $this->afterCreating(fn (VideoConsent $consent) => Guardianship::query()->firstOrCreate([
            'guardian_id' => $consent->guardian_id,
            'ward_id' => $consent->ward_id,
        ]));
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()])
            ->afterCreating(fn (VideoConsent $consent) => $consent->update([
                'revoked_by_guardian_id' => $consent->guardian_id,
            ]));
    }
}
