<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Fetches tire data from the database using raw PDO queries.
 *
 * This class is completely independent from the application's ORM layer.
 * It provides methods to retrieve tire records with all joined information
 * needed for product name generation.
 *
 * Size, LI and SI are resolved from their canonical lookup tables
 * (tires_width, tires_profile, tires_construction, tires_li, tires_si)
 * rather than from the denormalized tires.tire_size / tire_li / tire_si
 * columns which may be empty for newly-imported tires.
 *
 * Classified parameters are fetched via a LEFT JOIN to
 * tires_classified_parameters, which is the single source of truth for
 * tire parameter classification. The raw JSON is included in the result
 * set as `classified_parameters_json` for callers to decode.
 *
 * Legacy ex_* columns (ex_reinforcement, ex_run_flat, ex_rim_protector,
 * ex_approval, ex_seal, ex_silent) are NOT selected — all classification
 * data comes from tires_classified_parameters. The columns `ex_other`,
 * `other`, `all_markers`, and `name_markers` are retained because they
 * serve purposes outside of name generation (display, import, etc.).
 */
class TireDataFetcher
{
    private \PDO $pdo;

    /**
     * Base SELECT columns used across tire queries.
     *
     * Size is reconstructed from the canonical lookup tables:
     *   width [/profile] construction diameter  e.g. "205/55 R 16" or "185 R 14"
     *
     * LI and SI come from tires_li.code and tires_si.code respectively.
     *
     * Classified parameters JSON comes from tires_classified_parameters
     * via a LEFT JOIN (nullable — tires without classification get NULL).
     */
    private const BASE_SELECT = '
        t.id AS tire_id,
        pp.producer,
        tt.tread,
        CONCAT(
            tw.width,
            IF(tpr.profile IS NOT NULL AND tpr.profile != \'\', CONCAT(\'/\', tpr.profile), \'\'),
            \' \',
            tc.construction,
            \' \',
            t.tire_diameter
        ) AS tire_size,
        tl.code AS tire_li,
        ts.code AS tire_si,
        t.id_vehicles_type,
        CAST(t.reinforcement AS CHAR) AS reinforcement,
        t.other,
        t.ex_other,
        t.name_markers,
        t.all_markers,
        p.name AS current_name,
        p.better_slug AS current_slug,
        tcp.parameters AS classified_parameters_json
    ';

    /**
     * Base FROM/JOIN clause used across tire queries.
     *
     * Joins all lookup tables needed to reconstruct size, LI, SI,
     * producer name, tread name, and classified parameters.
     *
     * Uses LEFT JOIN for tires_classified_parameters because not every
     * tire may have been classified yet (e.g. newly imported tires
     * before the populate task runs).
     */
    private const BASE_FROM = '
        FROM tires t
        JOIN products p ON p.id = t.id
        JOIN products_producers pp ON pp.id = t.id_product_producer
        JOIN tires_treads tt ON tt.id = t.id_tires_tread
        JOIN tires_width tw ON tw.id = t.id_tires_width
        JOIN tires_profile tpr ON tpr.id = t.id_tires_profile
        JOIN tires_construction tc ON tc.id = t.id_tires_construction
        JOIN tires_li tl ON tl.id = t.id_tires_li
        JOIN tires_si ts ON ts.id = t.id_tires_si
        LEFT JOIN tires_classified_parameters tcp ON tcp.id_tire = t.id
    ';

    /**
     * @param \PDO $pdo active PDO connection instance
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Fetch base tire data with all joined info.
     *
     * Returns an array of associative arrays, each containing:
     * - tire_id (int)                        — tires.id / products.id
     * - producer (string)                    — producer name from products_producers
     * - tread (string)                       — tread name from tires_treads
     * - tire_size (string)                   — reconstructed from width/profile/construction lookup tables + diameter
     * - tire_li (string)                     — load index code from tires_li, e.g. "91" or "121/118"
     * - tire_si (string)                     — speed index code from tires_si, e.g. "H" or "R/Q"
     * - id_vehicles_type (int)               — vehicle type ID (1-10)
     * - reinforcement (string)               — CAST of ENUM value as CHAR (integer string or empty)
     * - other (string)                       — semicolon-separated raw parameter tokens
     * - ex_other (string)                    — comma-separated unclassified tokens (legacy, kept for display)
     * - name_markers (string)                — aggregated markers formatted for name
     * - all_markers (string)                 — all markers (comma+space separated)
     * - current_name (string)                — current products.name
     * - current_slug (string)                — current products.better_slug
     * - classified_parameters_json (?string) — JSON from tires_classified_parameters, or NULL
     *
     * @param null|int $vehicleTypeId Filter by vehicle type (1-10). Null = all.
     * @param null|int $limit         maximum number of rows to return
     * @param null|int $offset        row offset for pagination
     *
     * @return array<int, array<string, mixed>> array of tire data rows
     */
    public function fetchTires(?int $vehicleTypeId = null, ?int $limit = null, ?int $offset = null): array
    {
        $sql = 'SELECT '.self::BASE_SELECT.self::BASE_FROM;

        $params = [];
        $where = [];

        if (null !== $vehicleTypeId) {
            $where[] = 't.id_vehicles_type = :vtype';
            $params[':vtype'] = $vehicleTypeId;
        }

        if (!empty($where)) {
            $sql .= ' WHERE '.implode(' AND ', $where);
        }

        $sql .= ' ORDER BY t.id';

        if (null !== $limit) {
            $sql .= ' LIMIT :limit';
            if (null !== $offset) {
                $sql .= ' OFFSET :offset';
            }
        }

        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_INT);
        }

