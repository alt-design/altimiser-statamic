<?php

namespace AltDesign\Altimiser\Tests;

use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A container and a config array, which is all these tests need.
 *
 * The addon's service provider is deliberately not registered. It extends
 * Statamic's own, so booting it would drag a CMS in to test classes that take
 * plain arrays and strings. What is under test here is the applying logic, not
 * how Statamic discovers it.
 */
abstract class TestCase extends Orchestra
{
    /**
     * The package root, so a test that writes a template writes it here rather
     * than inside Testbench's skeleton in vendor, where it would survive the
     * run and be invisible.
     */
    protected function getBasePath(): string
    {
        return dirname(__DIR__);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('altimiser', require __DIR__.'/../config/altimiser.php');
    }
}
