<?php

namespace AltDesign\Altimiser;

use AltDesign\Altimiser\Integrations\AltSeo;

/**
 * The exact checks this receiver will act on.
 *
 * Altimiser sends nothing that is not on this list, so what is here is the whole
 * contract. Its own class rather than a method on the health endpoint because
 * the list depends on what the site has installed, which is worth being able to
 * test without booting a CMS.
 */
class AdvertisedChecks
{
    public function __construct(private AltSeo $altSeo) {}

    /** @return array<int, string> */
    public function all(): array
    {
        $checks = [
            ...config('altimiser.content_checks', []),
            ...config('altimiser.asset_checks', []),
            ...config('altimiser.file_checks', []),
        ];

        if (config('altimiser.patch_templates')) {
            $checks = [...$checks, ...config('altimiser.template_checks', [])];
        }

        // Offering a check we have nowhere to write is worse than not offering
        // it. Altimiser would spend a model call per collection generating
        // markup and then refuse every one of them at the last moment.
        if (! $this->altSeo->schemaEnabled()) {
            $checks = array_diff($checks, ['structured_data.missing']);
        }

        return array_values(array_unique($checks));
    }
}
