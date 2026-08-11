<?php

use AltDesign\Altimiser\Applying\AiTemplateEditor;
use AltDesign\Altimiser\Applying\ChangeRequest;
use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Applying\ImageDimensions;
use AltDesign\Altimiser\Applying\TemplateApplier;
use AltDesign\Altimiser\Applying\TemplateLocator;
use AltDesign\Altimiser\Applying\TemplatePatcher;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->relative = 'tests/tmp/altimiser-ai-views';
    $this->views = base_path($this->relative);

    File::deleteDirectory($this->views);
    File::makeDirectory($this->views, recursive: true);

    config()->set('altimiser.patch_templates', true);
    config()->set('altimiser.template_paths', [$this->relative]);
    config()->set('altimiser.template_checks', ['image.not_lazy_loaded']);
    config()->set('altimiser.ai.enabled', true);
    config()->set('altimiser.ai.model', 'gpt-5-mini');
    config()->set('altimiser.ai.prompt', 'Edit the template.');
    config()->set('altimiser.ai.key', 'test-key');
    config()->set('altimiser.ai.endpoint', 'https://api.openai.com/v1/chat/completions');
    config()->set('altimiser.git.enabled', false);
});

afterEach(function () {
    File::deleteDirectory($this->views);
});

function fakeEdit(array $response): void
{
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode($response)]]],
        ]),
    ]);
}

function variableChange(): ChangeRequest
{
    return new ChangeRequest(
        id: 'abc',
        check: 'image.not_lazy_loaded',
        url: 'https://example.com/about',
        target: ['selector' => 'img[src="/img/asset/abc123.jpg"]', 'attribute' => 'loading', 'match' => '/img/asset/abc123.jpg'],
        currentValue: null,
        suggestedValue: 'lazy',
    );
}

/** @param array<string, string> $candidates */
function applierSeeing(array $candidates): TemplateApplier
{
    $locator = Mockery::mock(TemplateLocator::class)->makePartial();
    $locator->shouldReceive('containing')->andReturn([]);
    $locator->shouldReceive('forUrl')->andReturn($candidates);

    return new TemplateApplier(
        new TemplatePatcher,
        $locator,
        app(AiTemplateEditor::class),
        app(GitRepository::class),
        app(ImageDimensions::class),
    );
}

it('fixes an image the literal matcher cannot see', function () {
    $path = $this->views.'/page.antlers.html';
    $source = '<article>'."\n".'    <img src="{{ hero:url }}" alt="{{ hero:alt }}">'."\n".'    <p>body</p>'."\n".'</article>';
    File::put($path, $source);

    $relative = $this->relative.'/page.antlers.html';

    fakeEdit([
        'applicable' => true,
        'file' => $relative,
        'start_line' => 2,
        'end_line' => 2,
        'new_text' => '    <img src="{{ hero:url }}" alt="{{ hero:alt }}" loading="lazy">',
        'reasoning' => 'The hero image is rendered from a variable here.',
    ]);

    $result = applierSeeing([$relative => $source])->apply(variableChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['resolved_by'])->toBe('model')
        ->and(File::get($path))->toContain('loading="lazy"')
        ->and(File::get($path))->toContain('{{ hero:alt }}');
});

it('writes nothing when the model names a file it was not given', function () {
    $path = $this->views.'/page.antlers.html';
    $source = '<img src="{{ hero:url }}">';
    File::put($path, $source);

    fakeEdit([
        'applicable' => true,
        'file' => '.env',
        'start_line' => 1,
        'end_line' => 1,
        'new_text' => 'APP_KEY=hacked',
        'reasoning' => 'Nope.',
    ]);

    $result = applierSeeing([$this->relative.'/page.antlers.html' => $source])
        ->apply(variableChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['message'])->toContain('not offered')
        ->and(File::get($path))->toBe($source);
});

it('writes nothing when the model points at lines that do not exist', function () {
    $path = $this->views.'/page.antlers.html';
    $source = '<img src="{{ hero:url }}">';
    File::put($path, $source);

    $relative = $this->relative.'/page.antlers.html';

    fakeEdit([
        'applicable' => true,
        'file' => $relative,
        'start_line' => 88,
        'end_line' => 99,
        'new_text' => '<img src="/hero.jpg" class="hero" loading="lazy">',
        'reasoning' => 'Pointed at lines that do not exist.',
    ]);

    $result = applierSeeing([$relative => $source])->apply(variableChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and(File::get($path))->toBe($source);
});

it('leaves the file alone on a dry run but reports the exact edit', function () {
    $path = $this->views.'/page.antlers.html';
    $source = '<section>'."\n".'    <img src="{{ hero:url }}">'."\n".'    <p>{{ content }}</p>'."\n".'</section>';
    File::put($path, $source);

    $relative = $this->relative.'/page.antlers.html';

    fakeEdit([
        'applicable' => true,
        'file' => $relative,
        'start_line' => 2,
        'end_line' => 2,
        'new_text' => '    <img src="{{ hero:url }}" loading="lazy">',
        'reasoning' => 'Adds the attribute.',
    ]);

    $result = applierSeeing([$relative => $source])->apply(variableChange(), dryRun: true)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['edit']['lines'])->toBe('2-2')
        ->and(File::get($path))->toBe($source);
});

