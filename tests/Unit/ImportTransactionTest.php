<?php

declare(strict_types=1);

use App\Domain\Import\ImportAborted;
use App\Domain\Import\ImportTransaction;

/**
 * SQLite zachowuje się tu jak MySQL tam, gdzie to ważne: rollback spoza PDO
 * zamyka transakcję (`inTransaction()` = false), a savepoint po nim nie istnieje.
 */
function importDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE products (id INTEGER PRIMARY KEY, ean TEXT UNIQUE)');
    $pdo->exec('CREATE TABLE tires (id INTEGER PRIMARY KEY)');

    return $pdo;
}

/** @return list<string> */
function eans(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT ean FROM products ORDER BY id');

    return false === $stmt ? [] : array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

/** A PDOException as pdo_mysql raises it on a deadlock. */
function deadlock(): PDOException
{
    return new class () extends PDOException {
        public function __construct()
        {
            parent::__construct('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock');
            $this->code = '40001';
            $this->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock'];
        }
    };
}

function insertProduct(PDO $pdo, string $ean): void
{
    $pdo->prepare('INSERT INTO products (ean) VALUES (?)')->execute([$ean]);
}

/** Runs one row the way ImportProcessor::run() does: a failed row is recorded, an abort is not. */
function importRow(ImportTransaction $tx, callable $work): ?Throwable
{
    try {
        $tx->savepoint('import_row', $work);

        return null;
    } catch (ImportAborted $e) {
        throw $e;
    } catch (Throwable $e) {
        return $e;
    }
}

describe('ImportTransaction', function (): void {
    it('keeps the rows around a failed one and nothing of the failed one', function (): void {
        $pdo = importDb();
        $tx = new ImportTransaction($pdo);
        $pdo->beginTransaction();

        importRow($tx, fn () => insertProduct($pdo, '1'));
        $failed = importRow($tx, function () use ($pdo): void {
            insertProduct($pdo, '2');
            $pdo->exec('INSERT INTO tires (id) VALUES (NULL, 1)');
        });
        importRow($tx, fn () => insertProduct($pdo, '3'));
        $pdo->commit();

        expect($failed)->toBeInstanceOf(PDOException::class)
            ->and(eans($pdo))->toBe(['1', '3']);
    });

    it('lets an inner savepoint undo only its own part of the row', function (): void {
        $pdo = importDb();
        $tx = new ImportTransaction($pdo);
        $pdo->beginTransaction();
        insertProduct($pdo, 'taken');

        importRow($tx, function () use ($pdo, $tx): void {
            try {
                $tx->savepoint('import_create', function () use ($pdo): void {
                    insertProduct($pdo, 'orphan');
                    insertProduct($pdo, 'taken');
                });
            } catch (PDOException) {
                insertProduct($pdo, 'fallback');
            }
        });
        $pdo->commit();

        expect(eans($pdo))->toBe(['taken', 'fallback']);
    });

    it('aborts on a deadlock instead of recording it as a failed row', function (): void {
        $pdo = importDb();
        $tx = new ImportTransaction($pdo);
        $pdo->beginTransaction();

        importRow($tx, function () use ($pdo): void {
            insertProduct($pdo, '1');
            throw deadlock();
        });
    })->throws(ImportAborted::class, '1213 Deadlock');

    it('aborts when a row swallowed the error that ended the transaction', function (): void {
        $pdo = importDb();
        $tx = new ImportTransaction($pdo);
        $pdo->beginTransaction();
        insertProduct($pdo, 'before');

        $aborted = null;

        try {
            importRow($tx, function () use ($pdo): void {
                // MySQL po zakleszczeniu: wszystko wycofane, kod łapie błąd i jedzie dalej.
                $pdo->exec('ROLLBACK');
                insertProduct($pdo, 'after');
            });
        } catch (ImportAborted $e) {
            $aborted = $e;
        }

        // Że `after` też przepada, zapewnia `autocommit = 0` w run() — tego SQLite
        // nie ma, sprawdzone na MySQL ręcznie (opis PR-a).
        expect($aborted)->toBeInstanceOf(ImportAborted::class)
            ->and($pdo->inTransaction())->toBeFalse();
    });

    it('commits what the work wrote', function (): void {
        $pdo = importDb();

        $result = (new ImportTransaction($pdo))->run(function () use ($pdo): string {
            insertProduct($pdo, '1');

            return 'done';
        });

        expect($result)->toBe('done')
            ->and($pdo->inTransaction())->toBeFalse()
            ->and(eans($pdo))->toBe(['1']);
    });

    it('rolls back every row, the good ones too, when the import aborts', function (): void {
        $pdo = importDb();
        $tx = new ImportTransaction($pdo);
        $aborted = null;

        try {
            $tx->run(function () use ($pdo, $tx): void {
                importRow($tx, fn () => insertProduct($pdo, '1'));
                importRow($tx, function () use ($pdo): void {
                    insertProduct($pdo, 'x');
                    throw new RuntimeException('bad row');
                });
                importRow($tx, function (): void {
                    throw deadlock();
                });
                importRow($tx, fn () => insertProduct($pdo, 'never'));
            });
        } catch (ImportAborted $e) {
            $aborted = $e;
        }

        expect($aborted)->toBeInstanceOf(ImportAborted::class)
            ->and($pdo->inTransaction())->toBeFalse()
            ->and(eans($pdo))->toBe([]);
    });

    it('refuses to start a row outside a transaction', function (): void {
        importRow(new ImportTransaction(importDb()), fn () => null);
    })->throws(ImportAborted::class);

    it('hands back to swallowing code a deadlock', function (): void {
        ImportTransaction::rethrowIfLost(deadlock());
    })->throws(ImportAborted::class);

    it('leaves swallowing code its ordinary errors, in a transaction or not', function (): void {
        ImportTransaction::rethrowIfLost(new RuntimeException('name too long'));
        ImportTransaction::rethrowIfLost(new PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry'));

        expect(true)->toBeTrue();
    });

    it('aborts a row that failed after the transaction disappeared', function (): void {
        $pdo = importDb();
        $tx = new ImportTransaction($pdo);
        $pdo->beginTransaction();

        importRow($tx, function () use ($pdo): void {
            $pdo->exec('ROLLBACK');
            throw new RuntimeException('anything');
        });
    })->throws(ImportAborted::class);
});
