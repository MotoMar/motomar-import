<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Generates tire product names by assembling structural components and suffixes.
 *
 * Name schema: Producer Tread Size[C|CP] LI_SI [suffixes...]
 *
 * Key details:
 * - C and CP reinforcement values are embedded before LI/SI (part of the size block)
 * - All other reinforcements (XL, RF) appear after LI/SI as suffixes
 * - LI/SI formatting is delegated to LiSiFormatter
 * - Suffixes are extracted per vehicle type by SuffixExtractor from pre-classified parameters
 *
 * Data flow:
 *   tires.other → TireParametersBuilder → tires_classified_parameters (JSON)
 *   tires_classified_parameters → SuffixExtractor → ordered suffixes → this class → product name
 *
 * This class does NOT touch raw parameter columns (ex_*, all_markers, bridge table).
 * All classification is done upstream. This class only formats.
 */
class NameGenerator
{
    private SuffixExtractor $suffixExtractor;

    /**
     * Reinforcement values that are placed before LI/SI (embedded in size block).
     */
    private const SIZE_EMBEDDED_REINFORCEMENTS = ['C', 'CP'];

    /**
     * Mapping from Propel integer index → string for the `reinforcement` ENUM.
     *
     * Schema: ENUM('C','CP','RF','XL') — stored as tinyint unsigned 1–4.
     * Used only as a last-resort fallback when classified parameters are empty
     * and the integer column is the only source available.
     */
    private const REINFORCEMENT_INT_MAP = [
        1 => 'C',
        2 => 'CP',
        3 => 'RF',
        4 => 'XL',
    ];

    /**
     * @param SuffixExtractor $suffixExtractor Extractor for vehicle-type-specific suffixes
     */
    public function __construct(SuffixExtractor $suffixExtractor)
    {
        $this->suffixExtractor = $suffixExtractor;
    }

    /**
     * Generate the full product name for a tire.
     *
     * @param array<string, mixed>    $tireRow              Tire data row from TireDataFetcher::fetchTires().
     *                                                      Must contain: producer, tread, tire_size, tire_li, tire_si,
     *                                                      id_vehicles_type. May contain: reinforcement (int fallback).
     * @param array<string, string[]> $classifiedParameters Decoded classified parameters from
     *                                                      TireParametersBuilder / tires_classified_parameters JSON.
     *                                                      Keyed by dictionary kind, values are arrays of code strings.
     *                                                      Example: ['reinforcement' => ['XL'], 'runflat' => ['Run Flat']]
     *
     * @return string The generated product name
     */
    public function generate(array $tireRow, array $classifiedParameters = []): string
    {
        $parts = [];

        // 1. Producer
        $producer = trim((string) ($tireRow['producer'] ?? ''));
        if ('' !== $producer) {
            $parts[] = $producer;
        }

        // 2. Tread (model name)
        $tread = trim((string) ($tireRow['tread'] ?? ''));
        if ('' !== $tread) {
            $parts[] = $tread;
        }

        // 3. Size + C/CP reinforcement (appended directly without space, e.g. "195/70R15C")
        $size = trim((string) ($tireRow['tire_size'] ?? ''));
        $reinforcement = $this->resolveReinforcement($tireRow, $classifiedParameters);

        if ('' !== $size) {
            $parts[] = \in_array($reinforcement, self::SIZE_EMBEDDED_REINFORCEMENTS, true)
                ? "{$size}{$reinforcement}"
                : $size;
        }

        // 4. LI/SI formatted
        $li = trim((string) ($tireRow['tire_li'] ?? ''));
        $si = trim((string) ($tireRow['tire_si'] ?? ''));
        $lisi = LiSiFormatter::format($li, $si);
        if ('' !== $lisi) {
            $parts[] = $lisi;
        }

        // 5. Suffixes (vehicle-type-specific, from pre-classified parameters)
        $vehicleType = (int) ($tireRow['id_vehicles_type'] ?? 0);
        $suffixes = $this->suffixExtractor->extractSuffixes($vehicleType, $classifiedParameters);
        foreach ($suffixes as $suffix) {
            $suffix = trim((string) $suffix);
            if ('' !== $suffix) {
                $parts[] = $suffix;
            }
        }

        // Assemble with single spaces, collapse any multiple spaces
        $name = implode(' ', $parts);
        $name = preg_replace('/\s{2,}/', ' ', $name);

        return trim($name);
    }

