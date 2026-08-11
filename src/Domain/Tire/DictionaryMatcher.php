<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Loads tire dictionary codes from the `tires_dictionary` table and provides
 * methods to match tire parameter values against dictionary kinds.
 *
 * The dictionary table contains rows with (kind, code, value, slug) where
 * `kind` categorises the code (e.g. "tube_type", "ply_rating", "season")
 * and `code` is the canonical representation used for matching.
 *
 * Matching is always case-insensitive and trim-safe.
 */
class DictionaryMatcher
{
    /**
     * Dictionary codes grouped by kind.
     *
     * @var array<string, string[]>
     */
    private array $codesByKind = [];

    /**
     * @param array<string, string[]> $codesByKind Dictionary codes grouped by kind
     */
    private function __construct(array $codesByKind)
    {
        $this->codesByKind = $codesByKind;
    }

    /**
     * Load the dictionary from `tires_dictionary`.
     */
    public static function fromPdo(\PDO $pdo): self
    {
        $stmt = $pdo->query('SELECT kind, code FROM tires_dictionary');

        if (false === $stmt) {
            throw new \RuntimeException('Failed to query tires_dictionary table.');
        }

        $codesByKind = [];

        /** @var array<string, mixed> $row */
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $kind = $row['kind'] ?? null;
            $code = $row['code'] ?? null;

            if (!is_string($kind) || !is_string($code) || '' === $kind || '' === $code) {
                continue;
            }

            $codesByKind[$kind][] = $code;
        }

        return new self($codesByKind);
    }

    /**
     * Build the dictionary from an in-memory map.
     *
     * The classification rules are worth testing without a database — this is
     * how the tests get a dictionary without one.
     *
     * @param array<string, string[]> $codesByKind Dictionary codes grouped by kind
     */
    public static function fromCodes(array $codesByKind): self
    {
        return new self($codesByKind);
    }

    /**
     * Return all known codes for a given dictionary kind.
     *
     * @param string $kind Dictionary kind (e.g. "tube_type", "season").
     *
     * @return string[] array of code strings; empty when the kind is unknown
     */
    private function getCodesForKind(string $kind): array
    {
        return $this->codesByKind[$kind] ?? [];
    }

    /**
     * Return the original (properly-cased) dictionary code that matches the
     * given parameter within the specified kind.
     *
     * @param string $parameter the tire parameter value to look up
     * @param string $kind      the dictionary kind to search
     *
     * @return null|string the canonical code string, or null when no match is found
     */
    public function getMatchedCode(string $parameter, string $kind): ?string
    {
        $codes = $this->getCodesForKind($kind);
        $normalised = mb_strtolower(trim($parameter), 'UTF-8');

        foreach ($codes as $code) {
            if ($normalised === mb_strtolower(trim($code), 'UTF-8')) {
                return $code;
            }
        }

        return null;
    }

    /**
     * Attempt to match a parameter against multiple dictionary kinds and
     * return the first matching kind and its canonical code.
     *
     * This is useful when classifying an unknown parameter token against
     * several candidate kinds (e.g. when parsing `ex_other` tokens).
     *
     * @param string   $parameter the tire parameter value
     * @param string[] $kinds     ordered list of dictionary kinds to try
     *
     * @return null|array{kind: string, code: string} the first match, or null
     */
    public function matchParameterToFirstKind(string $parameter, array $kinds): ?array
    {
        foreach ($kinds as $kind) {
            $code = $this->getMatchedCode($parameter, $kind);
            if (null !== $code) {
                return ['kind' => $kind, 'code' => $code];
            }
        }

        return null;
    }
}
