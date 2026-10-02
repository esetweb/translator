# Administration

For administrators and integrators: installation, permissions, configuration,
background processing, maintenance.

- [Requirements](#requirements)
- [Installation](#installation)
- [Permissions](#permissions)
- [Extension configuration](#extension-configuration)
- [Translation providers](#translation-providers)
- [Running automated jobs](#running-automated-jobs)
- [Maintenance](#maintenance)
- [Troubleshooting](#troubleshooting)

---

## Requirements

- TYPO3 **10.4**, PHP **7.4**
- A site configuration (`config/sites/*/config.yaml`) for every site that is
  translated – targets are site languages.
- `typo3/cms-scheduler` or cron, to process automated jobs in the background
- PHP-FPM recommended – it lets the *Send to provider now* button run a job in
  the background (see [Running automated jobs](#running-automated-jobs))

## Installation

```bash
composer require eset/eset-translator
```

Then in the Install Tool:

1. **Maintenance → Analyze Database Structure** – creates
   `tx_esettranslator_domain_model_job` and `…_jobitem`.
2. **Maintenance → Flush TYPO3 and PHP Cache** – needed after every update of
   the extension (TYPO3 10 caches the service container).
3. Configure at least one [provider](#translation-providers) if automated
   translation is wanted.
4. Set up the [background task](#running-automated-jobs).
5. Give editor groups the [permissions](#permissions).

---

## Permissions

Admins can do everything. For editor groups (`be_groups`):

| What | Where |
|------|-------|
| Use the **jobs module** | *Access Lists → Modules*: **ESET → Translation jobs** |
| Show **Request translation** (automated) | *Access Lists → Custom module options → ESET Translator*: **Request automated translation** |
| Show **Export / import** | *Access Lists → Custom module options → ESET Translator*: **Export / import translation** |
| Which **sites** may be translated | *Mounts and Workspaces → DB Mounts*: the site root must be inside a mount |
| Read vs. write | Page permissions (*Web → Access*): translating requires *edit content* on the page; the ESET context menu and *Show translation jobs* require *show page* |
| Which **overlay languages** (`languageId > 0`) | *Access Lists → Languages* (`allowed_languages`), empty = all |
| `languageId 0` (in place) | Always writable – gated by the DB mount and the page edit right |

The page module buttons and context menu items are only shown when the editor
may edit the page, the page belongs to a site, and at least one target is
allowed. *Request translation* additionally needs a configured provider.

**Typical setup** – a "Slovak translators" group:

- DB mount on the **SK site root** with edit rights (target)
- DB mount on the **English site root** with *show* only – English is available
  as a source, but the originals cannot be changed
- Custom module options *Request automated translation* and *Export / import
  translation*, module *ESET → Translation jobs*

### Optional user TSconfig

Only needed for finer control than mounts allow:

```typoscript
tx_esettranslator {
    # (site:language) keys the editor may read as source – wildcards allowed
    allowedSources = *:0, eset-cz:*
    deniedSources =
    # (site:language) keys the editor may write
    allowedTargets = eset-sk:0
    deniedTargets = eset-sk:9
    # ignore be_groups.allowed_languages entirely
    ignoreCoreLanguagePermissions = 1
    # never let this group write languageId 0 (in-place) content
    denyDefaultLanguageAsTarget = 1
}
```

Patterns: `*` (any), `eset-cz:*`, `*:1`, exact `eset-cz:0`. When
`allowedSources` / `allowedTargets` is set it **replaces** the
`allowed_languages` check; the DB-mount check still applies.

To hide the ESET context menu for a subtree (page TSconfig):

```typoscript
options.contextMenu.table.pages.disableItems = eset
```

---

## Extension configuration

*Install Tool → Settings → Extension Configuration → eset_translator*

| Key | Default | Purpose |
|-----|---------|---------|
| `deeplApiKey`, `deeplApiUrl`, `deeplGlossaryId` | – | DeepL Free / Pro. Free keys end with `:fx`; the endpoint is detected automatically. |
| `googleApiKey` | – | Google Cloud Translation v2 |
| `libreTranslateUrl`, `libreTranslateApiKey` | – | Self-hosted LibreTranslate |
| `enableMyMemory`, `myMemoryEmail` | `0` | Free, rate-limited – for testing only |
| `enableEchoProvider` | `0` | Development only – prefixes the text instead of translating |
| `defaultProvider` | `deepl` | Preferred provider when several support a language pair |
| `defaultFormat` | `xliff` | `xliff` or `catxml` |
| `allowManualFallback` | `1` | Offer the file export when no provider is configured |
| `defaultSourceLanguage` | `us:0` | Assumed source language when it cannot be derived from the page's copy origin – a key (`us:0`) or a code (`en`); empty = the page's own site language |
| `maxDepth` | `10` | Maximum subpage levels per job |
| `chunkSize` | `40` | Texts per provider request |
| `providerTimeout` | `30` | Provider HTTP timeout in seconds |
| `storageFolder` | `typo3temp/var/eset_translator` | Where exported files are kept (relative to the public folder) |
| `translatableTables` | `pages,tt_content` | Tables scanned for translatable fields |
| `excludedFields` | `pages.slug,pages.alias,pages.url,pages.tx_esettranslator_note` | `table.field` or `*.field` never exported; a FlexForm column listed here is skipped entirely |
| `excludedCTypes` | `html` | Content types unchecked by default in the wizard (editors can re-include them) |
| `translateFlexForm` | `1` | Translate text in FlexForm settings of plugins / grid elements |

Which fields count as translatable is described in
[How it works](HowItWorks.md#what-gets-translated).

> **Upgrading:** older versions had `tt_content.pi_flexform` in the default
> `excludedFields`. If your saved configuration still contains it, FlexForm
> content stays excluded – remove the entry to enable FlexForm translation.

---

## Translation providers

| Provider | Configure | Notes |
|----------|-----------|-------|
| DeepL | `deeplApiKey` (+ optional `deeplGlossaryId`) | Recommended. HTML is translated with tag handling. |
| Google Cloud Translation | `googleApiKey` | |
| LibreTranslate | `libreTranslateUrl` (+ key) | Self-hosted, free |
| MyMemory | `enableMyMemory = 1` | Testing only, rate limited |
| Echo | `enableEchoProvider = 1` | Development: tests the whole pipeline without an API |

**No provider configured:** *Request translation* is hidden everywhere; export /
import keeps working. If an automated job is still requested, it becomes a
manual job when `allowManualFallback = 1`, otherwise the request fails with
"Automated translation is not configured".

Custom providers: see [Developers](Developers.md#custom-translation-provider).

---

## Running automated jobs

*Translate now* in the wizard creates the job with status **Queued**. Queued
jobs are processed in two ways:

### 1. Background task (required)

```bash
vendor/bin/typo3 esettranslator:process --limit=10            # translate + import
vendor/bin/typo3 esettranslator:process --limit=10 --no-import  # translate only
```

Run it from cron, or add a **Scheduler → Execute console commands** task
(the command is schedulable). Every minute or every few minutes is a good
interval.

### 2. "Send to provider now" (▶) in the jobs module

Starts one job immediately via AJAX. With **PHP-FPM** the request returns at
once and the job keeps running in the same PHP worker after the response
(`fastcgi_finish_request()`); the button shows a spinner until it is done.
Without PHP-FPM (mod_php, built-in server) the job stays queued for the
background task and the editor is told so.

Limits of the PHP-FPM path: the worker is busy until the job is finished, and
the pool's `request_terminate_timeout` (if set) can stop a very long job – it
then stays *Running* and can be cancelled. Large or many jobs belong to the
background task.

Both paths are safe to run at the same time: a job is switched to *Running*
with one conditional database update, so only one process ever handles it.

---

## Maintenance

```bash
vendor/bin/typo3 esettranslator:cleanup-jobs --retention-days=7 --purge-days=30
```

Soft-deletes finished jobs after `retention-days` and removes soft-deleted rows
after `purge-days`. Schedulable as well.

Exported files are kept in `storageFolder` (`typo3temp/var/eset_translator` by
default) and can be deleted at any time – a job's file is regenerated on the
next download.

---

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Buttons missing in the page module | Custom module options, edit right on the page, page belongs to a site, at least one allowed target |
| *Request translation* missing | No provider configured / available, or the custom option is missing |
| Jobs stay *Queued* | The background task is not running – check the scheduler / cron |
| ▶ queues instead of running | PHP is not running under PHP-FPM – rely on the background task |
| Jobs stay *Running* | Process was killed (FPM timeout, deploy, crash). Cancel the job and queue it again; check `request_terminate_timeout` |
| Overlay translation fails with a language error | TYPO3 10 needs a `sys_language` record per overlay language id; the extension creates it on demand – check that the site language id is not taken by an unrelated record |
| Import: "Page … is not part of site …" | The file targets another site than the page it is imported into |
| Errors after updating the extension | Flush all caches in the Install Tool |
| Provider errors | *ESET → Translation jobs* → job → error message; TYPO3 log (`var/log`) |
