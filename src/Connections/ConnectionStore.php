<?php

namespace AltDesign\Altimiser\Connections;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Holds each control panel user's Altimiser connection.
 *
 * It lives in a file under storage rather than the cache, which can be cleared
 * out from under a user, and never beside the user's own file, which sites
 * usually commit. Each entry is encrypted with the application key.
 */
class ConnectionStore
{
    public function path(): string
    {
        return storage_path('app/altimiser/connections.json');
    }

    /**
     * @param  array{connection: string, token: string, expires_at: string, account_email: string}  $connection
     */
    public function put(string $userId, array $connection): void
    {
        $entries = $this->entries();
        $entries[$userId] = Crypt::encryptString(json_encode($connection, JSON_THROW_ON_ERROR));

        $this->write($entries);
    }

    /**
     * The user's connection, or null when there is none or it has expired.
     *
     * @return ?array{connection: string, token: string, expires_at: string, account_email: string}
     */
    public function live(string $userId): ?array
    {
        $connection = $this->find($userId);

        if ($connection === null) {
            return null;
        }

        return Carbon::parse($connection['expires_at'])->isFuture() ? $connection : null;
    }

    public function forget(string $userId): void
    {
        $entries = $this->entries();

        unset($entries[$userId]);

        $this->write($entries);
    }

    /** @return ?array{connection: string, token: string, expires_at: string, account_email: string} */
    private function find(string $userId): ?array
    {
        $encrypted = $this->entries()[$userId] ?? null;

        if ($encrypted === null) {
            return null;
        }

        try {
            $connection = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return isset($connection['token'], $connection['expires_at'], $connection['connection']) ? $connection : null;
    }

    /** @return array<string, string> */
    private function entries(): array
    {
        if (! File::exists($this->path())) {
            return [];
        }

        $entries = json_decode(File::get($this->path()), true);

        return is_array($entries) ? $entries : [];
    }

    /** @param  array<string, string>  $entries */
    private function write(array $entries): void
    {
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($entries, JSON_THROW_ON_ERROR), true);
    }
}
