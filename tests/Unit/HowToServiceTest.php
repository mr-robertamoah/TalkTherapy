<?php

use App\DTOs\CreateHowToDTO;
use App\Exceptions\AddedbyIsInvalidException;
use App\Exceptions\HowToException;
use App\Models\Administrator;
use App\Models\HowTo;
use App\Models\HowToStep;
use App\Models\User;
use App\Services\HowToService;


describe('create HowTo', function () {

    test("createHowTo fails when not admin", function () {
        
        $user = User::factory()->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How To',
            'user' => $user
        ]);
    
        $this->expectException(AddedbyIsInvalidException::class);
    
        HowToService::new()->createHowTo($createHowToDTO);
    });
    
    test("createHowTo fails when admin but without a name", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'user' => $user
        ]);
    
        $this->expectException(HowToException::class);
    
        HowToService::new()->createHowTo($createHowToDTO);
    });
    
    test("createHowTo fails when admin but without a page", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How to',
            'user' => $user,
        ]);
    
        $this->expectException(HowToException::class);
    
        HowToService::new()->createHowTo($createHowToDTO);
    });
    
    test("createHowTo fails when admin but without a howToSteps", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How to',
            'page' => 'Home',
            'user' => $user,
        ]);
    
        $this->expectException(HowToException::class);
    
        HowToService::new()->createHowTo($createHowToDTO);
    });
    
    test("createHowTo fails when admin but with empty howToSteps", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How to',
            'page' => 'Home',
            'howToSteps' => [],
            'user' => $user,
        ]);
    
        $this->expectException(HowToException::class);
    
        HowToService::new()->createHowTo($createHowToDTO);
    });
    
    test("createHowTo fails when admin with howToSteps having wrong positions", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How to',
            'page' => 'Home',
            'howToSteps' => [
                ['name' => 'how to step 1', 'position' => 1, 'elementId' => 'example1_id'],
                ['name' => 'how to step 2', 'position' => 1, 'elementId' => 'example2_id'],
            ],
            'user' => $user,
        ]);
    
        $this->expectException(HowToException::class);
    
        HowToService::new()->createHowTo($createHowToDTO);
        
        $this->assertDatabaseDoesntHave('how_tos', [
            'name' => 'How to',
            'page' => 'Home',
            'user_id' => $user->id,
        ]);
    
        $this->assertDatabaseDoesntHave('how_to_steps', [
            'name' => 'how to step 1',
            'position' => 1, 
            'element_id' => 'example1_id',
            'user_id' => $user->id,
        ]);
    });
    
    test("createHowTo fails when admin with howToSteps having same elememt ids", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How to',
            'page' => 'Home',
            'howToSteps' => [
                ['name' => 'how to step 1', 'position' => 1, 'elementId' => 'example1_id'],
                ['name' => 'how to step 2', 'position' => 2, 'elementId' => 'example1_id'],
            ],
            'user' => $user,
        ]);
    
        $this->expectException(HowToException::class);
    
        HowToService::new()->createHowTo($createHowToDTO);
        
        $this->assertDatabaseDoesntHave('how_tos', [
            'name' => 'How to',
            'page' => 'Home',
            'user_id' => $user->id,
        ]);
    
        $this->assertDatabaseDoesntHave('how_to_steps', [
            'name' => 'how to step 1',
            'position' => 1, 
            'element_id' => 'example1_id',
            'user_id' => $user->id,
        ]);
    });
    
    test("createHowTo is created when admin with enough data", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How to',
            'page' => 'Home',
            'howToSteps' => [
                ['name' => 'how to step', 'position' => 1, 'elementId' => 'example_id']
            ],
            'user' => $user,
        ]);
    
        $howTo = HowToService::new()->createHowTo($createHowToDTO);
    
        expect($howTo)->toBeTruthy(HowTo::class);
    
        $this->assertDatabaseHas('how_tos', [
            'name' => 'How to',
            'page' => 'Home',
            'user_id' => $user->id,
        ]);
    
        $this->assertDatabaseHas('how_to_steps', [
            'name' => 'how to step',
            'position' => 1, 
            'element_id' => 'example_id',
            'user_id' => $user->id,
        ]);
    });
});

