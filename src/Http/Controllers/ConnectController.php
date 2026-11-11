<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Altimiser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConnectController
{
    public const STATE_KEY = 'altimiser.connect_state';

    public function __invoke(Request $request, Altimiser $altimiser)
    {
        $state = Str::random(40);

        $url = $altimiser->connectUrl($state, cp_route('altimiser.connect.callback'));

        if ($url === null) {
            return redirect(cp_route('altimiser.index'))->with('error', 'Altimiser is not configured on this site.');
        }

        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($url);
    }
}
