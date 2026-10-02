# ESET Translator – documentation

Pick the page for your role:

| Page | For | Covers |
|------|-----|--------|
| [Editor guide](Editors.md) | Editors translating pages | Requesting a machine translation, exporting a page for an agency, importing the result, references, the jobs module |
| [Translation agency guide](TranslationAgency.md) | Translation agencies / translators | What the exported XLIFF file contains and what must stay untouched so it can be imported back |
| [Administration](Administration.md) | Administrators / integrators | Installation, permissions, extension configuration, running jobs, maintenance, troubleshooting |
| [How it works](HowItWorks.md) | Anyone who wants the full picture | Sites and languages, what gets translated, the job lifecycle, references, import |
| [Developers](Developers.md) | Developers | Custom providers and file formats, extension points, technical reference |

## In one paragraph

The extension translates TYPO3 pages into a **site and language** of your
choice. A translation is either done **automatically** by a provider (DeepL,
Google, LibreTranslate, …) or **manually**: the page's texts are exported as an
XLIFF file, translated by an agency, and imported back. Every translation is a
**job** you can follow in *ESET → Translation jobs*. Texts are always written
into the page that lives in the target site – a page is first copied into that
site's page tree, then translated *in place*, or translated into an overlay
language of its own site.
