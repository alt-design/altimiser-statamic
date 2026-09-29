# Altimiser for Statamic

Applies Altimiser's approved changes to a Statamic site. It implements the [receiver protocol](https://github.com/alt-design/altimiser/blob/main/docs/receiver-protocol.md), so it is one possible receiver rather than the only shape one can take.

The two repositories do not depend on each other. Each is held to the written protocol rather than to the other's implementation, which is what lets a receiver in another language be written against the same document.

## Installing

```bash
composer require alt-design/altimiser-statamic
php artisan vendor:publish --tag=altimiser-config
```

Set a secret. Anything without one is rejected, so an unconfigured install is inert rather than open.

```
ALTIMISER_SECRET=<a long random string>
```

Then connect the site from the Altimiser end with the same secret:

```bash
php artisan altimiser:connect https://example.com https://example.com/!/altimiser <secret>
```

## What it changes

**Content.** Entry fields, resolved by matching the value Altimiser saw in the rendered page against the entry's field values. No per-site configuration is needed when the value appears in exactly one field, which is the usual case. When two fields hold the same string, `altimiser.fields` breaks the tie; when the tie cannot be broken the change is reported as `ambiguous` rather than guessed at.

**Only ever a field the check was given.** `altimiser.fields` is not a preference order, it is the whole set of fields a check may write to. A value matched in any other field means the page rendered it from somewhere that is not ours to edit, and the answer is a per-page override in the first empty field the check does own.

That rule exists because of the meta title. Alt SEO's title default is commonly `{title}`, so the `<title>` a scan reads back is the entry's own title, character for character. Matching on value alone picks the `title` field, and a meta title rewritten for a search result is not the name of the page: writing it there renames the page in the menu, in every listing, in the breadcrumbs and in the control panel. So `fields.title` lists the SEO fields and never `title`, and a meta title with nowhere of its own to go is written as `alt_seo_meta_title` on that entry instead. `fields.h1` still points at `title`, because an H1 genuinely is the page's name.

For a value that was missing entirely there is nothing to match on, so it falls back to the first field in `altimiser.fields` that the blueprint defines and that is currently empty. It will never overwrite a field that already has content this way.

**Structured data.** Alt SEO ships an `alt_seo_schema` field and runs Antlers over it before rendering, so a JSON-LD block written once across a collection stays per-page once the variables resolve. That makes schema a content change rather than a template edit, which is why it goes through the same applier as a meta description: it matches on the value the scan saw, refuses to overwrite anything already there, and Statamic's own git subscriber commits it. Invalid JSON logs to the console and renders nothing, so the failure mode for markup a model wrote is a page without markup rather than a page with broken markup. Where a collection's own blueprint carries the `alt_seo_schema` field, the block is written once as that field's default rather than into every entry, so entries published later inherit it and an entry with its own value keeps it. `pages` is excluded by default, because entries there share a template and nothing else. A collection that already has a default is left alone.

**Lists in structured data.** A page listing a collection's entries, such as a team page or an article index, should loop over the collection rather than carry a list typed out, so `GET /health` reports each collection with a route and whether a loop over it will render. Alt SEO runs the schema field through Antlers as untrusted content, and Statamic refuses the collection tag there unless the site allows it. A refused loop renders an empty list rather than an error, so Altimiser only writes loops over collections reported as loopable. To allow them, add this to `config/statamic/antlers.php`:

```php
'allowedContentTags' => ['@default', 'collection:*'],
```

Naming collections instead (`'collection:partners:*'`) keeps the others out of reach of every Antlers enabled content field.

**Globals in structured data.** `GET /health` also reports each global set's single line text fields with their values, so a telephone number or an address in the markup can be `{{ contact:telephone }}` rather than a copy that goes stale when the office moves. Only single line text is reported, since a line break breaks the JSON string it lands in. Altimiser passes a model only the values already shown on the public pages it is describing. A global needs nothing allowing, because a variable is not a tag.

It needs `alt_seo_enable_schema` turned on, which is what swaps in the blueprint variant carrying the field. `GET /health` reports what this site's Alt SEO can do, and the check is withheld from the advertised list when it cannot do it. Offering a check with nowhere to write would mean Altimiser spending a model call per collection generating markup and then refusing every one of them at the last moment. It reports the reason too, so the answer to "why is there no structured data" is a line in the queue rather than an investigation.

**Templates.** Two routes, and which one a finding takes depends on whether the element is written out or built from variables.

The **literal patcher**, here in the receiver, handles elements written out in the template as plain HTML: adding `loading="lazy"`, switching a lazy above-the-fold image to `eager`, appending `display=swap` to a Google Fonts URL. It fires only when the literal Altimiser reported appears in exactly one template, exactly once. It is exact, free and repeatable, so it always gets first refusal.

On a real content-driven site it rarely fires. An image rendered as `<img src="{{ hero:url }}">` contains no literal to search for, and that is the common case rather than the exception. Anything it cannot place is reported as `no_match` with the reason, and belongs to the agent instead (see below).

Two templates writing out the same element is reported as `ambiguous` with both files named, rather than one of them being picked. That is usually a partial somebody copied instead of including.

