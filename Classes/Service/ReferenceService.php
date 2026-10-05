<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Dto\TranslationTarget;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handles "Insert records" content elements (CType shortcut) whose referenced
 * records live outside the pages of a translation job.
 *
 * Every reference is classified by where the referenced record lives:
 *  - inJob      on a page of the job - translated together with the page
 *  - targetSite elsewhere in the target site - may be translated where it is
 *  - foreign    in another site - must not be written to (a language id means a
 *               different language there); it is either relinked to an
 *               existing copy in the target site, or copied onto the page
 *  - missing    deleted / unknown record
 *
 * Structural changes (copy, relink) are applied when the job is created. A copy
 * shows exactly what the reference showed, so visitors see no difference until
 * the translation is imported.
 */
class ReferenceService
{
    public const SCOPE_IN_JOB = 'inJob';
    public const SCOPE_TARGET_SITE = 'targetSite';
    public const SCOPE_FOREIGN = 'foreign';
    public const SCOPE_MISSING = 'missing';

    public const ACTION_SKIP = 'skip';
    public const ACTION_TRANSLATE = 'translate';
    public const ACTION_COPY = 'copy';
    public const ACTION_RELINK_PREFIX = 'relink:';

    protected const TABLE = 'tt_content';
    protected const REFERENCE_FIELD = 'records';
    protected const SHORTCUT_CTYPE = 'shortcut';

    /** @var ConnectionPool */
    protected $connectionPool;

    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var PermissionService */
    protected $permissionService;

    /** @var RecordCollectorService */
    protected $recordCollector;

    /** @var array<int, string> pid => site identifier ('' = no site) */
    protected $siteCache = [];

    /** @var array<string, array<int, true>> site identifier => page uids of the site */
    protected $sitePageCache = [];

    /** @var array<int, array<int, array<string, mixed>>> original uid => its copies in the target site (current analyze()) */
    protected $copiesInTargetSite = [];

    public function __construct(
        ConnectionPool $connectionPool,
        SiteLanguageService $siteLanguageService,
        PermissionService $permissionService,
        RecordCollectorService $recordCollector
    ) {
        $this->connectionPool = $connectionPool;
        $this->siteLanguageService = $siteLanguageService;
        $this->permissionService = $permissionService;
        $this->recordCollector = $recordCollector;
    }

    /**
     * One entry per (shortcut element, referenced record) pair, in the order the
     * references appear in the shortcut.
     *
     * @return array<int, array<string, mixed>>
     */
    public function analyze(int $pageUid, TranslationTarget $source, TranslationTarget $target, int $depth): array
    {
        if (!isset($GLOBALS['TCA'][self::TABLE]['columns'][self::REFERENCE_FIELD])) {
            return [];
        }
        $pageUids = $this->recordCollector->resolvePageUids($pageUid, $source, $depth);

        // Pass 1: every (shortcut, rendered record) pair.
        $pairs = [];
        foreach ($this->fetchShortcuts($pageUids, $source) as $shortcut) {
            $shortcutUid = (int)$shortcut['uid'];
            $seenLeaves = [];
            foreach ($this->parseReferences((string)$shortcut[self::REFERENCE_FIELD]) as $topUid) {
                foreach ($this->expandReference($topUid, $pageUids, [$shortcutUid]) as $leaf) {
                    // One decision per record and shortcut, even if reached twice.
                    if (isset($seenLeaves[$leaf['uid']])) {
                        continue;
                    }
                    $seenLeaves[$leaf['uid']] = true;
                    $pairs[] = [$shortcut, $leaf['uid'], $topUid, $leaf['via']];
                }
            }
        }
        if ($pairs === []) {
            return [];
        }

        // Pass 2: copies in the target site for all records at once - one query,
        // independent of how often the content was copied across sites.
        $this->copiesInTargetSite = $target->getLanguageId() === 0
            ? $this->findCopiesInSite(array_column($pairs, 1), $target)
            : [];

        $entries = [];
        foreach ($pairs as [$shortcut, $refUid, $topUid, $via]) {
            $entries[] = $this->buildEntry($shortcut, $refUid, $topUid, $via, $pageUids, $target);
        }

        return $entries;
    }

