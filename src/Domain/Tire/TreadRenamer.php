<?php

declare(strict_types=1);

namespace App\Domain\Tire;

use App\Logger;

/**
 * Wykonuje zmiany nazw bieżników z kroku 2 i przelicza nazwy produktów
 * wszystkich opon tych bieżników.
 *
 * Import sam przelicza nazwy tylko opon z pliku; reszta opon bieżnika
 * zostałaby ze starą nazwą (tak zostało 39 opon Austone po ręcznej zmianie
 * na `Fixclime SP-401`). Obie części idą w transakcji importu.
 */
final class TreadRenamer
{
    private const BATCH_SIZE = 1000;

    public function __construct(
        private readonly TireRepository $repo,
        private readonly TireDataFetcher $fetcher,
        private readonly ProductNameRegenerator $regenerator,
        private readonly Logger $logger,
    ) {
    }

    /** @param list<TreadRename> $renames */
    public function rename(array $renames): void
    {
        foreach ($renames as $rename) {
            $this->repo->renameTread($rename->treadId, $rename->oldName, $rename->newName);
            $this->logger->info('Renamed tread', $rename->toArray());
        }
    }

    /**
     * @param list<TreadRename> $renames
     *
     * @return int ile produktów dostało nową nazwę albo `tire_model`
     */
    public function regenerateNames(array $renames): int
    {
        if ([] === $renames) {
            return 0;
        }

        $selection = TireSelection::treads(array_map(static fn (TreadRename $r): int => $r->treadId, $renames));
        $changed = 0;
        $afterId = 0;

        while ([] !== ($rows = $this->fetcher->fetchSelection($selection, $afterId, self::BATCH_SIZE))) {
            foreach ($rows as $row) {
                $change = $this->regenerator->change($row);
                $afterId = $change->tireId;

                if (!$change->isNoop()) {
                    $this->repo->applyNameChange($change);
                    ++$changed;
                }
            }
        }

        return $changed;
    }
}
