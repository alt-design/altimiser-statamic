<?php

use AltDesign\Altimiser\Applying\AssetApplier;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Applying\TemplateApplier;
use AltDesign\Altimiser\Http\Controllers\HealthController;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;

beforeEach(function () {
    config()->set('altimiser.fields', ['image' => ['alt']]);
    config()->set('altimiser.git.enabled', false);
});

function describedAsset(?string $alt): AssetContract
{
    $blueprint = Mockery::mock();
    $blueprint->shouldReceive('fields->all->keys->all')->andReturn(['alt']);

    $asset = Mockery::mock(AssetContract::class);
    $asset->shouldReceive('blueprint')->andReturn($blueprint);
    $asset->shouldReceive('id')->andReturn('assets::hero.jpg');
    $asset->shouldReceive('get')->with('alt')->andReturn($alt);

    $assets = Mockery::mock();
    $assets->shouldReceive('findByUrl')->andReturn($asset);
    Asset::swap($assets);

    return $asset;
}

function assetApplier(): AssetApplier
{
    return new AssetApplier(Mockery::mock(GitRepository::class), Mockery::mock(TemplateApplier::class));
}

function clearAltChange(string $written = 'A hero image.', bool $revert = true): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: 'image.alt_missing',
        url: 'https://example.com/',
        target: ['match' => '/assets/hero.jpg'],
        currentValue: $written,
        suggestedValue: '',
        revert: $revert,
    );
}

it('removes alt text Altimiser wrote so the asset has none', function () {
    $asset = describedAsset('A hero image.');
    $asset->shouldReceive('remove')->once()->with('alt')->andReturnSelf();
    $asset->shouldReceive('save')->once();
    $asset->shouldNotReceive('set');

    $result = assetApplier()->apply(clearAltChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied');
});

it('leaves alt text alone once somebody has edited it', function () {
    $asset = describedAsset('A better description.');
    $asset->shouldNotReceive('remove');

    $result = assetApplier()->apply(clearAltChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('value_changed');
});

it('reports alt text that is already empty as already applied', function () {
    $asset = describedAsset(null);
    $asset->shouldNotReceive('remove');

    expect(assetApplier()->apply(clearAltChange(), dryRun: false)->toArray()['status'])->toBe('already_applied');
});

it('removes nothing on a dry run', function () {
    $asset = describedAsset('A hero image.');
    $asset->shouldNotReceive('remove');

    expect(assetApplier()->apply(clearAltChange(), dryRun: true)->toArray()['status'])->toBe('applied');
});

it('still refuses an empty alt value that is not a revert', function () {
    $asset = describedAsset('A hero image.');
    $asset->shouldNotReceive('remove');

    $result = assetApplier()->apply(clearAltChange(revert: false), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['reason'])->toBe('no_suggestion');
});

it('advertises that it can clear a field', function () {
    $capabilities = (fn () => $this->capabilities())->call(new HealthController);

    expect($capabilities)->toContain('content.clear');
});
