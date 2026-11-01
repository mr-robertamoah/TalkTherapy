<?php

use App\Models\Counsellor;
use App\Models\CounsellorPayout;
use App\Models\User;
use App\Notifications\PayoutFailedNotification;
use Illuminate\Notifications\Messages\MailMessage;

// SCRUM-254: user-written text in a mail (names in the greeting, reasons and notes in lines) is
// rendered as Markdown in the HTML part. With secured encoding on (AppServiceProvider) none of it
// can become a link, an image or a tag -- and ordinary text still reads exactly as typed.

const MAIL_INJECTIONS = [
    '[Click here](https://evil.example/phish)',
    '![pixel](https://evil.example/track.png)',
    '<a href="https://evil.example/raw">raw html</a>',
    '<https://evil.example/autolink>',
];

function mailInjectionHtml(MailMessage $mail): string
{
    return (string) $mail->render();
}

test('crafted text in a mail line or greeting renders inert', function (string $payload) {
    $mail = (new MailMessage)->greeting("Hello {$payload}!")->line("Reason given: {$payload}");
    $html = mailInjectionHtml($mail);

    expect($html)->not->toMatch('/<a[^>]+href="https:\/\/evil\.example/')
        ->and($html)->not->toContain('<img')
        ->and($html)->not->toMatch('/<img[^>]+evil/');
})->with(MAIL_INJECTIONS);

test('a real notification with a crafted name and reason renders inert', function () {
    $counsellor = Counsellor::factory()->create(['user_id' => User::factory(), 'name' => '[Dr Evil](https://evil.example/name)']);
    $payout = CounsellorPayout::factory()->create(['counsellor_id' => $counsellor->id]);

    $html = mailInjectionHtml((new PayoutFailedNotification($payout, '![x](https://evil.example/r.png) [pay](https://evil.example/pay)'))->toMail($counsellor));

    expect($html)->not->toMatch('/<a[^>]+href="https:\/\/evil\.example/')
        ->and($html)->not->toContain('<img')
        ->and($html)->toContain('Dr Evil');
});

test('ordinary text still reads exactly as typed', function () {
    $html = mailInjectionHtml((new MailMessage)->line("O'Brien can't make 3pm -- 50% off & more."));

    expect(html_entity_decode(strip_tags($html), ENT_QUOTES))->toContain("O'Brien can't make 3pm -- 50% off & more.");
});
