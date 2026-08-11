<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Applying\AssetApplier;
use AltDesign\Altimiser\Applying\ChangeDispatcher;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\ChangeResult;
use AltDesign\Altimiser\Applying\ContentApplier;
use AltDesign\Altimiser\Applying\RobotsApplier;
use AltDesign\Altimiser\Applying\TemplateApplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ChangesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'dry_run' => ['boolean'],
            'changes' => ['required', 'array'],
        ]);

        [$changes, $rejected] = $this->parse($request->input('changes'));

        $dispatcher = new ChangeDispatcher([
            app(ContentApplier::class),
            app(AssetApplier::class),
            app(RobotsApplier::class),
            app(TemplateApplier::class),
        ]);

        return response()->json([
            'results' => [...$rejected, ...$dispatcher->dispatch($changes, $request->boolean('dry_run'))],
        ]);
    }

    /**
     * Each change is validated on its own. A batch is hundreds of changes and one
     * malformed entry rejecting the other 366 is a terrible trade, so a bad entry
     * comes back as a failed result and everything else still runs.
     *
     * @param  array<int, mixed>  $raw
     * @return array{0: array<int, ChangeRequest>, 1: array<int, array<string, mixed>>}
     */
    private function parse(array $raw): array
    {
        $changes = [];
        $rejected = [];

        foreach ($raw as $index => $change) {
            $validator = Validator::make(is_array($change) ? $change : [], [
                'id' => ['required', 'string'],
                'check' => ['required', 'string'],
                'url' => ['required', 'string'],
                'target' => ['array'],
                'current_value' => ['nullable', 'string'],
                // Nullable by design: a receiver may know how to compute the value
                // itself, so the absence of a suggestion is not a malformed change.
                'suggested_value' => ['nullable', 'string'],
            ]);

            if ($validator->fails()) {
                $rejected[] = ChangeResult::failed(
                    is_array($change) ? ($change['id'] ?? "index-{$index}") : "index-{$index}",
                    'invalid_change',
                    $validator->errors()->first(),
                )->toArray();

                continue;
            }

            $changes[] = ChangeRequest::fromArray($validator->validated());
        }

        return [$changes, $rejected];
    }
}
