<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

final class SpintaxGenerationService
{
    public function __construct(
        private readonly AiSettingsRepository $settingsRepository,
        private readonly AiTextProviderRouter $providerRouter,
        private readonly SpintaxTokenProtector $tokenProtector,
        private readonly SpintaxPromptBuilder $promptBuilder,
        private readonly SpintaxValidator $validator
    ) {
    }

    /**
     * Generate a validated draft with the existing active AI configuration.
     *
     * The original message is returned unchanged and is never persisted here.
     *
     * @return array{
     *     ok: bool,
     *     original_text: string,
     *     generated_text: string,
     *     provider: string,
     *     model: string,
     *     validation: array<string,mixed>|null,
     *     error: string|null
     * }
     */
    public function generate(
        string $sourceText,
        string $style,
        string $level,
        int $customMinBlocks = 3,
        int $customMinOptions = 3
    ): array {
        try {
            $configuration = (
                $this->settingsRepository
                    ->getPublicConfiguration()
            );

            $provider = (string) (
                $configuration['active_provider'] ?? ''
            );
            $model = (string) (
                $configuration['active_model'] ?? ''
            );
            $normalizedProvider = (
                $this->providerRouter
                    ->normalizeProvider($provider)
            );

            if ('' === $normalizedProvider) {
                return $this->failure(
                    $sourceText,
                    '',
                    $model,
                    'active_provider_missing'
                );
            }

            if ('' === trim($model)) {
                return $this->failure(
                    $sourceText,
                    $normalizedProvider,
                    '',
                    'active_model_missing'
                );
            }

            $apiKey = (string) (
                $this->settingsRepository
                    ->getCredential($normalizedProvider)
            );

            if ('' === trim($apiKey)) {
                return $this->failure(
                    $sourceText,
                    $normalizedProvider,
                    $model,
                    'provider_credential_missing'
                );
            }

            return $this->generateWithConfiguration(
                $sourceText,
                $style,
                $level,
                $normalizedProvider,
                $model,
                $apiKey,
                $customMinBlocks,
                $customMinOptions
            );
        } catch (\Throwable) {
            return $this->failure(
                $sourceText,
                '',
                '',
                'ai_settings_unavailable'
            );
        }
    }

