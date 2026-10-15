<?php

namespace AltDesign\Altimiser\Linking;

/**
 * What linking a mention found, and the field to save where it changed one.
 */
class LinkEdit
{
    /**
     * @param  string  $outcome  changed, already, inside_link or not_found
     * @param  ?string  $field  the top level field holding the mention
     * @param  mixed  $value  that field's new value, when it changed
     * @param  ?string  $path  where in the entry the mention is, for the record
     */
    public function __construct(
        public string $outcome,
        public ?string $field = null,
        public mixed $value = null,
        public ?string $path = null,
    ) {}

    public function changed(): bool
    {
        return $this->outcome === 'changed';
    }
}
