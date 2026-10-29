<?php

use AltDesign\Altimiser\Applying\AssetApplier;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\TemplateApplier;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Asset;

beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-alt-views';

    File::deleteDirectory(base_path($this->relative));
    File::makeDirectory(base_path($this->relative), recursive: true);

    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.git.enabled', false);

    Asset::swap(Mockery::mock(['findByUrl' => null]));
});

afterEach(function () {
    File::deleteDirectory(base_path($this->relative));
});

function footerTemplate(string $html): string
{
    $path = base_path('tests/tmp/altimiser-alt-views/footer.antlers.html');

    File::put($path, $html);

    return $path;
}

/** @param array<string, mixed> $target */
function altTextChange(string $suggested, ?string $current = '', string $check = 'image.alt_empty', array $target = []): ChangeRequest
{
    return new ChangeRequest(
        id: 'alt',
        check: $check,
        url: 'https://example.com/about',
        target: ['match' => '/img/footer-logo.svg', ...$target],
        currentValue: $current,
        suggestedValue: $suggested,
    );
}

it('writes alt text for an image outside the asset library into the template that writes it out', function () {
    $path = footerTemplate('<a href="/"><img src="/img/footer-logo.svg" alt="" class="w-[110px]"></a>');

    $result = app(AssetApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['type'])->toBe('template')
        ->and(File::get($path))->toBe('<a href="/"><img src="/img/footer-logo.svg" alt="Adams &amp; Moore home" class="w-[110px]"></a>');
});

it('picks out the reported image by its class where other templates write out the same file', function () {
    $overlay = '<div class="overlay"><img src="/img/footer-logo.svg" alt="" class="w-36 mx-auto"></div>';
    File::put(base_path($this->relative.'/layout.antlers.html'), $overlay);
    File::put(base_path($this->relative.'/partner.antlers.html'), $overlay);
    $footer = footerTemplate('<a href="/"><img src="/img/footer-logo.svg" alt="" class="w-[110px] lg:w-auto" loading="lazy"></a>');

    $linked = app(TemplateApplier::class)->apply(
        altTextChange('Adams & Moore home', target: ['context' => '<img src="/img/footer-logo.svg" alt="" class="w-[110px] lg:w-auto" loading="lazy">']),
        dryRun: false,
    )->toArray();
    $copied = app(TemplateApplier::class)->apply(
        altTextChange('Adams & Moore', target: ['context' => '<img src="/img/footer-logo.svg" alt="" class="w-36 mx-auto">']),
        dryRun: false,
    )->toArray();

    expect($linked['location']['file'])->toBe($this->relative.'/footer.antlers.html')
        ->and(File::get($footer))->toContain('alt="Adams &amp; Moore home" class="w-[110px] lg:w-auto"')
        ->and($copied['reason'])->toBe('ambiguous')
        ->and(File::get(base_path($this->relative.'/layout.antlers.html')))->toBe($overlay);
});

it('says there is neither an asset nor a template to edit when no template writes the tag out', function () {
    footerTemplate('<img src="{{ logo }}" alt="">');

    $result = app(AssetApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_match')
        ->and($result['message'])->toContain('No asset in this site matches /img/footer-logo.svg');
});

it('leaves templates alone when template patching is turned off', function () {
    config()->set('altimiser.patch_templates', false);
    $path = footerTemplate('<img src="/img/footer-logo.svg" alt="">');

    $result = app(AssetApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false)->toArray();

    expect($result['reason'])->toBe('no_match')
        ->and(File::get($path))->toBe('<img src="/img/footer-logo.svg" alt="">');
});

it('adds alt text to a tag that has none', function () {
    $path = footerTemplate('<img src="/img/footer-logo.svg" />');

    app(TemplateApplier::class)->apply(altTextChange('Adams & Moore home', null, 'image.alt_missing'), dryRun: false);

    expect(File::get($path))->toBe('<img src="/img/footer-logo.svg" alt="Adams &amp; Moore home" />');
});

it('replaces overlong alt text the scan saw', function () {
    $path = footerTemplate('<img src="/img/footer-logo.svg" alt="A very long description of the logo">');

    app(TemplateApplier::class)->apply(altTextChange('Adams & Moore home', 'A very long description of the logo', 'image.alt_too_long'), dryRun: false);

    expect(File::get($path))->toBe('<img src="/img/footer-logo.svg" alt="Adams &amp; Moore home">');
});

it('escapes alt text so it cannot break out of the attribute', function () {
    $path = footerTemplate('<img src="/img/footer-logo.svg" alt="">');

    app(TemplateApplier::class)->apply(altTextChange('A "Best firm" award, worth $10 or \1'), dryRun: false);

    expect(File::get($path))->toBe('<img src="/img/footer-logo.svg" alt="A &quot;Best firm&quot; award, worth $10 or \1">');
});

it('reports the same image on another page as already done', function () {
    footerTemplate('<img src="/img/footer-logo.svg" alt="">');

    app(TemplateApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false);
    $second = app(TemplateApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false)->toArray();

    expect($second['status'])->toBe('already_applied');
});

it('never places alt text by a class shared with other images', function () {
    $path = footerTemplate('<img src="{{ card:url }}" class="card-image" alt="">');

    $result = app(TemplateApplier::class)->apply(
        altTextChange('Adams & Moore home', target: ['context' => '<img src="/img/footer-logo.svg" class="card-image" alt="">']),
        dryRun: false,
    )->toArray();

    expect($result['reason'])->toBe('no_match')
        ->and(File::get($path))->toBe('<img src="{{ card:url }}" class="card-image" alt="">');
});

it('refuses alt text containing template syntax', function () {
    $path = footerTemplate('<img src="/img/footer-logo.svg" alt="">');

    $result = app(TemplateApplier::class)->apply(altTextChange('Our logo {{ site:name }}'), dryRun: false)->toArray();

    expect($result['status'])->toBe('failed')
        ->and($result['reason'])->toBe('invalid_change')
        ->and(File::get($path))->toBe('<img src="/img/footer-logo.svg" alt="">');
});

it('leaves alt text an editor has changed since the scan', function () {
    $path = footerTemplate('<img src="/img/footer-logo.svg" alt="Our logo">');

    $result = app(TemplateApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false)->toArray();

    expect($result['reason'])->toBe('value_changed')
        ->and(File::get($path))->toBe('<img src="/img/footer-logo.svg" alt="Our logo">');
});

it('never writes over alt text the template works out for itself', function () {
    $path = footerTemplate('<img src="/img/footer-logo.svg" alt="{{ logo_alt }}">');

    $result = app(TemplateApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false)->toArray();

    expect($result['reason'])->toBe('no_match')
        ->and(File::get($path))->toBe('<img src="/img/footer-logo.svg" alt="{{ logo_alt }}">');
});

it('only writes alt text on an img tag', function () {
    $path = footerTemplate('<link rel="preload" href="/img/footer-logo.svg" as="image">');

    $result = app(TemplateApplier::class)->apply(altTextChange('Adams & Moore home'), dryRun: false)->toArray();

    expect($result['reason'])->toBe('no_match')
        ->and(File::get($path))->toBe('<link rel="preload" href="/img/footer-logo.svg" as="image">');
});
