<?php

namespace AltDesign\Altimiser\Widgets;

use AltDesign\Altimiser\Altimiser;
use AltDesign\Altimiser\ServiceProvider;
use Statamic\Facades\User;
use Statamic\Widgets\Widget;

class AltimiserWidget extends Widget
{
    public function __construct(private Altimiser $altimiser) {}

    /**
     * Statamic only checks a widget's permission from the dashboard config, which
     * a site may override per user, so the widget refuses on its own account.
     */
    public function html()
    {
        if (! User::current()?->can(ServiceProvider::PERMISSION)) {
            return '';
        }

        return view('altimiser::widget', [
            'connected' => $this->altimiser->isConnected(),
            'summary' => $this->altimiser->summary(),
            'queue_url' => cp_route('altimiser.index'),
        ]);
    }
}
