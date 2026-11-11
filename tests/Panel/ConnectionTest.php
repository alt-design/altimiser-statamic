<?php

use AltDesign\Altimiser\Altimiser;
use AltDesign\Altimiser\Connections\ConnectionStore;
use AltDesign\Altimiser\Http\Controllers\ConnectController;
use AltDesign\Altimiser\ServiceProvider;
use AltDesign\Altimiser\Tests\Panel\PanelTestCase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Role;
use Statamic\Facades\User;

uses(PanelTestCase::class);

const VECTOR_CONNECTION = '01jcz6r4s8x2m0v3q9k7t5w1yd';
const VECTOR_SITE_SECRET = 'shared-secret';
const VECTOR_EXPIRES = 1769558400;

function vectorToken(): string
{
    return str_repeat('ab', 32);
}

function editor(string $email = 'editor@example.com'): Statamic\Contracts\Auth\User
{
    $role = Role::make('connection-test')->permissions(['access cp', ServiceProvider::PERMISSION]);
    $role->save();

    $user = User::make()->email($email)->assignRole($role->handle());
    $user->save();

    return $user;
}

function configureAltimiser(): void
{
    config([
        'altimiser.url' => 'https://altimiser.example.com',
        'altimiser.secret' => VECTOR_SITE_SECRET,
        'altimiser.site_url' => 'https://example.com',
        'altimiser.link_minutes' => 30,
    ]);
}

function storeConnection(Statamic\Contracts\Auth\User $user, ?string $expiresAt = null): void
{
    app(ConnectionStore::class)->put((string) $user->id(), [
        'connection' => VECTOR_CONNECTION,
        'token' => vectorToken(),
        'expires_at' => $expiresAt ?? '2026-11-12T10:00:00+00:00',
        'account_email' => 'sam@agency.example',
    ]);
}

function startConnecting(): string
{
    test()->get(cp_route('altimiser.connect'))->assertRedirect();

    return session(ConnectController::STATE_KEY);
}

function callbackUrl(array $query): string
{
    return cp_route('altimiser.connect.callback').'?'.http_build_query($query);
}

function exchangeResponse(array $overrides = []): array
{
    return [...[
        'connection' => VECTOR_CONNECTION,
        'token' => vectorToken(),
        'expires_at' => now()->addHours(12)->toIso8601String(),
        'account' => ['name' => 'Sam Example', 'email' => 'sam@agency.example'],
    ], ...$overrides];
}

beforeEach(function () {
    configureAltimiser();
    File::delete(app(ConnectionStore::class)->path());
});

afterEach(function () {
    File::delete(app(ConnectionStore::class)->path());
});

it('signs the connect link exactly as the protocol document shows', function () {
    $this->travelTo(Carbon\Carbon::createFromTimestamp(VECTOR_EXPIRES - 300));
    $this->actingAs(editor());

    $url = app(Altimiser::class)->connectUrl(
        's7Kq2wX9pL4mN8vR3tY6uZ1aB5cD0eFg',
        'https://example.com/cp/altimiser/connect/callback',
    );

    expect($url)->toBe(
        'https://altimiser.example.com/connect?expires=1769558400&redirect_uri=https%3A%2F%2Fexample.com%2Fcp%2Faltimiser%2Fconnect%2Fcallback&site=https%3A%2F%2Fexample.com&state=s7Kq2wX9pL4mN8vR3tY6uZ1aB5cD0eFg&user=editor%40example.com'
        .'&signature=43e86c386c1c7fd4f94ffcd47fc0ea4ca48e14631ab991cf3f6d10bbd7cbc271'
    );
});

it('signs a review link with the site secret alone when there is no connection', function () {
    $this->travelTo(Carbon\Carbon::createFromTimestamp(VECTOR_EXPIRES - 1800));
    $this->actingAs(editor());

    $url = app(Altimiser::class)->reviewUrl(embedded: true);

    expect($url)->toBe(
        'https://altimiser.example.com/review?embedded=1&expires=1769558400&site=https%3A%2F%2Fexample.com&user=editor%40example.com'
        .'&signature=56424ced69568825952d43fb40de622e730610712898c7ecc35cd1b0d1dd2347'
    )->and($url)->not->toContain('connection');
});

