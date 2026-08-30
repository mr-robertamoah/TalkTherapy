<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Enums\AdministratorTypeEnum;
use App\Enums\GenderEnum;
use App\Enums\LicensingTypeEnum;
use App\Enums\OrganizationAdminRoleEnum;
use App\Enums\OrganizationCounsellorCompensationTypeEnum;
use App\Enums\OrganizationCounsellorStatusEnum;
use App\Enums\OrganizationMemberBillingModeEnum;
use App\Enums\OrganizationMemberStatusEnum;
use App\Enums\RequestStatusEnum;
use App\Enums\RequestTypeEnum;
use App\Models\Organization;
use App\Models\Request;
use App\Models\Therapy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create super admin user
        $superAdmin = User::factory()->create([
            'username' => 'mr_robertamoah',
            'firstName' => 'Robert',
            'lastName' => 'Amoah',
            'email' => 'mr_robertamoah@yahoo.com',
            'password' => Hash::make(env('SUPER_PASSWORD', 'password')),
            'email_verified_at' => now(),
        ]);

        $superAdmin->administrator()->create([
            'verified_at' => now(),
            'type' => AdministratorTypeEnum::super->value,
        ]);

        // Create basic reference data
        $this->createLanguages($superAdmin);
        $this->createReligions($superAdmin);
        $this->createTherapyCases($superAdmin);
        $this->createProfessions($superAdmin);
        $this->createLicensingAuthorities($superAdmin);

        // Create demo users and counsellors
        $users = $this->createDemoUsers();
        $counsellors = $this->createDemoCounsellors($users);

        // Create demo therapies with sessions
        $this->createDemoTherapies($users, $counsellors);

        // Create demo group therapies
        $this->createDemoGroupTherapies($users, $counsellors);

        // Create demo discussions
        $this->createDemoDiscussions($counsellors);

        // Create deterministic "live" therapy/group therapy/discussion records for testing the
        // dedicated chat pages (SCRUM-20) -- the random data above only *might* produce an
        // in-session record on any given reseed, so these are named and always present.
        $this->createChatDemoData($users, $counsellors);

        // Create demo posts from counsellors
        $this->createDemoPosts($counsellors, $users);

        // SCRUM-134: dedicated accounts for testing counsellor account deletion -- kept separate
        // from the 6 main demo counsellors above since those are woven into therapies/group
        // therapies/discussions/chat demo data used by many other features.
        $this->createCounsellorDeletionDemoData();

        // SCRUM-157/158: dedicated PAID therapies (one PER_THERAPY, one PER_SESSION) for testing
        // the payment UI -- the random demo therapies above only *might* land on PAID, and never
        // deterministically pair the two payment models with a specific client/counsellor.
        $this->createPaymentDemoData();

        // SCRUM-165: a deterministic org-admin account + org exercising every section of the
        // new dashboard (both provider/consumer tables, an already-affiliated pending row, and
        // a pending request-queue item) -- the random demo data above never deterministically
        // produces an org admin to log in as.
        $this->createOrganizationDashboardDemoData();
    }

    private function createLanguages($user)
    {
        $user->addedLanguages()->createMany([
            ['name' => 'English'],
            ['name' => 'French'],
            ['name' => 'Twi'],
            ['name' => 'Ewe'],
            ['name' => 'Ga'],
            ['name' => 'Spanish'],
            ['name' => 'German'],
            ['name' => 'Mandarin'],
            ['name' => 'Arabic'],
            ['name' => 'Portuguese'],
        ]);
    }

    private function createReligions($user)
    {
        $user->addedReligions()->createMany([
            ['name' => 'Christianity'],
            ['name' => 'Islam'],
            ['name' => 'Traditional'],
            ['name' => 'Atheist'],
            ['name' => 'Judaism'],
            ['name' => 'Buddhism'],
            ['name' => 'Hinduism'],
            ['name' => 'Agnostic'],
        ]);
    }

    private function createTherapyCases($user)
    {
        $user->addedTherapyCases()->createMany([
            ['name' => 'Anxiety Disorders', 'description' => 'General anxiety, panic attacks, social anxiety'],
            ['name' => 'Depression', 'description' => 'Major depressive disorder, seasonal depression'],
            ['name' => 'Relationship Issues', 'description' => 'Couple therapy, family conflicts, communication problems'],
            ['name' => 'Trauma and PTSD', 'description' => 'Post-traumatic stress, childhood trauma, abuse recovery'],
            ['name' => 'Addiction Recovery', 'description' => 'Substance abuse, behavioral addictions'],
            ['name' => 'Grief and Loss', 'description' => 'Bereavement, loss of loved ones, life transitions'],
            ['name' => 'Stress Management', 'description' => 'Work stress, life pressures, burnout'],
            ['name' => 'Self-Esteem Issues', 'description' => 'Low confidence, self-worth, body image'],
            ['name' => 'Anger Management', 'description' => 'Anger control, emotional regulation'],
            ['name' => 'Eating Disorders', 'description' => 'Anorexia, bulimia, binge eating'],
            ['name' => 'Sleep Disorders', 'description' => 'Insomnia, sleep anxiety, sleep hygiene'],
            ['name' => 'Academic/Career Counseling', 'description' => 'Study stress, career transitions, performance anxiety'],
        ]);
    }

    private function createProfessions($user)
    {
        $user->addedProfessions()->createMany([
            ['name' => 'Clinical Psychologist'],
            ['name' => 'Licensed Clinical Social Worker'],
            ['name' => 'Marriage and Family Therapist'],
            ['name' => 'Licensed Professional Counselor'],
            ['name' => 'Psychiatrist'],
            ['name' => 'Addiction Counselor'],
            ['name' => 'Trauma Specialist'],
            ['name' => 'Child Psychologist'],
            ['name' => 'Cognitive Behavioral Therapist'],
            ['name' => 'Art Therapist'],
        ]);
    }

    private function createLicensingAuthorities($user)
    {
        $user->addedLicensingAuthorities()->createMany([
            ['name' => 'National Identification Authority', 'license_type' => LicensingTypeEnum::both->value],
            ['name' => 'Ghana Psychology Council', 'license_type' => LicensingTypeEnum::file->value],
            ['name' => 'American Psychological Association', 'license_type' => LicensingTypeEnum::file->value],
            ['name' => 'British Psychological Society', 'license_type' => LicensingTypeEnum::file->value],
            ['name' => 'National Association of Social Workers', 'license_type' => LicensingTypeEnum::file->value],
        ]);
    }

    private function createDemoUsers()
    {
        $users = collect();

        // Create diverse demo users
        $userData = [
            ['firstName' => 'Sarah', 'lastName' => 'Johnson', 'email' => 'sarah.johnson@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'Michael', 'lastName' => 'Chen', 'email' => 'michael.chen@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Emma', 'lastName' => 'Williams', 'email' => 'emma.williams@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'David', 'lastName' => 'Brown', 'email' => 'david.brown@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Lisa', 'lastName' => 'Anderson', 'email' => 'lisa.anderson@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'James', 'lastName' => 'Wilson', 'email' => 'james.wilson@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Maria', 'lastName' => 'Garcia', 'email' => 'maria.garcia@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'John', 'lastName' => 'Davis', 'email' => 'john.davis@example.com', 'gender' => GenderEnum::male],
            ['firstName' => 'Amy', 'lastName' => 'Taylor', 'email' => 'amy.taylor@example.com', 'gender' => GenderEnum::female],
            ['firstName' => 'Robert', 'lastName' => 'Miller', 'email' => 'robert.miller@example.com', 'gender' => GenderEnum::male],
        ];

        foreach ($userData as $data) {
            $user = User::factory()->create([
                'firstName' => $data['firstName'],
                'lastName' => $data['lastName'],
                'email' => $data['email'],
                'username' => strtolower($data['firstName'].'_'.$data['lastName']),
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'gender' => $data['gender']->value,
            ]);

            $users->push($user);
        }

        return $users;
    }

    private function createDemoCounsellors($users)
    {
        $counsellors = collect();

        // Make some users counsellors
        $counsellorUsers = $users->take(6); // First 6 users become counsellors

        $counsellorData = [
            ['name' => 'Dr. Sarah Johnson', 'about' => 'Specialized in anxiety and depression with 10 years of experience. I believe in creating a safe space for healing.'],
            ['name' => 'Dr. Michael Chen', 'about' => 'Trauma specialist focusing on PTSD and childhood trauma. Bilingual therapist with cultural sensitivity.'],
            ['name' => 'Dr. Emma Williams', 'about' => 'Marriage and family therapist helping couples and families build stronger relationships.'],
            ['name' => 'Dr. David Brown', 'about' => 'Addiction counselor with expertise in substance abuse and behavioral addictions recovery.'],
            ['name' => 'Dr. Lisa Anderson', 'about' => 'Child psychologist specializing in developmental issues and family dynamics.'],
            ['name' => 'Dr. James Wilson', 'about' => 'Cognitive behavioral therapist focusing on mood disorders and anxiety management.'],
        ];

        foreach ($counsellorUsers as $index => $user) {
            $counsellor = $user->counsellor()->create([
                'name' => $counsellorData[$index]['name'],
                'about' => $counsellorData[$index]['about'],
                'email' => $user->email,
                'phone' => fake()->phoneNumber(),
                'verified_at' => now(),
                'email_verified_at' => now(),
                'profession_id' => rand(1, 10),
                'contact_visible' => true,
            ]);

            // Attach random cases, languages, and religions
            $counsellor->cases()->attach([1, 2, 3, rand(4, 12)]);
            $counsellor->languages()->attach([1, rand(2, 5)]);
            $counsellor->religions()->attach([rand(1, 4)]);

            $counsellors->push($counsellor);
        }

        return $counsellors;
    }

    private function createDemoTherapies($users, $counsellors)
    {
        $nonCounsellorUsers = $users->skip(6); // Users who are not counsellors

        foreach ($nonCounsellorUsers as $user) {
            // Create 1-2 therapies per user
            $therapyCount = rand(1, 2);

            for ($i = 0; $i < $therapyCount; $i++) {
                $counsellor = $counsellors->random();

                $therapy = $user->addedTherapies()->create([
                    'name' => fake()->sentence(4),
                    'background_story' => fake()->paragraph(3),
                    'counsellor_id' => $counsellor->id,
                    'session_type' => ['Once', 'Periodic'][rand(0, 1)],
                    'payment_type' => ['FREE', 'PAID'][rand(0, 1)],
                    'allow_in_person' => rand(0, 1),
                    'anonymous' => rand(0, 1),
                    'public' => rand(0, 1),
                    'status' => 'pending',
                    'payment_data' => [
                        'amount' => rand(50, 200),
                        'currency' => 'USD',
                        'per' => 'session',
                    ],
                ]);

                // Attach therapy cases
                $therapy->cases()->attach([rand(1, 12), rand(1, 12)]);

                // Create therapy topics
                $topicCount = rand(2, 4);
                for ($j = 0; $j < $topicCount; $j++) {
                    $therapy->topics()->create([
                        'name' => fake()->sentence(3),
                        'description' => fake()->paragraph(2),
                        'counsellor_id' => $counsellor->id,
                    ]);
                }

                // Create sessions with some being held
                $this->createTherapySessions($therapy, $counsellor);
            }
        }
    }

    private function createTherapySessions($therapy, $counsellor)
    {
        $sessionCount = rand(2, 5);

        for ($i = 0; $i < $sessionCount; $i++) {
            $startTime = fake()->dateTimeBetween('-30 days', '+30 days');
            $endTime = (clone $startTime)->modify('+1 hour');

            $statuses = ['pending', 'held', 'in_session', 'abandoned'];
            $status = $statuses[array_rand($statuses)];

            $session = $counsellor->addedSessions()->create([
                'name' => 'Session '.($i + 1).' - '.fake()->words(3, true),
                'about' => fake()->paragraph(2),
                'for_id' => $therapy->id,
                'for_type' => $therapy::class,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'type' => ['online', 'in_person'][rand(0, 1)],
                'status' => $status,
            ]);

            // Create some messages for held sessions
            if ($status === 'held') {
                $this->createSessionMessages($session, $therapy, $counsellor);
            }
        }
    }

    private function createSessionMessages($session, $therapy, $counsellor)
    {
        $messageCount = rand(5, 15);

        for ($i = 0; $i < $messageCount; $i++) {
            $isFromCounsellor = rand(0, 1);
            $from = $isFromCounsellor ? $counsellor : $therapy->addedby;
            $to = $isFromCounsellor ? $therapy->addedby : $counsellor;

            $from->sentMessages()->create([
                'content' => fake()->sentence(rand(5, 15)),
                'to_id' => $to->id,
                'to_type' => $to::class,
                'for_id' => $session->id,
                'for_type' => $session::class,
                'created_at' => fake()->dateTimeBetween($session->start_time, $session->end_time),
            ]);
        }
    }

    private function createDemoGroupTherapies($users, $counsellors)
    {
        $groupTherapies = [];

        // Create 3-5 group therapies
        for ($i = 0; $i < rand(3, 5); $i++) {
            $creator = $users->random();

            $groupTherapy = $creator->addedGroupTherapies()->create([
                'name' => fake()->sentence(4),
                'about' => fake()->paragraph(3),
                'session_type' => ['Once', 'Periodic'][rand(0, 1)],
                'payment_type' => ['FREE', 'PAID'][rand(0, 1)],
                'max_users' => rand(5, 15),
                'allow_anyone' => rand(0, 1),
                'anonymous' => rand(0, 1),
                'public' => rand(0, 1),
                'status' => 'pending',
                'payment_data' => [
                    'amount' => rand(30, 100),
                    'currency' => 'USD',
                    'per' => 'session',
                ],
            ]);

            // Attach cases
            $groupTherapy->cases()->attach([rand(1, 12), rand(1, 12)]);

            // Add participants (users)
            $participants = $users->random(rand(3, 8));
            foreach ($participants as $participant) {
                $groupTherapy->users()->attach($participant->id, [
                    'anonymous' => fake()->boolean(),
                    'background_story' => fake()->paragraph(2),
                ]);
            }

            // Add counsellors
            $groupCounsellors = $counsellors->random(rand(1, 3));
            foreach ($groupCounsellors as $counsellor) {
                $groupTherapy->counsellors()->attach($counsellor->id, [
                    'state' => 'ACTIVE',
                ]);
            }

            $groupTherapies[] = $groupTherapy;
        }

        return $groupTherapies;
    }

    private function createDemoDiscussions($counsellors)
    {
        // Create discussions between counsellors
        for ($i = 0; $i < rand(3, 6); $i++) {
            $creator = $counsellors->random();
            $therapy = Therapy::inRandomOrder()->first();

            if (! $therapy) {
                continue;
            }

            $startTime = fake()->dateTimeBetween('-7 days', '+7 days');
            $endTime = fake()->dateTimeBetween($startTime, '+14 days');

            $discussion = $creator->addedDiscussions()->create([
                'name' => 'Discussion: '.fake()->sentence(4),
                'description' => fake()->paragraph(2),
                'for_id' => $therapy->id,
                'for_type' => $therapy::class,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'status' => ['pending', 'in_session', 'held'][rand(0, 2)],
            ]);

            // Add participating counsellors
            $participants = $counsellors->except($creator->id)->random(rand(1, 3));
            foreach ($participants as $participant) {
                $discussion->counsellors()->attach($participant->id);
            }

            // Create some discussion messages
            if ($discussion->status === 'held') {
                $this->createDiscussionMessages($discussion, $counsellors);
            }
        }
    }

    private function createDiscussionMessages($discussion, $counsellors)
    {
        $participants = collect([$discussion->addedby])
            ->merge($discussion->counsellors);

        $messageCount = rand(10, 25);

        for ($i = 0; $i < $messageCount; $i++) {
            $sender = $participants->random();

            $sender->sentMessages()->create([
                'content' => fake()->sentence(rand(8, 20)),
                'for_id' => $discussion->id,
                'for_type' => $discussion::class,
                'created_at' => fake()->dateTimeBetween($discussion->start_time, $discussion->end_time ?: now()),
            ]);
        }
    }

    private function createChatDemoData($users, $counsellors)
    {
        $client = $users->firstWhere('username', 'maria_garcia');
        throw_if(! $client, new RuntimeException('Chat demo seeder: expected seed user "maria_garcia" not found — did createDemoUsers() run first?'));

        $counsellor = $counsellors->first(fn ($c) => $c->name === 'Dr. Sarah Johnson');
        throw_if(! $counsellor, new RuntimeException('Chat demo seeder: expected seed counsellor "Dr. Sarah Johnson" not found — did createDemoUsers() run first?'));

        $secondCounsellor = $counsellors->first(fn ($c) => $c->name === 'Dr. Michael Chen');
        throw_if(! $secondCounsellor, new RuntimeException('Chat demo seeder: expected seed counsellor "Dr. Michael Chen" not found — did createDemoUsers() run first?'));

        // For SCRUM-71 (anonymity masking): a member who opted into per-member anonymity even
        // though the group itself defaults to non-anonymous -- exercises the per-member half of
        // GroupTherapy::isAnonymousFor()'s OR logic independently of the group-level flag.
        $anonymousParticipant = $users->firstWhere('username', 'john_davis');
        throw_if(! $anonymousParticipant, new RuntimeException('Chat demo seeder: expected seed user "john_davis" not found — did createDemoUsers() run first?'));

        // Individual therapy with a live "in session" session, for testing the individual
        // therapy chat page in "active session" mode (log in as maria_garcia or
        // sarah_johnson and visit /therapies/{id}/chat).
        $therapy = $client->addedTherapies()->create([
            'name' => 'Chat Demo Individual Therapy',
            'background_story' => 'Seeded therapy with a live session, for testing the therapy chat page.',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);

        $counsellor->addedSessions()->create([
            'name' => 'Chat Demo Live Session',
            'about' => 'Seeded live session for testing the therapy chat page.',
            'for_id' => $therapy->id,
            'for_type' => $therapy::class,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
        ]);

        // Group therapy with a live "in session" session, for testing the group therapy chat
        // page (log in as maria_garcia or sarah_johnson and visit /group-therapies/{id}/chat).
        $groupTherapy = $client->addedGroupTherapies()->create([
            'name' => 'Chat Demo Group Therapy',
            'about' => 'Seeded group therapy with a live session, for testing the group therapy chat page.',
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => true,
            'anonymous' => false,
            'public' => true,
            'status' => 'in_session',
        ]);

        $groupTherapy->users()->attach($client->id, [
            'anonymous' => false,
            'background_story' => 'Seeded participant for the chat demo group therapy.',
        ]);
        $groupTherapy->users()->attach($anonymousParticipant->id, [
            'anonymous' => true,
            'background_story' => 'Seeded anonymous participant for exercising SCRUM-71 anonymity masking.',
        ]);
        $groupTherapy->counsellors()->attach($counsellor->id, ['state' => 'ACTIVE']);

        $groupSession = $counsellor->addedSessions()->create([
            'name' => 'Chat Demo Group Live Session',
            'about' => 'Seeded live session for testing the group therapy chat page.',
            'for_id' => $groupTherapy->id,
            'for_type' => $groupTherapy::class,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addHour(),
            'type' => 'online',
            'status' => 'in_session',
        ]);

        // A message from the anonymous participant -- viewed by anyone other than
        // john_davis, MessageResource should mask its sender (null `fromUserId`); viewed by
        // john_davis themselves, it should show their own real id.
        $anonymousParticipant->sentMessages()->create([
            'content' => 'This message is from an anonymous group member and should show a masked sender to everyone except this member (SCRUM-71 test data).',
            'for_id' => $groupSession->id,
            'for_type' => $groupSession::class,
            'created_at' => now()->subMinutes(5),
        ]);

        $this->createGroupTherapyMembershipRequestDemoData($users);

        // Discussion in IN_SESSION status between two known counsellors, with existing
        // messages, for testing the discussion chat page (log in as sarah_johnson or
        // michael_chen and visit /discussions/{id}/chat).
        $discussion = $counsellor->addedDiscussions()->create([
            'name' => 'Chat Demo Discussion',
            'description' => 'Seeded discussion in IN_SESSION status for testing the discussion chat page.',
            'for_id' => $therapy->id,
            'for_type' => $therapy::class,
            'start_time' => now()->subMinutes(10),
            'end_time' => now()->addHour(),
            'status' => 'in_session',
        ]);

        $discussion->counsellors()->attach($secondCounsellor->id);

        $this->createDiscussionMessages($discussion, collect([$counsellor, $secondCounsellor]));
    }

    // SCRUM-72: a group therapy with `allow_anyone = false` and a deterministic PENDING
    // membership join request, so the accept/reject UI is manually verifiable without having to
    // trigger the join flow through the UI first. Log in as `maria_garcia` (the creator) to see
    // and respond to the pending request, or as `amy_taylor` (the requester) to see "you have a
    // pending request to join".
    private function createGroupTherapyMembershipRequestDemoData($users)
    {
        $creator = $users->firstWhere('username', 'maria_garcia');
        throw_if(! $creator, new RuntimeException('Membership request demo seeder: expected seed user "maria_garcia" not found — did createDemoUsers() run first?'));

        $requester = $users->firstWhere('username', 'amy_taylor');
        throw_if(! $requester, new RuntimeException('Membership request demo seeder: expected seed user "amy_taylor" not found — did createDemoUsers() run first?'));

        $groupTherapy = $creator->addedGroupTherapies()->create([
            'name' => 'Membership Request Demo Group Therapy',
            'about' => 'Seeded group therapy that requires a request to join, for testing the SCRUM-72 membership request accept/reject flow.',
            'session_type' => 'Periodic',
            'payment_type' => 'FREE',
            'max_users' => 10,
            'allow_anyone' => false,
            'anonymous' => false,
            'public' => true,
            'status' => 'pending',
        ]);

        $request = $requester->sentRequests()->create([
            'data' => ['anonymous' => false],
            'type' => RequestTypeEnum::groupTherapyMembership->value,
            'status' => RequestStatusEnum::pending->value,
        ]);
        $request->to()->associate($creator);
        $request->for()->associate($groupTherapy);
        $request->save();
    }

    private function createDemoPosts($counsellors, $users)
    {
        $postTopics = [
            'Understanding Anxiety: Signs and Management',
            'Building Healthy Relationships',
            'Coping with Depression: Daily Strategies',
            'The Importance of Mental Health Breaks',
            'Mindfulness Techniques for Stress Relief',
            'Setting Boundaries in Your Personal Life',
            'Recognizing Trauma Responses',
            'Self-Care Is Not Selfish',
            'Communication Skills for Better Relationships',
            'Dealing with Grief and Loss',
            'Managing Work-Life Balance',
            'Understanding Your Emotions',
            'Building Self-Esteem and Confidence',
            'Healthy Sleep Habits for Mental Wellness',
            'The Power of Gratitude Practice',
        ];

        $counsellorUsers = $counsellors->map(fn ($counsellor) => $counsellor->user);

        // Create posts from counsellors
        foreach ($counsellors as $counsellor) {
            $numberOfPosts = rand(2, 5);

            for ($i = 0; $i < $numberOfPosts; $i++) {
                $topic = fake()->randomElement($postTopics);

                $post = $counsellor->user->addedPosts()->create([
                    'content' => "**{$topic}**\n\n".$this->generatePostContent($topic),
                    'addedby_type' => $counsellor->user::class,
                    'addedby_id' => $counsellor->user->id,
                    'created_at' => fake()->dateTimeBetween('-3 months', 'now'),
                ]);

                // Add some likes from regular users
                $likers = $users->where('id', '!=', $counsellor->user->id)->random(rand(2, 8));
                foreach ($likers as $liker) {
                    $post->likes()->create([
                        'user_id' => $liker->id,
                    ]);
                }

                // Add some comments from users
                $commenters = $users->where('id', '!=', $counsellor->user->id)->random(rand(1, 4));
                foreach ($commenters as $commenter) {
                    $post->comments()->create([
                        'content' => fake()->paragraph(rand(1, 3)),
                        'user_id' => $commenter->id,
                        'created_at' => fake()->dateTimeBetween($post->created_at, 'now'),
                    ]);
                }
            }
        }

        // Create a few posts from regular users as well
        $regularUsers = $users->whereNotIn('id', $counsellorUsers->pluck('id'));
        foreach ($regularUsers->random(3) as $user) {
            $personalTopics = [
                'My Journey with Therapy',
                'Finding Hope in Dark Times',
                'What I Wish I Knew About Mental Health',
                'Support Groups Have Changed My Life',
                'Celebrating Small Victories',
            ];

            $post = $user->addedPosts()->create([
                'content' => '**'.fake()->randomElement($personalTopics)."**\n\n".fake()->paragraphs(rand(2, 4), true),
                'addedby_type' => $user::class,
                'addedby_id' => $user->id,
                'created_at' => fake()->dateTimeBetween('-2 months', 'now'),
            ]);

            // Add likes and comments from counsellors and other users
            $likers = $users->where('id', '!=', $user->id)->random(rand(3, 6));
            foreach ($likers as $liker) {
                $post->likes()->create([
                    'user_id' => $liker->id,
                ]);
            }
        }
    }

    private function generatePostContent($topic)
    {
        $contentMap = [
            'Understanding Anxiety' => "Anxiety is a normal human emotion, but when it becomes overwhelming, it can significantly impact our daily lives. Recognizing the signs early is crucial for effective management.\n\nCommon symptoms include persistent worry, restlessness, difficulty concentrating, and physical symptoms like rapid heartbeat or sweating. The good news is that anxiety is highly treatable through various approaches including therapy, mindfulness practices, and lifestyle changes.\n\nSome practical strategies include deep breathing exercises, regular physical activity, maintaining a consistent sleep schedule, and limiting caffeine intake. Remember, seeking professional help is a sign of strength, not weakness.",

            'Building Healthy Relationships' => "Healthy relationships are built on mutual respect, trust, and open communication. They require effort from all parties involved and contribute significantly to our overall mental well-being.\n\nKey components include setting clear boundaries, practicing active listening, expressing feelings honestly, and showing appreciation for one another. It's important to remember that healthy relationships aren't perfect – they involve navigating conflicts constructively and growing together.\n\nIf you find yourself in toxic relationship patterns, consider seeking support to develop healthier communication skills and boundary-setting techniques.",

            'Coping with Depression' => "Depression affects millions of people and can make even simple daily tasks feel overwhelming. Understanding that depression is a medical condition, not a personal failing, is the first step toward healing.\n\nDaily strategies that can help include maintaining a routine, getting adequate sleep, engaging in physical activity, and staying connected with supportive people. Even small accomplishments should be celebrated.\n\nProfessional treatment, including therapy and sometimes medication, can be incredibly effective. Remember that recovery is possible, and you don't have to face this alone.",

            'default' => "Mental health is just as important as physical health, yet it's often overlooked or stigmatized. Taking care of our emotional and psychological well-being should be a priority for everyone.\n\nThis means paying attention to our thoughts, feelings, and behaviors, and seeking help when we need it. There's no shame in talking to a therapist, counselor, or trusted friend about what you're going through.\n\nRemember that everyone's mental health journey is unique. What works for one person may not work for another, and that's okay. The important thing is to keep trying and to be patient with yourself as you navigate your path to wellness.",
        ];

        foreach ($contentMap as $key => $content) {
            if (str_contains($topic, $key)) {
                return $content;
            }
        }

        return $contentMap['default'];
    }

    // SCRUM-134: two dedicated counsellor accounts for testing the account-deletion feature --
    // one with no blocking state (deletion should succeed) and one with an in-session therapy
    // (deletion should be rejected by EnsureCanDeleteCounsellorAction's eligibility gate).
    private function createCounsellorDeletionDemoData(): void
    {
        $deletableUser = User::factory()->create([
            'firstName' => 'Deletable',
            'lastName' => 'Counsellor',
            'email' => 'deletable.counsellor@example.com',
            'username' => 'deletable_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $deletableUser->counsellor()->create([
            'name' => 'Dr. Deletable Counsellor',
            'about' => 'Seeded counsellor with no pending sessions, therapies, or affiliations -- account deletion should succeed for this account (SCRUM-134).',
            'email' => $deletableUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $blockedUser = User::factory()->create([
            'firstName' => 'Blocked',
            'lastName' => 'Counsellor',
            'email' => 'blocked.counsellor@example.com',
            'username' => 'blocked_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $blockedCounsellor = $blockedUser->counsellor()->create([
            'name' => 'Dr. Blocked Counsellor',
            'about' => 'Seeded counsellor with an in-session therapy -- account deletion should be rejected for this account (SCRUM-134).',
            'email' => $blockedUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        $blockedCounsellorClient = User::factory()->create([
            'firstName' => 'Blocked',
            'lastName' => 'CounsellorClient',
            'email' => 'blocked.counsellor.client@example.com',
            'username' => 'blocked_counsellor_client',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $blockedCounsellorClient->addedTherapies()->create([
            'name' => 'Counsellor Deletion Demo Therapy',
            'background_story' => 'Seeded in-session therapy, kept in_session on purpose so Dr. Blocked Counsellor cannot be deleted (SCRUM-134).',
            'counsellor_id' => $blockedCounsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'FREE',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
        ]);
    }

    private function createPaymentDemoData(): void
    {
        $client = User::factory()->create([
            'firstName' => 'Payment',
            'lastName' => 'DemoClient',
            'email' => 'payment.demo.client@example.com',
            'username' => 'payment_demo_client',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellorUser = User::factory()->create([
            'firstName' => 'Payment',
            'lastName' => 'DemoCounsellor',
            'email' => 'payment.demo.counsellor@example.com',
            'username' => 'payment_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $counsellor = $counsellorUser->counsellor()->create([
            'name' => 'Dr. Payment DemoCounsellor',
            'about' => 'Seeded counsellor for testing the payment UI (SCRUM-157/158).',
            'email' => $counsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);

        // PER_THERAPY: the whole therapy is charged once, via TherapyPaymentDetails.vue's "pay now".
        $client->addedTherapies()->create([
            'name' => 'Payment Demo Therapy (Per Therapy)',
            'background_story' => 'Seeded PAID, PER_THERAPY therapy for testing the client Pay Now action (SCRUM-157).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Once',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'pending',
            'payment_data' => [
                'amount' => 150,
                'currency' => 'USD',
                'per' => 'PER_THERAPY',
            ],
        ]);

        // PER_SESSION: each session is charged individually, via the session-actions modal's
        // "pay now" once that session is active.
        $perSessionTherapy = $client->addedTherapies()->create([
            'name' => 'Payment Demo Therapy (Per Session)',
            'background_story' => 'Seeded PAID, PER_SESSION therapy for testing the per-session Pay Now action (SCRUM-157).',
            'counsellor_id' => $counsellor->id,
            'session_type' => 'Periodic',
            'payment_type' => 'PAID',
            'allow_in_person' => false,
            'anonymous' => false,
            'public' => false,
            'status' => 'in_session',
            'payment_data' => [
                'amount' => 50,
                'currency' => 'USD',
                'per' => 'PER_SESSION',
            ],
        ]);

        // Kept within 5 minutes of "now" so it's immediately the therapy's activeSession -- the
        // Pay Now control in the session-actions modal only appears once a session is active
        // (mirrors how "start session"/"end session" already work).
        $counsellor->addedSessions()->create([
            'name' => 'Payment Demo Session',
            'about' => 'Seeded PAID session, immediately active so its Pay Now control is reachable without waiting.',
            'for_id' => $perSessionTherapy->id,
            'for_type' => $perSessionTherapy::class,
            'start_time' => now()->addMinutes(2),
            'end_time' => now()->addMinutes(62),
            'type' => 'online',
            'status' => 'pending',
            'payment_type' => 'PAID',
        ]);
    }

    private function createOrganizationDashboardDemoData(): void
    {
        $admin = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoAdmin',
            'email' => 'org.demo.admin@example.com',
            'username' => 'org_demo_admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $organization = Organization::factory()->create([
            'name' => 'Org Demo Wellness Collective',
            'legal_name' => 'Org Demo Wellness Collective Ltd',
            'registration_number' => 'REG-ORGDEMO-001',
            'description' => 'Seeded organization for testing the admin dashboard (SCRUM-165). Both a provider (affiliates counsellors) and a consumer (sponsors members).',
            'email' => 'contact@orgdemo.example.com',
            'phone' => fake()->phoneNumber(),
            'is_provider' => true,
            'is_consumer' => true,
            'self_apply_enabled' => true,
            'verified_at' => now(),
        ]);
        $organization->admins()->attach($admin->id, ['role' => OrganizationAdminRoleEnum::owner->value]);

        // A second, plain-role admin -- without this, exercising promote/demote/remove or the
        // last-owner-protection error (SCRUM-166) requires hand-creating an account via tinker
        // first, contrary to this project's seeding convention.
        $plainAdmin = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoPlainAdmin',
            'email' => 'org.demo.plain.admin@example.com',
            'username' => 'org_demo_plain_admin',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $organization->admins()->attach($plainAdmin->id, ['role' => OrganizationAdminRoleEnum::admin->value]);

        // An already-active affiliation, with agreed compensation, so the counsellors table
        // shows a fully-settled row alongside the pending application below.
        $affiliatedCounsellorUser = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoCounsellor',
            'email' => 'org.demo.counsellor@example.com',
            'username' => 'org_demo_counsellor',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $affiliatedCounsellor = $affiliatedCounsellorUser->counsellor()->create([
            'name' => 'Dr. Org DemoCounsellor',
            'about' => 'Seeded counsellor already affiliated with the demo organization (SCRUM-165).',
            'email' => $affiliatedCounsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        $affiliation = $organization->organizationCounsellors()->create([
            'counsellor_id' => $affiliatedCounsellor->id,
            'status' => OrganizationCounsellorStatusEnum::active->value,
            'source' => 'INVITED',
        ]);
        $affiliation->compensations()->create([
            'set_by_id' => $admin->id,
            'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
            'amount' => 2000,
            'currency' => 'USD',
            'effective_from' => now(),
        ]);

        // A pending compensation-CHANGE negotiation on top of the already-settled compensation
        // above -- an active affiliation can still have a new proposed change in flight (SCRUM-167),
        // distinct from the settled amount created just above. Exercises this counsellor's own
        // accept/reject/counter-offer UI on their my-organizations dashboard.
        $pendingCompensationChange = new Request([
            'type' => RequestTypeEnum::organizationCounsellorCompensationChange->value,
            'status' => RequestStatusEnum::pending->value,
            'round' => 1,
            'expires_at' => now()->addDays(7),
            'data' => [
                'type' => OrganizationCounsellorCompensationTypeEnum::fixed->value,
                'amount' => 2500,
                'currency' => 'USD',
                // Accept resolves accountability for the accepted terms via this id (see
                // RespondToOrganizationCounsellorCompensationRequestAction) -- omitting it (as an
                // earlier version of this seed data did) made "accept" fail on this seeded row
                // with "the original proposer no longer exists" (QA-caught, SCRUM-167).
                'proposedById' => $admin->id,
            ],
        ]);
        $pendingCompensationChange->from()->associate($organization);
        $pendingCompensationChange->to()->associate($affiliatedCounsellor);
        $pendingCompensationChange->for()->associate($affiliation);
        $pendingCompensationChange->save();

        // A second counsellor with a pending APPLICATION request -- distinct from the affiliated
        // row above, per AC7's "pending Request" vs "pending affiliation" distinction.
        $applicantCounsellorUser = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoApplicant',
            'email' => 'org.demo.applicant@example.com',
            'username' => 'org_demo_applicant',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $applicantCounsellor = $applicantCounsellorUser->counsellor()->create([
            'name' => 'Dr. Org DemoApplicant',
            'about' => 'Seeded counsellor with a pending application to the demo organization (SCRUM-165).',
            'email' => $applicantCounsellorUser->email,
            'phone' => fake()->phoneNumber(),
            'verified_at' => now(),
            'email_verified_at' => now(),
            'profession_id' => rand(1, 10),
            'contact_visible' => true,
        ]);
        // from/to/for are morph columns, deliberately excluded from Request::$fillable --
        // associate() (not create()) is required, mirroring CreateLinkAction's own pattern.
        $counsellorApplication = new Request([
            'type' => RequestTypeEnum::organizationCounsellorApplication->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [],
        ]);
        $counsellorApplication->from()->associate($applicantCounsellor);
        $counsellorApplication->to()->associate($organization);
        $counsellorApplication->for()->associate($organization);
        $counsellorApplication->save();

        // An active member with billing config set, plus a member with a pending self-apply
        // application -- same "settled vs. queued" pairing as the counsellor side above.
        $activeMember = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoMember',
            'email' => 'org.demo.member@example.com',
            'username' => 'org_demo_member',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $membership = $organization->members()->create([
            'user_id' => $activeMember->id,
            'status' => OrganizationMemberStatusEnum::active->value,
            'source' => 'INVITED',
        ]);
        $membership->billingConfigs()->create([
            'mode' => OrganizationMemberBillingModeEnum::retainer->value,
            'include_group_therapies' => true,
            'effective_from' => now(),
        ]);

        $applicantMember = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoMemberApplicant',
            'email' => 'org.demo.member.applicant@example.com',
            'username' => 'org_demo_member_applicant',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $memberApplication = new Request([
            'type' => RequestTypeEnum::organizationMemberApplication->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [],
        ]);
        $memberApplication->from()->associate($applicantMember);
        $memberApplication->to()->associate($organization);
        $memberApplication->for()->associate($organization);
        $memberApplication->save();

        // A user with a pending org-INVITE (org-initiated, no membership row yet) -- distinct
        // from $applicantMember's self-initiated APPLICATION above, and needed to exercise a
        // member's accept/reject of an invite via the generic Requests inbox (SCRUM-168 AC2).
        $invitedMember = User::factory()->create([
            'firstName' => 'Org',
            'lastName' => 'DemoMemberInvitee',
            'email' => 'org.demo.member.invitee@example.com',
            'username' => 'org_demo_member_invitee',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
        $memberInvite = new Request([
            'type' => RequestTypeEnum::organizationMemberInvite->value,
            'status' => RequestStatusEnum::pending->value,
            'data' => [],
        ]);
        $memberInvite->from()->associate($organization);
        $memberInvite->to()->associate($invitedMember);
        $memberInvite->for()->associate($organization);
        $memberInvite->save();
    }
}
