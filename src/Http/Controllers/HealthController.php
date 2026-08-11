<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\Applying\AiTemplateEditor;
use AltDesign\Altimiser\Applying\GitRepository;
use Illuminate\Http\JsonResponse;
use Statamic\Facades\Site;
use Statamic\Statamic;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'receiver' => 'statamic',
            'receiver_version' => '0.4.0',
            'cms_version' => Statamic::version(),
            'capabilities' => $this->capabilities(),
            'checks' => $this->checks(),
            'git' => $this->git(),
            'site_url' => Site::default()->absoluteUrl(),
        ]);
    }

    /** @return array<int, string> */
    private function capabilities(): array
    {
        $capabilities = ['content.update'];

        if (config('altimiser.patch_templates')) {
            $capabilities[] = 'template.patch';

            if (app(AiTemplateEditor::class)->isConfigured()) {
                $capabilities[] = 'template.ai_patch';
            }
        }

        return $capabilities;
    }

    /**
     * Surfaced so Altimiser can tell, before it sends anything, whether template
     * edits on this site will be committed or silently lost at the next deploy.
     *
     * @return array<string, mixed>
     */
    private function git(): array
    {
        $repository = app(GitRepository::class);

        return [
            'enabled' => $repository->enabled(),
            'available' => $repository->isAvailable(),
            'branch' => $repository->currentBranch(),
            'pushes' => (bool) config('altimiser.git.push'),
            'statamic_automation' => (bool) config('statamic.git.enabled', false),
        ];
    }

    /**
     * The exact checks this receiver will act on. Altimiser sends nothing that
     * is not on this list, so adding a transform here is the only change needed
     * to start applying a new kind of fix.
     *
     * @return array<int, string>
     */
    private function checks(): array
    {
        $checks = [
            ...config('altimiser.content_checks', []),
            ...config('altimiser.asset_checks', []),
            ...config('altimiser.file_checks', []),
        ];

        if (config('altimiser.patch_templates')) {
            $checks = [...$checks, ...config('altimiser.template_checks', [])];
        }

        return array_values(array_unique($checks));
    }
}
