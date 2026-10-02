# Translation agency guide

This guide is for translation agencies and translators who receive files
exported from our TYPO3 website. Please read it before you start – a file that
was changed in the wrong places cannot be imported back.

## The file

- **Format:** XLIFF 1.2 (`.xlf`), UTF-8. On request we can also send CATXML
  (the format of the TYPO3 extension l10nmgr).
- **One file = one job:** one page (optionally with subpages), one source and
  one target language. The file name contains the job number and the language
  pair, e.g. `eset-translation_20261002-214530-45ab1b90_us-0-to-sk-0.xlf`.
- Languages are set on the `<file>` element (`source-language`,
  `target-language`, e.g. `en-US` → `sk-SK`).

A unit looks like this:

```xml
<trans-unit id="tt_content/13141787/bodytext" datatype="html" xml:space="preserve"
            eset:table="tt_content" eset:uid="13141787" eset:field="bodytext"
            eset:page="163239" eset:hash="d782…">
  <source><![CDATA[<p>Text with <strong>markup</strong>.</p>]]></source>
  <target state="needs-translation"><![CDATA[]]></target>
  <note from="typo3">Text</note>
</trans-unit>
```

- `<source>` – the text to translate.
- `<target>` – put your translation here.
- `<note from="typo3">` – the name of the field (e.g. *Header*, *Text*, *Page
  title*) for context.

## Rules

**Please do:**

- Write the translation into `<target>`. Setting `state="translated"` is
  welcome but not required.
- Leave `<target>` empty for texts you do not translate – empty targets are
  skipped on import, the original stays.
- Return the file as XLIFF 1.2 in UTF-8. The file name may change.

**Please do not:**

- Change, add or remove **`id`** attributes, or merge / split units.
- Remove the **`eset:…`** attributes or the `xmlns:eset` declaration. They tell
  TYPO3 where each text belongs. (If your tool cannot keep them, tell us – we
  can still import the file through the job, but it is more work for us.)
- Change the `<source>` text or the attributes of `<file>`.
- Convert the file to XLIFF 2.0 or another format.

## Texts with HTML (`datatype="html"`)

Units marked `datatype="html"` contain HTML from a rich-text editor (paragraphs,
bold text, links, lists, tables). The markup is wrapped in a CDATA section
(`<![CDATA[ … ]]>`), which is valid XML – your tool reads it as normal text.

To keep the markup intact, **enable your tool's HTML / embedded content filter
for these units** so that tags are shown as protected tags instead of text –
for example the embedded content processor in Trados Studio or the HTML
cascading filter in memoQ.

In the translation:

- Keep every tag and its order. Translate the text between the tags.
- Do not translate attribute values such as `href="…"`, `class="…"` or `id="…"`
  (exception: `title="…"` and `alt="…"` texts may be translated).
- Keep entities such as `&nbsp;` and `&amp;` as they are.

Units marked `datatype="plaintext"` contain no markup.

## Questions

If something in the file is unclear or your tool reports problems, contact the
person who sent you the file **before** you change the file structure.
