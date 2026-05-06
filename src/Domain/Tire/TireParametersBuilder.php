<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Builds a classified, normalized parameter array for a tire.
 *
 * Takes a tire data row (from TireDataFetcher) and a DictionaryMatcher,
 * parses tokens from the unified `tires.other` column (semicolon-separated),
 * matches each token against the dictionary, and returns an associative array
 * keyed by tires_dictionary kind with arrays of matched codes as values.
 *
 * The `other` column is the single source of truth after the unification
 * script merged tokens from all legacy sources (ex_* columns, all_markers,
 * and the tires_tires_parameters bridge table) into it.
 *
 * Keys with no data are omitted — no empty arrays, no nulls.
 * The result is stored as JSON in the `tires_classified_parameters` table
 * (1:1 with `tires`, keyed by `id_tire`).
 *
 * This class centralises the classification logic so it can be reused by both
 * the PopulateTiresParametersTask (backfill) and the import flow.
 *
 * Example output:
 *   [
 *     'reinforcement' => ['XL'],
 *     'homologation'  => ['MO'],
 *     'rim_protector'  => ['FR'],
 *     'seal'          => ['ContiSeal'],
 *     'season'        => ['M+S', '3PMSF'],
 *     'ev'            => ['EV'],
 *   ]
 *
 * A tire with no special markers returns an empty array: []
 */
class TireParametersBuilder
{
    /**
     * Build the parameters array for a single tire.
     *
     * Parses tokens from `tires.other` (`;` separated), matches each token
     * against the dictionary for every kind applicable to the tire's vehicle
     * type, and returns the classified result.
     *
     * @param array<string, mixed> $tireRow Tire data row from TireDataFetcher (must include `other` and `id_vehicles_type`)
     * @param DictionaryMatcher    $matcher Dictionary matcher instance
     *
     * @return array<string, string[]> Classified parameters keyed by kind, only non-empty entries
     */
    public function buildParameters(array $tireRow, DictionaryMatcher $matcher): array
    {
        $vehicleType = (int) ($tireRow['id_vehicles_type'] ?? 0);
        $order = VehicleTypeClassificationOrder::forVehicleType($vehicleType);

        if (empty($order)) {
            return [];
        }
        $result = $this->initKindBuckets($order);

        $tokens = $this->parseOtherColumn($tireRow);

        foreach ($tokens as $token) {
            $match = $matcher->matchParameterToFirstKind($token, $order);

            if (null !== $match) {
                $result[$match['kind']][] = $match['code'];
            }
        }

        $result = $this->normalizeResult($result);

        return $this->cleanResult($result);
    }


    /**
     * Parse the `other` column into individual trimmed tokens.
     *
     * The `other` column uses `;` as separator. Both `XL;FSL` (old format)
     * and `XL; FP; M+S` (new format with spaces) are handled by trimming
     * each token after splitting.
     *
     * @param array<string, mixed> $tireRow Tire data row
     *
     * @return string[] Individual trimmed, non-empty tokens
     */
    private function parseOtherColumn(array $tireRow): array
    {
        $raw = trim((string) ($tireRow['other'] ?? ''));

        if ('' === $raw) {
            return [];
        }

        $tokens = explode(';', $raw);
        $cleaned = [];

        foreach ($tokens as $token) {
            $token = trim($token);

            if ('' !== $token) {
                $cleaned[] = $token;
            }
        }

        return $cleaned;
    }

    /**
     * Apply normalization rules to the result.
     *
     * - reinforcement: pick the strongest from multiple values
     * - tube_type: normalize TT/TL → TL/TT; merge separate TL + TT → TL/TT
     *
     * @param array<string, string[]> $result Parameters keyed by kind
     *
     * @return array<string, string[]> Normalized parameters
     */
    private function normalizeResult(array $result): array
    {
        if (isset($result['reinforcement']) && \count($result['reinforcement']) > 1) {
            $result['reinforcement'] = [ReinforcementHelper::pickStrongest($result['reinforcement'])];
        }

        if (isset($result['tube_type']) && !empty($result['tube_type'])) {
            $result['tube_type'] = $this->normalizeTubeType($result['tube_type']);
        }

        return $result;
    }

