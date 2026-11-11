<?php

use AltDesign\Altimiser\Applying\AssetApplier;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

function locateAsset(string $source): ?AssetContract
{
    return (fn (string $source) => $this->locate($source))->call(app(AssetApplier::class), $source);
}

it('finds the asset behind a Glide transform whose file name Glide encoded', function () {
    $asset = Mockery::mock(AssetContract::class);
    $container = Mockery::mock();
    $container->shouldReceive('asset')->once()->with('products/image-7-(4) min.png')->andReturn($asset);
    $containers = Mockery::mock();
    $containers->shouldReceive('find')->once()->with('assets')->andReturn($container);
    AssetContainer::swap($containers);

    expect(locateAsset('https://example.com/cached-img/containers/assets/products/image-7-%284%29%20min.png/ef2ee04fb7454fcc6b3a46fe2957ae8f/image-7-%284%29%20min.webp'))
        ->toBe($asset);
});

it('finds the asset behind a Glide transform of its URL rather than its container', function () {
    $asset = Mockery::mock(AssetContract::class);
    $assets = Mockery::mock();
    $assets->shouldReceive('findByUrl')->once()->with('/assets/group-498-(12).png')->andReturn($asset);
    Asset::swap($assets);

    expect(locateAsset('/cached-img/paths/assets/group-498-%2812%29.png/d12b8e44fb72e81accfd0dfb39f9e99e/group-498-%2812%29.webp'))
        ->toBe($asset);
});

it('decodes a plain asset URL before looking it up', function () {
    $asset = Mockery::mock(AssetContract::class);
    $assets = Mockery::mock();
    $assets->shouldReceive('findByUrl')->once()->with('/assets/team photo.jpg')->andReturn($asset);
    Asset::swap($assets);

    expect(locateAsset('https://example.com/assets/team%20photo.jpg?v=3'))->toBe($asset);
});
