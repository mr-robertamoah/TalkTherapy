<?php

namespace Database\Seeders;

use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Models\Counsellor;
use App\Models\GroupTherapy;
use App\Models\Session;
use App\Models\SessionAttendance;
use App\Models\Therapy;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// SCRUM-446: in-person sessions whose attendance window is open, on InPersonSessionSeeder's demo therapy and group.
// Passwords: "password".
// - "In-Person Attendance Demo Session" in "In-Person Demo Therapy" (counsellor `michael_chen`, client `john_davis`):
//   PENDING, ended an hour before seeding -- mark it, or mark the client absent and close it as a no-show.
// - "In-Person Attendance Demo Group Session" in "In-Person Demo Group": HELD, ended an hour before seeding. `amy_taylor`
//   joins the group anonymously, so the register masks her ("Client (Anonymous User)") next to `john_davis` and `robert_miller`.
// The window closes 48 hours after the end: running this again moves both sessions to an hour ago, puts their status
// back, clears their marks and the absent notice's hourly limit for the demo users. Skipped if an account, the therapy
// or the group is missing.
class InPersonAttendanceSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public const SESSION = 'In-Person Attendance Demo Session';

    public const GROUP_SESSION = 'In-Person Attendance Demo Group Session';

    public const DEMO_USERNAMES = ['john_davis', 'robert_miller', 'amy_taylor'];

    public function run(): void
    {
        if ($this->refusedHere()) {
            return;
        }

        $counsellor = User::query()->where('username', 'michael_chen')->first()?->counsellor;
        $demoUsers = User::query()->whereIn('username', self::DEMO_USERNAMES)->get()->keyBy('username');
        $anonymousMember = $demoUsers->get('amy_taylor');
        $therapy = Therapy::query()->where('name', 'In-Person Demo Therapy')->first();
        $group = GroupTherapy::query()->where('name', 'In-Person Demo Group')->first();

        if (! $counsellor || $demoUsers->count() !== count(self::DEMO_USERNAMES) || ! $anonymousMember->isAdult() || ! $therapy || ! $group) {
            return;
        }

        $end = now()->subHour();
        $start = $end->copy()->subHour();

        $sessions[] = $this->session($counsellor, $therapy, self::SESSION, SessionStatusEnum::pending, $start, $end,
            'Accra Mall, 2nd floor, Room 3 (opposite the bank)', 5.6221, -0.1735);

        // amy_taylor is an anonymous member during the session -- also if she left the group since an earlier run.
        if ($group->hasEverBeenMember($anonymousMember)) {
            $group->allMemberships()->updateExistingPivot($anonymousMember->id, ['anonymous' => true, 'left_at' => null]);
        } else {
            $group->allMemberships()->attach($anonymousMember->id, ['anonymous' => true, 'background_story' => 'Seeded anonymous member (SCRUM-446).', 'was_minor_at_join' => false]);
        }

        // The register lists who was a member at the time of the session: the demo members' memberships seeded just now
        // are dated before it (nobody else's is touched).
        DB::table('group_therapy_user')->where('group_therapy_id', $group->id)->whereIn('user_id', $demoUsers->pluck('id'))
            ->where('created_at', '>', $start)
            ->update(['created_at' => $start->copy()->subDays(7)]);

        $sessions[] = $this->session($counsellor, $group, self::GROUP_SESSION, SessionStatusEnum::held, $start, $end,
            'Osu Community Centre, main hall (behind the clinic)', 5.5560, -0.1820);

        // The absent notice goes at most once an hour per person and session (SessionAttendanceService): a re-seed lets
        // it be sent again.
        foreach ($sessions as $session) {
            foreach ($demoUsers as $user) {
                Cache::forget("absent-notice:{$user->id}:{$session->id}");
            }
        }
    }

    // Created once; on a later run moved back to an hour ago, its status put back and its marks cleared.
    private function session(Counsellor $counsellor, Therapy|GroupTherapy $for, string $name, SessionStatusEnum $status, CarbonInterface $start, CarbonInterface $end, string $landmark, float $latitude, float $longitude): Session
    {
        $session = Session::query()->whereMorphedTo('for', $for)->where('name', $name)->first()
            ?? $counsellor->addedSessions()->make([
                'name' => $name,
                'about' => 'Seeded in-person session that has just ended, for marking attendance (SCRUM-446).',
                'type' => SessionTypeEnum::in_person->value,
                'payment_type' => 'FREE',
            ]);

        $session->for()->associate($for);
        $session->forceFill([
            'status' => $status->value,
            'landmark' => $landmark,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'start_time' => $start->copy()->utc(),
            'end_time' => $end->copy()->utc(),
            'ended_at' => $status === SessionStatusEnum::held ? $end->copy()->utc() : null,
            'updatedby_type' => null,
            'updatedby_id' => null,
            'updatedby_anonymous' => false,
        ])->save();

        SessionAttendance::query()->where('session_id', $session->id)->delete();

        return $session;
    }
}
