<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Altimiser;
use Illuminate\Support\Carbon;

class ReviewPageController
{
    public function __invoke(Altimiser $altimiser)
    {
        $connection = $altimiser->currentConnection();

        return view('altimiser::review', [
            'connected' => $altimiser->isConnected(),
            'embedUrl' => $altimiser->reviewUrl(embedded: true),
            'accountEmail' => $connection['account_email'] ?? null,
            'connectionExpiresAt' => $connection ? Carbon::parse($connection['expires_at']) : null,
        ]);
    }
}
