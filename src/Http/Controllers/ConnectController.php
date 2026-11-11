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
        $callback = cp_route('altimiser.connect.callback');

        if (! $altimiser->isOnSiteOrigin($callback)) {
            return redirect(cp_route('altimiser.index'))->with(
                'error',
                'Connecting needs the control panel to be on the same address as the site itself. Open the control panel at the site\'s own address and try again.'
            );
        }

        $state = Str::random(40);

        $url = $altimiser->connectUrl($state, $callback);

        if ($url === null) {
            return redirect(cp_route('altimiser.index'))->with('error', 'Altimiser is not configured on this site.');
        }

        $request->session()->put(self::STATE_KEY, $state);

        return redirect()->away($url);
    }
}