Set `ALTIMISER_PATCH_TEMPLATES=false` to drop template handling entirely and never touch a file.

`h1.missing` is deliberately not on the template list. A page with no heading needs one written, which means deciding what it says, and the only place that decision can be made safely is with the whole repository open. Offering it here is how a page title once got written into a partial shared with four other pages.

## Patches from the agent

The receiver used to ask a model to make the edits the literal patcher could not, one finding at a time, with a dozen files of context. It was the wrong shape for the job. A model given six files and one finding cannot tell that the partial it is editing renders on four other pages, and it has no way to know that the two hundred and thirty eight images with no dimensions are really fifteen images.

That work now happens in Altimiser, in a GitHub Actions run against the client's own repository, where an agent has the whole checkout to read. What comes back is a unified diff, reviewed by a person in Altimiser, and sent here as one request.

```
POST /!/altimiser/patch
```

The body is the patch and a commit message, signed with the same shared secret as everything else. The receiver:

- refuses anything touching a file outside `patch_paths`, which is `resources/views`, `resources/fieldsets` and `resources/blueprints`
- refuses a working tree with uncommitted changes in the files the patch touches
- runs `git apply --check` first, so a patch that will not apply cleanly changes nothing
- applies it and makes one commit, returning the SHA

Nothing partial is ever left behind. The patch applies whole or not at all, and the revert is a `git revert` of one commit, the same as every other change this addon makes.

The receiver holds no model key and makes no model calls of its own for template work. That removes a third-party data transfer from the client's server: template source no longer leaves the site.

## Alt SEO

