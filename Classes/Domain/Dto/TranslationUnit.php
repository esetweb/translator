<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Dto;

/**
 * A single translatable field value.
 */
final class TranslationUnit implements \JsonSerializable
{
    /** @var string */
    private $table;

    /** @var int */
    private $uid;

    /** @var string */
    private $field;

    /** @var string */
    private $sourceText;

    /** @var string */
    private $targetText = '';

    /** @var bool */
    private $html;

    /** @var string Human readable hint for translators, e.g. "Page title" */
    private $label;

    /** @var int Page the record lives on */
    private $pageUid;

    /** @var int uid of an already existing localized record, 0 when none exists yet */
    private $targetUid = 0;

    /** @var array<string, string> */
    private $metaData = [];

    public function __construct(
        string $table,
        int $uid,
        string $field,
        string $sourceText,
        bool $html = false,
        string $label = '',
        int $pageUid = 0
    ) {
        $this->table = $table;
        $this->uid = $uid;
        $this->field = $field;
        $this->sourceText = $sourceText;
        $this->html = $html;
        $this->label = $label !== '' ? $label : $field;
        $this->pageUid = $pageUid;
    }

    /**
     * Stable identifier used in the exchange files. Round trips through
     * third party translation tools, therefore no separators that get escaped.
     */
    public function getId(): string
    {
        return $this->table . '/' . $this->uid . '/' . $this->field;
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    public static function parseId(string $id): array
    {
        $parts = explode('/', $id);
        if (count($parts) < 3) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid translation unit id.', $id),
                1710000002
            );
        }
        $table = array_shift($parts);
        $uid = (int)array_shift($parts);

        return [$table, $uid, implode('/', $parts)];
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getUid(): int
    {
        return $this->uid;
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getSourceText(): string
    {
        return $this->sourceText;
    }

    public function getTargetText(): string
    {
        return $this->targetText;
    }

    public function setTargetText(string $targetText): void
    {
        $this->targetText = $targetText;
    }

    public function isHtml(): bool
    {
        return $this->html;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    public function getTargetUid(): int
    {
        return $this->targetUid;
    }

    public function setTargetUid(int $targetUid): void
    {
        $this->targetUid = $targetUid;
    }

    public function isTranslated(): bool
    {
        return trim($this->targetText) !== '';
    }

    public function getSourceHash(): string
    {
        return sha1($this->sourceText);
    }

    public function setMetaData(string $key, string $value): void
    {
        $this->metaData[$key] = $value;
    }

    /**
     * @return array<string, string>
     */
    public function getMetaData(): array
    {
        return $this->metaData;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->getId(),
            'table' => $this->table,
            'uid' => $this->uid,
            'field' => $this->field,
            'label' => $this->label,
            'pageUid' => $this->pageUid,
            'html' => $this->html,
            'source' => $this->sourceText,
            'target' => $this->targetText,
        ];
    }
}
