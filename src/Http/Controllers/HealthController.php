<?php

namespace AltDesign\Altimiser\Http\Controllers;

use AltDesign\Altimiser\AdvertisedChecks;
use AltDesign\Altimiser\Altimiser;
use AltDesign\Altimiser\Applying\AiTemplateEditor;
use AltDesign\Altimiser\Applying\GitRepository;
use AltDesign\Altimiser\Integrations\AltSeo;
use Illuminate\Http\JsonResponse;
use Statamic\Facades\Site;
use Statamic\Statamic;
use Throwable;

class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'receiver' => 'statamic',
            'receiver_version' => app(Altimiser::class)->version(),
            'cms_version' => $this->cmsVersion(),
            'capabilities' => $this->capabilities(),
            'checks' => app(AdvertisedChecks::class)->all(),
            'git' => $this->git(),
            'integrations' => ['alt_seo' => app(AltSeo::class)->state()],
            'site_url' => Site::default()->absoluteUrl(),
        ]);
    }

    /**
     * Statamic reads its own version out of composer.lock, which some deploys
     * strip. Worth knowing but not worth refusing to answer over: without this,
     * a site missing that file cannot connect at all.
     */
    private function cmsVersion(): ?string
    {
        try {
            return Statamic::version();
        } catch (Throwable) {
            return null;
        }
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
}
