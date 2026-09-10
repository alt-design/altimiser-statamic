<?php

namespace AltDesign\Altimiser\Applying;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class AiTemplateEditor
{
    public function __construct(private EditValidator $validator) {}

    public function isConfigured(): bool
    {
        if (! config('altimiser.ai.enabled')) {
            return false;
        }

        return filled($this->key());
    }

    /**
     * Asks the model for one precise edit and then refuses to trust it. The value
     * here is the model understanding Antlers well enough to find where an
     * attribute belongs on a tag built from variables, which no string matcher
     * can do. Everything after that is validation.
     *
     * @param  array<string, string>  $candidates  relative path to contents
     */
    public function edit(ChangeRequest $change, array $candidates): TemplateEdit
    {
        if ($candidates === []) {
            return TemplateEdit::rejected('No templates could be located for this URL.');
        }

        return $this->editMany([$change->id => ['change' => $change, 'candidates' => $candidates]])[$change->id]
            ?? TemplateEdit::rejected('The model could not be reached.');
    }

    /**
     * Resolves many edits at once. The calls are read-only, so they cannot race
     * with each other, and ten of them concurrently take about as long as one.
     * This is the difference between a batch finishing in minutes and in hours.
     *
     * Keys are the caller's to choose and come back untouched, which is what lets
     * the applier ask one question on behalf of several identical changes.
     *
     * @param  array<string, array{change: ChangeRequest, candidates: array<string, string>}>  $requests
     * @return array<string, TemplateEdit> keyed as the requests were
     */
    public function editMany(array $requests): array
    {
        $edits = [];

        foreach (array_chunk($requests, config('altimiser.ai.concurrency', 10), preserve_keys: true) as $chunk) {
            $responses = Http::pool(function ($pool) use ($chunk): array {
                $pending = [];

                foreach ($chunk as $key => $request) {
                    $pending[] = $this->request($pool->as((string) $key), $request);
                }

                return $pending;
            });

            foreach ($chunk as $key => $request) {
                $edits[$key] = $this->interpret(
                    $responses[$key] ?? null,
                    $request['candidates'],
                    $request['change'],
                );
            }
        }

        return $edits;
    }

    /**
     * Retried, because at ten concurrent calls a rate limit is routine rather
     * than exceptional, and without this a 429 is recorded as a change that
     * failed. throw: false keeps the pool's own error handling intact.
     *
     * @param  array{change: ChangeRequest, candidates: array<string, string>}  $request
     */
    private function request(mixed $client, array $request): mixed
    {
        return $client
            ->withToken($this->key())
            ->timeout(config('altimiser.ai.timeout', 30))
            ->retry(config('altimiser.ai.retries', 3), 500, throw: false)
            ->post(config('altimiser.ai.endpoint'), $this->payload($request['change'], $request['candidates']));
    }

    /** @param array<string, string> $candidates */
    private function interpret(mixed $response, array $candidates, ChangeRequest $change): TemplateEdit
    {
        $renderedTag = $change->target['context'] ?? null;

        if (! $response instanceof Response) {
            return TemplateEdit::rejected('The model could not be reached.');
        }

        if (! $response->successful()) {
            return TemplateEdit::rejected("The model returned {$response->status()}.");
        }

        // A refusal is the model declining on its own terms, which is a real
        // answer and worth repeating rather than reporting as unreadable.
        if (filled($refusal = $response->json('choices.0.message.refusal'))) {
            return TemplateEdit::rejected("The model declined: {$refusal}");
        }

        if ($response->json('choices.0.finish_reason') === 'length') {
            return TemplateEdit::rejected('The reply was cut short before it was finished. Raise altimiser.ai.max_tokens.');
        }

        try {
            $content = $response->json('choices.0.message.content');

            return $this->validator->validate(
                is_string($content) ? json_decode($content, true, flags: JSON_THROW_ON_ERROR) : [],
                $candidates,
                $renderedTag,
                $change,
            );
        } catch (Throwable $exception) {
            return TemplateEdit::rejected("The model's reply could not be read: {$exception->getMessage()}");
        }
    }

