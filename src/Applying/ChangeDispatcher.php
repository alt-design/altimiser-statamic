<?php

namespace AltDesign\Altimiser\Applying;

use Throwable;

class ChangeDispatcher
{
    /** @param array<int, Applier> $appliers */
    public function __construct(private array $appliers) {}

    /**
     * Processes a bounded slice of the batch and hands the rest back as deferred.
     *
     * A model-driven template edit takes tens of seconds, and no web server will
     * hold a request open for hundreds of them. Rather than ask every client site
     * to run a queue worker, each request does a predictable amount of work and
     * reports honestly what it did not get to. Altimiser calls again until
     * nothing is left, and re-sending is safe because a change whose fix is
     * already in place comes back as already applied.
     *
     * @param  array<int, ChangeRequest>  $changes
     * @return array<int, array<string, mixed>>
     */
    public function dispatch(array $changes, bool $dryRun, ?int $limit = null): array
    {
        $limit ??= config('altimiser.batch_size', 10);

        $processing = array_slice($changes, 0, $limit);
        $deferred = array_slice($changes, $limit);

        $startedAt = microtime(true);

        [$results, $ranOutOfTime] = $this->applyWithinBudget($processing, $dryRun, $startedAt);

        foreach ([...$ranOutOfTime, ...$deferred] as $change) {
            $results[] = ChangeResult::deferred($change->id)->toArray();
        }

        return $results;
    }

    /**
     * Stops before the web server does.
     *
     * A batch that overruns is killed where it stands, and a change killed
     * between writing a file and committing it leaves an edit behind that nobody
     * recorded and the next run refuses to touch. The count alone cannot prevent
     * that, because how long ten changes take depends on how much of the site
     * has to be read to place them. So the clock gets a say too, and whatever
     * is left is handed back as deferred for the next call.
     *
     * @param  array<int, ChangeRequest>  $changes
     * @param  float  $startedAt  when the request began, resolving included
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, ChangeRequest>}
     */
    private function applyWithinBudget(array $changes, bool $dryRun, float $startedAt): array
    {
        $budget = (float) config('altimiser.time_budget', 45);
        $results = [];

        foreach ($changes as $index => $change) {
            // Always does at least one, so a batch can never stall completely on
            // a budget it has already spent resolving the work.
            if ($results !== [] && microtime(true) - $startedAt > $budget) {
                return [$results, array_slice($changes, $index)];
            }

            $results[] = $this->applyOne($change, $dryRun)->toArray();
        }

        return [$results, []];
    }

    private function applyOne(ChangeRequest $change, bool $dryRun): ChangeResult
    {
        foreach ($this->appliers as $applier) {
            if (! $applier->supports($change->check)) {
                continue;
            }

            try {
                return $applier->apply($change, $dryRun);
            } catch (Throwable $exception) {
                report($exception);

                return ChangeResult::failed($change->id, 'write_failed', $exception->getMessage());
            }
        }

        return ChangeResult::skipped(
            $change->id,
            'unsupported_check',
            "This receiver has no transform for {$change->check}.",
        );
    }
}
