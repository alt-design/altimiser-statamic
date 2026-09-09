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
     * How many changes one request will attempt. Model-driven template edits take
     * tens of seconds each, so a request that tried to do hundreds would be cut
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
     * looking for in exactly one place. Set this to false to make the receiver
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
     * When the deterministic patcher cannot find what it is looking for, which
     * is most of the time on a real site because images and embeds come from
     * variables rather than literals, a model is asked to make the edit instead.
     *
     * It never rewrites a file. It returns one exact search-and-replace, and the
     * receiver applies it only if the search string appears exactly once in a
     * file that was offered to it. See EditValidator for the full set of rules.
     */
    'ai' => [
        'enabled' => env('ALTIMISER_AI', false),
        // Falls back to OPENAI_API_KEY so a site that already talks to OpenAI needs no new variable.
        'key' => env('ALTIMISER_AI_KEY', env('OPENAI_API_KEY')),
        'endpoint' => env('ALTIMISER_AI_ENDPOINT', 'https://api.openai.com/v1/chat/completions'),
        'model' => env('ALTIMISER_AI_MODEL', 'gpt-5-mini'),
        /*
         * Kept below time_budget on purpose. A call that outlives the budget it
         * is being timed against cannot be stopped by it, and the request gets
         * killed by the web server instead of deferring its work politely.
         */
        'timeout' => env('ALTIMISER_AI_TIMEOUT', 30),
        // Model calls made at once during a batch. Read-only, so they cannot race.
        'concurrency' => 10,
        // At ten concurrent calls a rate limit is routine. Without a retry a 429
        // is indistinguishable from a change that genuinely cannot be made.
        'retries' => 3,
        /*
         * "Find this tag and give me a line range" is not a problem that rewards
         * thinking for thirty seconds. Set to null for a model that has never
         * heard of the parameter, which is anything before the reasoning models.
         */
        'reasoning_effort' => env('ALTIMISER_AI_REASONING', 'low'),
        // Reasoning tokens count towards this, so it is not as generous as it looks.
        'max_tokens' => env('ALTIMISER_AI_MAX_TOKENS', 8000),
        'max_files' => 12,
        'max_bytes' => 60000,
        'prompt' => 'You edit Statamic templates written in Antlers or Blade.

You will be given one SEO or performance fix and the templates that render the
page, with every line numbered. Find the single tag that produces the reported
element and return the smallest line range that contains it, plus the
replacement for those lines.

Rules you must follow:
- start_line and end_line refer to the numbers shown in the file. Keep the range
  as small as possible: one line is ideal, and never more than a few.
- new_text replaces exactly those lines. Do not include the line numbers.
- Preserve the original indentation, and change nothing except what the fix needs.
- Never alter Antlers or Blade expressions, variable names, or template logic.
- Never reformat or reorder attributes, and never touch surrounding markup.
- If the tag already has the attribute, update its value rather than adding a second.
- If you cannot find the tag, or the element comes from a partial you were not
  given, or applying the fix would risk changing what the page renders, set
  applicable to false and explain why. Declining is always better than guessing.',
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

    'template_checks' => [
        'h1.missing',
        'image.not_lazy_loaded',
        'image.lazy_above_fold',
        'image.dimensions_missing',
        'iframe.not_lazy_loaded',
        'font.no_display_swap',
        'performance.lcp_not_prioritised',
    ],

    /*
     * Candidate fields for each check, tried in order when the current value is
     * empty and there is nothing to match against. When the current value is
     * present the receiver matches on the value itself and only falls back to
     * this list to break a tie.
     */
    'fields' => [
        'title' => ['alt_seo_meta_title', 'seo_title', 'meta_title', 'title'],
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
