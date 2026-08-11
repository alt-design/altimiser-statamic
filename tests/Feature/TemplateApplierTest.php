<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\TemplateApplier;

beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-views';
    $this->views = base_path($this->relative);

    File::deleteDirectory($this->views);
    File::makeDirectory($this->views, recursive: true);

    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.template_checks', ['image.not_lazy_loaded', 'image.lazy_above_fold']);

    config()->set('altimiser.ai.enabled', false);
    config()->set('altimiser.git.enabled', false);

    $this->applier = app(TemplateApplier::class);
});

afterEach(function () {
    File::deleteDirectory($this->views);
});

function template(string $name, string $contents): string
{
    $path = base_path('tests/tmp/altimiser-views/'.$name);

    File::put($path, $contents);

    return $path;
}

function lazyChange(string $match = '/hero.jpg'): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/',
        target: ['match' => $match],
        currentValue: null,
        suggestedValue: 'lazy',
    );
}

it('writes the patched template to disk', function () {
    $path = template('page.antlers.html', '<img src="/hero.jpg" alt="Hero">');

    $result = $this->applier->apply(lazyChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['file'])->toBe($this->relative.'/page.antlers.html')
        ->and(File::get($path))->toBe('<img src="/hero.jpg" alt="Hero" loading="lazy">');
});

it('leaves the file alone on a dry run', function () {
    $path = template('page.antlers.html', '<img src="/hero.jpg" alt="Hero">');

    $result = $this->applier->apply(lazyChange(), dryRun: true)->toArray();

    expect($result['status'])->toBe('applied')
        ->and(File::get($path))->toBe('<img src="/hero.jpg" alt="Hero">');
});

it('refuses when two templates contain the same element', function () {
    template('one.antlers.html', '<img src="/hero.jpg">');
    template('two.antlers.html', '<img src="/hero.jpg">');

    $result = $this->applier->apply(lazyChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('ambiguous')
        ->and($result['message'])->toContain('one.antlers.html');
});

it('says so when the image comes from a variable and there is no model to ask', function () {
    template('page.antlers.html', '<img src="{{ hero:url }}" alt="{{ hero:alt }}">');

    $result = $this->applier->apply(lazyChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_match')
        ->and($result['message'])->toContain('AI editing is not enabled');
});

it('finds blade templates as well as antlers', function () {
    template('page.blade.php', '<img src="/hero.jpg" alt="Hero">');

    expect($this->applier->apply(lazyChange(), dryRun: false)->toArray()['status'])->toBe('applied');
});

it('declines every template check when patching is turned off', function () {
    config()->set('altimiser.patch_templates', false);

    expect($this->applier->supports('image.not_lazy_loaded'))->toBeFalse();
});

it('only claims checks it is configured for', function () {
    expect($this->applier->supports('image.not_lazy_loaded'))->toBeTrue()
        ->and($this->applier->supports('meta_description.too_short'))->toBeFalse();
});

it('finds the tag by its class when the url is a transform that no template contains', function () {
    // What a content-driven site actually renders: the src is assembled at
    // request time, so the one string we would search for is the one string
    // guaranteed not to be in the source.
    $path = template('tile.antlers.html', '<img src="{{ image | glide:width=400 }}" class="object-cover w-full rounded-xl">');

    $change = new ChangeRequest(
        id: 'abc',
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/about',
        target: [
            'selector' => 'img',
            'attribute' => 'loading',
            'match' => '/cached-img/containers/assets/tile.jpg/8a2c/tile.webp',
            'context' => '<img src="/cached-img/containers/assets/tile.jpg/8a2c/tile.webp" class="object-cover w-full rounded-xl">',
        ],
        currentValue: null,
        suggestedValue: 'lazy',
    );

    $result = $this->applier->apply($change, dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['resolved_by'])->toBe('class')
        ->and(File::get($path))->toContain('loading="lazy"');
});

it('leaves a class shared by several templates to the model rather than guessing', function () {
    template('one.antlers.html', '<img src="{{ image }}" class="w-full object-cover">');
    template('two.antlers.html', '<img src="{{ other }}" class="w-full object-cover">');

    $change = new ChangeRequest(
        id: 'abc',
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/about',
        target: [
            'attribute' => 'loading',
            'match' => '/cached-img/containers/assets/tile.jpg/8a2c/tile.webp',
            'context' => '<img src="/cached-img/containers/assets/tile.jpg/8a2c/tile.webp" class="w-full object-cover">',
        ],
        currentValue: null,
        suggestedValue: 'lazy',
    );

    $result = $this->applier->apply($change, dryRun: false)->toArray();

    // No model configured in this test, so it says so rather than picking one.
    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_match')
        ->and(File::get(base_path($this->relative.'/one.antlers.html')))->not->toContain('loading=');
});

it('does not patch the same template twice within one batch', function () {
    $path = template('tile.antlers.html', '<img src="{{ image }}" class="object-cover w-full">');

    $context = '<img src="/cached-img/containers/assets/a.jpg/1/a.webp" class="object-cover w-full">';
    $make = fn (string $id, string $match) => new ChangeRequest(
        id: $id,
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/about',
        target: ['attribute' => 'loading', 'match' => $match, 'context' => $context],
        currentValue: null,
        suggestedValue: 'lazy',
    );

    $first = $this->applier->apply($make('one', '/cached-img/containers/assets/a.jpg/1/a.webp'), dryRun: false)->toArray();
    $second = $this->applier->apply($make('two', '/cached-img/containers/assets/b.jpg/2/b.webp'), dryRun: false)->toArray();

    expect($first['status'])->toBe('applied')
        ->and($second['status'])->toBe('already_applied')
        ->and(substr_count(File::get($path), 'loading='))->toBe(1);
});
