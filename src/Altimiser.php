<?php

namespace AltDesign\Altimiser;

use AltDesign\Altimiser\Connections\ConnectionFailed;
use AltDesign\Altimiser\Connections\ConnectionStore;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Throwable;

class Altimiser
{
    /**
     * The installed version of this package, read from composer rather than
     * written down here. A number maintained by hand drifts from the tag it is
     * supposed to name, and a receiver reporting a version nobody released
     * tells you less than reporting nothing.
     */
    public function version(): ?string
    {
        try {
            return InstalledVersions::getPrettyVersion('alt-design/altimiser-statamic');
        } catch (Throwable) {
            return null;
        }
    }

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

        $connection = $this->currentConnection();

        $url = $this->unsignedUrl('/review', [
            'connection' => $connection['connection'] ?? null,
            'embedded' => $embedded ? '1' : null,
            'expires' => now()->addMinutes($minutes ?? config('altimiser.link_minutes', 30))->getTimestamp(),
            'site' => $this->siteUrl(),
            'user' => User::current()?->email(),
        ]);

        $signed = $url.'&signature='.$this->sign($url, config('altimiser.secret'));

        if ($connection === null) {
            return $signed;
        }

        return $signed.'&connection_signature='.$this->sign($url, $connection['token']);
    }

    /** The current control panel user's connection, when it has not expired. */
    public function currentConnection(): ?array
    {
        $id = User::current()?->id();

        return $id === null ? null : $this->connections()->live((string) $id);
    }

    /**
     * Whether Altimiser would accept this address as a place to send the admin
     * back to, which it does only on the origin of the site's own URL.
     */
    public function isOnSiteOrigin(string $url): bool
    {
        return $this->origin($url) === $this->origin($this->siteUrl());
    }

    /**
     * Where to send an admin to approve connecting to their Altimiser account.
     * The caller keeps the state and checks it when they come back.
     */
    public function connectUrl(string $state, string $redirectUri): ?string
    {
        if (! $this->isConnected()) {
            return null;
        }

        $url = $this->unsignedUrl('/connect', [
            'expires' => now()->addMinutes(5)->getTimestamp(),
            'redirect_uri' => $redirectUri,
            'site' => $this->siteUrl(),
            'state' => $state,
            'user' => User::current()?->email(),
        ]);

        return $url.'&signature='.$this->sign($url, config('altimiser.secret'));
    }

    /**
     * Swaps the code Altimiser sent back for a connection and keeps it against
     * the current user.
     *
     * @throws ConnectionFailed
     */
    public function completeConnection(string $code, string $redirectUri): void
    {
        $connection = $this->exchangeCode($code, $redirectUri);

        $this->connections()->put((string) User::current()->id(), $connection);
    }

    public function disconnect(): void
    {
        $id = User::current()?->id();

        if ($id !== null) {
            $this->connections()->forget((string) $id);
        }
    }

    /**
     * @return array{connection: string, token: string, expires_at: string, account_email: string}
     *
     * @throws ConnectionFailed
     */
    private function exchangeCode(string $code, string $redirectUri): array
    {
        $body = json_encode([
            'site' => $this->siteUrl(),
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], JSON_UNESCAPED_SLASHES);

        $timestamp = time();

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-Altimiser-Timestamp' => (string) $timestamp,
                    'X-Altimiser-Signature' => 'sha256='.$this->sign("{$timestamp}\n{$body}", config('altimiser.secret')),
                    'Accept' => 'application/json',
                ])
                ->withBody($body, 'application/json')
                ->post(rtrim(config('altimiser.url'), '/').'/api/connections');
        } catch (Throwable) {
            throw new ConnectionFailed('Altimiser could not be reached.');
        }

        if ($response->status() !== 201) {
            throw new ConnectionFailed((string) ($response->json('message') ?: 'Altimiser refused the connection.'));
        }

        $data = $response->json();

        if (! is_array($data) || blank($data['connection'] ?? null) || blank($data['token'] ?? null) || blank($data['expires_at'] ?? null)) {
            throw new ConnectionFailed('Altimiser sent an answer that could not be read.');
        }

        return [
            'connection' => $data['connection'],
            'token' => $data['token'],
            'expires_at' => $data['expires_at'],
            'account_email' => (string) ($data['account']['email'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function unsignedUrl(string $path, array $query): string
    {
        $query = array_filter($query);

        ksort($query);

        return rtrim(config('altimiser.url'), '/').$path.'?'.http_build_query($query);
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host'] ?? '').':'.$port;
    }

    private function connections(): ConnectionStore
    {
        return app(ConnectionStore::class);
    }

    private function sign(string $payload, string $key): string
    {
        return hash_hmac('sha256', $payload, $key);
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
            $signature = 'sha256='.$this->sign("{$timestamp}\n", config('altimiser.secret'));

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
