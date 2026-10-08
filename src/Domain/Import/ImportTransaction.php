<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Trzyma cały import w jednej transakcji, wiersz po wierszu.
 *
 * Każdy wiersz idzie pod savepointem, więc wiersz, który padnie w połowie, nie
 * zostawia po sobie nic — ani `products`, którego `tires` się nie wstawiło.
 * Błędy kończące całą transakcję to co innego: po zakleszczeniu MySQL sam już
 * wszystko wycofał, a każde następne zapytanie zapisałoby się od razu
 * (autocommit). Te zamieniamy na ImportAborted, którego nie wolno połknąć.
 *
 * Wykrycie nie zależy od złapania pierwotnego błędu, bo część kodu loguje
 * i jedzie dalej — dlatego po każdym wierszu savepoint musi nadal istnieć.
 */
final class ImportTransaction
{
    /**
     * Kody MySQL, po których nie ma już transakcji: zakleszczenie i zerwane
     * połączenie (razem z tym, co trzymało).
     */
    private const LOST_DRIVER_CODES = [1213, 2006, 2013];

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Otwiera transakcję, wykonuje $work i zatwierdza; każdy błąd wycofuje całość.
     *
     * Na czas importu sesja MySQL ma `autocommit = 0`. Bez tego zapytania, które
     * jakiś kod wykona po połkniętym zakleszczeniu, zapisałyby się od razu
     * i żaden rollback już by ich nie cofnął (sprawdzone na motomar_dev
     * prawdziwym zakleszczeniem); z nim lądują w nowej, niejawnej transakcji,
     * którą rollback poniżej wyrzuca.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        $mysql = 'mysql' === $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($mysql) {
            $this->pdo->exec('SET autocommit = 0');
        }

        try {
            $this->pdo->beginTransaction();

            try {
                $result = $work();
                $this->assertActive();
                $this->pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                $this->quietly('ROLLBACK');

                throw $e;
            }
        } finally {
            if ($mysql) {
                $this->quietly('SET autocommit = 1');
            }
        }
    }

    /**
     * Wykonuje $work pod savepointem. Błąd w środku cofa do savepointu i leci
     * dalej bez zmian; utracona transakcja leci jako ImportAborted.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function savepoint(string $name, callable $work): mixed
    {
        if (1 !== preg_match('/^[a-z_]+$/', $name)) {
            throw new \InvalidArgumentException("Invalid savepoint name: {$name}");
        }

        $this->assertActive();
        $this->exec("SAVEPOINT {$name}");

        try {
            $result = $work();
        } catch (ImportAborted $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (self::endsTransaction($e) || !$this->pdo->inTransaction()) {
                throw ImportAborted::after($e);
            }

            $this->exec("ROLLBACK TO SAVEPOINT {$name}");

            throw $e;
        }

        $this->exec("RELEASE SAVEPOINT {$name}");
        $this->assertActive();

        return $result;
    }

    /**
     * Dla kodu, który celowo połyka swoje błędy: oddaje te, których połknąć
     * nie wolno.
     *
     * Rozpoznaje tylko po kodzie błędu. `inTransaction()` tuż po zakleszczeniu
     * wciąż zwraca true (sprawdzone na MySQL), a poza importem jego false nie
     * znaczy nic złego — braku transakcji pilnuje savepoint().
     */
    public static function rethrowIfLost(\Throwable $e): void
    {
        if ($e instanceof ImportAborted) {
            throw $e;
        }

        if (self::endsTransaction($e)) {
            throw ImportAborted::after($e);
        }
    }

    private static function endsTransaction(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }

        $driverCode = is_array($e->errorInfo) ? ($e->errorInfo[1] ?? null) : null;

        return '40001' === (string) $e->getCode()
            || in_array($driverCode, self::LOST_DRIVER_CODES, true);
    }

    private function assertActive(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw ImportAborted::noTransaction();
        }
    }

    /**
     * Sprzątanie po błędzie: przy zerwanym połączeniu samo też padnie, a wtedy
     * ważny jest błąd pierwotny, nie ten — serwer i tak wycofa transakcję
     * zamkniętego połączenia.
     */
    private function quietly(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException) {
        }
    }

    /**
     * Nieudane polecenie savepointu znaczy, że transakcji już nie ma — po
     * niejawnym rollbacku MySQL odpowiada 1305 „SAVEPOINT does not exist” —
     * więc to nigdy nie jest błąd wiersza.
     */
    private function exec(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw ImportAborted::after($e);
        }
    }
}
