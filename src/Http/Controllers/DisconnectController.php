<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Altimiser;

class DisconnectController
{
    public function __invoke(Altimiser $altimiser)
    {
        $altimiser->disconnect();

        return redirect(cp_route('altimiser.index'))->with('success', 'Your Altimiser account is disconnected.');
    }
}
