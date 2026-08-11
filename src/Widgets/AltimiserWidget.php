<?php

namespace AltDesign\Altimiser\Widgets;

use AltDesign\Altimiser\Altimiser;
use Statamic\Widgets\Widget;

class AltimiserWidget extends Widget
{
    public function __construct(private Altimiser $altimiser) {}

    public function html()
    {
        return view('altimiser::widget', [
            'connected' => $this->altimiser->isConnected(),
            'summary' => $this->altimiser->summary(),
            'review_url' => $this->altimiser->reviewUrl(),
        ]);
    }
}