    /**
     * Normalize tube_type values.
     *
     * - TT/TL → TL/TT (canonical order)
     * - If both TL and TT exist as separate entries → merge to TL/TT
     *
     * @param string[] $values Tube type values
     *
     * @return string[] Normalized tube type values
     */
    private function normalizeTubeType(array $values): array
    {
        $values = array_map(
            static fn (string $val): string => ('TT/TL' === $val) ? 'TL/TT' : $val,
            $values
        );

        $hasTL = \in_array('TL', $values, true);
        $hasTT = \in_array('TT', $values, true);

        if ($hasTL && $hasTT) {
            $values = array_filter(
                $values,
                static fn (string $val): bool => 'TL' !== $val && 'TT' !== $val
            );
            $values[] = 'TL/TT';
        }

        return array_values(array_unique($values));
    }

    /**
     * Clean the result: deduplicate values, remove empty entries.
     *
     * @param array<string, string[]> $result Parameters keyed by kind
     *
     * @return array<string, string[]> Cleaned parameters (only non-empty kinds)
     */
    private function cleanResult(array $result): array
    {
        $cleaned = [];

        foreach ($result as $kind => $values) {
            $values = array_values(array_unique(
                array_filter(
                    array_map('trim', $values),
                    static fn (string $v): bool => '' !== $v
                )
            ));

            if (!empty($values)) {
                $cleaned[$kind] = $values;
            }
        }

        return $cleaned;
    }

    /**
     * Initialise empty buckets for each kind in the classification order.
     *
     * @param string[] $order Ordered list of dictionary kinds (from VehicleTypeClassificationOrder)
     *
     * @return array<string, string[]> Empty buckets keyed by kind
     */
    private function initKindBuckets(array $order): array
    {
        $buckets = [];

        foreach ($order as $kind) {
            $buckets[$kind] = [];
        }

        return $buckets;
    }



    /**
     * Upsert classified parameters for a single tire into tires_classified_parameters.
     *
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
     *
     * @param \PDO                    $pdo        Active PDO connection
     * @param int                     $tireId     The tire ID (tires.id)
     * @param array<string, string[]> $parameters Parameters from buildParameters()
     */
    public static function upsert(\PDO $pdo, int $tireId, array $parameters): void
    {
        $json = self::toJson($parameters);

        $stmt = $pdo->prepare('
            INSERT INTO tires_classified_parameters (id_tire, parameters)
            VALUES (:id, :json)
            ON DUPLICATE KEY UPDATE parameters = VALUES(parameters)
        ');
        $stmt->execute([
            ':id'   => $tireId,
            ':json' => $json,
        ]);
    }

    /**
     * Upsert classified parameters for multiple tires in a single transaction.
     *
     * @param \PDO                                $pdo          Active PDO connection
     * @param array<int, array<string, string[]>> $paramsByTire Parameters keyed by tire_id
     */
    public static function upsertBatch(\PDO $pdo, array $paramsByTire): void
    {
        if (empty($paramsByTire)) {
            return;
        }

        $stmt = $pdo->prepare('
            INSERT INTO tires_classified_parameters (id_tire, parameters)
            VALUES (:id, :json)
            ON DUPLICATE KEY UPDATE parameters = VALUES(parameters)
        ');

        $pdo->beginTransaction();

        try {
            foreach ($paramsByTire as $tireId => $parameters) {
                $stmt->execute([
                    ':id'   => $tireId,
                    ':json' => self::toJson($parameters),
                ]);
            }
            $pdo->commit();
        } catch (\Exception $e) {
            $pdo->rollBack();

            throw $e;
        }
    }

    /**
     * Encode parameters to a JSON string suitable for database storage.
     *
     * @param array<string, string[]> $parameters Parameters from buildParameters()
     *
     * @return string JSON-encoded string — '{}' for empty, or a populated JSON object
     */
    public static function toJson(array $parameters): string
    {
        if (empty($parameters)) {
            return '{}';
        }

        $encoded = json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (false === $encoded) {
            return '{}';
        }

        return $encoded;
    }

    /**
     * Decode a JSON string from tires_classified_parameters back into a parameters array.
     *
     * @param null|string $json JSON string from the database, or null
     *
     * @return array<string, string[]> Decoded parameters, or empty array for null/empty
     */
    public static function fromJson(?string $json): array
    {
        if (null === $json || '' === $json) {
            return [];
        }

        $decoded = json_decode($json, true);

        if (!\is_array($decoded)) {
            return [];
        }

        return $decoded;
    }
}
