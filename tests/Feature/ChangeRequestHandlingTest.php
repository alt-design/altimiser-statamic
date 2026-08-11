<?php

use AltDesign\Altimiser\Applying\ChangeDispatcher;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\TemplateApplier;
use AltDesign\Altimiser\Http\Middleware\VerifySignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * The receiver's half of the protocol.
 *
 * A protocol-shaped payload, signed the way the document describes, travels
 * through verification and dispatch and lands as a real edit to a real file.
 * Nothing here imports the central service: what is being proved is that this
 * receiver honours the written contract, which is the same thing a receiver in
 * another language would have to prove.
 *
 * The central service's own suite covers the other half, that it emits exactly
 * this shape and records each answer correctly.
 */
beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-protocol';
    $this->views = base_path($this->relative);

    File::deleteDirectory($this->views);
    File::makeDirectory($this->views, recursive: true);

    config()->set('altimiser.secret', 'shared-secret');
    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.template_checks', ['image.not_lazy_loaded']);
    config()->set('altimiser.ai.enabled', false);
    config()->set('altimiser.git.enabled', false);
});

afterEach(function () {
    File::deleteDirectory($this->views);
});

/**
 * Everything the protocol says a request carries, and nothing this receiver
 * knows about the sender.
 */
function protocolPayload(array $overrides = []): array
{
    return [
        'dry_run' => false,
        'changes' => [[
            'id' => 'fingerprint-abc',
            'check' => 'image.not_lazy_loaded',
            'url' => 'https://example.com/',
            'target' => ['selector' => 'img[src="/hero.jpg"]', 'attribute' => 'loading', 'match' => '/hero.jpg'],
            'current_value' => null,
            'suggested_value' => 'lazy',
        ]],
        ...$overrides,
    ];
}

/** @return array<int, array<string, mixed>> the results the sender would receive */
function handleSigned(array $payload, string $secret = 'shared-secret'): array
{
    $body = json_encode($payload);
    $timestamp = time();

    $request = Request::create('/!/altimiser/changes', 'POST', content: $body);
    $request->headers->set('X-Altimiser-Timestamp', (string) $timestamp);
    $request->headers->set('X-Altimiser-Signature', 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$body}", $secret));

    $response = (new VerifySignature)->handle($request, function (Request $verified) {
        $payload = $verified->json()->all();

        return response()->json([
            'results' => (new ChangeDispatcher([app(TemplateApplier::class)]))->dispatch(
                array_map(ChangeRequest::fromArray(...), $payload['changes']),
                $payload['dry_run'] ?? false,
            ),
        ]);
    });

    return json_decode($response->getContent(), true)['results'] ?? [];
}

it('carries a signed change into a real template file', function () {
    $path = $this->views.'/layout.antlers.html';
    File::put($path, '<img src="/hero.jpg" alt="Hero">');

    $results = handleSigned(protocolPayload());

    expect($results[0]['status'])->toBe('applied')
        ->and($results[0]['id'])->toBe('fingerprint-abc')
        ->and($results[0]['location']['type'])->toBe('template')
        ->and(File::get($path))->toBe('<img src="/hero.jpg" alt="Hero" loading="lazy">');
});

it('answers every change it was sent, keyed by the id it was given', function () {
    File::put($this->views.'/layout.antlers.html', '<img src="/hero.jpg" alt="Hero">');

    $results = handleSigned(protocolPayload());

    // The sender matches results back by this id and nothing else, so an answer
    // without one is an answer that goes nowhere.
    expect($results)->toHaveCount(1)
        ->and($results[0]['id'])->toBe('fingerprint-abc');
});

it('writes nothing on a dry run but still reports what would happen', function () {
    $path = $this->views.'/layout.antlers.html';
    File::put($path, '<img src="/hero.jpg" alt="Hero">');

    $results = handleSigned(protocolPayload(['dry_run' => true]));

    expect($results[0]['status'])->toBe('applied')
        ->and(File::get($path))->toBe('<img src="/hero.jpg" alt="Hero">');
});

it('rejects the whole request when the two ends disagree on the secret', function () {
    File::put($this->views.'/layout.antlers.html', '<img src="/hero.jpg" alt="Hero">');

    expect(handleSigned(protocolPayload(), secret: 'not-the-secret'))->toBe([]);
    expect(File::get($this->views.'/layout.antlers.html'))->toBe('<img src="/hero.jpg" alt="Hero">');
});

it('reports an unsupported check rather than failing the batch', function () {
    File::put($this->views.'/layout.antlers.html', '<img src="/hero.jpg" alt="Hero">');

    $payload = protocolPayload();
    $payload['changes'][0]['check'] = 'something.we.do.not.do';

    $results = handleSigned($payload);

    expect($results[0]['status'])->toBe('skipped')
        ->and($results[0]['reason'])->toBe('unsupported_check');
});
