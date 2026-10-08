<?php

declare(strict_types=1);

use App\Domain\Import\ImportSession;

describe('ImportSession', function (): void {
    it('keeps every step file the import controllers read and write', function (string $key): void {
        // ImportSession loguje każdy zapis przez error_log(); w testach to szum na stderr.
        $previousLog = ini_set('error_log', sys_get_temp_dir() . '/import-session-test.log');

        try {
            $session = new ImportSession(sys_get_temp_dir() . '/import-session-' . bin2hex(random_bytes(4)));
            $uuid = $session->start();

            $session->write($uuid, $key, [['tread_id' => 8847, 'new' => 'Fixclime SP-401']]);

            expect($session->readArray($uuid, $key))->toBe(['0' => ['tread_id' => 8847, 'new' => 'Fixclime SP-401']]);

            $session->reset();
        } finally {
            ini_set('error_log', is_string($previousLog) ? $previousLog : '');
        }
    })->with(['models', 'mapping', 'tread_renames', 'result']);

    it('refuses a key outside the list, which would otherwise be a path', function (): void {
        $session = new ImportSession(sys_get_temp_dir());

        $session->write('00000000-0000-4000-8000-000000000000', '../../etc/passwd', []);
    })->throws(InvalidArgumentException::class);
});
