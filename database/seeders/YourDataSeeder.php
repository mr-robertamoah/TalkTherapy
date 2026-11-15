<?php

namespace Database\Seeders;

use App\Enums\ConstantsEnum;
use App\Enums\CounsellorPayoutStatusEnum;
use App\Enums\LicensingAuthorityTypeEnum;
use App\Enums\LicensingTypeEnum;
use App\Enums\MessageStatusEnum;
use App\Enums\MessageTypeEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Enums\SessionStatusEnum;
use App\Enums\SessionTypeEnum;
use App\Enums\TherapyPaymentTypeEnum;
use App\Enums\TherapySessionTypeEnum;
use App\Enums\TherapyStatusEnum;
use App\Enums\TransactionStatusEnum;
use App\Models\AdminContentAccess;
use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\File;
use App\Models\GroupTherapy;
use App\Models\Guardianship;
use App\Models\License;
use App\Models\LicensingAuthority;
use App\Models\Message;
use App\Models\Profession;
use App\Models\Request;
use App\Models\Session;
use App\Models\Therapy;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\SessionCreatedNotification;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// SCRUM-50 (TT-8.2): "What we hold about you" (/profile/your-data) with something in every category. Passwords:
// "password".
// - your_data_demo: an adult client with a named therapy (a session, messages, a payment), an ended anonymous therapy,
//   a new email waiting for confirmation (masked), a deleted therapy (still held, counted), an urgent help chat (never counted or listed), a group (joined anonymously), a ward, an accepted age check whose document is waiting to be
//   deleted, notifications, and administrators' opens of their therapy, session and a message -- plus opens that
//   must NOT be listed (their group's page, their help chat, an urgent-help identity reveal).
// - your_data_demo_ward: their ward (15).
// - your_data_demo_counsellor: the counsellor of both therapies -- verified, with a professional licence (kept) and a
//   national ID (deleted on a date shown), a payout, and an administrator's open of the licence.
// The seeded document rows have no stored file behind them (opening one finds nothing). Safe to run again: skipped
// once your_data_demo exists.
class YourDataSeeder extends Seeder
{
    use RefusesOutsideLocal;

    public function run(): void
    {
        // SCRUM-429
        if ($this->refusedHere() || User::query()->where('username', 'your_data_demo')->exists()) {
            return;
        }

        DB::transaction(function () {
            $admin = User::query()->whereSuperAdmin()->first();
            $client = $this->person('your_data_demo', 'YourData', 'Demo', ['dob' => now()->subYears(32)->toDateString(), 'gender' => 'FEMALE', 'country' => 'Ghana']);
            $ward = $this->person('your_data_demo_ward', 'YourData', 'Ward', ['dob' => now()->subYears(15)->toDateString()]);
            Guardianship::query()->create(['guardian_id' => $client->id, 'ward_id' => $ward->id, 'ward_was_minor_at_creation' => true]);
            // A new email waiting for its owner to confirm it (shown masked on the page -- SCRUM-50 QA).
            $client->forceFill(['pending_email' => 'your.data.new@example.com', 'pending_email_requested_at' => now()])->save();

            $counsellor = $this->counsellor();
            [$therapy, $session, $message] = $this->therapies($client, $counsellor);
            $group = $this->group($client, $counsellor);
            $helpChat = $this->helpChat($client, $counsellor);

            Transaction::query()->create([
                'for_type' => Therapy::class, 'for_id' => $therapy->id, 'user_id' => $client->id,
                'reference' => 'your-data-demo-'.Str::lower(Str::random(10)), 'amount' => 15000, 'currency' => 'GHS',
                'status' => TransactionStatusEnum::success->value,
            ]);
            CounsellorPayout::query()->create([
                'counsellor_id' => $counsellor->id, 'initiated_by_id' => $counsellor->user_id,
                'reference' => 'payout_your_data_demo_'.Str::lower(Str::random(10)), 'amount' => 12000, 'currency' => 'GHS',
                'status' => CounsellorPayoutStatusEnum::succeeded->value,
            ]);

            $this->ageCheck($client);
            $licence = $this->licences($counsellor, $admin);
            $this->notifications($client, $therapy);

            if ($admin) {
                $opens = [
                    [AdminContentAccess::THERAPY, Therapy::class, $therapy->id, 50],
                    [AdminContentAccess::SESSION_MESSAGES, Session::class, $session->id, 30],
                    [AdminContentAccess::MESSAGE, Message::class, $message->id, 10],
                    [AdminContentAccess::LICENSE_DOCUMENT, License::class, $licence->id, 5],
                    // Never listed on the page:
                    [AdminContentAccess::THERAPY, GroupTherapy::class, $group->id, 40],
                    [AdminContentAccess::THERAPY, Therapy::class, $helpChat->id, 20],
                    [AdminContentAccess::HELP_REQUEST_IDENTITY, Therapy::class, $helpChat->id, 20],
                ];
                foreach ($opens as [$resource, $type, $id, $hoursAgo]) {
                    AdminContentAccess::create([
                        'admin_user_id' => $admin->id, 'resource' => $resource, 'subject_type' => $type, 'subject_id' => $id,
                        'ip' => '127.0.0.1', 'user_agent' => 'Seeded (SCRUM-50)', 'created_at' => now()->subHours($hoursAgo),
                    ]);
                }
            }
        });
    }

