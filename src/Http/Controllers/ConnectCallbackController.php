<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Altimiser;
use AltDesign\Altimiser\Connections\ConnectionFailed;
use Illuminate\Http\Request;

class ConnectCallbackController
{
    public function __invoke(Request $request, Altimiser $altimiser)
    {
        $expected = $request->session()->pull(ConnectController::STATE_KEY);
        $state = $request->query('state');

        if (! is_string($expected) || ! is_string($state) || ! hash_equals($expected, $state)) {
            return $this->back('error', 'That connection attempt could not be verified. Please try again.');
        }

        if ($request->query('error') === 'access_denied') {
            return $this->back('error', 'You chose not to connect your Altimiser account.');
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->back('error', 'Altimiser did not send back a code. Please try again.');
        }

        try {
            $altimiser->completeConnection($code, cp_route('altimiser.connect.callback'));
        } catch (ConnectionFailed $exception) {
            return $this->back('error', 'Could not connect your Altimiser account: '.$exception->getMessage());
        }

        return $this->back('success', 'Your Altimiser account is connected.');
    }

    private function back(string $level, string $message)
    {
        return redirect(cp_route('altimiser.index'))->with($level, $message);
    }
}