    /**
     * Resolves nested "Insert records" elements to the records they render.
     *
     * fluid_styled_content renders a shortcut as nothing but its records (no
     * header), so a referenced shortcut outside the job pages is replaced by its
     * leaves. Shortcuts on the job pages are analysed on their own and stay a
     * leaf here. A hidden nested shortcut renders nothing and yields no leaves.
     *
     * @param int[] $jobPageUids
     * @param int[] $path shortcut uids already on the way (cycle guard)
     * @return array<int, array{uid: int, via: int[]}>
     */
    protected function expandReference(int $uid, array $jobPageUids, array $path): array
    {
        $record = BackendUtility::getRecord(self::TABLE, $uid);
        if (!is_array($record)
            || (string)($record['CType'] ?? '') !== self::SHORTCUT_CTYPE
            || in_array((int)$record['pid'], $jobPageUids, true)
        ) {
            return [['uid' => $uid, 'via' => []]];
        }
        if (in_array($uid, $path, true) || count($path) > 10 || $this->isHidden($record)) {
            return [];
        }

        $leaves = [];
        foreach ($this->parseReferences((string)$record[self::REFERENCE_FIELD]) as $childUid) {
            foreach ($this->expandReference($childUid, $jobPageUids, array_merge($path, [$uid])) as $leaf) {
                $leaf['via'] = array_merge([$uid], $leaf['via']);
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }

    /**
     * Applies the editor's decisions and returns the referenced records that must
     * be translated in place (scope targetSite, action translate).
     *
     * Decisions not offered for an entry fall back to its default, so a forged
     * request can never write into another site.
     *
     * @param array<int, array<string, mixed>> $entries result of analyze()
     * @param array<string, string> $decisions "<shortcutUid>:<refUid>" => action
     * @return array{extraRecords: array<string, int[]>, report: string[]}
     */
    public function apply(array $entries, array $decisions): array
    {
        $extraRecords = [];
        $report = [];
        /** @var array<int, array<int, array<int, array{uid: int, action: string}>>> $perShortcut shortcut => top ref => leaves */
        $perShortcut = [];

        foreach ($entries as $entry) {
            $refUid = (int)$entry['refUid'];
            $action = $this->resolveAction($entry, (string)($decisions[$entry['key']] ?? ''));
            if ($action === self::ACTION_TRANSLATE) {
                $extraRecords[self::TABLE][] = $refUid;
            }
            $perShortcut[(int)$entry['shortcutUid']][(int)$entry['topRefUid']][] = [
                'uid' => $refUid,
                'action' => $action,
                'via' => array_map('intval', (array)($entry['via'] ?? [])),
            ];
        }

        foreach ($perShortcut as $shortcutUid => $plan) {
            $report = array_merge($report, $this->applyToShortcut((int)$shortcutUid, $plan));
        }

        if (isset($extraRecords[self::TABLE])) {
            $extraRecords[self::TABLE] = array_values(array_unique($extraRecords[self::TABLE]));
        }

        return ['extraRecords' => $extraRecords, 'report' => $report];
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function resolveAction(array $entry, string $requested): string
    {
        foreach ((array)$entry['actions'] as $action) {
            if ($action['value'] === $requested) {
                return $requested;
            }
        }

        return (string)$entry['default'];
    }

    /**
     * Rebuilds what the shortcut renders, in order, as a sequence of
     * "stay referenced" and "copy" items:
     *  - a reference without any change stays as it is (nested shortcuts too)
     *  - a reference with a changed leaf is replaced by its leaves, relinked
     *    leaves pointing to the copy in the target site
     *
     * Without copies only the reference list is updated. With copies the
     * shortcut is deleted (soft delete, restorable) and replaced, in
     * the original order, by the copies and by new shortcuts holding the
     * references in between.
     *
     * Every element created or changed here gets a provenance note in its
     * description column (see buildNote()).
     *
     * @param array<int, array<int, array{uid: int, action: string, via: int[]}>> $plan top ref => leaves
     * @return string[]
     */
    protected function applyToShortcut(int $shortcutUid, array $plan): array
    {
        $shortcut = BackendUtility::getRecord(self::TABLE, $shortcutUid);
        if (!is_array($shortcut)) {
            return [];
        }
        $topRefs = $this->parseReferences((string)$shortcut[self::REFERENCE_FIELD]);
        $report = [];
        $relinked = [];
        /** @var array<int, int[]> $copyVia leaf uid => nested shortcuts it was reached through */
        $copyVia = [];

        /** @var array<int, array{0: string, 1: int}> $items */
        $items = [];
        $hasCopy = false;
        foreach ($topRefs as $topUid) {
            $leaves = $plan[$topUid] ?? [];
            $changed = false;
            foreach ($leaves as $leaf) {
                if ($leaf['action'] === self::ACTION_COPY || strpos($leaf['action'], self::ACTION_RELINK_PREFIX) === 0) {
                    $changed = true;
                }
            }
            if (!$changed) {
                $items[] = ['ref', $topUid];
                continue;
            }
            foreach ($leaves as $leaf) {
                if ($leaf['action'] === self::ACTION_COPY) {
                    $items[] = ['copy', $leaf['uid']];
                    $copyVia[$leaf['uid']] = $leaf['via'];
                    $hasCopy = true;
                } elseif (strpos($leaf['action'], self::ACTION_RELINK_PREFIX) === 0) {
                    $relinkUid = (int)substr($leaf['action'], strlen(self::ACTION_RELINK_PREFIX));
                    $items[] = ['ref', $relinkUid];
                    $relinked[] = sprintf('%s -> #%d', $this->describeRecord($leaf['uid']), $relinkUid);
                    $report[] = sprintf('"Insert records" #%d: #%d replaced by its copy #%d.', $shortcutUid, $leaf['uid'], $relinkUid);
                } else {
                    $items[] = ['ref', $leaf['uid']];
                }
            }
        }

        if (!$hasCopy) {
            $refs = array_column($items, 1);
            if ($refs !== $topRefs) {
                $update = [self::REFERENCE_FIELD => $this->buildReferenceValue($refs)];
                $update += $this->buildNote($shortcut, 'Relinked to copies in this site: ' . implode('; ', $relinked) . '.');
                $this->executeDataHandler([self::TABLE => [$shortcutUid => $update]], []);
            }

            return $report;
        }

        // Consecutive references form one segment (one new shortcut).
        $segments = [];
        foreach ($items as $item) {
            $last = count($segments) - 1;
            if ($item[0] === 'ref' && $last >= 0 && $segments[$last]['type'] === 'ref') {
                $segments[$last]['uids'][] = $item[1];
            } else {
                $segments[] = ['type' => $item[0], 'uids' => [$item[1]]];
            }
        }

        // Each element is inserted directly after the shortcut, so the last
        // segment goes in first and the original order is kept.
        $replaced = $this->describeRecord($shortcutUid, $shortcut);
        foreach (array_reverse($segments) as $segment) {
            if ($segment['type'] === 'copy') {
                $refUid = $segment['uids'][0];
                $original = BackendUtility::getRecord(self::TABLE, $refUid) ?: [];
                $via = $copyVia[$refUid] ?? [];
                $note = sprintf(
                    'Copy of %s. Replaces the reference in %s%s.',
                    $this->describeRecord($refUid, $original),
                    $replaced,
                    $via === [] ? '' : ' (via "Insert records" #' . implode(' -> #', $via) . ')'
                );
                $newUid = $this->copyAfter($refUid, $shortcutUid, $this->buildNote($original, $note));
                $report[] = sprintf('Copied #%d onto the page (new #%d).', $refUid, $newUid);
            } else {
                $update = [self::REFERENCE_FIELD => $this->buildReferenceValue($segment['uids'])];
                $update += $this->buildNote(
                    $shortcut,
                    sprintf('Split from %s; holds the references that were kept or relinked.', $replaced)
                        . ($relinked === [] ? '' : ' Relinked: ' . implode('; ', $relinked) . '.')
                );
                $newUid = $this->copyAfter($shortcutUid, $shortcutUid, $update);
                $report[] = sprintf('New "Insert records" #%d for #%s.', $newUid, implode(', #', $segment['uids']));
            }
        }

        // A leftover (even hidden) original above its translated replacement
        // reads as "not translated" in the page module, so it is removed. The
        // delete is a DataHandler soft delete - restorable via recycler/history.
        $this->executeDataHandler([], [self::TABLE => [$shortcutUid => ['delete' => 1]]]);
        $report[] = sprintf('"Insert records" #%d deleted - replaced by the elements in its place.', $shortcutUid);

        return $report;
    }

    /**
     * Copies a record directly after another one (same page, column and
     * language) and returns the new uid. Localizations are not copied: those of
     * another site's record use language ids that mean something else here.
     *
     * @param array<string, mixed> $update
     */
    protected function copyAfter(int $uid, int $afterUid, array $update): int
    {
        $header = $this->getHeaderField();
        $original = BackendUtility::getRecord(self::TABLE, $uid);
        if ($header !== '' && is_array($original) && array_key_exists($header, $original)) {
            // Avoid the "(copy 1)" prefix; the copy replaces the original 1:1.
            $update[$header] = (string)$original[$header];
        }
        $dataHandler = $this->executeDataHandler([], [
            self::TABLE => [
                $uid => [
                    'copy' => [
                        'action' => 'paste',
                        'target' => ['target' => -$afterUid, 'ignoreLocalization' => true],
                        'update' => $update,
                    ],
                ],
            ],
        ]);

        return (int)($dataHandler->copyMappingArray_merged[self::TABLE][$uid] ?? 0);
    }

    /**
     * Provenance note for the table's description column (tt_content:
     * rowDescription, shown on the element in the page module). Appended to the
     * note the record already carries, never replacing it. Empty when the table
     * has no description column.
     *
     * The description column is never sent to translation
     * (RecordCollectorService::isTranslatableField()).
     *
     * @param array<string, mixed> $record the record whose note is extended
     * @return array<string, string>
     */
    protected function buildNote(array $record, string $text): array
    {
        $column = (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['descriptionColumn'] ?? '');
        if ($column === '' || !isset($GLOBALS['TCA'][self::TABLE]['columns'][$column])) {
            return [];
        }
        $line = sprintf(
            '[ESET Translator %s, %s] %s',
            date('Y-m-d H:i', (int)($GLOBALS['EXEC_TIME'] ?? time())),
            $this->getBackendUserName(),
            $text
        );
        $existing = trim((string)($record[$column] ?? ''));

        return [$column => $existing === '' ? $line : $existing . "\n" . $line];
    }

    /**
     * "#6 "Heading" (site us, page "Test site" [2])"
     *
     * @param array<string, mixed>|null $record loaded when not given
     */
    protected function describeRecord(int $uid, ?array $record = null): string
    {
        if ($record === null || $record === []) {
            $record = BackendUtility::getRecord(self::TABLE, $uid) ?: [];
        }
        $header = $this->getHeaderField();
        $title = $header !== '' ? trim((string)($record[$header] ?? '')) : '';
        $pid = (int)($record['pid'] ?? 0);
        $site = $this->getSiteIdentifier($pid);

        return sprintf(
            '#%d%s (site %s, page "%s" [%d])',
            $uid,
            $title !== '' ? ' "' . $title . '"' : '',
            $site !== '' ? $site : '-',
            $this->getPageTitle($pid),
            $pid
        );
    }

    protected function getBackendUserName(): string
    {
        $user = $GLOBALS['BE_USER']->user ?? [];

        return (string)($user['username'] ?? 'unknown');
    }

    /**
     * @param int[] $uids
     */
    protected function buildReferenceValue(array $uids): string
    {
        return implode(',', array_map(static function (int $uid): string {
            return self::TABLE . '_' . $uid;
        }, $uids));
    }

    /**
     * @param array<string, mixed> $shortcut
     * @param int[] $via
     * @param int[] $jobPageUids
     * @return array<string, mixed>
     */
    protected function buildEntry(array $shortcut, int $refUid, int $topRefUid, array $via, array $jobPageUids, TranslationTarget $target): array
    {
        $shortcutUid = (int)$shortcut['uid'];
        $header = $this->getHeaderField();
        $ref = BackendUtility::getRecord(self::TABLE, $refUid);

        $entry = [
            'key' => $shortcutUid . ':' . $refUid,
            'shortcutUid' => $shortcutUid,
            // Entry in the shortcut's own list this record is reached through,
            // and the nested shortcuts in between (empty = direct reference).
            'topRefUid' => $topRefUid,
            'via' => $via,
            'shortcutPid' => (int)$shortcut['pid'],
            'shortcutTitle' => $header !== '' ? (string)($shortcut[$header] ?? '') : '',
            'shortcutPageTitle' => $this->getPageTitle((int)$shortcut['pid']),
            'refUid' => $refUid,
            'refTitle' => '',
            'refCType' => '',
            'refPid' => 0,
            'refPageTitle' => '',
            'refSite' => '',
            'scope' => self::SCOPE_MISSING,
            'actions' => [],
            'default' => self::ACTION_SKIP,
        ];

        if (!is_array($ref)) {
            $entry['actions'] = [['value' => self::ACTION_SKIP]];

            return $entry;
        }

        $refPid = (int)$ref['pid'];
        $entry['refTitle'] = $header !== '' ? (string)($ref[$header] ?? '') : '';
        $entry['refCType'] = (string)($ref['CType'] ?? '');
        $entry['refPid'] = $refPid;
        $entry['refPageTitle'] = $this->getPageTitle($refPid);
        $entry['refSite'] = $this->getSiteIdentifier($refPid);

        if (in_array($refPid, $jobPageUids, true)) {
            $entry['scope'] = self::SCOPE_IN_JOB;
            $entry['actions'] = [['value' => self::ACTION_SKIP]];

            return $entry;
        }

        $actions = [];
        if ($entry['refSite'] !== '' && $entry['refSite'] === $target->getSiteIdentifier()) {
            $entry['scope'] = self::SCOPE_TARGET_SITE;
            if ($this->permissionService->canEditPage($refPid)) {
                $actions[] = ['value' => self::ACTION_TRANSLATE];
            }
            $actions[] = ['value' => self::ACTION_COPY];
            $actions[] = ['value' => self::ACTION_SKIP];
            $entry['actions'] = $actions;
            $entry['default'] = $actions[0]['value'] === self::ACTION_TRANSLATE ? self::ACTION_TRANSLATE : self::ACTION_SKIP;

            return $entry;
        }

        $entry['scope'] = self::SCOPE_FOREIGN;
        // Relinking only makes sense for in-place targets: the copy in the
        // target site *is* the translation. For overlay targets the frontend
        // overlays whatever is referenced, so a copy is the safe choice.
        if ($target->getLanguageId() === 0) {
            foreach ($this->copiesInTargetSite[$refUid] ?? [] as $copy) {
                $actions[] = [
                    'value' => self::ACTION_RELINK_PREFIX . $copy['uid'],
                    'uid' => (int)$copy['uid'],
                    'title' => $header !== '' ? (string)($copy[$header] ?? '') : '',
                    'pid' => (int)$copy['pid'],
                    'pageTitle' => $this->getPageTitle((int)$copy['pid']),
                    'hidden' => $this->isHidden($copy),
                ];
            }
        }
        $actions[] = ['value' => self::ACTION_COPY];
        $actions[] = ['value' => self::ACTION_SKIP];
        $entry['actions'] = $actions;
        // Relink when a copy already exists; copying changes the page structure,
        // so it is never the default.
        $entry['default'] = strpos($actions[0]['value'], self::ACTION_RELINK_PREFIX) === 0
            ? $actions[0]['value']
            : self::ACTION_SKIP;

        return $entry;
    }

    /**
     * Visible "Insert records" elements on the job pages in the source language.
     * Hidden ones are not rendered, so their references need no translation.
     *
     * @param int[] $pageUids
     * @return array<int, array<string, mixed>>
     */
    protected function fetchShortcuts(array $pageUids, TranslationTarget $source): array
    {
        if ($pageUids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $constraints = [
            $queryBuilder->expr()->in(
                'pid',
                $queryBuilder->createNamedParameter($pageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
            ),
            $queryBuilder->expr()->eq('CType', $queryBuilder->createNamedParameter(self::SHORTCUT_CTYPE)),
            $queryBuilder->expr()->neq(self::REFERENCE_FIELD, $queryBuilder->createNamedParameter('')),
        ];
        $languageField = (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['languageField'] ?? '');
        if ($languageField !== '') {
            $constraints[] = $queryBuilder->expr()->eq(
                $languageField,
                $queryBuilder->createNamedParameter($source->getLanguageId(), \PDO::PARAM_INT)
            );
        }
        $hiddenField = (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['enablecolumns']['disabled'] ?? '');
        if ($hiddenField !== '') {
            $constraints[] = $queryBuilder->expr()->eq($hiddenField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT));
        }

        return $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(...$constraints)
            ->orderBy('pid')
            ->addOrderBy('colPos')
            ->addOrderBy('sorting')
            ->execute()
            ->fetchAll();
    }

    /**
     * Records copied (t3_origuid) from any of $refUids that live in the target
     * site, grouped by the original's uid, newest first.
     *
     * Content of this installation is copied into many sites, so one record can
     * have hundreds of copies. Resolving the site of every copy (rootline per
     * pid) does not scale - the copies are matched against the page uids of
     * the target site instead, which are resolved once per request.
     *
     * @param int[] $refUids
     * @return array<int, array<int, array<string, mixed>>>
     */
    protected function findCopiesInSite(array $refUids, TranslationTarget $target): array
    {
        $origUidField = (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['origUid'] ?? '');
        $refUids = array_values(array_unique(array_filter(array_map('intval', $refUids))));
        if ($origUidField === '' || $refUids === [] || $target->getRootPageId() <= 0) {
            return [];
        }
        $sitePages = $this->getSitePageUids($target);

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $constraints = [
            $queryBuilder->expr()->in(
                $origUidField,
                $queryBuilder->createNamedParameter($refUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
            ),
        ];
        $languageField = (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['languageField'] ?? '');
        if ($languageField !== '') {
            $constraints[] = $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT));
        }

        $statement = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(...$constraints)
            ->orderBy('uid', 'DESC')
            ->execute();

        $copies = [];
        while ($row = $statement->fetch()) {
            if (isset($sitePages[(int)$row['pid']])) {
                $copies[(int)$row[$origUidField]][] = $row;
            }
        }

        return $copies;
    }

    /**
     * uid => true for every page of the target's site (default language), by
     * walking the tree level by level from the site root. Subtrees that are
     * roots of other sites are excluded. One query per tree level.
     *
     * @return array<int, true>
     */
    protected function getSitePageUids(TranslationTarget $target): array
    {
        $siteIdentifier = $target->getSiteIdentifier();
        if (isset($this->sitePageCache[$siteIdentifier])) {
            return $this->sitePageCache[$siteIdentifier];
        }

        $otherRoots = [];
        foreach ($this->siteLanguageService->getAllTargets() as $candidate) {
            if ($candidate->getSiteIdentifier() !== $siteIdentifier && $candidate->getRootPageId() > 0) {
                $otherRoots[$candidate->getRootPageId()] = true;
            }
        }

        $rootPageId = $target->getRootPageId();
        $pages = [$rootPageId => true];
        $level = [$rootPageId];
        $languageField = (string)($GLOBALS['TCA']['pages']['ctrl']['languageField'] ?? '');
        for ($depth = 0; $level !== [] && $depth < 100; $depth++) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where($queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($level, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                ));
            if ($languageField !== '') {
                $queryBuilder->andWhere(
                    $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
                );
            }
            $next = [];
            foreach ($queryBuilder->execute()->fetchAll() as $row) {
                $uid = (int)$row['uid'];
                if (isset($pages[$uid]) || isset($otherRoots[$uid])) {
                    continue;
                }
                $pages[$uid] = true;
                $next[] = $uid;
            }
            $level = $next;
        }

        return $this->sitePageCache[$siteIdentifier] = $pages;
    }

    /**
     * Group field values: "tt_content_12,tt_content_7" or plain "12,7" (single
     * allowed table). Other tables are ignored.
     *
     * @return int[]
     */
    protected function parseReferences(string $value): array
    {
        $uids = [];
        foreach (GeneralUtility::trimExplode(',', $value, true) as $item) {
            if (preg_match('/^(.+)_(\d+)$/', $item, $matches)) {
                if ($matches[1] === self::TABLE) {
                    $uids[] = (int)$matches[2];
                }
            } elseif (ctype_digit($item)) {
                $uids[] = (int)$item;
            }
        }

        return array_values(array_unique(array_filter($uids)));
    }

    protected function getSiteIdentifier(int $pid): string
    {
        if (!isset($this->siteCache[$pid])) {
            $site = $pid > 0 ? $this->siteLanguageService->getSiteForPage($pid) : null;
            $this->siteCache[$pid] = $site === null ? '' : $site->getIdentifier();
        }

        return $this->siteCache[$pid];
    }

    protected function getPageTitle(int $pid): string
    {
        $page = $pid > 0 ? BackendUtility::getRecord('pages', $pid, 'title') : null;

        return (string)($page['title'] ?? '');
    }

    protected function getHeaderField(): string
    {
        return (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['label'] ?? '');
    }

    /**
     * @param array<string, mixed> $row
     */
    protected function isHidden(array $row): bool
    {
        $hiddenField = (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['enablecolumns']['disabled'] ?? '');

        return $hiddenField !== '' && !empty($row[$hiddenField]);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap
     * @param array<string, array<int|string, array<string, mixed>>> $commandMap
     */
    protected function executeDataHandler(array $dataMap, array $commandMap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        // The copy replaces a visible reference - it must be visible too.
        $dataHandler->neverHideAtCopy = true;
        $dataHandler->start($dataMap, $commandMap);
        if ($commandMap !== []) {
            $dataHandler->process_cmdmap();
        }
        if ($dataMap !== []) {
            $dataHandler->process_datamap();
        }
        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException(implode(' | ', $dataHandler->errorLog), 1710000170);
        }

        return $dataHandler;
    }
}
