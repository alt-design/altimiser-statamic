<?php

return [
    /*
     * The shared secret Altimiser signs its requests with. Requests without a
     * valid signature are rejected, so leaving this empty disables the receiver.
     */
    'secret' => env('ALTIMISER_SECRET'),

    'route_prefix' => env('ALTIMISER_ROUTE_PREFIX', '!/altimiser'),

    /*
     * Where Altimiser lives, so the control panel can link into its review queue.
     * The link is signed with the secret above and expires, so nothing usable
     * reaches the browser.
     */
    'url' => env('ALTIMISER_URL'),
    'link_minutes' => env('ALTIMISER_LINK_MINUTES', 30),

    /*
     * How long the control panel keeps Altimiser's counts before asking again.
     * The sidebar badge reads these on every page, so without a cache every
     * screen would wait on a cross-network request. A few minutes stale is fine
     * for a queue somebody works through weekly.
     */
    'summary_minutes' => env('ALTIMISER_SUMMARY_MINUTES', 5),

    /*
     * How this site identifies itself to Altimiser. Defaults to Statamic's own
     * absolute site URL, which is right unless the two disagree.
     */
    'site_url' => env('ALTIMISER_SITE_URL'),

    /*
     * How many changes one request will attempt. Each one reads and writes files
     * and makes a commit, so a request that tried to do hundreds would be cut
     * off by the web server. Anything beyond this is reported as deferred and
     * Altimiser asks again.
     */
    'batch_size' => env('ALTIMISER_BATCH_SIZE', 10),

    /*
     * Seconds a request will keep starting new changes for. The count above
     * cannot know how slow a particular ten will be, and a request killed by the
     * web server mid-change leaves a file written but not committed. Set this
     * comfortably below the shortest of fastcgi_read_timeout, max_execution_time
     * and whatever the load balancer allows.
     */
    'time_budget' => env('ALTIMISER_TIME_BUDGET', 45),

    /*
     * Templates are only patched when Altimiser can find the exact literal it is
     * looking for in exactly one place. Anything built from variables is left
     * alone here and belongs to the agent, which reads the whole repository and
     * sends a patch back for review. Set this to false to make the receiver
     * report template findings without ever writing to a file.
     */
    'patch_templates' => env('ALTIMISER_PATCH_TEMPLATES', true),

    'template_paths' => [
        'resources/views',
    ],

    /*
     * Template edits are committed, one commit per change, so every automated
     * edit is isolated in the history and revertable with git revert.
     *
     * A template with uncommitted changes is refused rather than edited: that
     * work belongs to somebody and we are not sweeping it into our commit.
     * Where git is unavailable, templates are not touched at all.
     *
     * The [BOT] prefix is the convention Statamic suggests and Forge Quick
     * Deploy can be configured to skip, so pushing a fix back to the repo does
     * not trigger a redeploy of the site that just made it.
     */
    'git' => [
        'enabled' => env('ALTIMISER_GIT', true),
        'binary' => env('ALTIMISER_GIT_BINARY', 'git'),
        'push' => env('ALTIMISER_GIT_PUSH', true),
        'remote' => env('ALTIMISER_GIT_REMOTE', 'origin'),
        // Null follows whatever branch the site is checked out on.
        'branch' => env('ALTIMISER_GIT_BRANCH'),
        'message' => env('ALTIMISER_GIT_MESSAGE', '[BOT] Altimiser: :check on :url'),
        'author' => env('ALTIMISER_GIT_AUTHOR', 'Altimiser <altimiser@alt-design.net>'),
    ],

    /*
     * Which applier handles which check. Move a check between these lists if a
     * site holds the value somewhere other than where we assume.
     */
    'content_checks' => [
        'structured_data.missing',
        'title.missing',
        'title.too_short',
        'title.too_long',
        'title.irrelevant',
        'meta_description.missing',
        'meta_description.too_short',
        'meta_description.too_long',
        'meta_description.irrelevant',
        'open_graph.missing',
        'canonical.missing',
    ],

    /*
     * Values that belong to an asset rather than to a page. Alt text describes
     * the image, so it is written once on the asset and every page using it
     * follows.
     */
    'asset_checks' => [
        'image.alt_missing',
        'image.alt_too_long',
    ],

    /*
     * Plain files with a well understood shape, written deterministically.
     */
    'file_checks' => [
        'site.robots_missing',
        'sitemap.not_declared_in_robots',
    ],

    /*
     * Attributes on elements a template writes out literally. Nothing here needs
     * a judgement about the page: adding loading="lazy" to a tag is the same
     * edit wherever that tag is.
     *
     * h1.missing is deliberately not on this list. A page with no heading needs
     * one written, which means deciding what it says, and the only place that
     * decision can be made safely is with the whole repository open. That is the
     * agent's, and offering it here is how a page title once got written into a
     * partial shared with four other pages.
     */
    'template_checks' => [
        'image.not_lazy_loaded',
        'image.lazy_above_fold',
        'image.dimensions_missing',
        'iframe.not_lazy_loaded',
        'font.no_display_swap',
        'performance.lcp_not_prioritised',
    ],

    /*
     * Structured data written once for a collection rather than once per entry.
     *
     * The markup is a template with Antlers in it, so every entry in a
     * collection wants the same one, and an entry published next week wants it
     * too. Statamic augments an empty field to its blueprint default, and Alt
     * SEO reads the augmented value, so a default on the collection's own
     * blueprint renders everywhere it is not overridden.
     *
     * Only collections whose blueprint already carries the alt_seo_schema field
     * are written to. Adding the field here would take Alt SEO's injected tab
     * away from that collection along with every other field on it, so a site
     * opts in by putting the field in its own blueprint.
     *
     * except lists the collections whose entries genuinely differ from one
     * another. Pages is the obvious one: a contact page and a service page
     * share a template and nothing else.
     */
    'collection_defaults' => [
        'enabled' => env('ALTIMISER_COLLECTION_DEFAULTS', true),
        'except' => ['pages'],
    ],

    /*
     * Candidate fields for each check, tried in order when the current value is
     * empty and there is nothing to match against. When the current value is
     * present the receiver matches on the value itself and only falls back to
     * this list to break a tie.
     */
    'fields' => [
        /*
         * Never the entry's own title. A meta title and a page's name are two
         * different things: the first is written for a search result, the
         * second is what the menu, the breadcrumbs, every listing and the
         * control panel call this page. Alt SEO's title default is commonly
         * {title}, which makes them identical on the rendered page and makes
         * matching on value alone offer to rename the site.
         */
        'title' => ['alt_seo_meta_title', 'seo_title', 'meta_title'],
        'meta_description' => ['alt_seo_meta_description', 'seo_description', 'meta_description', 'description', 'summary'],
        'canonical' => ['alt_seo_canonical_url', 'canonical_url', 'canonical'],
        'open_graph' => ['alt_seo_social_title', 'alt_seo_social_description', 'og_title', 'og_description', 'og_image'],
        'h1' => ['title'],
        'image' => ['alt', 'alt_text'],
        /*
         * Alt SEO stores JSON-LD per entry and runs Antlers over it before
         * rendering, so one block written across a collection stays per-page
         * once the variables resolve. Invalid JSON logs to the console and
         * renders nothing, which is the failure mode you want for markup a
         * model wrote.
         */
        'structured_data' => ['alt_seo_schema'],
    ],
];
