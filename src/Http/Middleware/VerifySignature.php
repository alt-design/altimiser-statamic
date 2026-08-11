<?php

namespace AltDesign\Altimiser\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySignature
{
    private int $toleranceInSeconds = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('altimiser.secret');

        if (blank($secret)) {
            return $this->reject('Altimiser is not configured on this site.');
        }

        $timestamp = (int) $request->header('X-Altimiser-Timestamp');

        if (abs(time() - $timestamp) > $this->toleranceInSeconds) {
            return $this->reject('Signature timestamp is outside the accepted window.');
        }

        $expected = 'sha256='.hash_hmac('sha256', "{$timestamp}\n{$request->getContent()}", $secret);

        if (! hash_equals($expected, (string) $request->header('X-Altimiser-Signature'))) {
            return $this->reject('Signature does not match.');
        }

        return $next($request);
    }

    private function reject(string $message): Response
    {
        return response()->json(['message' => $message], 401);
    }
}
