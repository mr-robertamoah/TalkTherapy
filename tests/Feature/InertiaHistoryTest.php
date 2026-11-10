<?php

use App\Models\User;

// SCRUM-406: Inertia page history is encrypted, and cleared whenever a session ends -- sign-out, closing the
// account, and the login page (which also covers a session that expired) -- so the back button can't show
// chats or admin views from before. Helpers are file-local and ihx-prefixed (--parallel).

function ihxPage($response): array
{
    return $response->assertOk()->viewData('page');
}

test('pages are sent with history encryption on, and nothing cleared during normal use', function () {
    $user = User::factory()->adult()->create();

    $page = ihxPage($this->actingAs($user)->get(route('profile.show')));
    expect($page['encryptHistory'])->toBeTrue()
        ->and($page['clearHistory'])->toBeFalse();

    expect(ihxPage($this->get('/'))['clearHistory'])->toBeFalse();
});

test('signing out clears the history on the next page', function () {
    $user = User::factory()->adult()->create();

    $this->actingAs($user)->post(route('logout'))->assertRedirect('/');
    $this->assertGuest();

    $page = ihxPage($this->get('/'));
    expect($page['clearHistory'])->toBeTrue()->and($page['encryptHistory'])->toBeTrue();
    // Once only.
    expect(ihxPage($this->get('/'))['clearHistory'])->toBeFalse();
});

test('closing the account clears the history too', function () {
    $user = User::factory()->adult()->create();

    $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/');

    expect(ihxPage($this->get('/'))['clearHistory'])->toBeTrue();
});

test('the login page clears the history -- the case of a session that simply expired', function () {
    expect(ihxPage($this->get(route('login')))['clearHistory'])->toBeTrue();
});

test('encryption can be turned off with the env value', function () {
    config(['inertia.history.encrypt' => false]);

    expect(ihxPage($this->get(route('login')))['encryptHistory'])->toBeFalse();
});

test('history is encrypted only where the browser can do it -- https, or a localhost host', function () {
    expect(ihxPage($this->get('https://talktherapy.example/login'))['encryptHistory'])->toBeTrue()
        ->and(ihxPage($this->get('http://localhost/login'))['encryptHistory'])->toBeTrue()
        ->and(ihxPage($this->get('http://app.localhost/login'))['encryptHistory'])->toBeTrue()
        // Plain http elsewhere (a LAN address, an http staging host): asking would break navigation.
        ->and(ihxPage($this->get('http://192.168.1.20/login'))['encryptHistory'])->toBeFalse()
        ->and(ihxPage($this->get('http://talktherapy.example/login'))['encryptHistory'])->toBeFalse();
});

test('a signed-in person\'s pages are never stored by the browser cache; public pages are left alone', function () {
    $user = User::factory()->adult()->create();

    $html = $this->actingAs($user)->get(route('profile.show'))->assertOk();
    expect($html->headers->get('Cache-Control'))->toContain('no-store');

    $visit = $this->actingAs($user)->get(route('profile.show'), ['X-Inertia' => 'true', 'X-Inertia-Version' => $html->viewData('page')['version']]);
    expect($visit->headers->get('Cache-Control'))->toContain('no-store');

    auth()->logout();
    expect($this->get(route('login'))->headers->get('Cache-Control'))->not->toContain('no-store');
});