    private function person(string $username, string $first, string $last, array $attributes = []): User
    {
        return User::factory()->create([
            'firstName' => $first, 'lastName' => $last, 'username' => $username,
            'email' => str_replace('_', '.', $username).'@example.com',
            'password' => Hash::make('password'), 'email_verified_at' => now(), ...$attributes,
        ]);
    }

    private function counsellor(): Counsellor
    {
        $user = $this->person('your_data_demo_counsellor', 'YourData', 'Counsellor', ['dob' => now()->subYears(45)->toDateString()]);

        $counsellor = $user->counsellor()->create([
            'name' => 'Dr. YourData Counsellor',
            'about' => 'Seeded counsellor for "What we hold about you" (SCRUM-50).',
            'email' => $user->email,
            'phone' => fake()->phoneNumber(),
            'email_verified_at' => now(),
            // A real one (or none), so this seeder also runs alone on an existing database.
            'profession_id' => Profession::query()->orderBy('id')->value('id'),
            'contact_visible' => true,
        ]);
        $counsellor->forceFill(['verified_at' => now()->subMonths(2), 'verification_expires_at' => now()->addMonths(10)])->save();

        return $counsellor;
    }

    // [the named therapy, its session, one of the client's messages in it]
    private function therapies(User $client, Counsellor $counsellor): array
    {
        $therapy = $this->therapy($client, $counsellor, 'Your Data Demo Therapy', ['status' => TherapyStatusEnum::in_session->value]);
        $session = $this->session($therapy, $counsellor, SessionStatusEnum::held->value);
        $message = $this->message($client, $session, 'Thank you for today.');
        $this->message($client, $session, 'See you next week.');
        $this->message($counsellor, $session, 'Take care until then.');

        $this->therapy($client, $counsellor, 'Your Data Demo Anonymous Therapy', ['status' => TherapyStatusEnum::ended->value, 'anonymous' => true]);
        $this->therapy($client, $counsellor, 'Your Data Demo Deleted Therapy', ['status' => TherapyStatusEnum::ended->value])->delete();

        return [$therapy, $session, $message];
    }

    private function helpChat(User $client, Counsellor $counsellor): Therapy
    {
        $chat = $this->therapy($client, $counsellor, 'Your Data Demo Help Chat', ['status' => TherapyStatusEnum::ended->value]);
        $chat->forceFill(['is_help_chat' => true])->save();
        $this->message($client, $this->session($chat, $counsellor, SessionStatusEnum::held->value), 'I needed to talk to someone.');

        return $chat;
    }

    private function group(User $client, Counsellor $counsellor): GroupTherapy
    {
        $group = new GroupTherapy([
            'name' => 'Your Data Demo Group', 'about' => 'Seeded group for "What we hold about you" (SCRUM-50).',
            'session_type' => TherapySessionTypeEnum::once->value, 'payment_type' => TherapyPaymentTypeEnum::free->value,
            'status' => TherapyStatusEnum::in_session->value, 'public' => false, 'anonymous' => false,
            'allow_anyone' => false, 'allow_in_person' => false, 'max_users' => 10,
        ]);
        $group->addedby()->associate($counsellor->user);
        $group->save();
        DB::table('group_therapy_user')->insert(['group_therapy_id' => $group->id, 'user_id' => $client->id, 'anonymous' => true, 'created_at' => now()->subWeek(), 'updated_at' => now()->subWeek()]);
        $this->message($client, $this->session($group, $counsellor, SessionStatusEnum::held->value), 'Hello, everyone.');

        return $group;
    }