[Alt SEO](https://github.com/alt-design/Alt-SEO-Addon) is supported directly, and it is worth explaining why it needs to be. Two of its behaviours break naive value matching, and both are the normal case rather than an edge case.

**Inherited defaults.** If `alt_seo_meta_description` is empty on an entry, the addon falls back to `alt_seo_meta_description_default` in `content/alt-seo/settings.yaml`, which every page shares. So a page can render a description that exists nowhere on its own record.

Writing to the shared default would let one approval rewrite hundreds of pages that Altimiser has never looked at. Instead the receiver writes a per-page override into the entry's own empty field, exactly as an editor would in the CP. The blast radius is one page, and the result is flagged `inherited`.

Because the entry field is legitimately empty in this case, the value-changed guard is relaxed for it, but only to write into a field that is empty, so nothing can be overwritten.

**Substituted variables.** Alt SEO expands `{title}`, `{site_name}` and `{description}` at render time, so a field storing `{title} | {site_name}` renders as `About | Acme Ltd` and never equals what was scanned. The receiver runs the same substitution before comparing, which lets it identify the right field. Writing then replaces the pattern with a literal for that page, and the result is flagged `flattened_template`. A field holding the value verbatim always wins over one that only matches after substitution, since writing to it discards nothing.

The addon's handles are already in the default `altimiser.fields` config, ahead of the generic ones, so an Alt SEO site needs no configuration.

**Not handled:** `alt_seo_noindex` and `alt_seo_nofollow` are toggles rather than text and no check currently targets them, and the hard-coded final fallback (`{title} | {site name}` when nothing at all is set) resolves through the inherited-override path like any other inherited value.

## Version control

Template edits are committed, one commit per change.

**Content needs nothing from us.** Statamic Pro's own git automation listens for `Saved` events, and `$entry->save()` fires them, so on a site with `STATAMIC_GIT_ENABLED=true` every meta description change is already committed. Statamic tracks content and config, not views, which is why templates are handled here.

**Templates require a clean tree.** Before editing, the receiver checks that the specific file has no uncommitted changes, and refuses with `git_dirty` if it does. That work belongs to somebody and it is not being swept into an automated commit. The check is per-file, so an unrelated dirty file elsewhere does not block anything. A site that is not a git working tree gets `git_unavailable` and its templates are never touched.

**Commits use the `[BOT]` prefix**, which is the convention Statamic suggests and which Forge Quick Deploy can be configured to skip. That matters: the receiver pushes its commit back to the repo, and without the prefix that push would trigger a redeploy of the site that just made the change. With it, the fix reaches the repository and is picked up by the next real deploy instead of being overwritten by it.

Identity is passed per commit (`git -c user.name=...`) because the web user on a deployed site usually has no `~/.gitconfig`, which would otherwise make every commit fail.

**A commit that fails rolls the file back.** An edit we could not commit is an untracked change nobody asked for, so it is restored rather than left for someone to find later. A push that fails is not rolled back, since the commit exists locally, but the result reports `pushed: false` so Altimiser can tell you.

Each edit is one commit, so undoing one is `git revert <sha>`. The SHA comes back on the change's `location`.

| Variable | Purpose |
| --- | --- |
| `ALTIMISER_GIT` | Master switch. Off means templates are edited without version control. |
| `ALTIMISER_GIT_PUSH` | Whether to push after committing. Off means edits do not survive a deploy. |
| `ALTIMISER_GIT_REMOTE` / `ALTIMISER_GIT_BRANCH` | Defaults to `origin` and whatever branch is checked out. |
| `ALTIMISER_GIT_MESSAGE` | Defaults to `[BOT] Altimiser: :check on :url`. Keep the prefix. |
| `ALTIMISER_GIT_AUTHOR` | Commit author, `Name <email>`. |

## In the control panel

Point the addon at Altimiser:

```
ALTIMISER_URL=https://altimiser.example.com
```

That adds **Altimiser**, under a rocket, to the Tools section of the sidebar. The page is the review queue and
nothing else: the queue carries its own heading and figures, so a second set around the frame would
only compete with them.

The sidebar entry carries a count, the way **Updates** does. It uses Statamic's own `ui-badge` with
the props their `UpdatesBadge` passes (amber, small, pill), rather than classes of our own that
would match today and drift the first time they restyle the panel. It is the only thing in the
control panel that says there is work waiting without somebody going looking for it. It counts what is
actionable, not skipped and not yet approved, so it reaches zero when the queue has been worked
through. The obvious alternative, everything this receiver could act on, never would, and a badge
that never clears is one people learn to stop seeing.

Those counts are cached for `ALTIMISER_SUMMARY_MINUTES` (default 5). The badge is read on every
control panel page, and without a cache each one would wait on a cross-network request before
rendering.

There is also a dashboard widget if you want the count on the front page. Statamic's dashboard is an
explicit list, so it has to be named in `config/statamic/cp.php`:

```php
'widgets' => [
    ['type' => 'altimiser', 'width' => 100],
    // your existing widgets
],
```

### How access works

The link is signed here, server side, with the same shared secret used for everything else, and it
expires (`ALTIMISER_LINK_MINUTES`, default 30). Nothing usable reaches the browser: the URL carries
only a signature. The control panel user's email is inside the signed payload, so Altimiser can
record who approved what, and the page sits behind the control panel's own authentication.

The counts come from a single signed server-to-server request. It is the only inbound direction in
the protocol, it is read-only, and it fails quietly: an unreachable Altimiser costs you the numbers,
not the page.

### The embedded frame

Altimiser returns `frame-ancestors` naming only this site's origin, so nothing else can frame a
client's data.

**Embedding across origins needs the session cookie to survive a third-party context**, which means
HTTPS on both ends. Altimiser sets `SameSite=None`, `Secure` and `Partitioned` for embedded requests
automatically, but a browser with third-party cookies fully blocked may still refuse. Every page
therefore carries an **Open in a new tab** link that always works, and says so underneath the frame
if it comes up blank. Worth checking in Safari specifically before you rely on the embed with a
client.

## The safety rule

A scan is a snapshot. Between scanning and applying, an editor may have rewritten the very field Altimiser wants to change.

Every change carries the value the scan saw. If what is on the site no longer matches, the change is skipped with reason `value_changed`. Nothing is overwritten on the basis of stale information.

## Configuration

| Key | Purpose |
| --- | --- |
| `secret` | Shared secret for request signatures. Empty disables the receiver. |
| `route_prefix` | Where the endpoints mount. Defaults to `!/altimiser`. |
| `link_minutes` / `summary_minutes` | How long a review link stays valid, and how long the sidebar badge caches its count. |
| `patch_templates` | Whether template files may be written to at all, by the literal patcher or by a patch from the agent. |
| `patch_paths` | Where a patch from the agent may write. Reaches further than `template_paths` because a heading the agent cannot promote without an option to promote it is a field that has to exist first. Falls back to `template_paths` when unset. |
| `template_paths` | Directories searched for templates, relative to the app root. |
| `content_checks` / `asset_checks` / `file_checks` / `template_checks` | Which applier handles which check. |
| `fields` | Candidate field handles per check group. |
| `batch_size` / `time_budget` | How much one request will attempt, by count and by seconds. Set `time_budget` below the web server's own timeout. |
| `git.*` | Commit and push behaviour. Every change ends up in a commit: entry and asset saves fire Statamic's own git subscriber, and whatever it has not committed by the time the save returns is committed here instead. |

## Testing

```bash
composer install
./vendor/bin/pest
```

Testbench provides a container and a config array, and that is all these tests need. The addon's service provider is deliberately never registered: it extends Statamic's own, so booting it would drag a CMS in to exercise classes that take plain arrays and strings. What is under test is the applying logic, not how Statamic discovers it.

`ChangeRequestHandlingTest` is this package's half of the protocol. A protocol-shaped payload, signed the way the document describes, travels through verification and dispatch and lands as a real edit to a real file, with nothing imported from the central service. `SignatureTest` computes the HMAC from the protocol description for the same reason. Together they are what any receiver has to prove, in any language.

`EditValidator` is the one to read first if you are reviewing this for safety: every rule that stops a model's output reaching a client's template is there, and each has a test that proves the file is left untouched when the rule fires.

`ContentApplier`'s git behaviour is covered against a stub entry, which is why `ContentResolver::find()` returns `?object` rather than `?Entry`.

`AssetApplier` and `TemplateLocator` are the exceptions: their Statamic glue (`Asset::findByUrl`, `$entry->template()`, `$asset->set()->save()`) is not covered and would need a booted CMS to exercise properly. Both are deliberately thin for that reason.
