<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Applying\PatchApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A patch, rather than a list of values.
 *
 * Its own endpoint because it is a different kind of change: one diff across
 * several templates, decided once and applied once, where everything on the
 * changes endpoint is a value going into a field.
 */
class PatchController
{
    public function __invoke(Request $request, PatchApplier $applier): JsonResponse
    {
        $validated = $request->validate([
            'patch' => ['required', 'string'],
            'message' => ['required', 'string', 'max:500'],
            'dry_run' => ['boolean'],
        ]);

        return response()->json($applier->apply(
            $validated['patch'],
            $validated['message'],
            (bool) ($validated['dry_run'] ?? false),
        ));
    }
}
