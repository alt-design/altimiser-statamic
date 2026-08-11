<?php

namespace AltDesign\Altimiser;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Throwable;

class Altimiser
{
    public function isConnected(): bool
    {
        return filled(config('altimiser.url')) && filled(config('altimiser.secret'));
    }

    /**
     * A signed link into Altimiser's review queue.
     *
     * Signed here, server side, with the secret the two ends already share. No
     * credential reaches the browser: the URL carries only a signature that
     * expires, scoped to this site and to whoever is logged into the control
     * panel, which is also how Altimiser attributes the approval.
     */
    public function reviewUrl(?int $minutes = null, bool $embedded = false): ?string
    {
        if (! $this->isConnected()) {
            return null;
        }

        $base = rtrim(config('altimiser.url'), '/').'/review';

        $query = array_filter([
            'embedded' => $embedded ? '1' : null,
            'expires' => now()->addMinutes($minutes ?? config('altimiser.link_minutes', 30))->getTimestamp(),
            'site' => $this->siteUrl(),
            'user' => User::current()?->email(),
        ]);

        ksort($query);

        $url = $base.'?'.http_build_query($query);

        return $url.'&signature='.hash_hmac('sha256', $url, config('altimiser.secret'));
    }

    /**
     * Outstanding counts for the control panel. Degrades to nothing rather than
     * breaking the panel: a widget is not worth an error page if Altimiser is
     * unreachable or still scanning.
     *
     * @return ?array<string, mixed>
     */
    public function summary(): ?array
    {
        if (! $this->isConnected()) {
            return null;
        }

        // Cached, because the sidebar badge asks for this on every control panel
        // page. An uncached call would put a cross-network request with a five
        // second timeout in front of every screen an editor opens, which is a
        // steep price for a number that changes a few times a day.
        return Cache::remember(
            'altimiser.summary.'.md5($this->siteUrl()),
            now()->addMinutes(config('altimiser.summary_minutes', 5)),
            fn (): ?array => $this->fetchSummary(),
        );
    }

    /** Forgets the cached counts, so an action taken here shows immediately. */
    public function forgetSummary(): void
    {
        Cache::forget('altimiser.summary.'.md5($this->siteUrl()));
    }

    /** @return ?array<string, mixed> */
    private function fetchSummary(): ?array
    {
        try {
            $timestamp = time();
            $signature = 'sha256='.hash_hmac('sha256', "{$timestamp}\n", config('altimiser.secret'));

            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Altimiser-Timestamp' => (string) $timestamp,
                    'X-Altimiser-Signature' => $signature,
                    'Accept' => 'application/json',
                ])
                ->get(rtrim(config('altimiser.url'), '/').'/api/summary', ['site' => $this->siteUrl()]);

            return $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function siteUrl(): string
    {
        return rtrim(config('altimiser.site_url') ?: Site::default()->absoluteUrl(), '/');
    }
}
