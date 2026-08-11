<?php

namespace AltDesign\Altimiser\Applying;

interface Applier
{
    public function supports(string $check): bool;

    public function apply(ChangeRequest $change, bool $dryRun): ChangeResult;
}
