<?php

namespace AltDesign\Altimiser;

use Statamic\Facades\CP\Nav;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/altimiser.php', 'altimiser');
    }

    /**
     * Routes are loaded here rather than left to Statamic's addon handling, and
     * the files are deliberately not named web.php or cp.php.
     *
     * Statamic auto-detects those two filenames and registers them with its own
     * prefixes and middleware. That would mean the receiver endpoints land inside
     * the web middleware group, where CSRF rejects every machine-to-machine POST,
     * and that loading them here as well produces a second copy of every route at
     * a doubled prefix. Naming them something else leaves this provider in sole
     * charge, so the routes are identical however the addon was installed.
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/receiver.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/panel.php');

        // Alongside the routes rather than in bootAddon, for the same reason: that
        // hook only runs once Statamic has discovered the package, and a widget
        // whose views are not registered fails with an unhelpful hint path error.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'altimiser');

        // Registered directly for the same reason. Statamic's own $widgets handling
        // lives behind the discovery check, so relying on it makes the control
        // panel widget appear or not depending on how the addon was installed.
        Widgets\AltimiserWidget::register();

        $this->addNavItem();

        parent::boot();
    }

    /**
     * A sidebar entry under Tools, so the review queue is navigable rather than
     * something to be stumbled upon on a dashboard.
     *
     * It carries its own view so it can show a count the way Updates does. That
     * badge is the only thing in the control panel that says there is work
     * waiting without somebody going looking for it.
     */
    private function addNavItem(): void
    {
        Nav::extend(function ($nav) {
            $nav->tools('Altimiser')
                ->route('altimiser.index')
                // Statamic ships no rocket, and NavItem takes raw SVG as readily
                // as one of its own names, so this travels with the addon.
                ->icon(file_get_contents(__DIR__.'/../resources/svg/rocket.svg'))
                ->view('altimiser::nav');
        });
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/altimiser.php' => config_path('altimiser.php'),
        ], 'altimiser-config');
    }
}