it('adds the connection and both signatures to a review link as the protocol document shows', function () {
    $this->travelTo(Carbon\Carbon::createFromTimestamp(VECTOR_EXPIRES - 1800));
    $user = editor();
    storeConnection($user);
    $this->actingAs($user);

    $url = app(Altimiser::class)->reviewUrl(embedded: true);

    expect($url)->toBe(
        'https://altimiser.example.com/review?connection=01jcz6r4s8x2m0v3q9k7t5w1yd&embedded=1&expires=1769558400&site=https%3A%2F%2Fexample.com&user=editor%40example.com'
        .'&signature=d747d7461ab0bd482ca2c63dbfdba805272879161a55b85294b1bfbc254d670f'
        .'&connection_signature=05f7d157967aa7d78326438cc32082256bd12016a9f6eb3de2145b6e931d7cba'
    )->and($url)->not->toContain(vectorToken());
});

it('treats an expired connection as not connected', function () {
    $user = editor();
    storeConnection($user, now()->subMinute()->toIso8601String());
    $this->actingAs($user);

    expect(app(Altimiser::class)->reviewUrl())->not->toContain('connection')
        ->and(app(Altimiser::class)->currentConnection())->toBeNull();

    $this->get(cp_route('altimiser.index'))
        ->assertOk()
        ->assertSee('Connect your Altimiser account')
        ->assertDontSee('Connected as');
});

it('does not give one user the connection of another', function () {
    $other = editor('other@example.com');
    storeConnection($other);
    $someone = User::make()->email('someone@example.com')->makeSuper();
    $someone->save();
    $this->actingAs($someone);

    expect(app(Altimiser::class)->currentConnection())->toBeNull();
});

it('offers to connect, framed safely, with a line saying what it is for', function () {
    $this->actingAs(editor());

    $this->get(cp_route('altimiser.index'))
        ->assertOk()
        ->assertSee('Connect your Altimiser account')
        ->assertSee('target="_top"', false)
        ->assertSee('agency staff doing developer work')
        ->assertSee('Reviewing works without it');
});

it('shows who is connected and until when, without the token', function () {
    $user = editor();
    storeConnection($user, '2026-11-12T10:00:00+00:00');
    $this->actingAs($user);

    $this->get(cp_route('altimiser.index'))
        ->assertOk()
        ->assertSee('Connected as sam@agency.example until 12 Nov 2026')
        ->assertSee('Disconnect')
        ->assertDontSee(vectorToken());
});

it('sends the admin to a signed connect link with a long state kept in their session', function () {
    $this->actingAs(editor());

    $response = $this->get(cp_route('altimiser.connect'));
    $location = $response->headers->get('Location');
    $state = session(ConnectController::STATE_KEY);

    parse_str(parse_url($location, PHP_URL_QUERY), $query);
    $unsigned = substr($location, 0, strpos($location, '&signature='));

    expect(strlen($state))->toBeGreaterThanOrEqual(32)
        ->and($location)->toStartWith('https://altimiser.example.com/connect?')
        ->and($query['state'])->toBe($state)
        ->and($query['redirect_uri'])->toBe(cp_route('altimiser.connect.callback'))
        ->and($query['user'])->toBe('editor@example.com')
        ->and($query['site'])->toBe('https://example.com')
        ->and((int) $query['expires'])->toBeBetween(time() + 290, time() + 310)
        ->and($query['signature'])->toBe(hash_hmac('sha256', $unsigned, VECTOR_SITE_SECRET));
});

it('refuses a callback with no state, a wrong state or a reused state', function () {
    $this->actingAs(editor());

    $this->get(callbackUrl(['code' => 'abc']))->assertRedirect(cp_route('altimiser.index'))->assertSessionHas('error');

    $state = startConnecting();
    $this->get(callbackUrl(['code' => 'abc', 'state' => 'x'.$state]))->assertSessionHas('error');

    $state = startConnecting();
    Http::fake(['*/api/connections' => Http::response(exchangeResponse(), 201)]);
    $this->get(callbackUrl(['code' => 'abc', 'state' => $state]))->assertSessionHas('success');
    $this->get(callbackUrl(['code' => 'abc', 'state' => $state]))->assertSessionHas('error');

    Http::assertSentCount(1);
});

it('does not exchange anything for a callback whose state is wrong', function () {
    Http::fake();
    $this->actingAs(editor());
    startConnecting();

    $this->get(callbackUrl(['code' => 'abc', 'state' => 'wrong']))->assertSessionHas('error');

    Http::assertNothingSent();
    expect(app(Altimiser::class)->currentConnection())->toBeNull();
});

