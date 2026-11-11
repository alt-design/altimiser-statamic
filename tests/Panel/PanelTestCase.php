<?php

namespace AltDesign\Altimiser\Tests\Panel;

use AltDesign\Altimiser\ServiceProvider;
use Facades\Statamic\Version;
use Illuminate\Support\Facades\File;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Orchestra\Testbench\TestCase;
use Statamic\Addons\Manifest;
use Statamic\Providers\StatamicServiceProvider;
use Statamic\Statamic;

/**
 * Boots Statamic with the addon registered, which the plain test case avoids.
 * Authorisation lives in Statamic's gate, so it can only be tested through it.
 *
 * Statamic's own AddonTestCase is not used because it replaces the navigation
 * builder with a mock, which would discard the item this addon adds to it.
 */
abstract class PanelTestCase extends TestCase
{
    protected string $usersDirectory;

    protected function setUp(): void
    {
        $this->usersDirectory = sys_get_temp_dir().'/altimiser-panel-'.uniqid();

        parent::setUp();

        $this->withoutVite();

        Version::shouldReceive('get')->andReturn('6.0.0');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->usersDirectory);

        parent::tearDown();
    }

    protected function getBasePath(): string
    {
        return dirname(__DIR__, 2);
    }

    protected function getPackageProviders($app): array
    {
        return [
            StatamicServiceProvider::class,
            InertiaServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return ['Statamic' => Statamic::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app->make(Manifest::class)->manifest = [
            'alt-design/altimiser-statamic' => [
                'id' => 'alt-design/altimiser-statamic',
                'slug' => null,
                'version' => 'dev-main',
                'namespace' => 'AltDesign\\Altimiser',
                'autoload' => 'src',
                'provider' => ServiceProvider::class,
            ],
        ];

        $app['config']->set('altimiser', require dirname(__DIR__, 2).'/config/altimiser.php');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('statamic.users.repository', 'file');
        $app['config']->set('statamic.stache.watcher', false);
        $app['config']->set('statamic.stache.stores.users.directory', $this->usersDirectory);
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('statamic.editions.pro', true);
    }
}