it('reports the model declining rather than forcing a change', function () {
    $source = '<img src="{{ hero:url }}">';
    $relative = $this->relative.'/page.antlers.html';
    File::put($this->views.'/page.antlers.html', $source);

    fakeEdit([
        'applicable' => false,
        'file' => '',
        'start_line' => 0,
        'end_line' => 0,
        'new_text' => '',
        'reasoning' => 'The image is rendered by a partial I was not given.',
    ]);

    $result = applierSeeing([$relative => $source])->apply(variableChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['message'])->toContain('partial I was not given');
});

it('does not call the model when no templates could be located', function () {
    Http::fake();

    $result = applierSeeing([])->apply(variableChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('skipped')
        ->and($result['message'])->toContain('No templates could be located');

    Http::assertNothingSent();
});

it('prefers the deterministic patcher and never calls the model when a literal matches', function () {
    File::put($this->views.'/page.antlers.html', '<img src="/img/asset/abc123.jpg" alt="Hero">');

    Http::fake();

    $result = app(TemplateApplier::class)->apply(variableChange(), dryRun: false)->toArray();

    expect($result['status'])->toBe('applied')
        ->and($result['location']['resolved_by'])->toBe('src')
        ->and(File::get($this->views.'/page.antlers.html'))->toContain('loading="lazy"');

    Http::assertNothingSent();
});

function declined(): array
{
    return ['choices' => [['message' => ['content' => json_encode([
        'applicable' => false,
        'file' => '',
        'start_line' => 0,
        'end_line' => 0,
        'new_text' => '',
        'reasoning' => 'Declining in this test.',
    ])]]]];
}

it('puts the templates before the question so the prefix can be cached', function () {
    Http::fake(['api.openai.com/*' => Http::response(declined())]);

    app(AiTemplateEditor::class)->edit(
        variableChange(),
        ['resources/views/page.antlers.html' => '<img src="{{ hero }}">'],
    );

    Http::assertSent(function ($request): bool {
        $prompt = $request['messages'][1]['content'];

        // The expensive, repeated part first; the part that differs last. The
        // other way round, every call is a fresh read of the whole file.
        return strpos($prompt, '=== FILE:') < strpos($prompt, 'Check: image.not_lazy_loaded');
    });
});

it('asks for as little thinking as the job needs', function () {
    config()->set('altimiser.ai.reasoning_effort', 'low');
    config()->set('altimiser.ai.max_tokens', 8000);

    Http::fake(['api.openai.com/*' => Http::response(declined())]);

    app(AiTemplateEditor::class)->edit(variableChange(), ['resources/views/page.antlers.html' => '<img>']);

    Http::assertSent(fn ($request): bool => $request['reasoning_effort'] === 'low'
        && $request['max_completion_tokens'] === 8000);
});

it('sends no tuning a plainer model would reject', function () {
    config()->set('altimiser.ai.reasoning_effort', null);
    config()->set('altimiser.ai.max_tokens', null);

    Http::fake(['api.openai.com/*' => Http::response(declined())]);

    app(AiTemplateEditor::class)->edit(variableChange(), ['resources/views/page.antlers.html' => '<img>']);

    Http::assertSent(fn ($request): bool => ! array_key_exists('reasoning_effort', $request->data())
        && ! array_key_exists('max_completion_tokens', $request->data()));
});

it('tries again when the model is busy rather than calling the change failed', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(['error' => 'slow down'], 429)
        ->push(declined()),
    ]);

    $edit = app(AiTemplateEditor::class)->edit(variableChange(), ['resources/views/page.antlers.html' => '<img>']);

    Http::assertSentCount(2);
    expect($edit->rejection)->toBe('Declining in this test.');
});

it('repeats a refusal rather than reporting it as an unreadable reply', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['message' => ['refusal' => 'I will not edit this.', 'content' => null]]],
    ])]);

    $edit = app(AiTemplateEditor::class)->edit(variableChange(), ['resources/views/page.antlers.html' => '<img>']);

    expect($edit->rejection)->toContain('I will not edit this.');
});

it('says when a reply was cut short instead of blaming the json', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [['finish_reason' => 'length', 'message' => ['content' => '{"applicable":tr']]],
    ])]);

    $edit = app(AiTemplateEditor::class)->edit(variableChange(), ['resources/views/page.antlers.html' => '<img>']);

    expect($edit->rejection)->toContain('cut short');
});
