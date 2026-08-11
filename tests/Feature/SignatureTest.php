<?php

use AltDesign\Altimiser\Http\Middleware\VerifySignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The signature as the protocol defines it, computed here rather than imported
 * from the central service. That is the point: a receiver has to be verifiable
 * against the written contract, not against one particular implementation of
 * the other end.
 *
 * @return array<string, string>
 */
function protocolHeaders(string $body, string $secret = 'shared-secret', ?int $timestamp = null): array
{
    $timestamp ??= time();

    return [
        'X-Altimiser-Timestamp' => (string) $timestamp,
        'X-Altimiser-Signature' => 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$body}", $secret),
    ];
}

function signedRequest(string $body, array $headerOverrides = [], string $secret = 'shared-secret'): Request
{
    $headers = [...protocolHeaders($body, $secret), ...$headerOverrides];

    $request = Request::create('/!/altimiser/changes', 'POST', content: $body);

    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }

    return $request;
}

function passesVerification(Request $request): bool
{
    config()->set('altimiser.secret', 'shared-secret');

    $response = (new VerifySignature)->handle($request, fn (): Response => response('ok'));

    return $response->getStatusCode() === 200;
}

it('accepts a request signed by the central service', function () {
    $body = json_encode(['changes' => [['id' => 'abc']]]);

    expect(passesVerification(signedRequest($body)))->toBeTrue();
});

it('accepts a signed request with an empty body', function () {
    expect(passesVerification(signedRequest('')))->toBeTrue();
});

it('rejects a body that was altered after signing', function () {
    $request = signedRequest(json_encode(['changes' => []]));
    $tampered = Request::create('/!/altimiser/changes', 'POST', content: json_encode(['changes' => ['injected']]));
    $tampered->headers->replace($request->headers->all());

    expect(passesVerification($tampered))->toBeFalse();
});

it('rejects a signature made with the wrong secret', function () {
    expect(passesVerification(signedRequest('{}', secret: 'not-the-secret')))->toBeFalse();
});

it('rejects a replayed request outside the time window', function () {
    $body = '{}';
    $stale = protocolHeaders($body, 'shared-secret', time() - 600);

    $request = Request::create('/!/altimiser/changes', 'POST', content: $body);
    $request->headers->add($stale);

    expect(passesVerification($request))->toBeFalse();
});

it('accepts a request at the edge of the time window', function () {
    $body = '{}';
    $recent = protocolHeaders($body, 'shared-secret', time() - 290);

    $request = Request::create('/!/altimiser/changes', 'POST', content: $body);
    $request->headers->add($recent);

    expect(passesVerification($request))->toBeTrue();
});

it('rejects an unsigned request', function () {
    $request = Request::create('/!/altimiser/changes', 'POST', content: '{}');

    expect(passesVerification($request))->toBeFalse();
});

it('refuses every request when no secret is configured on the site', function () {
    config()->set('altimiser.secret', null);

    $response = (new VerifySignature)->handle(
        signedRequest('{}'),
        fn (): Response => response('ok'),
    );

    expect($response->getStatusCode())->toBe(401);
});
