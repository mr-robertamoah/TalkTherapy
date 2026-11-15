<?php

use App\Models\User;
use Database\Seeders\CounsellorSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\NotificationCenterSeeder;
use Database\Seeders\YourDataSeeder;

// SCRUM-429: demo seeders never run outside local and testing by accident.

function spgForget(): void
{
    putenv('ALLOW_DEMO_SEED');
    unset($_SERVER['ALLOW_DEMO_SEED'], $_ENV['ALLOW_DEMO_SEED']);
}

test('seeding refuses anywhere but local and testing, and creates nobody', function (string $environment, string $seeder) {
    spgForget();
    app()->detectEnvironment(fn () => $environment);

    $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])
        ->expectsOutputToContain('Not seeding: APP_ENV is '.$environment)
        ->assertSuccessful();

    expect(User::query()->count())->toBe(0);
})->with([
    ['production', DatabaseSeeder::class],
    ['staging', DatabaseSeeder::class],
    ['production', CounsellorSeeder::class],
    ['production', NotificationCenterSeeder::class],
    ['production', YourDataSeeder::class],
]);

test('the override works outside production, but never in production', function () {
    spgForget();
    putenv('ALLOW_DEMO_SEED=true');
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('db:seed', ['--class' => CounsellorSeeder::class, '--force' => true])->expectsOutputToContain('Not seeding');
    expect(User::query()->count())->toBe(0);

    spgForget();
});
