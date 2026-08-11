# Altimiser for Statamic

Applies Altimiser's approved changes to a Statamic site. It implements the [receiver protocol](https://github.com/alt-design/altimiser/blob/main/docs/receiver-protocol.md), so it is one possible receiver rather than the only shape one can take.

**Tests for this package live in [alt-design/altimiser](https://github.com/alt-design/altimiser)**, which installs it as a dev dependency. They are there rather than here because what is worth proving is that both ends of the protocol still agree, and only that repository has both.

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

For a value that was missing entirely there is nothing to match on, so it falls back to the first field in `altimiser.fields` that the blueprint defines and that is currently empty. It will never overwrite a field that already has content this way.

**Templates.** Two paths, tried in order.

The **literal patcher** handles elements written out in the template as plain HTML: adding `loading="lazy"`, switching a lazy above-the-fold image to `eager`, appending `display=swap` to a Google Fonts URL. It fires only when the literal Altimiser reported appears in exactly one template, exactly once. It is exact, free and repeatable, so it always gets first refusal.

On a real content-driven site it rarely fires. An image rendered as `<img src="{{ hero:url }}">` contains no literal to search for, and that is the common case rather than the exception.

The **AI editor** picks up from there. See below.

Set `ALTIMISER_PATCH_TEMPLATES=false` to drop template handling entirely and never touch a file.

## AI template editing

Off by default. Turn it on with:

```
ALTIMISER_AI=true
OPENAI_API_KEY=sk-...
ALTIMISER_AI_MODEL=gpt-5-mini
```

The model's job is the part no string matcher can do: reading Antlers or Blade and working out which tag produces an element that was built from variables. It is not trusted with anything else.

**It never rewrites a file.** The templates it is shown are line-numbered, and it answers with a line range and the replacement for exactly those lines. The receiver applies it only if every one of these holds:

- the file it names is one the receiver chose to send it
- the line range exists in that file
- the range is at most 12 lines, because a fix that needs more than that is not the kind of fix this does
- it is not the whole file
- the replacement differs from what is there, and is not dramatically shorter (these fixes add attributes; an edit that mostly deletes is not a fix)
- where the scan reported the rendered element, the replacement still produces something consistent with it

An edit failing any rule is discarded and reported as `no_match` with the reason. The model can also decline, and a declared "I cannot find this" is treated as a valid answer rather than something to retry harder.

Context is chosen rather than searched, and it is narrowed twice. Every literal the rendered element offers, its class list, id and alt text as well as its src, is searched for first: where one of them lands in a handful of files, those are the files the model gets. Only when nothing narrows it does the receiver fall back to what Statamic already knows, which is the entry's template, its layout and the partials those reference one level deep. Either way it is capped at `ai.max_files` and `ai.max_bytes`, 12 files and 60KB by default.

That narrowing is worth more than it sounds. Of 131 distinct image elements on one real site, 4 were findable by their src and 82 by their class, because a Glide URL appears nowhere in the source and a class list appears verbatim.

Use `--dry-run` from the Altimiser end to see the exact edit the model proposed, per page, before anything is written.

### What a batch costs

Three things keep the bill down, and they matter more than the choice of model.

**One question per distinct question.** An image inside a loop is rendered on forty pages and arrives as forty changes, every one asking the same thing of the same templates. They are fingerprinted on everything the prompt is built from, asked once, and the answer is shared. On a real queue that was 781 changes over 218 actual questions, so roughly a quarter of the calls. Fingerprinting includes the value and the candidate file list, because dimensions differ per asset and the same element on two URLs can come from different templates.

**Templates first, question last.** OpenAI caches identical prompt prefixes and charges a fraction for a hit, but only for a prefix. A per-change line at the top of the prompt would make every call a fresh read of fifteen thousand tokens of markup, so the files go in front and the change-specific part goes after them. Every change against the same templates then rides the same cached prefix.

**As little thinking as the job needs.** `ai.reasoning_effort` defaults to `low`, because finding a tag and returning a line range does not reward deliberation. Set it to null for a model that predates the parameter, and it is left out of the request entirely.

Rate limits are retried three times, since at ten concurrent calls a 429 is routine rather than exceptional, and without that it would be recorded as a change that could not be made. A refusal and a reply cut short by the token limit are each reported as what they are.

`ai.timeout` is deliberately below `time_budget`, and the budget's clock starts before the model calls rather than after them. Resolving is by far the slowest part of a request, so a budget that only covered writing let a slow resolve run the whole request past whatever the web server allowed. That is what produces a page of changes that simply never answered.

**Worth knowing:** this sends template source to OpenAI from the client's own server, using the key configured on that site. Nothing goes via Altimiser's infrastructure, but it is still a third-party data transfer, and clients with strict data rules should be asked. Prompt and model live in the published config, so they can be tuned per site without a new release, but a genuine behaviour improvement still means updating the addon everywhere.

## Alt SEO

[Alt SEO](https://github.com/alt-design/Alt-SEO-Addon) is supported directly, and it is worth explaining why it needs to be. Two of its behaviours break naive value matching, and both are the normal case rather than an edge case.

**Inherited defaults.** If `alt_seo_meta_description` is empty on an entry, the addon falls back to `alt_seo_meta_description_default` in `content/alt-seo/settings.yaml`, which every page shares. So a page can render a description that exists nowhere on its own record.

Writing to the shared default would let one approval rewrite hundreds of pages that Altimiser has never looked at. Instead the receiver writes a per-page override into the entry's own empty field, exactly as an editor would in the CP. The blast radius is one page, and the result is flagged `inherited`.

Because the entry field is legitimately empty in this case, the value-changed guard is relaxed for it — but only to write into a field that is empty, so nothing can be overwritten.

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
| `patch_templates` | Whether template files may be written to at all. |
| `ai.enabled` | Whether a model may be asked to edit templates the literal patcher cannot handle. |
| `ai.model` / `ai.prompt` | Model and system prompt for those edits. |
| `ai.reasoning_effort` | How hard the model thinks. `low` by default; null omits it for models that predate the parameter. |
| `ai.max_tokens` | Ceiling on the reply, reasoning tokens included. A reply cut short is reported as such. |
| `ai.retries` | Attempts per call. Rate limits are routine at ten concurrent calls. |
| `ai.timeout` | Seconds per call. Keep below `time_budget`, or a slow resolve outlives the budget meant to bound it. |
| `ai.max_files` / `ai.max_bytes` | Ceiling on how much template source is sent per edit. |
| `template_paths` | Directories searched for templates, relative to the app root. |
| `content_checks` / `asset_checks` / `file_checks` / `template_checks` | Which applier handles which check. |
| `fields` | Candidate field handles per check group. |
| `batch_size` / `time_budget` | How much one request will attempt, by count and by seconds. Set `time_budget` below the web server's own timeout. |
| `git.*` | Commit and push behaviour. Every change ends up in a commit: entry and asset saves fire Statamic's own git subscriber, and whatever it has not committed by the time the save returns is committed here instead. |

## Testing

The parts worth testing, `FieldResolver`, `TemplatePatcher` and `EditValidator`, take plain arrays and strings and have no Statamic dependency. They are exercised from the central application's suite via `tests/Feature/FieldResolverTest.php` and `tests/Feature/TemplatePatcherTest.php`, which is why the addon has no bootstrapped CMS of its own.

`EditValidator` is the one to read first if you are reviewing this for safety: every rule that stops a model's output reaching a client's template is there, and each has a test that proves the file is left untouched when the rule fires.

`ContentApplier`'s git behaviour is covered in `tests/Feature/ContentGitCommitTest.php` against a stub entry, which is why `ContentResolver::find()` returns `?object` rather than `?Entry`.

`AssetApplier` and `TemplateLocator` are the exceptions: their Statamic glue (`Asset::findByUrl`, `$entry->template()`, `$asset->set()->save()`) is not covered and would need a Statamic test harness to exercise properly. Both are deliberately thin for that reason.
