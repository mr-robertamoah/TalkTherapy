<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Enums\AdministratorTypeEnum;
use App\Enums\GenderEnum;
use App\Enums\LicensingTypeEnum;
use App\Models\Language;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

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
            'type' => AdministratorTypeEnum::super->value
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
        
        // Create demo posts from counsellors
        $this->createDemoPosts($counsellors, $users);
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
                'username' => strtolower($data['firstName'] . '_' . $data['lastName']),
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
                        'per' => 'session'
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
                'name' => "Session " . ($i + 1) . " - " . fake()->words(3, true),
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
                    'per' => 'session'
                ],
            ]);
            
            // Attach cases
            $groupTherapy->cases()->attach([rand(1, 12), rand(1, 12)]);
            
            // Add participants (users)
            $participants = $users->random(rand(3, 8));
            foreach ($participants as $participant) {
                $groupTherapy->users()->attach($participant->id, [
                    'anonymous' => fake()->boolean(),
                    'background_story' => fake()->paragraph(2)
                ]);
            }
            
            // Add counsellors
            $groupCounsellors = $counsellors->random(rand(1, 3));
            foreach ($groupCounsellors as $counsellor) {
                $groupTherapy->counsellors()->attach($counsellor->id, [
                    'state' => 'ACTIVE'
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
            $therapy = \App\Models\Therapy::inRandomOrder()->first();
            
            if (!$therapy) continue;
            
            $startTime = fake()->dateTimeBetween('-7 days', '+7 days');
            $endTime = fake()->dateTimeBetween($startTime, '+14 days');
            
            $discussion = $creator->addedDiscussions()->create([
                'name' => "Discussion: " . fake()->sentence(4),
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
            'The Power of Gratitude Practice'
        ];
        
        $counsellorUsers = $counsellors->map(fn($counsellor) => $counsellor->user);
        
        // Create posts from counsellors
        foreach ($counsellors as $counsellor) {
            $numberOfPosts = rand(2, 5);
            
            for ($i = 0; $i < $numberOfPosts; $i++) {
                $topic = fake()->randomElement($postTopics);
                
                $post = $counsellor->user->addedPosts()->create([
                    'content' => "**{$topic}**\n\n" . $this->generatePostContent($topic),
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
                'Celebrating Small Victories'
            ];
            
            $post = $user->addedPosts()->create([
                'content' => "**" . fake()->randomElement($personalTopics) . "**\n\n" . fake()->paragraphs(rand(2, 4), true),
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
            
            'default' => "Mental health is just as important as physical health, yet it's often overlooked or stigmatized. Taking care of our emotional and psychological well-being should be a priority for everyone.\n\nThis means paying attention to our thoughts, feelings, and behaviors, and seeking help when we need it. There's no shame in talking to a therapist, counselor, or trusted friend about what you're going through.\n\nRemember that everyone's mental health journey is unique. What works for one person may not work for another, and that's okay. The important thing is to keep trying and to be patient with yourself as you navigate your path to wellness."
        ];
        
        foreach ($contentMap as $key => $content) {
            if (str_contains($topic, $key)) {
                return $content;
            }
        }
        
        return $contentMap['default'];
    }
}
