<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

final class SpintaxPromptBuilder
{
    /**
     * @return array{
     *   system:string,
     *   user:string,
     *   min_blocks:int,
     *   min_options:int,
     *   style:string
     * }
     */
    public function build(
        string $protectedText,
        string $style,
        string $level,
        int $customMinBlocks = 3,
        int $customMinOptions = 3
    ): array {
        $protectedText = trim($protectedText);

        if ('' === $protectedText) {
            throw new \InvalidArgumentException(
                'The source message cannot be empty.'
            );
        }

        $style = $this->normalizeStyle($style);
        [$minBlocks, $minOptions] = $this->resolveLimits(
            $level,
            $customMinBlocks,
            $customMinOptions
        );

        $styleInstruction = match ($style) {
            'words' => (
                'Prefer concise word-level alternatives. Use phrase-level '
                .'alternatives only when a word-only change would sound unnatural.'
            ),
            'phrases' => (
                'Prefer complete phrase-level alternatives that remain natural '
                .'in every combination.'
            ),
            default => (
                'Use a natural mix of word-level and phrase-level alternatives.'
            ),
        };

        $system = implode("\n", [
            'You are a meticulous Spanish-language WhatsApp copy editor.',
            'Transform the supplied message into valid flat spintax.',
            'Use only the syntax {option one|option two|option three}.',
            'Do not use nested spintax.',
            'Return only the transformed message, with no markdown, explanation, title or code fence.',
            'Preserve the original meaning, intent, tone and language.',
            'Preserve all facts, names, URLs, phone numbers, dates, amounts, currencies and line breaks.',
            'Every protected marker such as [[MZWT_TOKEN_001]] must remain exactly once and unchanged.',
            'Each alternative must make grammatical and semantic sense with every surrounding combination.',
            'Avoid false synonyms, awkward grammar, exaggerated claims and changes in commercial meaning.',
            'Before returning, silently review the complete result and correct any combination that could sound unnatural or change meaning.',
        ]);

        $user = implode("\n", [
            sprintf(
                'Create at least %d independent spintax blocks.',
                $minBlocks
            ),
            sprintf(
                'Each block must contain at least %d meaningful alternatives.',
                $minOptions
            ),
            $styleInstruction,
            '',
            'MESSAGE:',
            $protectedText,
        ]);

        return [
            'system'      => $system,
            'user'        => $user,
            'min_blocks'  => $minBlocks,
            'min_options' => $minOptions,
            'style'       => $style,
        ];
    }

    private function normalizeStyle(string $style): string
    {
        $style = strtolower(trim($style));

        return in_array(
            $style,
            ['mixed', 'words', 'phrases'],
            true
        ) ? $style : 'mixed';
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolveLimits(
        string $level,
        int $customMinBlocks,
        int $customMinOptions
    ): array {
        return match (strtolower(trim($level))) {
            'balanced_5x3' => [5, 3],
            'high_8x4'     => [8, 4],
            'custom'       => [
                max(3, min(30, $customMinBlocks)),
                max(3, min(10, $customMinOptions)),
            ],
            default        => [3, 3],
        };
    }
}
