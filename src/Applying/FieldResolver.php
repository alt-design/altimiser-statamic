<?php

namespace AltDesign\Altimiser\Applying;

class FieldResolver
{
    public function __construct(private ValueResolver $values) {}

    /**
     * Works out which field produced a value Altimiser saw in the rendered page.
     * Deliberately has no Statamic dependency: it takes plain arrays so the hard
     * part is testable without booting a CMS.
     *
     * @param  array<string, mixed>  $data  the entry's current field values
     * @param  array<int, string>  $blueprintFields  every field handle the blueprint defines
     * @param  array<string, string>  $variables  substitutions such as title and site_name
     */
    public function resolve(ChangeRequest $change, array $data, array $blueprintFields, array $variables = []): FieldMatch
    {
        $candidates = config("altimiser.fields.{$change->checkGroup()}", []);

        if (blank($change->currentValue)) {
            return $this->firstWritableCandidate($candidates, $data, $blueprintFields);
        }

        $match = $this->fieldHoldingValue($change->currentValue, $data, $candidates, $variables);

        if ($match->matched()) {
            return $match;
        }

        // Nothing on the entry produced it, so it was inherited from a site-wide
        // default. A per-page override is the change with the smallest blast radius.
        return $this->inheritedOverride($candidates, $data, $blueprintFields, $match);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $candidates
     * @param  array<string, string>  $variables
     */
    private function fieldHoldingValue(string $value, array $data, array $candidates, array $variables): FieldMatch
    {
        $literal = [];
        $templated = [];

        foreach ($data as $handle => $stored) {
            if (! is_scalar($stored)) {
                continue;
            }

            $stored = (string) $stored;

            if ($this->values->equivalent($stored, $value)) {
                $literal[] = $handle;

                continue;
            }

            if (! $this->values->containsPlaceholders($stored)) {
                continue;
            }

            if ($this->values->equivalent($this->values->render($stored, $variables), $value)) {
                $templated[] = $handle;
            }
        }

        return $this->pick($literal, $templated, $candidates);
    }

    /**
     * @param  array<int, string>  $literal
     * @param  array<int, string>  $templated
     * @param  array<int, string>  $candidates
     */
    private function pick(array $literal, array $templated, array $candidates): FieldMatch
    {
        // A field storing the value verbatim beats one that only matches after
        // substitution, because writing to it discards nothing.
        foreach ([[$literal, false], [$templated, true]] as [$matches, $flattens]) {
            /*
             * Only a field this check was told it may write to.
             *
             * Alt SEO's title default is often {title}, so the meta title a
             * scan reads back is the entry's own title, verbatim. Matching on
             * value alone then picks the title field, and an SEO rewrite of a
             * meta title renames the page in the menu, every listing, the
             * breadcrumbs and the control panel. A value found outside the
             * candidates means it was produced somewhere that is not ours to
             * edit, which calls for a per-page override rather than an edit.
             */
            $preferred = array_values(array_intersect($candidates, $matches));

            if ($preferred === []) {
                continue;
            }

            if (count($preferred) === 1) {
                return $flattens ? FieldMatch::flattening($preferred[0]) : FieldMatch::found($preferred[0]);
            }

            return FieldMatch::failed(
                'ambiguous',
                'More than one field holds this value ('.implode(', ', $preferred).'), so applying would be a guess.',
            );
        }

        return FieldMatch::failed('no_match', 'No field on this entry produced the value Altimiser saw.');
    }

    /**
     * @param  array<int, string>  $candidates
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $blueprintFields
     */
    private function inheritedOverride(array $candidates, array $data, array $blueprintFields, FieldMatch $failure): FieldMatch
    {
        if ($failure->reason === 'ambiguous') {
            return $failure;
        }

        $match = $this->firstWritableCandidate($candidates, $data, $blueprintFields);

        return $match->matched() ? FieldMatch::inherited($match->field) : $match;
    }

    /**
     * The only safe way to invent a location: the first configured field the
     * blueprint defines and that is currently empty. Never overwrites content.
     *
     * @param  array<int, string>  $candidates
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $blueprintFields
     */
    private function firstWritableCandidate(array $candidates, array $data, array $blueprintFields): FieldMatch
    {
        foreach ($candidates as $candidate) {
            if (! in_array($candidate, $blueprintFields, true)) {
                continue;
            }

            if (filled($data[$candidate] ?? null)) {
                continue;
            }

            return FieldMatch::found($candidate);
        }

        return FieldMatch::failed(
            'no_match',
            'The blueprint defines none of the expected fields ('.implode(', ', $candidates).') as empty and writable.',
        );
    }
}
