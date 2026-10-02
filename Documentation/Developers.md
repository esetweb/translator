# Developers

Extension points and technical reference.

- [Architecture overview](#architecture-overview)
- [Custom translation provider](#custom-translation-provider)
- [Custom exchange format](#custom-exchange-format)
- [Overriding and extending](#overriding-and-extending)
- [Technical reference](#technical-reference)

---

## Architecture overview

| Class | Role |
|-------|------|
| `Domain\Dto\TranslationTarget` | A `(site, languageId)` pair; key `"site:id"` |
| `Domain\Dto\TranslationUnit` / `TranslationDataSet` | One text field / all texts of a job, independent of storage and format |
| `Domain\Model\Job` / `JobItem` | Persisted job and per-field items (Extbase) |
| `Service\SiteLanguageService` | Site configuration → targets |
| `Service\PermissionService` | Allowed sources / targets, page and job access |
| `Service\RecordCollectorService` | Collects translatable fields (incl. FlexForm) of the job's pages |
| `Service\ReferenceService` | "Insert records" analysis and copy / relink / translate decisions |
| `Service\JobService` | Creates jobs (permission check → references → collect → persist) |
| `Service\TranslationRunner` | Processes an automated job: claim, translate in batches, import |
| `Service\BackgroundJobDispatcher` | Runs a job after the HTTP response (PHP-FPM) |
| `Service\ImportService` | Writes translations through DataHandler (in place / overlay) |
| `Service\ExportService` | Serializes a job with a format |
| `Format\*` | XLIFF 1.2, CATXML – tagged `eset_translator.translation_format` |
| `Provider\*` | DeepL, Google, LibreTranslate, MyMemory, Echo – tagged `eset_translator.translation_provider` |
| `Controller\TranslationWizardController` | AJAX endpoints of the wizards |
| `Controller\JobAjaxController` | AJAX endpoints of the jobs module (run, status) |
| `Controller\JobController` | Extbase backend module *Translation jobs* |

---

## Custom translation provider

Implement `ESET\Translator\Provider\TranslationProviderInterface`, or extend
`AbstractTranslationProvider` (HTTP requests, JSON decoding, error handling,
language-code normalisation, result mapping), and tag the service.

```php
<?php
namespace Vendor\MyExt\Translator;

use ESET\Translator\Domain\Dto\TranslationTarget;
use ESET\Translator\Provider\AbstractTranslationProvider;

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

        $decoded = $this->decodeJson($response); // throws on non-JSON
        return $this->mapResults($texts, $decoded['translations'] ?? []);
    }
}
```

`Configuration/Services.yaml` of your extension:

```yaml
services:
  Vendor\MyExt\Translator\AcmeProvider:
    tags: ['eset_translator.translation_provider']
```

Add `acmeApiKey` to your `ext_conf_template.txt`
(`$this->configuration->get('acmeApiKey')` reads any key). The provider then
appears in the wizard, in `defaultProvider` and in the runner. Override
`getBatchSize()` if the API has a hard limit (see `DeeplProvider`).

`$options['html']` is `true` for batches of HTML texts – keep tags and
attributes intact for those.

---

## Custom exchange format

Implement `ESET\Translator\Format\FormatInterface` (or extend `AbstractFormat`
for DOM helpers) and tag it:

```yaml
services:
  Vendor\MyExt\Format\TmxFormat:
    tags: ['eset_translator.translation_format']
```

- `export(TranslationDataSet): string` – serialize.
- `import(string): TranslationDataSet` – parse back, with the translation in each
  unit's target text. Unit ids are `table/uid/field`
  (`TranslationUnit::parseId()`).
- `canImport(content, fileName)` – cheap sniff for upload routing.

Never trust field names from a file – `ImportService` re-validates them against
the TCA anyway. The format appears in the wizard and in `defaultFormat`.

---

## Overriding and extending

| Want to … | How |
|-----------|-----|
| Scan more tables / skip fields | `translatableTables` / `excludedFields` extension configuration |
| Turn FlexForm translation on / off | `translateFlexForm` (or list the column in `excludedFields`) |
| Change the jobs module templates | TypoScript `module.tx_esettranslator.view.templateRootPaths.10 = …` |
| Hide the context menu for a subtree | Page TSconfig `options.contextMenu.table.pages.disableItems = eset` |
| Restrict targets beyond page mounts | User TSconfig `tx_esettranslator.*` ([Administration](Administration.md#optional-user-tsconfig)) |
| Add a provider / format | Tagged service (above) |
| React to job completion | Decorate `TranslationRunner` |

---

## Technical reference

**Backend module:** top module `eset`, sub module signature
`eset_EsetTranslatorJobs`, Extbase argument namespace
`tx_esettranslator_eset_esettranslatorjobs`.

**JavaScript (RequireJS):**

| Module | Used by |
|--------|---------|
| `TYPO3/CMS/EsetTranslator/TranslationWizard` | Page module buttons, context menu – *Request translation* (core `MultiStepWizard`) and *Export / import* (modal with tabs) |
| `TYPO3/CMS/EsetTranslator/ContextMenuActions` | Page tree context menu callbacks |
| `TYPO3/CMS/EsetTranslator/JobActions` | Jobs module – ▶ via AJAX with spinner; loads the core Modal for `t3js-modal-trigger` confirmations |

**AJAX routes** (`TYPO3.settings.ajaxUrls.*`):

| Route | Method | Purpose |
|-------|--------|---------|
| `eset_translator_options` | GET | Everything the wizards need for a page (targets, sources, providers, formats, permissions) |
| `eset_translator_analyze` | GET | Content types and references for page / source / target / depth |
| `eset_translator_create_job` | POST | Create a job (`mode` automated/manual, `references` JSON) |
| `eset_translator_export` | GET | Download a job's file (`job`) |
| `eset_translator_import` | POST | Job-less file import (multipart) |
| `eset_translator_job_run` | POST | Start a job in the background (`job`) |
| `eset_translator_job_status` | GET | Status of jobs (`jobs=1,2,3`) |

All JSON endpoints answer HTTP 200 with `{success: false, message}` on errors –
TYPO3 10's `AjaxRequest` rejects non-2xx responses before the body is read.

**Console commands:** `esettranslator:process` (`--limit`, `--no-import`),
`esettranslator:cleanup-jobs` (`--retention-days`, `--purge-days`).

**Service tags:** `eset_translator.translation_provider`,
`eset_translator.translation_format`.

**Database:** `tx_esettranslator_domain_model_job`,
`tx_esettranslator_domain_model_jobitem`.
