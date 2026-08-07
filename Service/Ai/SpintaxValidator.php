<?php

declare(strict_types=1);

namespace MauticPlugin\MauticZenderBundle\Service\Ai;

final class SpintaxValidator
{
    public function __construct(
        private readonly SpintaxTokenProtector $tokenProtector
    ) {
    }

    /**
     * @param array<string,string> $protectedTokens
     *
     * @return array{
     *   valid:bool,
     *   block_count:int,
     *   minimum_option_count:int,
     *   errors:list<string>
     * }
     */
    public function validate(
        string $originalProtectedText,
        string $candidate,
        array $protectedTokens,
        int $minimumBlocks,
        int $minimumOptions
    ): array {
        $errors = [];
        $blocks = $this->parseBlocks($candidate, $errors);

        if (count($blocks) < $minimumBlocks) {
            $errors[] = sprintf(
                'Expected at least %d spintax blocks; found %d.',
                $minimumBlocks,
                count($blocks)
            );
        }

        $minimumFound = PHP_INT_MAX;

        foreach ($blocks as $index => $block) {
            $alternatives = array_map(
                'trim',
                explode('|', $block)
            );
            $minimumFound = min(
                $minimumFound,
                count($alternatives)
            );

            if (count($alternatives) < $minimumOptions) {
                $errors[] = sprintf(
                    'Block %d has fewer than %d alternatives.',
                    $index + 1,
                    $minimumOptions
                );
            }

            foreach ($alternatives as $alternative) {
                if ('' === $alternative) {
                    $errors[] = sprintf(
                        'Block %d contains an empty alternative.',
                        $index + 1
                    );
                }

                if (
                    str_contains($alternative, '{')
                    || str_contains($alternative, '}')
                ) {
                    $errors[] = sprintf(
                        'Block %d contains nested spintax.',
                        $index + 1
                    );
                }
            }

            if (
                count(array_unique($alternatives))
                !== count($alternatives)
            ) {
                $errors[] = sprintf(
                    'Block %d contains duplicate alternatives.',
                    $index + 1
                );
            }
        }

        try {
            $this->tokenProtector
                ->assertProtectedTokensPreserved(
                    $candidate,
                    $protectedTokens
                );
        } catch (\Throwable $exception) {
            $errors[] = $exception->getMessage();
        }

        $this->assertFactsPreserved(
            $originalProtectedText,
            $candidate,
            $errors
        );

        return [
            'valid'                => [] === $errors,
            'block_count'          => count($blocks),
            'minimum_option_count' => (
                PHP_INT_MAX === $minimumFound
                    ? 0
                    : $minimumFound
            ),
            'errors'               => array_values(
                array_unique($errors)
            ),
        ];
    }

    /**
     * @param list<string> $errors
     *
     * @return list<string>
     */
    private function parseBlocks(
        string $candidate,
        array &$errors
    ): array {
        $depth = 0;
        $start = null;
        $blocks = [];
        $length = strlen($candidate);

        for ($index = 0; $index < $length; ++$index) {
            $character = $candidate[$index];

            if ('{' === $character) {
                ++$depth;

                if (1 === $depth) {
                    $start = $index + 1;
                } else {
                    $errors[] = 'Nested spintax is not allowed.';
                }
            } elseif ('}' === $character) {
                if (0 === $depth) {
                    $errors[] = 'A closing brace has no opening brace.';
                    continue;
                }

                --$depth;

                if (0 === $depth && null !== $start) {
                    $blocks[] = substr(
                        $candidate,
                        $start,
                        $index - $start
                    );
                    $start = null;
                }
            }
        }

        if (0 !== $depth) {
            $errors[] = 'Spintax braces are not balanced.';
        }

        return $blocks;
    }

    /**
     * @param list<string> $errors
     */
    private function assertFactsPreserved(
        string $original,
        string $candidate,
        array &$errors
    ): void {
        $patterns = [
            'URL' => '~https?://[^\s{}|]+~iu',
            'phone-like value' => (
                '~(?<!\d)(?:\+?\d[\d\s().-]{6,}\d)(?!\d)~u'
            ),
            'currency or amount' => (
                '~(?:[$€£S/]\s?\d[\d.,]*|\d[\d.,]*\s?(?:USD|EUR|PEN|soles?|dólares?))~iu'
            ),
        ];

        foreach ($patterns as $label => $pattern) {
            preg_match_all($pattern, $original, $originalMatches);
            preg_match_all($pattern, $candidate, $candidateMatches);

            $expected = array_values(
                array_unique($originalMatches[0] ?? [])
            );
            $actual = array_values(
                array_unique($candidateMatches[0] ?? [])
            );

            foreach ($expected as $value) {
                if (!in_array($value, $actual, true)) {
                    $errors[] = sprintf(
                        'The %s "%s" was not preserved.',
                        $label,
                        $value
                    );
                }
            }
        }
    }
}
