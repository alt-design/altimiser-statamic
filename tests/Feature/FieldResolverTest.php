<?php

use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\FieldResolver;
use AltDesign\Altimiser\Applying\ValueResolver;

beforeEach(function () {
    config()->set('altimiser.fields', [
        'title' => ['alt_seo_meta_title', 'seo_title', 'title'],
        'meta_description' => ['alt_seo_meta_description', 'seo_description', 'meta_description', 'description'],
    ]);

    $this->resolver = new FieldResolver(new ValueResolver);
});

function change(string $check, ?string $currentValue, string $suggested = 'New value'): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: $check,
        url: 'https://example.com/about',
        target: [],
        currentValue: $currentValue,
        suggestedValue: $suggested,
    );
}

it('finds the field holding the value the scan saw', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us.'),
        ['title' => 'About', 'seo_description' => 'About us.'],
        ['title', 'seo_description'],
    );

    expect($match->matched())->toBeTrue()
        ->and($match->field)->toBe('seo_description');
});

it('matches even when the stored value has different whitespace', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us and what we do.'),
        ['seo_description' => "About us\n  and what   we do."],
        ['seo_description'],
    );

    expect($match->field)->toBe('seo_description');
});

it('refuses to guess when two fields this check may write to hold the same value', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Same text.'),
        ['seo_description' => 'Same text.', 'meta_description' => 'Same text.'],
        ['seo_description', 'meta_description'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('ambiguous')
        ->and($match->message)->toContain('seo_description')
        ->and($match->message)->toContain('meta_description');
});

it('will not write to a field this check was never given, however well it matches', function () {
    // summary and intro hold the value, and neither is a meta description
    // field. Writing to one because it happens to match is how an SEO rewrite
    // lands in a field the site renders somewhere else entirely.
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Same text.'),
        ['summary' => 'Same text.', 'intro' => 'Same text.'],
        ['summary', 'intro'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->message)->toContain('alt_seo_meta_description');
});

it('breaks a tie using the configured field order', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Same text.'),
        ['seo_description' => 'Same text.', 'intro' => 'Same text.'],
        ['seo_description', 'intro'],
    );

    expect($match->field)->toBe('seo_description');
});

it('reports no match when nothing holds the value', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Text that is not stored anywhere.'),
        ['title' => 'About'],
        ['title'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('no_match');
});

it('picks the first empty configured field when nothing was rendered', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['title' => 'About', 'seo_description' => ''],
        ['title', 'seo_description', 'description'],
    );

    expect($match->field)->toBe('seo_description');
});

it('skips a configured field the blueprint does not define', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['title' => 'About'],
        ['title', 'description'],
    );

    expect($match->field)->toBe('description');
});

it('will not invent a location when no configured field is available', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['title' => 'About'],
        ['title'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('no_match');
});

it('never overwrites a configured field that already has content', function () {
    $match = $this->resolver->resolve(
        change('meta_description.missing', null),
        ['seo_description' => 'Already written', 'description' => ''],
        ['seo_description', 'description'],
    );

    expect($match->field)->toBe('description');
});

it('ignores non-scalar field values when matching', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us.'),
        ['blocks' => [['type' => 'text']], 'seo_description' => 'About us.'],
        ['blocks', 'seo_description'],
    );

    expect($match->field)->toBe('seo_description');
});

it('treats an inherited site-wide default as a per-page override', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Award-winning studio in Bristol.'),
        ['title' => 'About', 'alt_seo_meta_description' => ''],
        ['title', 'alt_seo_meta_description'],
    );

    expect($match->matched())->toBeTrue()
        ->and($match->field)->toBe('alt_seo_meta_description')
        ->and($match->inherited)->toBeTrue();
});

