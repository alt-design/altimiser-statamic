<?php

namespace AltDesign\Altimiser\Integrations;

use AltDesign\AltSeo\Helpers\Data;
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

    /**
     * The templates a page falls back to when it sets no title or description
     * of its own.
     *
     * Worth reporting because they decide what most of the site says. A default
     * of {title} alone leaves every result in Google unattributed, and one that
     * builds a description out of the title and the site name produces a
     * description that describes nothing, on every page that has not been given
     * its own.
     *
     * @return array{title: ?string, description: ?string}
     */
    public function defaults(): array
    {
        if (! $this->installed()) {
            return ['title' => null, 'description' => null];
        }

        try {
            $settings = new Data('settings');

            return [
                'title' => $this->template($settings->get('alt_seo_meta_title_default')),
                'description' => $this->template($settings->get('alt_seo_meta_description_default')),
            ];
        } catch (Throwable) {
            // No settings file yet, or a shape we do not recognise. Alt SEO
            // falls back to its own default in that case, which is the good one.
            return ['title' => null, 'description' => null];
        }
    }

    private function template(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) ?: null;
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
            'defaults' => $this->defaults(),
        ];
    }
}
