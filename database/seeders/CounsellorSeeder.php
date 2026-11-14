<?php

namespace Database\Seeders;

use App\Models\Counsellor;
use App\Models\User;
use Illuminate\Database\Seeder;

class CounsellorSeeder extends Seeder
{
    use RefusesOutsideLocal;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // SCRUM-429
        if ($this->refusedHere()) {
            return;
        }

        User::factory()
            ->has(Counsellor::factory()->state(function ($arr, $user) {
                return array_merge($arr, [
                    'name' => $user->name,
                    'email' => $user->email,
                ]);
            }))
            ->createMany(5);
    }
}
