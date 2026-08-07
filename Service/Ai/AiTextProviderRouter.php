<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

final class AiTextProviderRouter
{
    public function __construct(
        private readonly OpenAiProviderClient $openAiClient,
        private readonly AnthropicProviderClient $anthropicClient,
        private readonly GrokProviderClient $grokClient
    ) {
    }

    /**
     * @return array{
     *     ok: bool,
     *     text: string,
     *     http_status: int|null,
     *     error: string|null,
     *     provider: string,
     *     model: string
     * }
     */
    public function generate(
        string $provider,
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt
    ): array {
        $provider = $this->normalizeProvider($provider);
        $model    = trim($model);

        if ('' === $provider) {
            return $this->failure(
                '',
                $model,
                'unsupported_provider'
            );
        }

        $result = match ($provider) {
            'openai' => $this->openAiClient->generateText(
                $apiKey,
                $model,
                $systemPrompt,
                $userPrompt
            ),
            'anthropic' => $this->anthropicClient->generateText(
                $apiKey,
                $model,
                $systemPrompt,
                $userPrompt
            ),
            'grok' => $this->grokClient->generateText(
                $apiKey,
                $model,
                $systemPrompt,
                $userPrompt
            ),
        };

        return [
            'ok' => true === ($result['ok'] ?? false),
            'text' => (string) ($result['text'] ?? ''),
            'http_status' => isset($result['http_status'])
                ? (int) $result['http_status']
                : null,
            'error' => isset($result['error'])
                ? (string) $result['error']
                : null,
            'provider' => $provider,
            'model' => $model,
        ];
    }

    public function normalizeProvider(string $provider): string
    {
        return match (strtolower(trim($provider))) {
            'openai' => 'openai',
            'anthropic', 'claude' => 'anthropic',
            'grok', 'xai' => 'grok',
            default => '',
        };
    }

    /**
     * @return array{
     *     ok: false,
     *     text: '',
     *     http_status: null,
     *     error: string,
     *     provider: string,
     *     model: string
     * }
     */
    private function failure(
        string $provider,
        string $model,
        string $error
    ): array {
        return [
            'ok' => false,
            'text' => '',
            'http_status' => null,
            'error' => $error,
            'provider' => $provider,
            'model' => $model,
        ];
    }
}
