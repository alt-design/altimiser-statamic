<?php

namespace AltDesign\Altimiser\Applying;

interface PreparesBatch
{
    /**
     * Do any slow, read-only lookups for a whole slice of changes before any of
     * them are applied, so they can be done concurrently rather than one by one.
     *
     * @param  array<int, ChangeRequest>  $changes
     */
    public function prepare(array $changes, bool $dryRun): void;
}
