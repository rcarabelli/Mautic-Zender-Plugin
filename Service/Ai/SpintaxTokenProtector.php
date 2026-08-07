<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

final class SpintaxTokenProtector
{
    private const TOKEN_PATTERN = '/\{(?:contactfield|leadfield|customfield|unsubscribe|webview|signature|trackingpixel)[^{}]*\}/iu';

    /**
     * @return array{protected_text:string,tokens:array<string,string>}
     */
    public function protect(string $text): array
    {
        $tokens = [];
        $index  = 0;

        $protectedText = preg_replace_callback(
            self::TOKEN_PATTERN,
            static function (array $match) use (&$tokens, &$index): string {
                $marker = sprintf(
                    '[[MZWT_TOKEN_%03d]]',
                    ++$index
                );

                $tokens[$marker] = $match[0];

                return $marker;
            },
            $text
        );

        if (null === $protectedText) {
            throw new \RuntimeException(
                'Unable to protect Mautic placeholders.'
            );
        }

        return [
            'protected_text' => $protectedText,
            'tokens'         => $tokens,
        ];
    }

    /**
     * @param array<string,string> $tokens
     */
    public function restore(string $text, array $tokens): string
    {
        if ([] === $tokens) {
            return $text;
        }

        foreach ($tokens as $marker => $originalToken) {
            if (1 !== substr_count($text, $marker)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Protected marker %s was changed or duplicated.',
                        $marker
                    )
                );
            }
        }

        $restored = strtr($text, $tokens);

        foreach (array_keys($tokens) as $marker) {
            if (str_contains($restored, $marker)) {
                throw new \RuntimeException(
                    'A protected marker remained after restoration.'
                );
            }
        }

        return $restored;
    }

    /**
     * @param array<string,string> $tokens
     */
    public function assertProtectedTokensPreserved(
        string $text,
        array $tokens
    ): void {
        foreach ($tokens as $marker => $originalToken) {
            if (1 !== substr_count($text, $marker)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Protected marker %s must appear exactly once.',
                        $marker
                    )
                );
            }

            if (str_contains($text, $originalToken)) {
                throw new \InvalidArgumentException(
                    'Original Mautic placeholders must stay protected.'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    public function extractMauticTokens(string $text): array
    {
        preg_match_all(
            self::TOKEN_PATTERN,
            $text,
            $matches
        );

        return array_values($matches[0] ?? []);
    }
}
