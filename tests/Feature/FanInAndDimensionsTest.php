<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\ImageDimensions;
use AltDesign\Altimiser\Applying\TemplateApplier;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-fanin';
    $this->views = base_path($this->relative);

    File::deleteDirectory($this->views);
    File::makeDirectory($this->views, recursive: true);

    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.template_checks', ['image.not_lazy_loaded', 'image.dimensions_missing']);
    config()->set('altimiser.ai.enabled', false);
    config()->set('altimiser.git.enabled', false);
});

afterEach(function () {
    File::deleteDirectory($this->views);
});

function lazyFor(string $match, string $id = 'abc'): ChangeRequest
{
    return new ChangeRequest(
        id: $id,
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/about',
        target: ['selector' => "img[src=\"{$match}\"]", 'attribute' => 'loading', 'match' => $match],
        currentValue: null,
        suggestedValue: 'lazy',
    );
}

it('reports the second change to the same template as already applied', function () {
    File::put($this->views.'/partial.antlers.html', '<section><img src="/logo.svg" alt="Logo"><p>x</p></section>');

    $applier = app(TemplateApplier::class);

    $first = $applier->apply(lazyFor('/logo.svg', 'one'), dryRun: false)->toArray();
    $second = $applier->apply(lazyFor('/logo.svg', 'two'), dryRun: false)->toArray();

    expect($first['status'])->toBe('applied')
        ->and($second['status'])->toBe('already_applied')
        ->and($second['id'])->toBe('two')
        ->and($second['message'])->toContain('already has this fix')
        ->and(substr_count(File::get($this->views.'/partial.antlers.html'), 'loading='))->toBe(1);
});

it('reports already applied on a later run too, not just within a batch', function () {
    File::put($this->views.'/partial.antlers.html', '<section><img src="/logo.svg" loading="lazy" alt="Logo"><p>x</p></section>');

    $result = app(TemplateApplier::class)->apply(lazyFor('/logo.svg'), dryRun: false)->toArray();

    expect($result['status'])->toBe('already_applied');
});

it('does not call an unsupported check already applied', function () {
    File::put($this->views.'/partial.antlers.html', '<section><img src="/logo.svg" alt="Logo"><p>x</p></section>');

    $unsupported = new ChangeRequest(
        id: 'abc',
        check: 'image.alt_too_long',
        url: 'https://example.com/about',
        target: ['selector' => 'img[src="/logo.svg"]', 'attribute' => 'alt', 'match' => '/logo.svg'],
        currentValue: 'Logo',
        suggestedValue: 'Shorter',
    );

    expect(app(TemplateApplier::class)->apply($unsupported, dryRun: false)->toArray()['status'])
        ->not->toBe('already_applied');
});

function dimensionChange(string $match): ChangeRequest
{
    return new ChangeRequest(
        id: 'dim',
        check: 'image.dimensions_missing',
        url: 'https://example.com/about',
        target: ['selector' => "img[src=\"{$match}\"]", 'attribute' => 'width', 'match' => $match],
        currentValue: null,
        suggestedValue: null,
    );
}

it('reads real dimensions off disk and writes both attributes', function () {
    $image = imagecreatetruecolor(320, 180);
    File::makeDirectory(public_path('altimiser-test'), recursive: true);
    imagepng($image, public_path('altimiser-test/box.png'));
    imagedestroy($image);

    File::put($this->views.'/partial.antlers.html', '<section><img src="/altimiser-test/box.png" alt="Box"><p>x</p></section>');

    $result = app(TemplateApplier::class)->apply(dimensionChange('/altimiser-test/box.png'), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and(File::get($this->views.'/partial.antlers.html'))
        ->toContain('width="320"')
        ->toContain('height="180"');

    File::deleteDirectory(public_path('altimiser-test'));
});

it('refuses to bake a glide transform size into a template', function () {
    expect(app(ImageDimensions::class)->for('/cached-img/containers/assets/a.png/c9cb515e5d8d1180f8cd46cd8720274a/a.webp'))
        ->toBeNull();
});

it('will not read outside the public directory', function () {
    expect(app(ImageDimensions::class)->for('/../.env'))->toBeNull()
        ->and(app(ImageDimensions::class)->for('https://elsewhere.test/a.png'))->toBeNull();
});

it('skips a dimensions change it cannot compute and has no model for', function () {
    File::put($this->views.'/partial.antlers.html', '<section><img src="/missing.png" alt="x"><p>y</p></section>');

    $result = app(TemplateApplier::class)->apply(dimensionChange('/missing.png'), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and(File::get($this->views.'/partial.antlers.html'))->not->toContain('width=');
});