it('matches a field whose stored value only resolves after substitution', function () {
    $match = $this->resolver->resolve(
        change('title.too_short', 'About | Acme Ltd'),
        ['title' => 'About', 'alt_seo_meta_title' => '{title} | {site_name}'],
        ['title', 'alt_seo_meta_title'],
        ['title' => 'About', 'site_name' => 'Acme Ltd'],
    );

    expect($match->field)->toBe('alt_seo_meta_title')
        ->and($match->flattensTemplate)->toBeTrue()
        ->and($match->inherited)->toBeFalse();
});

it('prefers a literal match over a templated one', function () {
    $match = $this->resolver->resolve(
        change('title.too_short', 'About | Acme Ltd'),
        ['alt_seo_meta_title' => '{title} | {site_name}', 'seo_title' => 'About | Acme Ltd'],
        ['alt_seo_meta_title', 'seo_title'],
        ['title' => 'About', 'site_name' => 'Acme Ltd'],
    );

    expect($match->field)->toBe('seo_title')
        ->and($match->flattensTemplate)->toBeFalse();
});

it('does not call a genuine match inherited', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'About us.'),
        ['alt_seo_meta_description' => 'About us.'],
        ['alt_seo_meta_description'],
    );

    expect($match->field)->toBe('alt_seo_meta_description')
        ->and($match->inherited)->toBeFalse();
});

it('will not override an inherited value into a field that already has content', function () {
    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Some inherited default text.'),
        ['alt_seo_meta_description' => 'Something else entirely'],
        ['alt_seo_meta_description'],
    );

    expect($match->matched())->toBeFalse()
        ->and($match->reason)->toBe('no_match');
});

it('overrides per page when the value came from fields it may not write to', function () {
    config()->set('altimiser.fields.meta_description', ['alt_seo_meta_description']);

    $match = $this->resolver->resolve(
        change('meta_description.too_short', 'Same text.'),
        ['summary' => 'Same text.', 'intro' => 'Same text.', 'alt_seo_meta_description' => ''],
        ['summary', 'intro', 'alt_seo_meta_description'],
    );

    // Which of summary and intro produced it does not matter, because neither
    // was ever going to be written to. The empty SEO field is the answer.
    expect($match->matched())->toBeTrue()
        ->and($match->field)->toBe('alt_seo_meta_description');
});

it('never writes a meta title into the entry title', function () {
    config()->set('altimiser.fields.title', ['alt_seo_meta_title', 'seo_title', 'meta_title']);

    // The shape every Statamic site with Alt SEO's default {title} template
    // has: the rendered <title> is the entry's own title, verbatim.
    $match = $this->resolver->resolve(
        change('title.too_short', 'Insights'),
        ['title' => 'Insights', 'alt_seo_meta_title' => ''],
        ['title', 'alt_seo_meta_title'],
    );

    // Rewriting the title field would rename the page in the menu, in every
    // listing, in the breadcrumbs and in the control panel.
    expect($match->field)->toBe('alt_seo_meta_title')
        ->and($match->field)->not->toBe('title');
});

it('still writes an H1 to the entry title, which is what an H1 is', function () {
    config()->set('altimiser.fields.h1', ['title']);

    $match = $this->resolver->resolve(
        change('h1.missing', null),
        ['title' => ''],
        ['title'],
    );

    expect($match->field)->toBe('title');
});

it('writes structured data into the Alt SEO schema field', function () {
    // The field runs Antlers before rendering, so one block written across a
    // collection stays per-page once the variables resolve.
    config()->set('altimiser.fields.structured_data', ['alt_seo_schema']);

    $change = change(
        'structured_data.missing',
        null,
        '{"@context":"https://schema.org","@type":"Person","name":"{{ title }}"}',
    );

    $match = $this->resolver->resolve(
        $change,
        ['alt_seo_meta_title' => 'Simon Moore', 'alt_seo_schema' => null],
        ['alt_seo_meta_title', 'alt_seo_schema'],
    );

    // Nothing to match on, so it falls back to the first candidate the blueprint
    // defines and leaves empty. Never overwrites markup somebody wrote.
    expect($match->field)->toBe('alt_seo_schema');
});
