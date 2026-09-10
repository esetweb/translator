# ESET Translator (`eset_translator`)

Site‑aware translation management for **TYPO3 10.4**. Export/import translation
files (XLIFF 1.2 or l10nmgr‑compatible CATXML) and request automated translations
from pluggable translation providers (DeepL, Google, LibreTranslate, MyMemory, …).

- **Extension key:** `eset_translator`
- **PHP namespace:** `ESET\Translator\`
- **Requires:** TYPO3 `10.4.x`, PHP `7.4` (typed properties are used; no 8.x‑only syntax)

---

## 1. Why this extension exists

`localizationteam/l10nmgr` keys every translation by the global
`sys_language_uid`. On an installation with many sites that all use
`languageId 0` as their *own* localized default language (site A = English,
site B = Slovak, site C = Czech, … all `languageId 0`):

- `sys_language_uid 0` is ambiguous — it means a different language per site.
- `be_groups.allowed_languages` collapses every site's default language into a
  single **"Default"** checkbox that cannot be restricted per site.
- l10nmgr refuses `languageId 0` as a translation target, so a page that lives
  in a single‑language site cannot be translated at all.

This extension models a translation endpoint as the pair
**`(siteIdentifier, languageId)`** (`ESET\Translator\Domain\Dto\TranslationTarget`,
transport key `"<site>:<languageId>"`, e.g. `eset-sk:0`). Permissions are resolved
per site from the backend group's **page‑tree mounts**, not from the merged
language checkbox.

---

## 2. Concepts

| Term | Meaning |
|------|---------|
| **Target** | Where the translated strings are written: a `(site, language)` of the site the page physically lives in. `languageId 0` ⇒ **write in place** (overwrite the field values). `languageId > 0` ⇒ create/update the language overlay records. |
| **Source** | The language the page is *currently written in*. Chosen by the editor; it is only metadata for the exchange file / provider, not a record location. |
| **Job** | One translation request (`tx_esettranslator_domain_model_job`) with per‑field items (`…_jobitem`) so progress can be shown and a partially failed job resumed. Modes: `manual` (export/import) and `automated` (provider). |

### The three workflows

1. **Source‑of‑truth site** (e.g. English, single language) — originals are
   authored here and **not translated in place**. The "Request translation"
   button/action is only offered where the page can actually be edited.
2. **Copied page, in‑place** — an English page is copied (TYPO3 copy/paste) into
   the Slovak site's page tree. It now lives at `sk:0` holding English text.
   Translate it *in place*: source = `English`, target = `Slovensky … [in place]`.
   The translated text overwrites the same records. No overlay, no page mapping.
3. **Multi‑language site, overlay** — a site with real overlay languages
   (`languageId` 1, 2, …). Source = the site's default language, target = an
   overlay language ⇒ standard TYPO3 localization records are created.

   > **v10.4 note:** `DataHandler::localize()` still validates the target
   > against a `sys_language` record (removed in v11). The extension creates a
   > matching `sys_language` record on demand — a one‑time bootstrap keyed by
   > `languageId` — so overlay languages defined only in `config.yaml` work
   > without a manually created "Website Language" record.

For a copied page the source language is guessed automatically from
`t3_origuid` (the record TYPO3 stamps on copy) → the extension config
`defaultSourceLanguage` → the page's own site language. The editor can always
override it in the modal.

**"Only fields that are not translated yet"** (wizard checkbox):

- *Overlay target* — skips fields whose overlay record already holds a value
  different from the default language.
- *In‑place target* (`languageId 0`) — there is no overlay record, so a field
  counts as translated when its value already **differs from the record it was
  copied from** (`t3_origuid`), or when a previous ESET import wrote exactly that
  value. Re‑running a job then only picks up new / reverted content.

Counts in the Translation jobs module are **per field**, not per content element
(a typical CE has 2–3 translatable fields).

---

## 3. Installation

```bash
composer require eset/eset-translator
```

Then in the Install Tool: **Maintenance → Analyze Database Structure** (creates
`tx_esettranslator_domain_model_job` / `…_jobitem`) and **Flush all caches**.

---

## 4. Backend UI

| Entry point | Where |
|-------------|-------|
| **Request translation** / **Export / import translation** buttons | Page module doc header (`web_layout`), for pages the user may edit that belong to a site |
| **ESET ▸** submenu (Request translation / Export‑import / Show translation jobs) | Page‑tree context menu |
| **ESET → Translation jobs** module | Progress of all automated and manual jobs, per‑field status, download / re‑import / re‑queue / cancel |

---

## 5. Permissions

**No TSconfig required.** The model is pure backend groups:

| What | Configured by |
|------|---------------|
| Which **sites** a group may translate | the group's **DB Mounts** — the site root page must be inside a mount |
| Read vs. write on a site | standard **page permissions** (`Web → Access`). "Request translation" / "Export‑import" require *edit content* on the page; the ESET submenu parent + "Show jobs" require *show*. |
| Which **overlay languages** (`id > 0`) | the group's **"Languages"** field (`allowed_languages`) — core `checkLanguageAccess()`, empty = all |
| `languageId 0` (in‑place) | always writable, gated only by the mount + page edit right |
| Everything | admins bypass all checks |

Example: a "Slovak translators" group gets a **DB Mount on the SK site root**
(edit) and a **DB Mount on the English site root** with *show‑only* page
permissions (so English is available as a translation source but the originals
cannot be changed).

### Optional TSconfig override

Only needed for control finer than page‑tree mounts allow. User TSconfig:

```typoscript
tx_esettranslator {
    # (site,language) keys the editor may read as a source — wildcards allowed
    allowedSources = *:0, eset-cz:*
    # (site,language) keys the editor may write
    allowedTargets = eset-sk:0
    deniedTargets  = eset-sk:9
    # ignore be_groups.allowed_languages entirely
    ignoreCoreLanguagePermissions = 1
    # forbid this group from ever writing languageId 0 content
    denyDefaultLanguageAsTarget = 1
}
```

Patterns support `*` (any), `eset-cz:*`, `*:1`, exact `eset-cz:0`. When
`allowedSources`/`allowedTargets` is set it **replaces** the `allowed_languages`
check; the DB‑mount check still applies.

---

## 6. Extension configuration

Install Tool → **Settings → Extension Configuration → eset_translator**:

| Key | Default | Purpose |
|-----|---------|---------|
| `deeplApiKey`, `deeplApiUrl`, `deeplGlossaryId` | – | DeepL Free/Pro. Free keys end `:fx`, endpoint auto‑detected. |
| `googleApiKey` | – | Google Cloud Translation v2 |
| `libreTranslateUrl`, `libreTranslateApiKey` | – | self‑hosted LibreTranslate |
| `enableMyMemory`, `myMemoryEmail` | `0` | free, rate‑limited — testing only |
| `enableEchoProvider` | `0` | dev only — prefixes text instead of translating; use it to exercise the pipeline without an API |
| `defaultProvider` | `deepl` | preferred provider when several support a pair |
| `defaultFormat` | `xliff` | `xliff` or `catxml` |
| `allowManualFallback` | `1` | offer the file download when no provider is configured |
| `defaultSourceLanguage` | `us:0` | assumed source language when the copy origin can't be derived — a target key (`us:0`) or bare code (`en`); empty = use the page's own site language |
| `maxDepth` | `10` | max page‑tree depth per job |
| `chunkSize` | `40` | strings per provider request |
| `providerTimeout` | `30` | provider HTTP timeout (s) |
| `storageFolder` | `typo3temp/var/eset_translator` | where generated files are kept |
| `translatableTables` | `pages,tt_content` | tables scanned for translatable fields |
| `excludedFields` | `pages.slug,pages.alias,pages.url,pages.tx_esettranslator_note` | `table.field` (or `*.field`) never exported. Also skips a whole FlexForm column when listed (e.g. `tt_content.pi_flexform`). |
| `excludedCTypes` | `html` | `tt_content` CType values skipped by default (e.g. raw HTML embeds). Pre‑unchecked in the wizard's "content types" list; the editor can re‑include them per request. |
| `translateFlexForm` | `1` | collect translatable text from FlexForm option sheets (plugin / grid‑element settings in `pi_flexform`) |

A field is treated as translatable when it is TCA type `input`/`text`, not
`readOnly`, not `l10n_mode = exclude`, not `allowLanguageSynchronization`, and
not a non‑text `eval` (int, date, password, …) or link/colour picker.

### FlexForm content

Custom plugins and grid elements often keep their editable labels (headers,
button text, claims) in `pi_flexform` option sheets rather than in real DB
columns. With `translateFlexForm = 1` those leaves are collected too: each one
becomes a unit whose field is a path `pi_flexform/<sheet>/<fieldname>`, using the
same input/text rules as above, and is written back through DataHandler so RTE
transformation and history behave normally. The data structure is resolved per
record (via its `CType` / `list_type`), so it works with `flux`, `gridelements`,
`container` and hand‑written DS alike. `onlyUntranslated` compares each leaf
against the copy origin (in place) or the overlay record (overlay), like scalar
fields.

Not handled: FlexForm **sections/containers** and language‑split flex
(`langChildren` / `vDA`). Add `tt_content.pi_flexform` back to `excludedFields`,
or set `translateFlexForm = 0`, to turn the feature off.

> **Upgrading:** earlier versions shipped `tt_content.pi_flexform` in the default
> `excludedFields`. If you saved the extension configuration back then, that
> entry is still in your `excludedFields` value and keeps FlexForm content
> excluded — remove it in *Settings → Extension Configuration* to pick the
> feature up.

---

## 7. Automated translation — failsafe policy

- **No provider configured** (no keys, MyMemory/Echo off): the *Request
  translation* button and context‑menu item are **hidden**. Manual
  export/import stays available.
- If the automated path is still reached: with `allowManualFallback = 1` the job
  silently becomes a manual job and the modal explains *"Automated translation is
  not configured — contact your administrator"*; with `allowManualFallback = 0`
  the same message is a hard error.

### Running automated jobs

Automated jobs are created with status `queued` and processed by a CLI command:

```bash
vendor/bin/typo3 esettranslator:process --limit=10        # translate + import
vendor/bin/typo3 esettranslator:process --limit=10 --no-import
```

Either run it from cron directly, or add a **Scheduler → "Execute console
commands"** task (`typo3/cms-scheduler`) — the command is `schedulable`.

---

## 8. Exchange file formats

### XLIFF 1.2 (`xliff`, default)

Standard XLIFF understood by memoQ, Trados, Phrase, XTM, OmegaT, … TYPO3 context
is kept in a private namespace `https://www.eset.com/ns/typo3/translator/1.0` on
`<file>` (`eset:source-target`, `eset:target-target`, `eset:page`, `eset:job`)
and on each `<trans-unit>` (`eset:table`, `eset:uid`, `eset:field`, `eset:hash`).

