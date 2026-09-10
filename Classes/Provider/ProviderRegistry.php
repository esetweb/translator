<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;
use ESET\Translator\Service\ConfigurationService;

/**
 * Holds every registered provider and answers the question the UI cares about:
 * "is there any usable automated provider for this source/target pair?"
 */
class ProviderRegistry
{
    /** @var array<string, TranslationProviderInterface> */
    protected $providers = [];

    /** @var ConfigurationService */
    protected $configuration;

    /**
     * @param iterable<TranslationProviderInterface> $providers
     */
    public function __construct(iterable $providers, ConfigurationService $configuration)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getIdentifier()] = $provider;
        }
        $this->configuration = $configuration;
    }

    /**
     * @return array<string, TranslationProviderInterface>
     */
    public function getAll(): array
    {
        return $this->providers;
    }

    /**
     * Providers that are configured and therefore usable right now.
     *
     * @return array<string, TranslationProviderInterface>
     */
    public function getAvailable(): array
    {
        return array_filter(
            $this->providers,
            static function (TranslationProviderInterface $provider): bool {
                return $provider->isAvailable();
            }
        );
    }

    /**
     * @return array<string, TranslationProviderInterface>
     */
    public function getAvailableFor(TranslationTarget $source, TranslationTarget $target): array
    {
        return array_filter(
            $this->getAvailable(),
            static function (TranslationProviderInterface $provider) use ($source, $target): bool {
                return $provider->supports($source, $target);
            }
        );
    }

    public function has(string $identifier): bool
    {
        return isset($this->providers[$identifier]);
    }

    public function get(string $identifier): TranslationProviderInterface
    {
        if (!isset($this->providers[$identifier])) {
            throw new TranslationProviderException(
                sprintf('Unknown translation provider "%s".', $identifier),
                1710000110
            );
        }

        return $this->providers[$identifier];
    }

    /**
     * Preferred provider for a pair, honouring the configured default.
     */
    public function resolveDefault(TranslationTarget $source, TranslationTarget $target): ?TranslationProviderInterface
    {
        $candidates = $this->getAvailableFor($source, $target);
        if ($candidates === []) {
            return null;
        }
        $preferred = $this->configuration->getDefaultProvider();
        if ($preferred !== '' && isset($candidates[$preferred])) {
            return $candidates[$preferred];
        }

        return reset($candidates) ?: null;
    }

    public function hasAnyAvailable(): bool
    {
        return $this->getAvailable() !== [];
    }

    /**
     * @return array<string, string>
     */
    public function getOptionsFor(TranslationTarget $source, TranslationTarget $target): array
    {
        $options = [];
        foreach ($this->getAvailableFor($source, $target) as $identifier => $provider) {
            $options[$identifier] = $provider->getTitle();
        }

        return $options;
    }
}