describe('update HowTo', function () {

    test("createHowTo fails when not admin", function () {
        
        $user = User::factory()->create();
        
        $createHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How To',
            'user' => $user
        ]);
    
        $this->expectException(AddedbyIsInvalidException::class, "You must be an administrator to perform this action.");
    
        HowToService::new()->updateHowTo($createHowToDTO);
    });

    test("updateHowTo fails without HowTo", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $updateHowToDTO = CreateHowToDTO::fromArray([
            'name' => 'How To',
            'user' => $user
        ]);
    
        $this->expectException(HowToException::class, 'The how-to was not found.');
    
        HowToService::new()->updateHowTo($updateHowToDTO);
    });
    
    test("updateHowTo fails when admin but without a howTo name, page and howToSteps", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $howTo = HowTo::factory()->create([
            'user_id' => $user->id
        ]);

        $updateHowToDTO = CreateHowToDTO::fromArray([
            'user' => $user,
            'howTo' => $howTo,
        ]);
    
        $this->expectException(HowToException::class);
    
        HowToService::new()->updateHowTo($updateHowToDTO);
    });
    
    test("updateHowTo is success when admin howTo name is provided", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $howTo = HowTo::factory()->create([
            'user_id' => $user->id
        ]);
        
        $name = 'How to';
        $updateHowToDTO = CreateHowToDTO::fromArray([
            'name' => $name,
            'user' => $user,
            'howTo' => $howTo,
        ]);
    
        expect($name)->not()->toBe($howTo->name);
        HowToService::new()->updateHowTo($updateHowToDTO);

        expect($howTo->name)->tobe($name);

        $this->assertDatabaseHas('how_tos', [
            'name' => $howTo->name,
            'description' => $howTo->description,
        ]);
    });
    
    test("updateHowTo is success when admin howTo name is page", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        
        $howTo = HowTo::factory()->create([
            'user_id' => $user->id
        ]);

        $page = 'About';
        $updateHowToDTO = CreateHowToDTO::fromArray([
            'page' => $page,
            'user' => $user,
            'howTo' => $howTo,
        ]);
    
        expect($howTo->page)->not()->toBe($page);

        HowToService::new()->updateHowTo($updateHowToDTO);

        expect($howTo->page)->tobe($page);

        $this->assertDatabaseHas('how_tos', [
            'page' => $howTo->page,
            'description' => $howTo->description,
        ]);
    });
    
    test("updateHowTo is successful when admin when new howToStep is added", function () {
        
        $user = User::factory()->has(Administrator::factory())->create();
        $howTo = HowTo::factory(state: [
            'user_id' => $user->id,
        ])->has(HowToStep::factory(state: function($h, $s) {
            // dd($s);
            return  [
                ...$h,
                'how_to_id' => $s["id"],
                'user_id' => $s["user_id"],
            ];
        }))->create();
        
        $updateHowToDTO = CreateHowToDTO::fromArray([
            'addedHowToSteps' => [
                ['name' => 'how to step', 'position' => 2, 'elementId' => 'example2_id']
            ],
            'user' => $user,
            'howTo' => $howTo,
        ]);
    
        HowToService::new()->updateHowTo($updateHowToDTO);
    
        expect($howTo->howToSteps()->count())->toBe(2);
    
        $this->assertDatabaseHas('how_to_steps', [
            'name' => 'how to step',
            'position' => 2, 
            'element_id' => 'example2_id',
            'user_id' => $user->id,
        ]);
    });
    
    test("updateHowTo is successful when admin when a howToStep updated", function () {
        
        $counter = 0;
        $user = User::factory()->has(Administrator::factory())->create();
        $howTo = HowTo::factory(state: [
            'user_id' => $user->id,
        ])->has(HowToStep::factory()->count(2)->state(function($h, $s) use (&$counter) {
            $counter++;
            return  [
                ...$h,
                'how_to_id' => $s["id"],
                'user_id' => $s["user_id"],
                'position' => $counter,
                'element_id' => "element{$counter}_id"
            ];
        }))->create();
        
        $updateHowToDTO = CreateHowToDTO::fromArray([
            'howToSteps' => [
                ['id' => 2, 'name' => 'how to step', 'position' => 2, 'elementId' => 'example4_id']
            ],
            'user' => $user,
            'howTo' => $howTo,
        ]);
    
        HowToService::new()->updateHowTo($updateHowToDTO);
    
        expect($howTo->howToSteps()->count())->toBe(2);
    
        $this->assertDatabaseHas('how_to_steps', [
            'name' => 'how to step',
            'position' => 2, 
            'element_id' => 'example4_id',
            'user_id' => $user->id,
        ]);
    });
    
    test("updateHowTo is successful when admin when a howToStep deleted", function () {
        
        $counter = 0;
        $user = User::factory()->has(Administrator::factory())->create();
        $howTo = HowTo::factory(state: [
            'user_id' => $user->id,
        ])->has(HowToStep::factory()->count(2)->state(function($h, $s) use (&$counter) {
            $counter++;
            return  [
                ...$h,
                'how_to_id' => $s["id"],
                'user_id' => $s["user_id"],
                'position' => $counter,
                'element_id' => "element{$counter}_id"
            ];
        }))->create();
        
        $updateHowToDTO = CreateHowToDTO::fromArray([
            'deletedHowToSteps' => [
                ['id' => 2]
            ],
            'user' => $user,
            'howTo' => $howTo,
        ]);
    
        HowToService::new()->updateHowTo($updateHowToDTO);
    
        expect($howTo->howToSteps()->count())->toBe(1);
    
        $this->assertDatabaseMissing('how_to_steps', [
            'id' => 2,
            'position' => 2,
            'user_id' => $user->id,
        ]);
    });
    
    test("updateHowTo fails when admin and when updating howToStep with wrong position", function () {
        
        $counter = 0;
        $user = User::factory()->has(Administrator::factory())->create();
        $howTo = HowTo::factory(state: [
            'user_id' => $user->id,
        ])->has(HowToStep::factory()->count(2)->state(function($h, $s) use (&$counter) {
            $counter++;
            return  [
                ...$h,
                'how_to_id' => $s["id"],
                'user_id' => $s["user_id"],
                'position' => $counter,
                'element_id' => "element{$counter}_id"
            ];
        }))->create();
        
        $updateHowToDTO = CreateHowToDTO::fromArray([
            'howToSteps' => [
                ['id' => 2, 'name' => 'how to step', 'position' => 1, 'elementId' => 'example4_id']
            ],
            'user' => $user,
            'howTo' => $howTo,
        ]);

        $this->expectException(HowToException::class, 'The positions provided for the how-to-steps are not valid. Ensure each one has a unique non-zero position from the existing how-to-steps.');
    
        HowToService::new()->updateHowTo($updateHowToDTO);
    });
});