    /**
     * A plain HTTP call rather than an SDK. This addon installs into client sites
     * whose dependency graphs we do not control, and every package it requires is
     * a chance of a conflict that stops it installing at all. One POST needs no
     * dependency beyond the HTTP client Laravel already ships.
     *
     * Both tuning options are omitted when unset, so pointing this at a model
     * that has never heard of them is a config change rather than a 400.
     *
     * @param  array<string, string>  $candidates
     * @return array<string, mixed>
     */
    private function payload(ChangeRequest $change, array $candidates): array
    {
        return array_filter([
            'model' => config('altimiser.ai.model'),
            'messages' => [
                ['role' => 'system', 'content' => config('altimiser.ai.prompt')],
                ['role' => 'user', 'content' => $this->userPrompt($change, $candidates)],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'template_edit',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'reasoning_effort' => config('altimiser.ai.reasoning_effort'),
            'max_completion_tokens' => config('altimiser.ai.max_tokens'),
        ], fn (mixed $value): bool => $value !== null);
    }

    private function key(): ?string
    {
        return config('altimiser.ai.key');
    }

    /**
     * Templates first, the question last.
     *
     * That ordering is the whole point. OpenAI caches identical prompt prefixes
     * automatically and charges a fraction for the hit, but only for a prefix: a
     * per-change line at the top makes every one of these a fresh read of fifteen
     * thousand tokens of markup. With the files in front, every change against
     * the same templates rides the same cached prefix.
     *
     * @param  array<string, string>  $candidates
     */
    private function userPrompt(ChangeRequest $change, array $candidates): string
    {
        $files = '';

        foreach ($candidates as $path => $contents) {
            $files .= "\n=== FILE: {$path} ===\n".$this->numbered($contents)."\n";
        }

        return implode("\n", array_filter([
            'Templates:',
            $files,
            '',
            '--- The fix ---',
            "Check: {$change->check}",
            "Page URL: {$change->url}",
            'Element reported: '.($change->target['selector'] ?? 'unknown'),
            'Attribute to set: '.($change->target['attribute'] ?? 'unknown'),
            $this->valueInstruction($change),
            'Rendered value that identified the element: '.($change->matchLiteral() ?? 'not available'),
            $this->renderedContext($change),
            '',
            ...$this->brief($change),
        ]));
    }

    /**
     * What a correct edit looks like for this kind of finding.
     *
     * There are two kinds and they were being briefed identically. Telling a
     * model that the page contained the element, when the finding is that it
     * did not, leaves it no honest way to comply, and what it does instead is
     * find the nearest element that looks related and put the value on it as an
     * attribute nobody asked for.
     *
     * @return array<int, string>
     */
    private function brief(ChangeRequest $change): array
    {
        if ($change->reportsAMissingElement()) {
            return [
                'No element matching that selector exists on the rendered page. That',
                'absence is the finding. Do not add an attribute to another element to',
                'carry the value, and do not add an attribute that was not named above:',
                'an attribute nobody asked for is not this fix.',
                'The only edit that fixes this is one of two things. If one of these',
                'templates renders this page\'s main heading at the wrong level, change',
                'that tag name and nothing else. If the element is genuinely absent and',
                'no existing tag is the one that should have been it, decline: a page',
                'that needs a heading written needs a person, not an attribute.',
                'Return the line range holding the tag and the replacement for it.',
            ];
        }

        return [
            'The rendered page contained an element matching that selector. The',
            'element is produced by one of the templates above, most likely from a',
            'variable rather than a literal. Match it on its class list and other',
            'attributes rather than on its URL, which is generated. Set the named',
            'attribute and change nothing else: never add a second attribute to',
            'carry a value the named one could hold. If no tag in these templates',
            'could have produced that exact element, decline.',
            'Return the line range holding the tag and the replacement for it.',
        ];
    }

    /**
     * The element exactly as the browser received it. Its classes and alt text
     * are written in the template literally, so this is the strongest signal for
     * telling one image apart from the fifty-four others on the page.
     */
    private function renderedContext(ChangeRequest $change): string
    {
        $context = $change->target['context'] ?? null;

        if (blank($context)) {
            return '';
        }

        return 'The element as rendered, whose classes and alt text will appear in the '
            ."correct template: {$context}";
    }

    /**
     * Some fixes have no literal value we can supply, because the right value
     * differs per asset. Those need the attribute bound to a template variable
     * rather than hard-coded, and the model has to be told so explicitly.
     */
    private function valueInstruction(ChangeRequest $change): string
    {
        if (filled($change->suggestedValue) && $change->pageSpecific) {
            return "Value expected: {$change->suggestedValue}\n"
                .'That value belongs to this one page and these templates render many, so it '
                .'must never appear in the file as a literal. It can only come from a variable '
                .'already in scope, and if none holds it, that is a reason to decline.';
        }

        if (filled($change->suggestedValue)) {
            return "Value to set: {$change->suggestedValue}";
        }

        if ($change->check === 'image.dimensions_missing') {
            return 'No literal value is available and a hard-coded size would be wrong for '
                .'the other assets this template renders. Add both width and height bound to '
                ."the asset's own dimensions using the variables already in scope, and if no "
                .'such variables are available say so rather than inventing a size.';
        }

        return 'No literal value is available. Decline unless the correct value is '
            .'unambiguous from the template itself.';
    }

    /** Line numbers give the model an unambiguous way to point at markup. */
    private function numbered(string $contents): string
    {
        $numbered = '';

        foreach (preg_split('/\R/', $contents) as $index => $line) {
            $numbered .= str_pad((string) ($index + 1), 5, ' ', STR_PAD_LEFT)."| {$line}\n";
        }

        return rtrim($numbered, "\n");
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['applicable', 'file', 'start_line', 'end_line', 'new_text', 'reasoning'],
            'properties' => [
                'applicable' => [
                    'type' => 'boolean',
                    'description' => 'False when the element cannot be found or the change would not be safe.',
                ],
                'file' => [
                    'type' => 'string',
                    'description' => 'Exact path of one of the supplied files, copied verbatim.',
                ],
                'start_line' => [
                    'type' => 'integer',
                    'description' => 'First line to replace, using the numbers shown in the file.',
                ],
                'end_line' => [
                    'type' => 'integer',
                    'description' => 'Last line to replace. Keep the range as small as possible.',
                ],
                'new_text' => [
                    'type' => 'string',
                    'description' => 'Replacement for those lines, without line numbers, keeping the original indentation.',
                ],
                'reasoning' => ['type' => 'string'],
            ],
        ];
    }
}
