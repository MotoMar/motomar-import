<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Transakcja importu przepadła — nic zapisanego od tej chwili nie dałoby się
 * wycofać. Zatrzymuje cały import; nigdy nie jest obsługiwane jak błąd wiersza.
 */
final class ImportAborted extends \RuntimeException
{
    public static function after(\Throwable $cause): self
    {
        return new self('Transakcja importu przepadła: ' . $cause->getMessage(), 0, $cause);
    }

    public static function noTransaction(): self
    {
        return new self('Transakcja importu przepadła: baza nie ma już otwartej transakcji.');
    }
}