A **job‑less import** (Export/import modal) relies on those attributes to know
where to write. If a translation tool strips the namespace, import the file from
the **Translation jobs** module instead — that path uses the job's stored
source/target.

### CATXML (`catxml`)

l10nmgr‑compatible CATXML for existing vendor pipelines.

---

## 9. Writing a custom translation provider

Implement `ESET\Translator\Provider\TranslationProviderInterface` (or extend
`AbstractTranslationProvider`, which gives you `RequestFactory`, JSON decoding,
error handling, language‑code normalisation and result mapping) and tag the
service.

```php
<?php
namespace Vendor\MyExt\Translator;

use ESET\Translator\Domain\Dto\TranslationTarget;
use ESET\Translator\Provider\AbstractTranslationProvider;
use ESET\Translator\Provider\TranslationProviderException;

final class AcmeProvider extends AbstractTranslationProvider
{
    public function getIdentifier(): string { return 'acme'; }
    public function getTitle(): string      { return 'ACME MT'; }

    public function isAvailable(): bool
    {
        return trim((string)$this->configuration->get('acmeApiKey')) !== '';
    }

    public function supports(TranslationTarget $source, TranslationTarget $target): bool
    {
        return parent::supports($source, $target)
            && $this->normalizeLanguageCode($target) !== 'ja'; // whatever ACME can't do
    }

    /**
     * @param string[] $texts
     * @return string[] keyed exactly like $texts
     */
    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }
        $response = $this->request('POST', 'https://api.acme.example/v1/mt', [
            'headers' => ['Authorization' => 'Bearer ' . $this->configuration->get('acmeApiKey')],
            'json' => [
                'from' => $this->normalizeLanguageCode($source),
                'to'   => $this->normalizeLanguageCode($target),
                'html' => !empty($options['html']),
                'q'    => array_values($texts),
            ],
        ]);

        $decoded = $this->decodeJson($response);           // throws on non‑JSON
        return $this->mapResults($texts, $decoded['translations'] ?? []);
    }
}
```

