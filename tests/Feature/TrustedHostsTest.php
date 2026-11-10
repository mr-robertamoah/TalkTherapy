<?php

use App\Support\AppHost;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

// SCRUM-397: only our own host in production, and links on APP_URL. Laravel's TrustHosts is off in tests by
// design, so the patterns and the link rule are tested directly. Helpers are file-local (none needed).

afterEach(function () {
    Request::setTrustedHosts([]);
    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

test('the trusted hosts are APP_URL\'s host and TRUSTED_HOSTS, exactly -- no other host or subdomain', function () {
    config(['app.url' => 'https://talktherapy.example', 'app.trusted_hosts' => ' www.talktherapy.example ,']);
    Request::setTrustedHosts(AppHost::trustedPatterns());

    $host = fn (string $name) => Request::create('https://'.$name.'/')->getHost();

    expect($host('talktherapy.example'))->toBe('talktherapy.example')
        ->and($host('WWW.talktherapy.example'))->toBe('www.talktherapy.example');
    foreach (['evil.example', 'attacker.talktherapy.example', 'talktherapy.example.evil.example'] as $foreign) {
        expect(fn () => $host($foreign))->toThrow(SuspiciousOperationException::class);
    }
});

test('in production absolute links are built on APP_URL, over https, whatever the request\'s host', function () {
    config(['app.url' => 'https://talktherapy.example']);
    $this->app->detectEnvironment(fn () => 'production');

    AppHost::enforceLinks($this->app);
    $this->app['url']->setRequest(Request::create('http://evil.example/'));

    expect(route('privacy'))->toBe('https://talktherapy.example/privacy');
});

test('in local and testing nothing is forced', function () {
    config(['app.url' => 'https://talktherapy.example']);

    AppHost::enforceLinks($this->app);
    $this->app['url']->setRequest(Request::create('http://localhost:8000/'));

    expect(route('privacy'))->toBe('http://localhost:8000/privacy');
});

test('a foreign host gets a 400 once trusted hosts apply', function () {
    config(['app.url' => 'https://talktherapy.example']);
    Request::setTrustedHosts(AppHost::trustedPatterns());

    $this->get('http://evil.example/privacy')->assertStatus(400);
});

test('the app uses these patterns for TrustHosts, and entries with a scheme or port still work', function () {
    config(['app.url' => 'https://TalkTherapy.example:443', 'app.trusted_hosts' => 'https://health.talktherapy.example:8080, ']);

    expect(app(TrustHosts::class)->hosts())->toBe(AppHost::trustedPatterns())
        ->and(AppHost::trustedPatterns())->toBe(['^talktherapy\\.example$', '^health\\.talktherapy\\.example$']);
});

test('a deployed environment that isn\'t production (staging) gets the forced links too', function () {
    config(['app.url' => 'https://staging.talktherapy.example']);
    $this->app->detectEnvironment(fn () => 'staging');

    AppHost::enforceLinks($this->app);
    $this->app['url']->setRequest(Request::create('http://evil.example/'));

    expect(route('privacy'))->toBe('https://staging.talktherapy.example/privacy');
});

test('Reverb accepts websocket origins only from our own hosts once deployed, anything locally, or an explicit list', function () {
    expect(AppHost::reverbOrigins(null, 'production', 'https://TalkTherapy.example', 'health.talktherapy.example'))->toBe(['talktherapy.example', 'health.talktherapy.example'])
        ->and(AppHost::reverbOrigins('', 'staging', 'https://staging.talktherapy.example', ''))->toBe(['staging.talktherapy.example'])
        ->and(AppHost::reverbOrigins(null, 'local', 'http://localhost:8000', ''))->toBe(['*'])
        ->and(AppHost::reverbOrigins(null, 'testing', null, null))->toBe(['*'])
        ->and(AppHost::reverbOrigins('app.example, *.app.example', 'production', 'https://talktherapy.example', ''))->toBe(['app.example', '*.app.example'])
        // No usable host: refuse every origin rather than allow all.
        ->and(AppHost::reverbOrigins(null, 'production', '', ''))->toBe([]);
});

test('the Reverb app is configured through that rule', function () {
    expect(config('reverb.apps.apps.0.allowed_origins'))->toBe(['*']);
});
