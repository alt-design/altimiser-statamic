<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Altimiser;

class ReviewPageController
{
    public function __invoke(Altimiser $altimiser)
    {
        return view('altimiser::review', [
            'connected' => $altimiser->isConnected(),
            'embedUrl' => $altimiser->reviewUrl(embedded: true),
        ]);
    }
}