    /**
     * Provider-configured generation used by the internal workflow and
     * mock-only acceptance tests.
     *
     * @return array{
     *     ok: bool,
     *     original_text: string,
     *     generated_text: string,
     *     provider: string,
     *     model: string,
     *     validation: array<string,mixed>|null,
     *     error: string|null
     * }
     */
    public function generateWithConfiguration(
        string $sourceText,
        string $style,
        string $level,
        string $provider,
        string $model,
        string $apiKey,
        int $customMinBlocks = 3,
        int $customMinOptions = 3
    ): array {
        $originalText = $sourceText;

        if ('' === trim($sourceText)) {
            return $this->failure(
                $originalText,
                $provider,
                $model,
                'source_message_empty'
            );
        }

        try {
            $protected = $this->tokenProtector->protect(
                $sourceText
            );
            $prompt = $this->promptBuilder->build(
                $protected['protected_text'],
                $style,
                $level,
                $customMinBlocks,
                $customMinOptions
            );

            // AI_SPINTAX_PIPELINE_EXCHANGE_LOG_BEGIN
            $diagnosticRequestId = bin2hex(
                random_bytes(12)
            );

            $this->writeExchangeDiagnostic(
                'pipeline_request',
                $diagnosticRequestId,
                [
                    'source_text' => $originalText,
                    'protected_text' => (
                        $protected['protected_text']
                    ),
                    'protected_tokens' => (
                        $protected['tokens']
                    ),
                    'style' => $style,
                    'level' => $level,
                    'custom_min_blocks' => $customMinBlocks,
                    'custom_min_options' => $customMinOptions,
                    'provider' => $provider,
                    'normalized_provider' => (
                        $this->providerRouter
                            ->normalizeProvider($provider)
                    ),
                    'model' => $model,
                    'system_prompt' => $prompt['system'],
                    'user_prompt' => $prompt['user'],
                    'resolved_min_blocks' => (
                        $prompt['min_blocks']
                    ),
                    'resolved_min_options' => (
                        $prompt['min_options']
                    ),
                ]
            );
            // AI_SPINTAX_PIPELINE_EXCHANGE_LOG_END

            $providerResult = $this->providerRouter->generate(
                $provider,
                $apiKey,
                $model,
                $prompt['system'],
                $prompt['user']
            );

            $this->writeExchangeDiagnostic(
                'pipeline_provider_result',
                $diagnosticRequestId,
                [
                    'provider_result' => $providerResult,
                ]
            );

            if (true !== $providerResult['ok']) {
                $this->writeExchangeDiagnostic(
                    'pipeline_failure',
                    $diagnosticRequestId,
                    [
                        'stage' => 'provider',
                        'error' => (
                            $providerResult['error']
                            ?? 'provider_generation_failed'
                        ),
                        'provider_result' => $providerResult,
                    ]
                );
                return $this->failure(
                    $originalText,
                    (string) $providerResult['provider'],
                    (string) $providerResult['model'],
                    (string) (
                        $providerResult['error']
                        ?? 'provider_generation_failed'
                    )
                );
            }

            $candidate = trim(
                (string) $providerResult['text']
            );
            $validation = $this->validator->validate(
                $protected['protected_text'],
                $candidate,
                $protected['tokens'],
                (int) $prompt['min_blocks'],
                (int) $prompt['min_options']
            );

            $this->writeExchangeDiagnostic(
                'pipeline_validation',
                $diagnosticRequestId,
                [
                    'candidate' => $candidate,
                    'validation' => $validation,
                ]
            );

            if (true !== $validation['valid']) {
                $this->writeExchangeDiagnostic(
                    'pipeline_failure',
                    $diagnosticRequestId,
                    [
                        'stage' => 'validation',
                        'error' => 'invalid_spintax',
                        'candidate' => $candidate,
                        'validation' => $validation,
                    ]
                );

                return $this->failure(
                    $originalText,
                    (string) $providerResult['provider'],
                    (string) $providerResult['model'],
                    'invalid_spintax',
                    $validation
                );
            }

            $restored = $this->tokenProtector->restore(
                $candidate,
                $protected['tokens']
            );

            $this->writeExchangeDiagnostic(
                'pipeline_success',
                $diagnosticRequestId,
                [
                    'generated_text' => $restored,
                    'validation' => $validation,
                    'provider' => (
                        $providerResult['provider']
                    ),
                    'model' => $providerResult['model'],
                ]
            );

            return [
                'ok' => true,
                'original_text' => $originalText,
                'generated_text' => $restored,
                'provider' => (string) (
                    $providerResult['provider']
                ),
                'model' => (string) (
                    $providerResult['model']
                ),
                'validation' => $validation,
                'error' => null,
            ];
        } catch (\Throwable $throwable) {
            $this->writeExchangeDiagnostic(
                'pipeline_exception',
                $diagnosticRequestId ?? bin2hex(
                    random_bytes(12)
                ),
                [
                    'exception_class' => get_class(
                        $throwable
                    ),
                    'exception_message' => (
                        $throwable->getMessage()
                    ),
                    'exception_code' => (
                        $throwable->getCode()
                    ),
                ]
            );

            return $this->failure(
                $originalText,
                $this->providerRouter
                    ->normalizeProvider($provider),
                trim($model),
                'generation_pipeline_failed'
            );
        }
    }

    /**
     * @param array<string,mixed>|null $validation
     *
     * @return array{
     *     ok: false,
     *     original_text: string,
     *     generated_text: '',
     *     provider: string,
     *     model: string,
     *     validation: array<string,mixed>|null,
     *     error: string
     * }
     */
    /**
     * Append one private diagnostic event.
     *
     * API keys and credential values are never passed here.
     *
     * @param array<string,mixed> $data
     */
    private function writeExchangeDiagnostic(
        string $event,
        string $requestId,
        array $data
    ): void {
        try {
            $directory = (
                '/home/paellas/.7cats-runtime/'
                .'ai-spintax-debug'
            );
            $file = $directory.'/exchanges.jsonl';

            if (!is_dir($directory)) {
                @mkdir($directory, 0700, true);
            }

            if (!is_dir($directory)) {
                return;
            }

            @chmod($directory, 0700);

            $record = [
                'timestamp_utc' => gmdate('c'),
                'event' => $event,
                'request_id' => $requestId,
                'component' => (
                    'SpintaxGenerationService'
                ),
                'data' => $data,
            ];

            $encoded = json_encode(
                $record,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if (false === $encoded) {
                return;
            }

            @file_put_contents(
                $file,
                $encoded.PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
            @chmod($file, 0600);
        } catch (\Throwable) {
            // Diagnostics must never break generation.
        }
    }

    private function failure(
        string $originalText,
        string $provider,
        string $model,
        string $error,
        ?array $validation = null
    ): array {
        return [
            'ok' => false,
            'original_text' => $originalText,
            'generated_text' => '',
            'provider' => $provider,
            'model' => $model,
            'validation' => $validation,
            'error' => $error,
        ];
    }
}
