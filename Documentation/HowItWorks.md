# How it works

The full picture: how sites and languages are modelled, what gets translated,
how a job moves through its states, how referenced content is handled, and
what happens on import.

- [Why this extension exists](#why-this-extension-exists)
- [Sites, languages and targets](#sites-languages-and-targets)
- [The three workflows](#the-three-workflows)
- [What gets translated](#what-gets-translated)
- [Jobs and their lifecycle](#jobs-and-their-lifecycle)
- [Referenced content](#referenced-content)
- [Import](#import)
- [Exchange files](#exchange-files)

---

## Why this extension exists

It replaces `localizationteam/l10nmgr` on an installation with many sites that
all use **`languageId 0` as their own, different language** (site A = English,
site B = Slovak, site C = Czech – all `languageId 0`). l10nmgr keys everything by
the global `sys_language_uid`, so there:

- `sys_language_uid 0` means a different language in every site,
- `be_groups.allowed_languages` merges all default languages into one "Default"
  checkbox that cannot be restricted per site,
- `languageId 0` cannot be a translation target, so single-language sites cannot
  be translated at all.

## Sites, languages and targets

A translation endpoint is the pair **(site, language id)** – a *target*, written
as `"<siteIdentifier>:<languageId>"`, e.g. `eset-sk:0`.

| Term | Meaning |
|------|---------|
| **Target** | Where texts are written: a language of the site the page **physically lives in**. `languageId 0` → **in place** (the field values are overwritten). `languageId > 0` → TYPO3 **overlay** records are created / updated. |
| **Source** | The language the page is written in **now**. Chosen by the editor; it only tells the provider / agency what to translate from. It can be a language of another site (an English page copied into the Slovak site). |
| **Job** | One translation request with one item per text field. Mode *automated* (provider) or *manual* (file export / import). |

The default source is guessed from the page's copy origin (`t3_origuid`, which
TYPO3 sets on copy & paste) → the `defaultSourceLanguage` setting → the page's
own site language.

Permissions are evaluated per target (see [Administration](Administration.md#permissions)).

## The three workflows

1. **Source site** (e.g. English) – originals are written here and not
   translated in place. Editors usually have read-only access.
2. **Copied page, in place** – an English page is copied into the Slovak site's
   page tree. It lives at `sk:0` and contains English. Translate it with
   source = English, target = *Slovensky [in place]*: the same records get the
   Slovak text. No overlays, no page mapping.
3. **Multi-language site, overlay** – a site with overlay languages (ids 1, 2, …).
   Source = the site's default language, target = an overlay language; standard
   TYPO3 localizations are created.

> TYPO3 10 still validates overlay languages against a `sys_language` record
> (removed in v11). The extension creates a matching record on demand, so
> languages defined only in the site configuration work.

## What gets translated

Records of `translatableTables` (default `pages`, `tt_content`) on the job's
pages (the page plus `depth` subpage levels) in the **source language**.

A field is translatable when it is TCA type `input` or `text` and **none** of
these apply:

- listed in `excludedFields`, or a built-in exclusion (`slug`/URL segments and a
  few layout fields)
- `readOnly`, `l10n_mode = exclude`, or `allowLanguageSynchronization`
- a non-text `eval` (int, date, time, password, …), a link or colour picker
- the table's description column (`tt_content.rowDescription` – internal notes)
- not rendered in the frontend: `tt_content.header` when the header layout is
  *Hidden* or the element is *Insert records*
- empty, or only markup without text (`<p>&nbsp;</p>`)

Rich-text fields (and fields that contain markup) are sent as **HTML**, all
others as plain text.

**FlexForm** (`translateFlexForm = 1`): text leaves of plugin / grid-element
settings (`pi_flexform`) become units with the field path
`pi_flexform/<sheet>/<field>`. The data structure is resolved per record, so
flux, gridelements, container and hand-written structures work. Not supported:
FlexForm sections and language-split flex (`langChildren` / `vDA`).

**Only fields that are not translated yet:**

- *Overlay target* – skips fields whose overlay already holds a value different
  from the source.
- *In-place target* – there is no overlay, so a field counts as translated when
  it differs from the record it was **copied from** (`t3_origuid`), or when an
  earlier ESET import wrote exactly that value. Re-running a job picks up only
  new or reverted content.

## Jobs and their lifecycle

```
Manual:     new ──download──▶ exported ──import──▶ imported
                                             └───▶ failed

Automated:  queued ──run──▶ running ──▶ translated ──import──▶ imported
                               │                         └──▶ failed
                               └──▶ failed ──queue again──▶ queued

Any unfinished job ──cancel──▶ cancelled
```

- A job stores every text field as an **item** (source text, translation,
  status), so progress is shown per field and a failed job can be resumed
  (*Queue again* retries the failed items only).
- **Automated jobs** are processed by the background task
  (`esettranslator:process`) or started from the jobs module (▶). The runner
  sends pending items to the provider in batches (HTML and plain text
  separately), saves the progress after every batch, then imports.
- A job is claimed with one conditional update (`queued` → `running`), so the
  background task and ▶ never process the same job twice.
- **Manual jobs** are exported on download; the file can be regenerated at any
  time from the stored items, in any format.

## Referenced content

*Insert records* elements (CType `shortcut`) show records stored elsewhere. Those
records are not on the job's pages, so the wizard lists them in step 3. Hidden
*Insert records* elements are ignored (they render nothing).

**Nested references** are resolved: when a referenced record is itself an
*Insert records* element outside the job's pages, its records are listed instead
(fluid_styled_content renders a shortcut as nothing but its records).

Every referenced record is classified:

| Scope | Where it lives | Choices |
|-------|----------------|---------|
| In job | On a page of the job | None – translated with the page |
| Target site | Elsewhere in the target site | Translate the original *(only with edit rights on its page)*, copy, leave |
| Other site | Another site | Link to an existing copy *(in-place targets only)*, copy, leave |
| Missing | Deleted record | None |

**A record of another site is never written to** – its language ids mean other
languages, and an in-place write would change another site's live content.

The choices are applied **when the job is created**, before texts are collected,
so copies are collected like any other content of the page:

- **Translate original** – the record is added to the job and translated where
  it is.
- **Link** – the reference is replaced by a copy of the record that already
  exists in the target site (found via `t3_origuid`).
- **Copy** – the record is copied (without its localizations) to the position
  of the *Insert records* element. The *Insert records* element is then
  **deleted** (soft delete, restorable from the Recycler). If only some of its
  records are copied, the remaining / linked references move into new
  *Insert records* elements, so the order on the page stays the same.

The server re-analyses the page and ignores any choice it did not offer, so a
forged request cannot write into another site.

Every element created or changed here gets a **provenance note** appended to its
description field (`rowDescription`, *Notes* tab): date, user, the original
record (uid, title, site, page) and the *Insert records* element it replaces.
The copy also keeps `t3_origuid` pointing to the original.

## Import

All writes go through the **DataHandler**, exactly like a manual edit:
permissions, history / undo, reference index and hooks apply.

- **In place** – the translated values overwrite the fields of the records.
- **Overlay** – an existing localization is updated, or one is created
  (`localize` command) and then updated.
- Pages are imported before content elements.
- Field names from a file are **never trusted**: each one is checked against the
  translatable fields of the table; FlexForm paths against the record's data
  structure. Empty translations are skipped.
- **From the job** (jobs module): page, source and target come from the job;
  only units that belong to the job are taken from the file.
- **Without a job** (*Export / import → Import* on the page): page and languages
  are read from the file (`eset:*` attributes). The page must belong to the
  target site.

## Exchange files

**XLIFF 1.2** (default): `trans-unit`s directly under `<body>`; TYPO3 context in
the private namespace `https://www.eset.com/ns/typo3/translator/1.0`
(`eset:source-target`, `eset:target-target`, `eset:page`, `eset:job` on
`<file>`; `eset:table`, `eset:uid`, `eset:field`, `eset:page`, `eset:hash` on
each unit). Unit ids are `table/uid/field`. Texts are written as CDATA; HTML
units have `datatype="html"`. Older files that wrapped units in `<group>` still
import.

**CATXML**: l10nmgr-compatible, for existing agency pipelines.

What agencies must preserve: [Translation agency guide](TranslationAgency.md).
