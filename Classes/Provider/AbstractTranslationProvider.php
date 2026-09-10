<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;
use ESET\Translator\Service\ConfigurationService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

abstract class AbstractTranslationProvider implements TranslationProviderInterface
{
    /** @var RequestFactory */
    protected $requestFactory;

    /** @var ConfigurationService */
    protected $configuration;

    public function __construct(RequestFactory $requestFactory, ConfigurationService $configuration)
    {
        $this->requestFactory = $requestFactory;
        $this->configuration = $configuration;
    }

    public function supports(TranslationTarget $source, TranslationTarget $target): bool
    {
        return $source->getTranslationCode() !== '' && $target->getTranslationCode() !== '';
    }

    public function getBatchSize(): int
    {
        return $this->configuration->getChunkSize();
    }

    /**
     * ISO 639-1 code most APIs expect ("cs-CZ" -> "cs").
     */
    protected function normalizeLanguageCode(TranslationTarget $target): string
    {
        $code = $target->getTranslationCode();
        $code = str_replace('_', '-', $code);
        $code = explode('-', $code)[0];

        return strtolower($code);
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['timeout'] = $options['timeout'] ?? $this->configuration->getProviderTimeout();
        $options['http_errors'] = false;

        try {
            $response = $this->requestFactory->request($url, $method, $options);
        } catch (\Throwable $exception) {
            throw new TranslationProviderException(
                sprintf('%s: request failed (%s)', $this->getTitle(), $exception->getMessage()),
                1710000060,
                $exception
            );
        }

        if ($response->getStatusCode() >= 400) {
            throw new TranslationProviderException(
                sprintf(
                    '%s: API responded with HTTP %d. %s',
                    $this->getTitle(),
                    $response->getStatusCode(),
                    $this->truncate((string)$response->getBody())
                ),
                1710000061
            );
        }

        return $response;
    }

    /**
     * @return array<mixed>
     */
    protected function decodeJson(ResponseInterface $response): array
    {
        $body = (string)$response->getBody();
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new TranslationProviderException(
                sprintf('%s: unexpected API response "%s".', $this->getTitle(), $this->truncate($body)),
                1710000062
            );
        }

        return $decoded;
    }

    protected function truncate(string $value, int $length = 300): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return strlen($value) > $length ? substr($value, 0, $length) . '…' : $value;
    }

    /**
     * Maps a translated, numerically indexed result list back onto the original
     * keys of the input array.
     *
     * @param string[] $texts
     * @param string[] $translations
     * @return string[]
     */
    protected function mapResults(array $texts, array $translations): array
    {
        $keys = array_keys($texts);
        $result = [];
        foreach (array_values($translations) as $index => $translation) {
            if (!isset($keys[$index])) {
                break;
            }
            $result[$keys[$index]] = (string)$translation;
        }

        return $result;
    }
}
