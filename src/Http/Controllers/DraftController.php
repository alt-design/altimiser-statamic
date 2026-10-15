<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Drafts\DraftEntries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A content draft, saved as an unpublished entry.
 *
 * Its own endpoint because it creates content rather than changing a value
 * that is already there, which nothing on the changes endpoint ever does.
 */
class DraftController
{
    public function __invoke(Request $request, DraftEntries $entries): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:100000'],
            'collection' => ['required_without:expands', 'nullable', 'string', 'max:100'],
            'expands' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json($entries->store($validated));
    }
}