it('shows a friendly notice when the person declines', function () {
    Http::fake();
    $this->actingAs(editor());
    $state = startConnecting();

    $this->get(callbackUrl(['error' => 'access_denied', 'state' => $state]))
        ->assertRedirect(cp_route('altimiser.index'))
        ->assertSessionHas('error', 'You chose not to connect your Altimiser account.');

    Http::assertNothingSent();
});

it('exchanges the code with a request signed and shaped as the protocol document says', function () {
    Http::fake(['*/api/connections' => Http::response(exchangeResponse(), 201)]);
    $this->actingAs(editor());
    $state = startConnecting();

    $this->get(callbackUrl(['code' => 'the-code', 'state' => $state]))
        ->assertRedirect(cp_route('altimiser.index'))
        ->assertSessionHas('success');

    Http::assertSent(function ($request) {
        $timestamp = $request->header('X-Altimiser-Timestamp')[0];
        $body = $request->body();

        return $request->method() === 'POST'
            && $request->url() === 'https://altimiser.example.com/api/connections'
            && json_decode($body, true) === [
                'site' => 'https://example.com',
                'code' => 'the-code',
                'redirect_uri' => cp_route('altimiser.connect.callback'),
            ]
            && abs((int) $timestamp - time()) < 5
            && $request->header('X-Altimiser-Signature')[0] === 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$body}", VECTOR_SITE_SECRET)
            && $request->header('Accept')[0] === 'application/json'
            && str_starts_with($request->header('Content-Type')[0], 'application/json');
    });

    expect(app(Altimiser::class)->currentConnection())->toMatchArray([
        'connection' => VECTOR_CONNECTION,
        'token' => vectorToken(),
        'account_email' => 'sam@agency.example',
    ]);
});

it('reports a refused exchange without keeping anything', function () {
    Http::fake(['*/api/connections' => Http::response(['message' => 'That code has expired.'], 422)]);
    $this->actingAs(editor());
    $state = startConnecting();

    $this->get(callbackUrl(['code' => 'old', 'state' => $state]))
        ->assertSessionHas('error', 'Could not connect your Altimiser account: That code has expired.');

    expect(app(Altimiser::class)->currentConnection())->toBeNull();
});

it('keeps the connection encrypted, outside the paths a site commits', function () {
    Http::fake(['*/api/connections' => Http::response(exchangeResponse(), 201)]);
    $user = editor();
    $this->actingAs($user);
    $state = startConnecting();
    $this->get(callbackUrl(['code' => 'the-code', 'state' => $state]));

    $store = app(ConnectionStore::class);
    $contents = File::get($store->path());
    $entries = json_decode($contents, true);

    expect($store->path())->toStartWith(storage_path())
        ->and($store->path())->not->toContain(base_path('content'))
        ->and($store->path())->not->toContain(base_path('users'))
        ->and($store->path())->not->toStartWith($this->usersDirectory)
        ->and($contents)->not->toContain(vectorToken())
        ->and($contents)->not->toContain('sam@agency.example')
        ->and(json_decode(Crypt::decryptString($entries[$user->id()]), true)['token'])->toBe(vectorToken());
});

it('forgets the connection on disconnect and offers to connect again', function () {
    $user = editor();
    storeConnection($user);
    $this->actingAs($user);

    $this->post(cp_route('altimiser.disconnect'))
        ->assertRedirect(cp_route('altimiser.index'))
        ->assertSessionHas('success');

    expect(app(Altimiser::class)->currentConnection())->toBeNull()
        ->and(File::get(app(ConnectionStore::class)->path()))->not->toContain((string) $user->id());

    Http::fake();
    $this->get(cp_route('altimiser.index'))->assertSee('Connect your Altimiser account');
});

it('keeps the connect routes behind the permission', function () {
    $role = Role::make('no-altimiser')->permissions(['access cp']);
    $role->save();
    $user = User::make()->email('plain@example.com')->assignRole($role->handle());
    $user->save();
    $this->actingAs($user);

    $this->getJson(cp_route('altimiser.connect'))->assertForbidden();
    $this->getJson(cp_route('altimiser.connect.callback'))->assertForbidden();
    $this->postJson(cp_route('altimiser.disconnect'))->assertForbidden();
});
