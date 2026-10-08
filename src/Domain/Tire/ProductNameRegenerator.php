<?php

declare(strict_types=1);

namespace App\Domain\Tire;

/**
 * Computes a product's name the way the import does, and optionally the
 * classification it rests on — without writing anything.
 *
 * Reclassification is computed in memory rather than by writing first, so a
 * dry run shows the name the write would produce. Order matters for the same
 * reason the import cares about it: a name built before the classification is
 * refreshed is the old name with no signal that it is stale.
 */
final class ProductNameRegenerator
{
    public function __construct(
        private readonly NameGenerator $generator,
        private readonly ?DictionaryMatcher $matcher = null,
        private readonly TireParametersBuilder $builder = new TireParametersBuilder(),
    ) {
    }

    /**
     * @param array<string, mixed> $row one row from TireDataFetcher
     */
    public function change(array $row): NameChange
    {
        $stored = TireDataFetcher::decodeClassifiedParameters($row);
        $parameters = $stored;
        $reclassified = null;

        if (null !== $this->matcher) {
            $vehicleType = RowField::integer($row, 'id_vehicles_type');
            $fresh = $this->builder->buildParameters($row, $this->matcher);

            // Whatever the classifier cannot produce, it must not delete —
            // the same guard TireRepository::refreshClassifiedParameters keeps.
            $parameters = TireParametersBuilder::preserveUnclassifiableKinds(
                $fresh,
                $stored,
                VehicleTypeClassificationOrder::forVehicleType($vehicleType),
            );

            if (self::canonical($parameters) !== self::canonical($stored)) {
                $reclassified = $parameters;
            }
        }

        $generated = $this->generator->generateWithSlug($row, $parameters);

        return new NameChange(
            RowField::integer($row, 'tire_id'),
            RowField::text($row, 'current_name'),
            $generated['name'],
            RowField::text($row, 'current_slug'),
            $generated['slug'],
            RowField::text($row, 'current_model'),
            RowField::text($row, 'tread'),
            $parameters,
            $reclassified,
        );
    }

    /**
     * Classification compared regardless of key and value order.
     *
     * @param array<string, string[]> $parameters
     */
    private static function canonical(array $parameters): string
    {
        $parameters = array_filter($parameters, static fn (array $codes): bool => [] !== $codes);
        ksort($parameters);

        foreach ($parameters as &$codes) {
            sort($codes);
        }

        return (string) json_encode($parameters, JSON_UNESCAPED_UNICODE);
    }
}