        if (null !== $limit) {
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            if (null !== $offset) {
                $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            }
        }

        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single tire by its ID.
     *
     * @param int $tireId the tire/product ID to look up
     *
     * @return null|array<string, mixed> tire data row or null if not found
     */
    public function fetchTireById(int $tireId): ?array
    {
        $sql = 'SELECT '.self::BASE_SELECT.self::BASE_FROM.' WHERE t.id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $tireId, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return false !== $row ? $row : null;
    }

    /**
     * Fetch multiple tires by their IDs.
     *
     * @param int[] $tireIds array of tire/product IDs
     *
     * @return array<int, array<string, mixed>> array of tire data rows indexed by tire_id
     */
    public function fetchTiresByIds(array $tireIds): array
    {
        if (empty($tireIds)) {
            return [];
        }

        $tireIds = array_values(array_unique(array_map('intval', $tireIds)));

        $placeholders = implode(',', array_fill(0, count($tireIds), '?'));
        $sql = 'SELECT '.self::BASE_SELECT.self::BASE_FROM
             ." WHERE t.id IN ({$placeholders}) ORDER BY t.id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($tireIds);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[(int) $row['tire_id']] = $row;
        }

        return $result;
    }

    /**
     * Decode the classified parameters JSON from a tire data row.
     *
     * Convenience method that extracts and decodes the `classified_parameters_json`
     * field from a tire row returned by any fetch method. Returns an empty array
     * when the field is NULL (no classification exists) or contains invalid JSON.
     *
     * @param array<string, mixed> $tireRow Tire data row from any fetch method
     *
     * @return array<string, string[]> Decoded classified parameters keyed by kind
     */
    public static function decodeClassifiedParameters(array $tireRow): array
    {
        return TireParametersBuilder::fromJson($tireRow['classified_parameters_json'] ?? null);
    }

    /**
     * Count tires grouped by vehicle type.
     *
     * Useful for reporting and progress tracking during batch operations.
     *
     * @return array<int, int> associative array of vehicle_type_id => count
     */
    public function countByVehicleType(): array
    {
        $sql = '
            SELECT id_vehicles_type, COUNT(*) AS cnt
            FROM tires
            GROUP BY id_vehicles_type
            ORDER BY id_vehicles_type
        ';

        $stmt = $this->pdo->query($sql);

        $result = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $result[(int) $row['id_vehicles_type']] = (int) $row['cnt'];
        }

        return $result;
    }

    /**
     * Count total tires, optionally filtered by vehicle type.
     *
     * @param null|int $vehicleTypeId Filter by vehicle type. Null = all.
     *
     * @return int total count
     */
    public function countTires(?int $vehicleTypeId = null): int
    {
        $sql = 'SELECT COUNT(*) AS cnt FROM tires';

        $params = [];
        if (null !== $vehicleTypeId) {
            $sql .= ' WHERE id_vehicles_type = :vtype';
            $params[':vtype'] = $vehicleTypeId;
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_INT);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