    private function therapy(User $client, Counsellor $counsellor, string $name, array $attributes): Therapy
    {
        return $client->addedTherapies()->create([
            'name' => $name, 'background_story' => 'Seeded for "What we hold about you" (SCRUM-50).',
            'counsellor_id' => $counsellor->id, 'session_type' => TherapySessionTypeEnum::once->value,
            'payment_type' => TherapyPaymentTypeEnum::free->value, 'allow_in_person' => false,
            'anonymous' => false, 'public' => false, ...$attributes,
        ]);
    }

    private function session(Therapy|GroupTherapy $for, Counsellor $by, string $status): Session
    {
        $session = $by->addedSessions()->make([
            'name' => "{$for->name} Session", 'about' => 'Seeded (SCRUM-50).',
            'start_time' => now()->subDays(2), 'end_time' => now()->subDays(2)->addHour(),
            'type' => SessionTypeEnum::online->value, 'status' => $status, 'payment_type' => TherapyPaymentTypeEnum::free->value,
        ]);
        $session->for()->associate($for);
        $session->save();

        return $session;
    }

    private function message(User|Counsellor $from, Session $session, string $content): Message
    {
        $message = new Message(['content' => $content, 'type' => MessageTypeEnum::normal->value, 'status' => MessageStatusEnum::seen->value]);
        $message->from()->associate($from);
        $message->for()->associate($session);
        $message->save();

        return $message;
    }

    // Accepted five days ago: its document is deleted identity_verification.document_retention_days after that.
    private function ageCheck(User $client): void
    {
        $request = new Request([
            'type' => RequestTypeEnum::ageVerification->value,
            'status' => RequestStatusEnum::accepted->value,
            'data' => ['attestation' => 'I confirm that the date of birth on my account is accurate.', 'attestedDob' => $client->dob->toDateString()],
        ]);
        $request->from()->associate($client);
        $request->for()->associate($client);
        $request->save();
        Request::query()->whereKey($request->id)->update(['created_at' => now()->subDays(6), 'responded_at' => now()->subDays(5)]);

        $request->files()->attach($this->file('your-data-demo-id.jpg', 'identity_documents')->id, ['tag' => 'front-id']);
    }

    // Both cited by a verification accepted three days ago: the professional licence is kept; the national ID's scan
    // goes counsellor.national_id_retention_days after that. Returns the professional licence.
    private function licences(Counsellor $counsellor, ?User $admin): License
    {
        $licence = function (string $authorityName, string $number) use ($counsellor) {
            $authority = LicensingAuthority::query()->where('name', $authorityName)->first()
                ?? LicensingAuthority::factory()->create(['name' => $authorityName, 'type' => LicensingAuthorityTypeEnum::govermental->value, 'license_type' => LicensingTypeEnum::number->value]);
            $license = new License(['number' => $number, 'validated' => true]);
            $license->licensingAuthority()->associate($authority);
            $license->addedby()->associate($counsellor);
            $license->for()->associate($counsellor);
            $license->save();
            $license->files()->attach($this->file("{$number}.jpg", 'license_documents')->id);

            return $license;
        };

        $nationalId = $licence(ConstantsEnum::nationalId->value, 'GHA-000555666-7');
        $professional = $licence('Your Data Demo Counselling Board', 'YDB-2026-0050');

        $request = new Request([
            'type' => RequestTypeEnum::counsellor->value,
            'status' => RequestStatusEnum::accepted->value,
            'data' => ['nationalIdLicense' => $nationalId->id, 'otherLicense' => $professional->id],
        ]);
        $request->from()->associate($counsellor);
        $request->for()->associate($counsellor);
        if ($admin) {
            $request->to()->associate($admin);
        }
        $request->save();
        Request::query()->whereKey($request->id)->update(['responded_at' => now()->subDays(3)]);

        return $professional;
    }

    private function file(string $name, string $disk): File
    {
        return File::query()->create(['name' => $name, 'mime' => 'image/jpeg', 'size' => 1024, 'path' => '', 'storage' => $disk]);
    }

    private function notifications(User $client, Therapy $therapy): void
    {
        foreach ([10, 600, 2880] as $minutesAgo) {
            $client->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => SessionCreatedNotification::class,
                'data' => ['session_id' => 0, 'therapyId' => $therapy->id],
                'read_at' => $minutesAgo > 60 ? now()->subMinutes($minutesAgo - 5) : null,
                'created_at' => now()->subMinutes($minutesAgo),
                'updated_at' => now()->subMinutes($minutesAgo),
            ]);
        }
    }
}