`Configuration/Services.yaml` in your extension:

```yaml
services:
  Vendor\MyExt\Translator\AcmeProvider:
    tags: ['eset_translator.translation_provider']
```

Add `acmeApiKey` to your `ext_conf_template.txt` (the `ConfigurationService`
reads any key with `$this->configuration->get('acmeApiKey')`). Nothing else —
the provider appears in the wizard, in `defaultProvider`, and in the job runner
automatically. `getBatchSize()` controls how the runner chunks; override it if
your API has a hard limit (see `DeeplProvider`).

---

## 10. Writing a custom exchange format

Implement `ESET\Translator\Format\FormatInterface` (or extend `AbstractFormat`
for DOM helpers) and tag it:

```yaml
services:
  Vendor\MyExt\Format\TmxFormat:
    tags: ['eset_translator.translation_format']
```

`export(TranslationDataSet)` serialises; `import(string): TranslationDataSet`
parses back with the translated value in each unit's *target text*;
`canImport(content, fileName)` is a cheap sniff for upload routing. Never trust
field names from an uploaded file — the importer re‑validates them against the
TCA. The format then shows up in the wizard and the `defaultFormat` setting.

---

## 11. Overriding / extending

| Want to… | How |
|----------|-----|
| Scan more tables / skip fields | `translatableTables` / `excludedFields` extension config |
| Turn FlexForm translation on/off | `translateFlexForm` extension config (or list the column in `excludedFields`) |
| Change job list / detail templates | TypoScript `module.tx_esettranslator.view.templateRootPaths.10 = …` |
| Change the context‑menu labels or hide the submenu for a subtree | page TSconfig `options.contextMenu.table.pages.disableItems = eset` |
| Restrict a group's targets beyond page mounts | user TSconfig `tx_esettranslator.*` (section 5) |
| Add a provider / format | tagged service (sections 9–10) |
| React to job completion | listen to Extbase persistence, or wrap `TranslationRunner` via a decorator service |

---

## 12. Backend module / RequireJS reference

- Top module: `eset` — sub‑module signature `eset_EsetTranslatorJobs`
- Extbase argument namespace: `tx_esettranslator_eset_esettranslatorjobs`
- JS modules: `TYPO3/CMS/EsetTranslator/TranslationWizard`,
  `TYPO3/CMS/EsetTranslator/ContextMenuActions`
- AJAX routes: `ajax_eset_translator_options`, `…_create_job`, `…_export`, `…_import`
- Service tags: `eset_translator.translation_provider`,
  `eset_translator.translation_format`
