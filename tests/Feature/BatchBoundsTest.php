<?php

use AltDesign\Altimiser\Applying\AiTemplateEditor;
use AltDesign\Altimiser\Applying\ChangeDispatcher;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Applying\ImageDimensions;
use AltDesign\Altimiser\Applying\TemplateApplier;
use AltDesign\Altimiser\Applying\TemplateLocator;
use AltDesign\Altimiser\Applying\TemplatePatcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-batch';
    $this->views = base_path($this->relative);

    File::deleteDirectory($this->views);
    File::makeDirectory($this->views, recursive: true);
    File::put($this->views.'/page.antlers.html', '<section><img src="/a.svg" alt="a"><p>x</p></section>');

    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.template_checks', ['image.not_lazy_loaded']);
    config()->set('altimiser.ai.enabled', false);
    config()->set('altimiser.git.enabled', false);
});

afterEach(function () {
    File::deleteDirectory($this->views);
});

function lazyLoadChange(int $index, string $match = '/a.svg'): ChangeRequest
{
    return new ChangeRequest(
        id: "change-{$index}",
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/about',
        target: ['selector' => "img[src=\"{$match}\"]", 'attribute' => 'loading', 'match' => $match],
        currentValue: null,
        suggestedValue: 'lazy',
    );
}

function dispatchAll(int $count, ?int $limit = null): array
{
    $dispatcher = new ChangeDispatcher([app(TemplateApplier::class)]);

    return $dispatcher->dispatch(
        array_map(fn (int $index): ChangeRequest => lazyLoadChange($index), range(1, $count)),
        dryRun: false,
        limit: $limit,
    );
}

it('attempts only up to the batch size and defers the rest', function () {
    $results = collect(dispatchAll(10, limit: 3));

    expect($results)->toHaveCount(10)
        ->and($results->where('status', 'deferred'))->toHaveCount(7)
        ->and($results->whereNotIn('status', ['deferred']))->toHaveCount(3);
});

it('echoes back the id of every deferred change so nothing is lost', function () {
    $results = collect(dispatchAll(5, limit: 2));

    expect($results->pluck('id')->sort()->values()->all())
        ->toBe(['change-1', 'change-2', 'change-3', 'change-4', 'change-5']);
});

it('defers nothing when the batch fits', function () {
    expect(collect(dispatchAll(2, limit: 10))->where('status', 'deferred'))->toBeEmpty();
});

it('uses the configured batch size by default', function () {
    config()->set('altimiser.batch_size', 2);

    expect(collect(dispatchAll(6))->where('status', 'deferred'))->toHaveCount(4);
});

it('resolves the whole slice with one round of concurrent model calls', function () {
    config()->set('altimiser.ai.enabled', true);
    config()->set('altimiser.ai.key', 'test-key');
    config()->set('altimiser.ai.concurrency', 10);
    config()->set('altimiser.ai.prompt', 'Edit it.');
    config()->set('altimiser.ai.endpoint', 'https://api.openai.com/v1/chat/completions');

    // Nothing in the templates matches, so every change needs the model.
    File::put($this->views.'/page.antlers.html', '<section><img src="{{ hero }}" alt="a"><p>x</p></section>');

    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'applicable' => false,
            'file' => '',
            'start_line' => 0,
            'end_line' => 0,
            'new_text' => '',
            'reasoning' => 'Declining in this test.',
        ])]]],
    ])]);

    $locator = Mockery::mock(TemplateLocator::class)->makePartial();
    $locator->shouldReceive('containing')->andReturn([]);
    $locator->shouldReceive('forUrl')->andReturn(['resources/views/page.antlers.html' => '<img src="{{ hero }}">']);

    $applier = new TemplateApplier(
        new TemplatePatcher,
        $locator,
        app(AiTemplateEditor::class),
        app(GitRepository::class),
        app(ImageDimensions::class),
    );

    (new ChangeDispatcher([$applier]))->dispatch(
        // Four genuinely different elements, so there are four questions to ask.
        array_map(fn (int $index): ChangeRequest => lazyLoadChange($index, "/image-{$index}.svg"), range(1, 4)),
        dryRun: false,
        limit: 4,
    );

    // Four changes, four model calls, all issued inside a single pooled round.
    Http::assertSentCount(4);
});

