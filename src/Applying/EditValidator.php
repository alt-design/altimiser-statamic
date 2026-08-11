<?php

namespace AltDesign\Altimiser\Applying;

class EditValidator
{
    private int $maximumLines = 12;

    public function __construct(private RenderedConsistency $consistency) {}

    /**
     * Everything a model returns is treated as a suggestion, not an instruction.
     * These rules are the whole reason it is safe to let a model near a client's
     * templates: an edit that does not survive them is discarded, not applied.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $candidates  path to contents of the files we offered
     */
    public function validate(array $response, array $candidates, ?string $renderedTag = null): TemplateEdit
    {
        if (($response['applicable'] ?? false) !== true) {
            return TemplateEdit::rejected($response['reasoning'] ?? 'The model declined to make this change.');
        }

        $file = $response['file'] ?? '';

        // Only files we chose to send. Blocks the model naming any other path.
        if (! array_key_exists($file, $candidates)) {
            return TemplateEdit::rejected("The model named a file that was not offered to it: {$file}");
        }

        $lines = preg_split('/\R/', $candidates[$file]);
        $start = (int) ($response['start_line'] ?? 0);
        $end = (int) ($response['end_line'] ?? 0);
        $replacement = $response['new_text'] ?? '';

        if ($start < 1 || $end < $start || $end > count($lines)) {
            return TemplateEdit::rejected("The model named lines {$start} to {$end}, which are not in the file.");
        }

        // A large range is a rewrite, not an edit. These fixes are one tag.
        if ($end - $start + 1 > $this->maximumLines) {
            return TemplateEdit::rejected('The edit spans '.($end - $start + 1)." lines, more than the {$this->maximumLines} a single fix should need.");
        }

        if ($end - $start + 1 >= count($lines)) {
            return TemplateEdit::rejected('The model tried to rewrite the whole file rather than make an edit.');
        }

        $replaced = implode("\n", array_slice($lines, $start - 1, $end - $start + 1));

        if ($this->collapse($replaced) === $this->collapse($replacement)) {
            return TemplateEdit::rejected('The model returned an edit that changes nothing.');
        }

        if ($this->deletesTooMuch($replaced, $replacement)) {
            return TemplateEdit::rejected('The edit removes substantially more than it adds, which is not what a fix looks like.');
        }

        // The last and most useful guard: the tag it chose must be capable of
        // having produced the element that was actually reported.
        if ($this->consistency->contradicts($replaced, $renderedTag)) {
            return TemplateEdit::rejected(
                'The tag at those lines cannot have rendered the reported element: its literal '
                .'attributes do not match what the page actually served.',
            );
        }

        return TemplateEdit::accepted(
            $file,
            $start,
            $end,
            $replaced,
            $replacement,
            $response['reasoning'] ?? null,
        );
    }

    /**
     * These fixes add attributes. An edit markedly shorter than what it replaces
     * is deleting markup, which is never the intent.
     */
    private function deletesTooMuch(string $replaced, string $replacement): bool
    {
        return mb_strlen($this->collapse($replacement)) < mb_strlen($this->collapse($replaced)) * 0.8;
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
