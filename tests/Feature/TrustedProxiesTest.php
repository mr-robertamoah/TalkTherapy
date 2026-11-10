<?php

use App\Http\Middleware\StoreVisitationMiddleware;
use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// SCRUM-138: TRUSTED_PROXIES really reaches the global TrustProxies middleware (bootstrap/app.php), a
// listed proxy's forwarded address and scheme are believed, and nobody else's are -- nor anything that
// would trust more than the proxy itself. Helpers are file-local and tpx-prefixed (--parallel).

// Boots the app again with TRUSTED_PROXIES set (or unset), so bootstrap/app.php reads it as in production.
function tpxBoot($test, ?string $value): void
{
    if ($value === null) {
        putenv('TRUSTED_PROXIES');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    } else {
        putenv("TRUSTED_PROXIES={$value}");
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = $value;
    }
    TrustProxies::flushState();
    (fn () => $this->refreshApplication())->call($test);
    Route::get('/__tpx-client', fn (Request $request) => ['ip' => $request->ip(), 'secure' => $request->isSecure()]);
}

function tpxSeen($test, string $from): array
{
    // The visit log needs tables this freshly booted app's database doesn't have; the address it would
    // record is what $request->ip() returns, which is what's checked here.
    return $test->withoutMiddleware(StoreVisitationMiddleware::class)->withServerVariables([
        'REMOTE_ADDR' => $from, 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->getJson('/__tpx-client')->assertOk()->json();
}

beforeEach(function () {
    $this->tpxOriginal = getenv('TRUSTED_PROXIES') === false ? null : (string) getenv('TRUSTED_PROXIES');
});

// Back to what this worker started with (Docker's env_file sets it, empty), for the tests after these.
afterEach(function () {
    tpxBoot($this, $this->tpxOriginal);
});

test('only explicit addresses and ranges are accepted -- never a wildcard or a shortcut that trusts more', function () {
    expect(TrustedProxies::fromEnv(' 10.0.0.5 , 10.0.0.0/16,2001:db8::/32 ,173.245.48.0/20'))
        ->toBe(['10.0.0.5', '10.0.0.0/16', '2001:db8::/32', '173.245.48.0/20']);

    expect(TrustedProxies::fromEnv('*,**,REMOTE_ADDR,PRIVATE_SUBNETS,0.0.0.0/0,::/0,10.0.0.0/7,2001::/28,2a00::/16,10.0.0.0/33,not-an-ip,10.0.0.1/x,'))->toBe([])
        // Nothing in the IPv4-mapped space (it would match every IPv4 visitor through a dual-stack listener) --
        // but IPv6 loopback, a proxy on the same host, is fine.
        ->and(TrustedProxies::fromEnv('::ffff:0:0/96,::ffff:0.0.0.0/96,::/96,::/16,0::0/16,::ffff:10.0.0.1'))->toBe([])
        ->and(TrustedProxies::fromEnv('::1,2606:4700::/32,2a06:98c0::/29'))->toBe(['::1', '2606:4700::/32', '2a06:98c0::/29'])
        ->and(TrustedProxies::ignored(' 10.0.0.5, *, ::ffff:0:0/96 '))->toBe(['*', '::ffff:0:0/96'])
        ->and(TrustedProxies::fromEnv(null))->toBe([])
        ->and(TrustedProxies::fromEnv('*,10.0.0.5'))->toBe(['10.0.0.5']);
});

test('behind a listed proxy, the visitor\'s own address and https are what the app sees', function () {
    tpxBoot($this, '10.9.0.0/16');
    expect(tpxSeen($this, '10.9.4.2'))->toBe(['ip' => '203.0.113.9', 'secure' => true]);

    // Someone else sending the same headers is not believed. (Booted again: the test client builds its next
    // request from the last one, which was https.)
    tpxBoot($this, '10.9.0.0/16');
    expect(tpxSeen($this, '198.51.100.7'))->toBe(['ip' => '198.51.100.7', 'secure' => false]);
});

test('with none listed, or only a wildcard, forwarded headers are ignored', function () {
    // PRIVATE_SUBNETS and REMOTE_ADDR are shortcuts Laravel would honour (10.9.4.2 is private, and connecting):
    // dropping them is what keeps these requests from being believed.
    foreach ([null, '*', 'REMOTE_ADDR', 'PRIVATE_SUBNETS'] as $value) {
        tpxBoot($this, $value);

        expect(tpxSeen($this, '10.9.4.2'))->toBe(['ip' => '10.9.4.2', 'secure' => false], 'TRUSTED_PROXIES='.var_export($value, true));
    }

    // An IPv4-mapped range, from a visitor seen through a dual-stack listener.
    tpxBoot($this, '::ffff:0:0/96');
    expect(tpxSeen($this, '::ffff:198.51.100.7'))->toBe(['ip' => '::ffff:198.51.100.7', 'secure' => false]);
});
