# Editor guide

This guide is for editors who translate pages in the TYPO3 backend.

- [Before you start](#before-you-start)
- [Where to find the actions](#where-to-find-the-actions)
- [Request a machine translation](#request-a-machine-translation)
- [Export a page for a translation agency](#export-a-page-for-a-translation-agency)
- [Import a translated file](#import-a-translated-file)
- [Referenced content ("Insert records")](#referenced-content-insert-records)
- [The Translation jobs module](#the-translation-jobs-module)
- [What is translated and what is not](#what-is-translated-and-what-is-not)
- [Troubleshooting](#troubleshooting)

---

## Before you start

**Translations are written into the page that lives in the target site.**

- **Translating into another site** (e.g. an English page for the Slovak site):
  first **copy the page** into the Slovak site's page tree (normal TYPO3
  copy & paste). The copy still contains English text. Then translate the copy
  – the English texts are replaced by Slovak ones *in place*. The original
  English page is never changed.
- **Translating into a second language of the same site** (a site with overlay
  languages, e.g. Swiss German and Swiss French): translate the page itself;
  TYPO3 language versions (overlays) are created for it.

In the language pickers, targets that are written in place are marked
**"in place"**.

You need edit rights on the page. If the buttons are missing, see
[Troubleshooting](#troubleshooting).

---

## Where to find the actions

| Where | What |
|-------|------|
| **Page module** (Web → Page), buttons at the top | **Request translation** (machine translation) and **Export / import translation** |
| **Page tree**, right-click a page → **ESET** | **Request translation**, **Export / import content for translation** (same as the page module buttons) and **Show translation jobs** |
| **ESET → Translation jobs** module | All jobs: status, download, import, start, cancel, delete |

**Request translation** is only shown when a translation provider is configured
and you are allowed to use it. **Export / import translation** works without a
provider.

---

## Request a machine translation

Click **Request translation**. A wizard opens with three steps.

### Step 1 – Languages

| Field | What to choose |
|-------|----------------|
| **Source language** | The language the page is written in **now**. For a page copied from the English site this is English, even though the page now lives in another site. It is pre-selected from the page's copy origin – check it. |
| **Target** | The site and language to write into. Only languages of the site the page lives in are offered. |
| **Include subpages (levels)** | `0` = only this page, `1` = this page and its direct subpages, … |

If source and target are the same language, a warning is shown and you cannot
continue.

### Step 2 – Content

- **Only fields that are not translated yet** – leave this on when you re-run a
  translation: fields that were already translated (by hand or by an earlier
  job) are skipped, so only new or changed content is translated.
- **Content types to translate** – uncheck the types you want to leave out.
  Some types (e.g. *HTML*) are unchecked by default because they usually contain
  code rather than text.

If the page contains *Insert records* elements that show content stored
elsewhere, you are told so here – you decide what to do with it in step 3.

### Step 3 – References & summary

- **References** – see [Referenced content](#referenced-content-insert-records).
  If there are none, this is stated.
- **Summary** – check source, target, levels and options once more.
- **Translation provider** – pick one if several are available. Only providers
  that support the chosen language pair are listed; if none does, use
  *Export / import* instead.

Click **Translate now**. A translation **job** is created and you get a
confirmation. The job is processed in the background – follow it in
**ESET → Translation jobs**. You can also start it right away from there with
the **▶** button.

---

## Export a page for a translation agency

Click **Export / import translation**. The window has two tabs: **Export** and
**Import**.

On **Export** you go through the same steps as above (languages, content,
references), then choose the **file format**:

- **XLIFF 1.2** – the standard format of translation tools. Use this unless the
  agency asks for something else.
- **CATXML** – the format of the old l10nmgr extension, for existing agency
  workflows.

Click **Create job and download file**. A job is created (status *Exported*) and
the file is downloaded. Send it to the agency together with the
[Translation agency guide](TranslationAgency.md).

You can download the file of a job again at any time from the jobs module
(also in another format).

---

## Import a translated file

When the agency sends the translated file back, import it in one of two ways:

### Recommended: from the job

1. Open **ESET → Translation jobs** and click the job.
2. In **Actions**, choose the file and click **Import**.

This always works, because TYPO3 uses the information stored with the job
(page, source, target). Only texts that belong to this job are imported.

### Alternative: on the page

1. Open the page (it must be the page in the **target site**).
2. **Export / import translation** → tab **Import** → choose the file →
   **Import file**.

This reads the page and languages from the file itself. If the agency's tool
removed that information, the import fails – use the job instead.

### After the import

- Translated texts are written exactly like a manual edit (with history and
  undo). Units the translator left empty are skipped.
- The job gets the status *Imported* (or *Failed* with an explanation).
- Check the page in the page module and the frontend.

---

## Referenced content ("Insert records")

An *Insert records* element shows content elements that are stored somewhere
else – often on another page or in another site. That content is not part of
the page, so it needs a decision. Step 3 lists every referenced element with
where it lives and lets you choose:

| Where the referenced content lives | Your choices |
|------------------------------------|--------------|
| On a page that is part of this translation | Nothing to decide – it is translated with the page |
| Elsewhere in the **target site** (e.g. a shared content folder) | **Translate the original record** (changes it everywhere it is used), **Copy onto this page and translate the copy**, or **Leave as is** |
| In **another site** | **Link to existing copy** (if a translated copy already exists in your site), **Copy onto this page and translate the copy**, or **Leave as is** |

Content of another site is never changed by a translation.

- **Link to existing copy** – the *Insert records* element is changed to show
  the copy that already exists in your site instead of the original.
- **Copy onto this page** – the referenced content is copied into the page at
  the position of the *Insert records* element and translated with the page. The
  *Insert records* element is then **deleted** (it can be restored from the
  Recycler) so the page does not show the old content next to the new one.
  When only some of its records are copied, the rest is kept in a new
  *Insert records* element at the right position.
- **Leave as is** – nothing changes; that content stays untranslated.

If an *Insert records* element points to another *Insert records* element, you
see the content that is finally shown, marked "via Insert records #…".

Copies, links and new elements get a note in their **Notes** tab (field
"Description") saying where they came from, when, and who started the
translation. Copies and links are made when you start the job.

**Set all to** at the top applies one choice to all rows where it is possible.

---

## The Translation jobs module

**ESET → Translation jobs** lists your jobs (administrators see all jobs). Filter
by status, site or page.

### Statuses

| Status | Meaning |
|--------|---------|
| New | Manual job created, file not downloaded yet |
| Exported | File downloaded – waiting for the agency |
| Queued | Machine translation waiting to be processed |
| Running | Being translated right now |
| Translated | Translated, not written into the page yet |
| Imported | Done – texts are in the page |
| Failed | Something went wrong – the error is shown under the job |
| Cancelled | Stopped by an editor |

Progress is counted **per text field**, not per content element.

### Actions

| Button | What it does |
|--------|--------------|
| Details | Overview, progress, every single text with its status, import form |
| Download | The translation file (choose the format in the job view) |
| ▶ Send to provider now | Starts a queued machine translation immediately. The icon turns into a spinner while it runs; the list updates when it is done. |
| ↻ Queue again | Retries a failed job (failed texts only) |
| ✕ Cancel | Stops a job that is not finished |
| Delete | Removes the job (asks for confirmation). Texts already written into pages stay. |

---

## What is translated and what is not

Translated: text fields of pages and content elements (titles, headers,
subheaders, body text, link texts, image captions, plugin settings that contain
text, …).

**Not** translated:

- The **URL segment** of pages (`slug`) – adjust it by hand after translating if
  the URL should be in the new language.
- Headers set to **Hidden** (they are only internal labels).
- The header of *Insert records* elements (it is never shown).
- Internal **notes** (the "Description" field in the Notes tab).
- Content types you unchecked in step 2.
- Numbers, dates, links, colours and other non-text fields.

---

## Troubleshooting

| Problem | Cause / solution |
|---------|------------------|
| No buttons in the page module | You cannot edit the page, the page is not part of a site, or your group has no translation permission – ask your administrator. |
| Only *Export / import* is shown | No translation provider is configured, or you may not request machine translations. |
| "Source and target are the same language" | Pick the language the page is **currently** written in as source. |
| "Nothing to translate" | Everything is already translated (try without *Only fields that are not translated yet*) or all content types are unchecked. |
| "Page … is not part of site …" on import | Import into the copy of the page that lives in the target site, or import from the job. |
| Import says the file has no site information | The agency's tool removed it – import from the job instead. |
| A job stays *Queued* | Machine translations are processed in the background; click ▶ in the jobs module to start it now, or ask your administrator whether the background task runs. |
| A job stays *Running* for a long time | It may have been interrupted – cancel it and queue it again, or contact your administrator. |
| Referenced content is still in the original language | It was set to *Leave as is* – run the translation again and choose *Copy* or *Link* in step 3. |
