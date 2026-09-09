<?php

namespace AltDesign\Altimiser\Integrations;

use Composer\InstalledVersions;
use Throwable;

/**
 * What this site's Alt SEO install can actually do.
 *
 * Structured data lives in a field the addon only adds when schema is switched
 * on, so whether we can write it is a property of the site rather than of this
 * receiver. Reporting it lets Altimiser stop generating markup for a site with
 * nowhere to put it, and say why instead of quietly skipping sixty changes.
 */
class AltSeo
{
    private string $package = 'alt-design/alt-seo';

    public function installed(): bool
    {
        try {
            return InstalledVersions::isInstalled($this->package);
        } catch (Throwable) {
            return false;
        }
    }

    public function version(): ?string
    {
        try {
            return $this->installed() ? InstalledVersions::getPrettyVersion($this->package) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The flag decides which blueprint the addon injects, and only the
     * seo-with-schema variant carries the field we write to. Without it there is
     * no field, whatever else the entry has.
     */
    public function schemaEnabled(): bool
    {
        return $this->installed() && (bool) config('alt-seo.alt_seo_enable_schema', false);
    }

    /** @return array<string, mixed> */
    public function state(): array
    {
        return [
            'installed' => $this->installed(),
            'version' => $this->version(),
            'schema_enabled' => $this->schemaEnabled(),
            /*
             * Not about schema storage, despite the name. It governs whether a
             * collection may define its own alt_seo tab instead of taking the
             * injected one. Reported because it changes where the field comes
             * from, which is worth knowing when one turns out to be missing.
             */
            'collection_blueprints' => (bool) config('alt-seo.alt_seo_support_collection_blueprints', false),
        ];
    }
}
