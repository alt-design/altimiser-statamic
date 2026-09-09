<?php

use AltDesign\Altimiser\AdvertisedChecks;
use AltDesign\Altimiser\Altimiser;
use AltDesign\Altimiser\Integrations\AltSeo;
use Composer\InstalledVersions;

/** An Alt SEO whose answers we choose, since the test harness has none installed. */
function altSeoReporting(bool $installed, bool $schemaEnabled, ?string $version = null): AltSeo
{
    $altSeo = Mockery::mock(AltSeo::class)->makePartial();
    $altSeo->shouldReceive('installed')->andReturn($installed);
    $altSeo->shouldReceive('schemaEnabled')->andReturn($schemaEnabled);
    $altSeo->shouldReceive('version')->andReturn($version);

    return $altSeo;
}

it('does not offer structured data when there is nowhere to write it', function () {
    // Advertising a check we cannot honour is worse than not offering it: the
    // central service would spend a model call per collection and then refuse
    // every one of them at the last moment.
    $checks = (new AdvertisedChecks(altSeoReporting(installed: true, schemaEnabled: false)))->all();

    expect($checks)->not->toContain('structured_data.missing')
        ->and($checks)->toContain('meta_description.too_short');
});

it('offers structured data once the schema field exists', function () {
    $checks = (new AdvertisedChecks(altSeoReporting(installed: true, schemaEnabled: true)))->all();

    expect($checks)->toContain('structured_data.missing');
});

it('withholds template checks when template patching is off', function () {
    config()->set('altimiser.patch_templates', false);

    $checks = (new AdvertisedChecks(altSeoReporting(installed: true, schemaEnabled: true)))->all();

    expect($checks)->not->toContain('image.not_lazy_loaded');
});

it('reports what Alt SEO on this site can do', function () {
    // Nothing installed in the harness, which is itself the answer worth giving:
    // a site without the addon is unable rather than misconfigured.
    $state = app(AltSeo::class)->state();

    expect($state)->toHaveKeys(['installed', 'version', 'schema_enabled', 'collection_blueprints'])
        ->and($state['installed'])->toBeFalse()
        ->and($state['schema_enabled'])->toBeFalse()
        ->and($state['version'])->toBeNull();
});

it('reports the version composer actually installed', function () {
    // Not a literal in the controller: a hand-maintained number drifts from the
    // tag, which is how the receiver came to announce a 0.4.0 that was never
    // released.
    expect((new Altimiser)->version())
        ->toBe(InstalledVersions::getPrettyVersion('alt-design/altimiser-statamic'));
});