    /**
     * Generate name and slug pair for a tire.
     *
     * @param array<string, mixed>    $tireRow              Tire data row from TireDataFetcher
     * @param array<string, string[]> $classifiedParameters Decoded classified parameters
     *
     * @return array{name: string, slug: string} Associative array with 'name' and 'slug' keys
     */
    public function generateWithSlug(array $tireRow, array $classifiedParameters = []): array
    {
        $name = $this->generate($tireRow, $classifiedParameters);
        $slug = SlugGenerator::generate($name);

        return [
            'name' => $name,
            'slug' => "{$slug}-{$tireRow['tire_id']}",
        ];
    }

    /**
     * Generate names for a batch of tires.
     *
     * @param array[]                             $tireRows            Array of tire data rows
     * @param array<int, array<string, string[]>> $allClassifiedParams Classified parameters keyed by tire_id
     *
     * @return array[] Array of ['id' => int, 'name' => string, 'slug' => string]
     */
    public function generateBatch(array $tireRows, array $allClassifiedParams = []): array
    {
        $results = [];

        foreach ($tireRows as $tireRow) {
            $tireId = (int) ($tireRow['tire_id'] ?? 0);
            $classifiedParameters = $allClassifiedParams[$tireId] ?? [];

            $nameAndSlug = $this->generateWithSlug($tireRow, $classifiedParameters);

            $results[] = [
                'id'   => $tireId,
                'name' => $nameAndSlug['name'],
                'slug' => $nameAndSlug['slug'],
            ];
        }

        return $results;
    }

    /**
     * Resolve the reinforcement value for size-block embedding (C/CP).
     *
     * Primary source: classified parameters JSON ('reinforcement' kind).
     * Fallback: the integer `reinforcement` column from the tire row,
     * mapped via REINFORCEMENT_INT_MAP. This fallback exists only for
     * robustness during the transition period and should eventually be
     * removed once all tires have classified parameters populated.
     *
     * @param array<string, mixed>    $tireRow              Tire data row
     * @param array<string, string[]> $classifiedParameters Classified parameters
     *
     * @return string The reinforcement code (e.g. "C", "XL") or empty string
     */
    private function resolveReinforcement(array $tireRow, array $classifiedParameters): string
    {
        // 1. Primary: classified parameters
        $reinforcements = $classifiedParameters['reinforcement'] ?? [];

        if (!empty($reinforcements)) {
            return $this->pickStrongestReinforcement($reinforcements);
        }

        // 2. Fallback: integer reinforcement column (Propel ENUM index)
        $raw = $tireRow['reinforcement'] ?? null;

        if (null === $raw || '' === (string) $raw) {
            return '';
        }

        $intVal = (int) $raw;

        return self::REINFORCEMENT_INT_MAP[$intVal] ?? '';
    }

    /**
     * Pick the strongest reinforcement code from a list.
     *
     * Priority: C < CP < RF < XL (higher index wins).
     *
     * When only a single value is present it is returned as-is.
     * When multiple values are present (e.g. import produced "C, XL"),
     * the strongest wins.
     *
     * @param string[] $codes Reinforcement code strings
     *
     * @return string The resolved single reinforcement code
     */
    private function pickStrongestReinforcement(array $codes): string
    {
        if (1 === \count($codes)) {
            return trim($codes[0]);
        }

        $priority = array_flip(self::REINFORCEMENT_INT_MAP);
        $best = null;
        $bestPriority = -1;

        foreach ($codes as $code) {
            $code = trim($code);

            if ('' === $code) {
                continue;
            }

            $p = $priority[$code] ?? -1;

            if ($p > $bestPriority) {
                $best = $code;
                $bestPriority = $p;
            }
        }

        return $best ?? trim($codes[0]);
    }
}
