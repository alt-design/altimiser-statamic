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
        $this->modify(function (array $entries) use ($userId, $connection): array {
            $entries[$userId] = Crypt::encryptString(json_encode($connection, JSON_THROW_ON_ERROR));

            return $entries;
        });
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
        $this->modify(function (array $entries) use ($userId): array {
            unset($entries[$userId]);

            return $entries;
        });
    }

    /** @return ?array{connection: string, token: string, expires_at: string, account_email: string} */
    private function find(string $userId): ?array
    {
        $encrypted = $this->entries()[$userId] ?? null;

        return $encrypted === null ? null : $this->open($encrypted);
    }

    /** @return array<string, string> */
    private function entries(): array
    {
        if (! File::exists($this->path())) {
            return [];
        }

        return $this->decode(File::get($this->path()));
    }

    /**
     * Reads, changes and writes the file under one exclusive lock, so two users
     * connecting at once cannot overwrite each other's entry. Entries that have
     * expired are dropped on the way out.
     *
     * @param  callable(array<string, string>): array<string, string>  $change
     */
    private function modify(callable $change): void
    {
        File::ensureDirectoryExists(dirname($this->path()));

        $handle = fopen($this->path(), 'c+');
        chmod($this->path(), 0600);
        flock($handle, LOCK_EX);

        try {
            $entries = $this->withoutExpired($change($this->decode((string) stream_get_contents($handle))));

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($entries, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  array<string, string>  $entries
     * @return array<string, string>
     */
    private function withoutExpired(array $entries): array
    {
        return array_filter($entries, function (string $encrypted): bool {
            $connection = $this->open($encrypted);

            return $connection !== null && Carbon::parse($connection['expires_at'])->isFuture();
        });
    }

    /** @return array<string, string> */
    private function decode(string $contents): array
    {
        $entries = json_decode($contents, true);

        return is_array($entries) ? $entries : [];
    }

    /** @return ?array{connection: string, token: string, expires_at: string, account_email: string} */
    private function open(string $encrypted): ?array
    {
        try {
            $connection = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return isset($connection['token'], $connection['expires_at'], $connection['connection']) ? $connection : null;
    }
}
