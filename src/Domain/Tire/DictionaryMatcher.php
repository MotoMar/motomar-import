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
     * @param \PDO $pdo active database connection
     */
    public function __construct(\PDO $pdo)
    {
        $stmt = $pdo->query('SELECT kind, code FROM tires_dictionary');
        if (false === $stmt) {
            throw new \RuntimeException('Failed to query tires_dictionary table.');
        }

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $kind = (string) $row['kind'];
            $code = (string) $row['code'];
            $this->codesByKind[$kind][] = $code;
        }
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
     * Return all loaded dictionary kinds.
     *
     * @return string[]
     */
    private function getKinds(): array
    {
        return array_keys($this->codesByKind);
    }

    /**
     * Check whether a parameter value matches any code within the given
     * dictionary kind. Comparison is case-insensitive and whitespace-trimmed.
     *
     * @param string $parameter the tire parameter value to test
     * @param string $kind      the dictionary kind to match against
     *
     * @return bool true when the parameter matches a code in the kind
     */
    private function matchParameterToKind(string $parameter, string $kind): bool
    {
        $codes = $this->getCodesForKind($kind);
        $normalised = mb_strtolower(trim($parameter), 'UTF-8');

        foreach ($codes as $code) {
            if ($normalised === mb_strtolower(trim($code), 'UTF-8')) {
                return true;
            }
        }

        return false;
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
