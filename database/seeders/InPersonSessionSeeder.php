<?php

namespace Database\Seeders;

use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\Seeder;

// SCRUM-443: in-person sessions with a place, among adults only. Passwords: "password".
// - "In-Person Demo Therapy": client `john_davis`, counsellor `michael_chen` (Dr. Michael Chen), allows in-person, with
//   one upcoming IN_PERSON session in Accra (a pin and a landmark). Either of them can also propose an in-person time.
// - "In-Person Demo Group": created by Dr. Michael Chen, members `john_davis` and `robert_miller`, allows in-person, with
//   one upcoming IN_PERSON session.
// After DatabaseSeeder has given the demo adults a date of birth; skipped once the therapy exists.
class InPersonSessionSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $client = User::query()->where('username', 'john_davis')->first();
        $member = User::query()->where('username', 'robert_miller')->first();
        $counsellor = User::query()->where('username', 'michael_chen')->first()?->counsellor;

        if (! $client?->isAdult() || ! $member?->isAdult() || ! $counsellor || Therapy::query()->where('name', 'In-Person Demo Therapy')->exists()) {
            return;
        }

        $therapy = $client->addedTherapies()->create([
            'name' => 'In-Person Demo Therapy',
            'background_story' => 'Seeded adult therapy that allows in-person sessions, for trying an in-person session and proposal with a place (SCRUM-443).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'ONCE',
            'payment_type' => 'FREE',
            'allow_in_person' => true,
            'anonymous' => false,
            'public' => false,
            'status' => TherapyStatusEnum::in_session->value,
        ]);

        $this->inPersonSession($counsellor, $therapy, 'In-Person Demo Session', 'Accra Mall, 2nd floor, Room 3 (opposite the bank)', 5.6221, -0.1735);

        $group = $counsellor->addedGroupTherapies()->create([
            'name' => 'In-Person Demo Group',
            'about' => 'Seeded adults-only group that meets in person (SCRUM-443).',
            'session_type' => 'ONCE',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => false,
            'allow_in_person' => true,
            'anonymous' => false,
            'public' => false,
            'status' => TherapyStatusEnum::in_session->value,
        ]);
        $group->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE', 'role' => 'NORMAL']);

        foreach ([$client, $member] as $adult) {
            $group->users()->attach($adult->id, ['anonymous' => false, 'background_story' => 'Seeded member.', 'was_minor_at_join' => false]);
        }

        // QA: a different day from the 1:1 session -- the same counsellor and client, so overlapping times blocked edits.
        $this->inPersonSession($counsellor, $group, 'In-Person Demo Group Session', 'Osu Community Centre, main hall (behind the clinic)', 5.5560, -0.1820, daysAhead: 4);
    }

    private function inPersonSession(Counsellor $counsellor, Therapy|GroupTherapy $for, string $name, string $landmark, float $latitude, float $longitude, int $daysAhead = 3): void
    {
        $session = $counsellor->addedSessions()->make([
            'name' => $name,
            'about' => 'Seeded in-person session with a place (SCRUM-443).',
            'type' => SessionTypeEnum::in_person->value,
            'status' => SessionStatusEnum::pending->value,
            'payment_type' => 'FREE',
            'landmark' => $landmark,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'start_time' => now()->addDays($daysAhead)->setTime(10, 0)->utc(),
            'end_time' => now()->addDays($daysAhead)->setTime(11, 0)->utc(),
        ]);
        $session->for()->associate($for);
        $session->save();
    }
}
