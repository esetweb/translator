<?php

declare(strict_types=1);

namespace ESET\Translator\Format;

/**
 * Holds every registered exchange format.
 */
class FormatRegistry
{
    /** @var array<string, FormatInterface> */
    protected $formats = [];

    /**
     * @param iterable<FormatInterface> $formats
     */
    public function __construct(iterable $formats)
    {
        foreach ($formats as $format) {
            $this->formats[$format->getIdentifier()] = $format;
        }
    }

    /**
     * @return array<string, FormatInterface>
     */
    public function getAll(): array
    {
        return $this->formats;
    }

    public function has(string $identifier): bool
    {
        return isset($this->formats[$identifier]);
    }

    public function get(string $identifier): FormatInterface
    {
        if (!isset($this->formats[$identifier])) {
            throw new \RuntimeException(
                sprintf('Unknown translation format "%s".', $identifier),
                1710000050
            );
        }

        return $this->formats[$identifier];
    }

    /**
     * Detects the format of an uploaded file.
     */
    public function detect(string $content, string $fileName): FormatInterface
    {
        foreach ($this->formats as $format) {
            if ($format->canImport($content, $fileName)) {
                return $format;
            }
        }

        throw new \RuntimeException(
            sprintf('The file "%s" does not match any known translation format.', $fileName),
            1710000051
        );
    }

    /**
     * @return array<string, string> identifier => title
     */
    public function getOptions(): array
    {
        $options = [];
        foreach ($this->formats as $identifier => $format) {
            $options[$identifier] = $format->getTitle();
        }

        return $options;
    }
}
