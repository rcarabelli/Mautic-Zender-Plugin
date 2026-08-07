<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Throwable;

final class GrokProviderClient
{
    private const RESPONSES_URL = 'https://api.x.ai/v1/responses';

    private const MODELS_URL = 'https://api.x.ai/v1/models';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Validate a credential and return selectable Grok models.
     *
     * The API key is used only in the Authorization header. It is never
     * written to logs or included in the returned result.
     *
     * @return array{
     *     valid: bool,
     *     models: list<string>,
     *     model_count: int,
     *     http_status: int|null,
     *     error: string|null
     * }
     */
    public function inspectCredential(string $apiKey): array
    {
        $apiKey = trim($apiKey);

        if ('' === $apiKey) {
            return $this->failure('missing_api_key', null);
        }

        try {
            $response = $this->client->request(
                'GET',
                self::MODELS_URL,
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Accept' => 'application/json',
                    ],
                    'http_errors' => false,
                    'timeout' => 20,
                    'connect_timeout' => 8,
                ]
            );

            $httpStatus = $response->getStatusCode();

            if ($httpStatus < 200 || $httpStatus >= 300) {
                return $this->failure(
                    'http_'.$httpStatus,
                    $httpStatus
                );
            }

            $decoded = json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (
                !is_array($decoded)
                || !isset($decoded['data'])
                || !is_array($decoded['data'])
            ) {
                return $this->failure(
                    'invalid_models_response',
                    $httpStatus
                );
            }

            $models = $this->selectGrokModels(
                $decoded['data']
            );

            return [
                'valid' => true,
                'models' => $models,
                'model_count' => count($models),
                'http_status' => $httpStatus,
                'error' => null,
            ];
        } catch (Throwable $throwable) {
            $this->logger->warning(
                '[ZENDER AI] Grok model discovery failed',
                [
                    'exception_class' => get_class($throwable),
                    'provider' => 'grok',
                ]
            );

            return $this->failure('request_failed', null);
        }
    }

    /**
     * @param array<int, mixed> $rows
     *
     * @return list<string>
     */
    /**
     * Generate plain text using the xAI Responses API.
     *
     * Credentials, prompts and generated content are never written to logs.
     *
     * @return array{
     *     ok: bool,
     *     text: string,
     *     http_status: int|null,
     *     error: string|null
     * }
     */
    public function generateText(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt
    ): array {
        $apiKey       = trim($apiKey);
        $model        = trim($model);
        $systemPrompt = trim($systemPrompt);
        $userPrompt   = trim($userPrompt);

        if ('' === $apiKey) {
            return $this->generationFailure(
                'missing_api_key',
                null
            );
        }

        if ('' === $model) {
            return $this->generationFailure(
                'missing_model',
                null
            );
        }

        if ('' === $systemPrompt || '' === $userPrompt) {
            return $this->generationFailure(
                'missing_prompt',
                null
            );
        }

        try {
            $response = $this->client->request(
                'POST',
                self::RESPONSES_URL,
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$apiKey,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => $model,
                        'instructions' => $systemPrompt,
                        'input' => $userPrompt,
                        'store' => false,
                        'max_output_tokens' => 4096,
                    ],
                    'http_errors' => false,
                    'timeout' => 60,
                    'connect_timeout' => 10,
                ]
            );

            $httpStatus = $response->getStatusCode();

            if ($httpStatus < 200 || $httpStatus >= 300) {
                return $this->generationFailure(
                    'provider_http_'.$httpStatus,
                    $httpStatus
                );
            }

            $decoded = json_decode(
                (string) $response->getBody(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            if (!is_array($decoded)) {
                return $this->generationFailure(
                    'invalid_generation_response',
                    $httpStatus
                );
            }

            $generatedText = $this->extractGeneratedText(
                $decoded
            );

            if ('' === $generatedText) {
                return $this->generationFailure(
                    'empty_generation',
                    $httpStatus
                );
            }

            return [
                'ok' => true,
                'text' => $generatedText,
                'http_status' => $httpStatus,
                'error' => null,
            ];
        } catch (\Throwable $throwable) {
            $this->logger->warning(
                '[ZENDER AI] Grok text generation failed',
                [
                    'exception_class' => get_class($throwable),
                    'provider' => 'grok',
                ]
            );

            return $this->generationFailure(
                'request_failed',
                null
            );
        }
    }

    /**
     * @param array<string,mixed> $decoded
     */
    private function extractGeneratedText(array $decoded): string
    {
        $topLevel = trim(
            (string) ($decoded['output_text'] ?? '')
        );

        if ('' !== $topLevel) {
            return $topLevel;
        }

        $parts = [];

        foreach ((array) ($decoded['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (
                    !is_array($content)
                    || 'output_text' !== ($content['type'] ?? null)
                ) {
                    continue;
                }

                $text = trim(
                    (string) ($content['text'] ?? '')
                );

                if ('' !== $text) {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return array{
     *     ok: false,
     *     text: '',
     *     http_status: int|null,
     *     error: string
     * }
     */
    private function generationFailure(
        string $error,
        ?int $httpStatus
    ): array {
        return [
            'ok' => false,
            'text' => '',
            'http_status' => $httpStatus,
            'error' => $error,
        ];
    }

    private function selectGrokModels(array $rows): array
    {
        $models = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = trim((string) ($row['id'] ?? ''));

            if (
                '' === $id
                || !str_starts_with(strtolower($id), 'grok-')
            ) {
                continue;
            }

            $models[$id] = true;
        }

        $ids = array_keys($models);
        natcasesort($ids);

        return array_values($ids);
    }

    /**
     * @return array{
     *     valid: false,
     *     models: list<string>,
     *     model_count: 0,
     *     http_status: int|null,
     *     error: string
     * }
     */
    private function failure(
        string $error,
        ?int $httpStatus
    ): array {
        return [
            'valid' => false,
            'models' => [],
            'model_count' => 0,
            'http_status' => $httpStatus,
            'error' => $error,
        ];
    }
}
