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
        // \App\Models\User::factory(10)->create();

        $user = User::factory()->create([
            'username' => 'mr_robertamoah',
            'firstName' => 'Robert',
            'lastName' => 'Amoah',
            'email' => 'mr_robertamoah@yahoo.com',
            'password' => Hash::make(env('SUPER_PASSWORD', 'password'))
        ]);

        $user->administrator()->create([
            'verified_at' => now(),
            'type' => AdministratorTypeEnum::super->value
        ]);

        $user->addedLanguages()->createMany([
            ['name' => 'English'],
            ['name' => 'French'],
            ['name' => 'Twi'],
            ['name' => 'Ewe'],
            ['name' => 'Ga'],
        ]);

        $user->addedReligions()->createMany([
            ['name' => 'Christianity'],
            ['name' => 'Islam'],
            ['name' => 'Traditional'],
            ['name' => 'Atheist'],
        ]);

        $user->addedTherapyCases()->createMany([
            ['name' => 'Anxiety'],
            ['name' => 'Stress'],
            ['name' => 'Depression'],
            ['name' => 'Addiction'],
        ]);

        $user->addedLicensingAuthorities()->createMany([
            [
                'name' => 'National Identification Authority',
                'license_type' => LicensingTypeEnum::both->value,
                'country' => 'Ghana',
                'validated' => true
            ],
            [
                'name' => 'Ghana Psychological Association',
                'license_type' => LicensingTypeEnum::both->value,
                'country' => 'Ghana',
                'validated' => true
            ],
        ]);

        // dderick
        $dderick = User::factory()->create([
            'username' => 'dderick',
            'firstName' => 'Derrick',
            'lastName' => 'Amponsah',
            'password' => Hash::make('password123'),
            'gender' => GenderEnum::male->value
        ]);

        $dderick->counsellor()->create([
            'email' => 'dderik@gmail.com',
            'phone' => '+233233453423',
            'about' => 'I will humbly take you through sessions till we can both help you out of the hole.',
            'email_verified_at' => now(),
            'verified_at' => now()
        ]);
    }
}