it('asks once for changes that would ask the same question', function () {
    config()->set('altimiser.ai.enabled', true);
    config()->set('altimiser.ai.key', 'test-key');
    config()->set('altimiser.ai.prompt', 'Edit it.');
    config()->set('altimiser.ai.endpoint', 'https://api.openai.com/v1/chat/completions');

    File::put($this->views.'/page.antlers.html', '<section><img src="{{ hero }}" alt="a"><p>x</p></section>');

    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['content' => json_encode([
            'applicable' => false,
            'file' => '',
            'start_line' => 0,
            'end_line' => 0,
            'new_text' => '',
            'reasoning' => 'Declining in this test.',
        ])]]],
    ])]);

    $locator = Mockery::mock(TemplateLocator::class)->makePartial();
    $locator->shouldReceive('containing')->andReturn([]);
    $locator->shouldReceive('forUrl')->andReturn(['resources/views/page.antlers.html' => '<img src="{{ hero }}">']);

    $results = (new ChangeDispatcher([new TemplateApplier(
        new TemplatePatcher,
        $locator,
        app(AiTemplateEditor::class),
        app(GitRepository::class),
        app(ImageDimensions::class),
    )]))->dispatch(
        // The same element on the same templates, arriving six times because six
        // pages render it. One question, six answers.
        array_map(fn (int $index): ChangeRequest => lazyLoadChange($index), range(1, 6)),
        dryRun: false,
        limit: 6,
    );

    Http::assertSentCount(1);

    // Every change still gets its own answer, rather than five of them going
    // missing because they shared a call.
    expect(collect($results))->toHaveCount(6)
        ->and(collect($results)->pluck('reason')->unique()->all())->toBe(['no_match']);
});

it('stops starting new changes once the time budget is spent', function () {
    // Zero seconds means every change after the first is over budget, which is
    // the shape of a slow batch without having to make the test wait for one.
    config()->set('altimiser.time_budget', 0);

    $results = collect(dispatchAll(5, limit: 5));

    expect($results->where('status', 'deferred'))->toHaveCount(4)
        ->and($results->whereNotIn('status', ['deferred']))->toHaveCount(1);
});

it('always attempts at least one change so a batch cannot stall', function () {
    config()->set('altimiser.time_budget', 0);

    expect(collect(dispatchAll(1, limit: 1))->where('status', 'deferred'))->toBeEmpty();
});

it('counts the time spent resolving against the budget', function () {
    config()->set('altimiser.ai.enabled', true);
    config()->set('altimiser.ai.key', 'test-key');
    config()->set('altimiser.ai.prompt', 'Edit it.');
    config()->set('altimiser.ai.endpoint', 'https://api.openai.com/v1/chat/completions');
    config()->set('altimiser.time_budget', 0.05);

    File::put($this->views.'/page.antlers.html', '<section><img src="{{ hero }}" alt="a"></section>');

    // A slow resolve, which is the normal case: the model calls are far and away
    // the longest part of a request. The budget has to see them, or a request
    // spends its whole allowance before the clock has even started.
    Http::fake(function () {
        usleep(120_000);

        return Http::response([
            'choices' => [['message' => ['content' => json_encode([
                'applicable' => false,
                'file' => '',
                'start_line' => 0,
                'end_line' => 0,
                'new_text' => '',
                'reasoning' => 'Declining in this test.',
            ])]]],
        ]);
    });

    $locator = Mockery::mock(TemplateLocator::class)->makePartial();
    $locator->shouldReceive('containing')->andReturn([]);
    $locator->shouldReceive('forUrl')->andReturn(['resources/views/page.antlers.html' => '<img src="{{ hero }}">']);

    $results = collect((new ChangeDispatcher([new TemplateApplier(
        new TemplatePatcher,
        $locator,
        app(AiTemplateEditor::class),
        app(GitRepository::class),
        app(ImageDimensions::class),
    )]))->dispatch(
        array_map(fn (int $index): ChangeRequest => lazyLoadChange($index, "/image-{$index}.svg"), range(1, 4)),
        dryRun: false,
        limit: 4,
    ));

    // One attempted so the batch always moves, the rest handed back honestly
    // rather than the request running on until the web server kills it.
    expect($results->where('status', 'deferred'))->toHaveCount(3);
});
