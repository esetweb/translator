# ESET Translator (`eset_translator`)

Site-aware translation management for **TYPO3 10.4**. Translate pages
automatically with a translation provider (DeepL, Google, LibreTranslate, …) or
export them as XLIFF 1.2 / l10nmgr-compatible CATXML for a translation agency and
import the result back – into any **site and language** the editor may write.

- **Extension key:** `eset_translator`
- **PHP namespace:** `ESET\Translator\`
- **Requires:** TYPO3 `10.4.x`, PHP `7.4`

## Why

On an installation with many sites that each use `languageId 0` for their *own*
language, `sys_language_uid` is meaningless across sites and l10nmgr cannot
target `languageId 0` at all. This extension addresses a translation as the pair
**(site, language id)** – e.g. `eset-sk:0` – and resolves permissions per site.

## Features

- **Request translation** – a step wizard (languages → content → references &
  summary) that creates an automated job for a provider.
- **Export / import** – the same steps produce an XLIFF / CATXML file for an
  agency; the translated file is imported on the page or from the job.
- **In place or overlay** – copied pages are translated in place in the target
  site; sites with overlay languages get normal TYPO3 localizations.
- **Referenced content** – "Insert records" elements pointing to other pages or
  sites are detected; their content can be translated, linked to an existing
  copy, or copied onto the page – content of other sites is never changed.
- **Translation jobs module** – progress per field, re-download in any format,
  import, start in the background, re-queue, cancel.
- **Safe writes** – everything goes through DataHandler (history, permissions);
  field names from files are validated against the TCA.
- **Pluggable** providers and file formats (tagged services).

## Quick start

```bash
composer require eset/eset-translator
```

1. Install Tool: **Analyze Database Structure**, then **Flush all caches**.
2. Extension configuration: set e.g. `deeplApiKey` (optional – export / import
   works without a provider).
3. Schedule `vendor/bin/typo3 esettranslator:process` (Scheduler or cron).
4. Give editor groups the custom module options *ESET Translator* and the module
   *ESET → Translation jobs*, plus DB mounts on the sites they translate.

## Documentation

| | |
|-|-|
| [Editor guide](Documentation/Editors.md) | Translating pages, exporting for an agency, importing, references, jobs module |
| [Translation agency guide](Documentation/TranslationAgency.md) | What the XLIFF file contains and what must be preserved – hand this to your agency |
| [Administration](Documentation/Administration.md) | Installation, permissions, configuration, background processing, troubleshooting |
| [How it works](Documentation/HowItWorks.md) | Targets, what gets translated, job lifecycle, references, import, file formats |
| [Developers](Documentation/Developers.md) | Custom providers / formats, extension points, technical reference |
