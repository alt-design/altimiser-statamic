<?php

use AltDesign\Altimiser\Applying\ChangeDispatcher;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\TemplateApplier;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-batch';
    $this->views = base_path($this->relative);

    File::deleteDirectory($this->views);
    File::makeDirectory($this->views, recursive: true);
    File::put($this->views.'/page.antlers.html', '<section><img src="/a.svg" alt="a"><p>x</p></section>');

    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.template_checks', ['image.not_lazy_loaded']);
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
