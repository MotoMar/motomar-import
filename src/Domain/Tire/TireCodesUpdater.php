<?php

declare(strict_types=1);

namespace App\Domain\Tire;

use App\Bootstrap;
use PDO;

final class TireCodesUpdater
{
    private const ZERO_VARIANT_PRODUCER_SLUGS = [
        'michelin',
        'kleber',
        'bfgoodrich',
        'kormoran',
        'pirelli',
        'continental',
        'barum',
        'uniroyal',
        'semperit',
        'gislaved',
        'point-s',
        'viking',
        'matador',
        'cultor',
        'mitas',
    ];

    private const BATCH_SIZE = 1000;

    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo ??= Bootstrap::pdo();
    }

    /**
     * Rebuild the legacy tires_codes lookup table from current tire codes.
     *
     * @return array{tires: int, codes: int}
     */
    public function rebuild(): array
    {
        $startedTransaction = false;

        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $this->pdo->exec('DELETE FROM tires_codes');

            $stmt = $this->pdo->query(
                'SELECT
                    t.id,
                    t.ref,
                    t.ref2,
                    t.ean,
                    pp.producer,
                    p.price_catalog_netto
                FROM tires t
                LEFT JOIN products_producers pp ON t.id_product_producer = pp.id
                LEFT JOIN products p ON t.id = p.id'
            );

            if ($stmt === false) {
                throw new \RuntimeException('Failed to read tires for tires_codes rebuild.');
            }

            $batch = [];
            $tireCount = 0;
            $codeCount = 0;

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                ++$tireCount;

                $producerSlug = TireRepository::slug((string) ($row['producer'] ?? ''));
                $price = (float) ($row['price_catalog_netto'] ?? 0);
                $codes = array_merge(
                    $this->codesForValue((string) ($row['ref'] ?? ''), $producerSlug),
                    $this->codesForValue((string) ($row['ref2'] ?? ''), $producerSlug),
                    $this->codesForValue((string) ($row['ean'] ?? ''), $producerSlug)
                );

                foreach ($codes as $code) {
                    $batch[] = [
                        'code' => $code,
                        'producer_slug' => $producerSlug,
                        'tire_id' => (int) $row['id'],
                        'price_catalog_netto' => $price,
                    ];
                    ++$codeCount;

                    if (count($batch) >= self::BATCH_SIZE) {
                        $this->insertBatch($batch);
                        $batch = [];
                    }
                }
            }

            if ($batch !== []) {
                $this->insertBatch($batch);
            }

            if ($startedTransaction) {
                $this->pdo->commit();
            }

            return ['tires' => $tireCount, 'codes' => $codeCount];
        } catch (\Throwable $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @return string[]
     */
    private function codesForValue(string $value, string $producerSlug): array
    {
        $code = mb_strtolower(trim($value), 'UTF-8');

        if ($code === '') {
            return [];
        }

        if (in_array($producerSlug, self::ZERO_VARIANT_PRODUCER_SLUGS, true)) {
            return self::zeroVariants($code);
        }

        return [$code];
    }

    /**
     * Mirrors the old TiresCodes::codes() zero-prefix/suffix variants.
     *
     * @return string[]
     */
    private static function zeroVariants(string $code): array
    {
        $codes = [];
        $place = 0;

        if ($code[0] === '0') {
            ++$place;
        }

        if (substr($code, -1) === '0') {
            $place += 2;
        }

        switch ($place) {
            case 0:
                $codes[] = $code;
                $codes[] = '0' . $code;
                break;

            case 1:
                $trimmed = ltrim($code, '0');
                $leadingZeroCount = strpos($code, $trimmed);

                for ($i = 0; $i <= $leadingZeroCount; ++$i) {
                    $codes[] = str_repeat('0', $i) . $trimmed;
                }
                break;

            case 2:
                $codes[] = $code;
                $codes[] = substr($code, 0, -1);
                $codes[] = '0' . $code;
                $codes[] = substr('0' . $code, 0, -1);
                break;

            case 3:
                $trimmed = ltrim($code, '0');
                $leadingZeroCount = strpos($code, $trimmed);

                for ($i = 0; $i <= $leadingZeroCount; ++$i) {
                    $zeros = str_repeat('0', $i);
                    $codes[] = $zeros . $trimmed;
                    $codes[] = $zeros . substr($trimmed, 0, -1);
                }
                break;
        }

        $codes[] = $code . '0';

        return array_values(array_filter(array_unique($codes), static fn(string $value): bool => $value !== ''));
    }

    /**
     * @param array<int, array{code: string, producer_slug: string, tire_id: int, price_catalog_netto: float}> $rows
     */
    private function insertBatch(array $rows): void
    {
        $placeholders = [];
        $params = [];

        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?)';
            $params[] = $row['code'];
            $params[] = $row['producer_slug'];
            $params[] = $row['tire_id'];
            $params[] = $row['price_catalog_netto'];
        }

        $sql = 'INSERT INTO tires_codes (code, producer_slug, tire_id, price_catalog_netto) VALUES '
            . implode(', ', $placeholders);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
