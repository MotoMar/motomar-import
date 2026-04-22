<?php

declare(strict_types=1);

namespace App;

final class Logger
{
    public function __construct(private readonly string $logDir) {}

    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $ctx  = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = sprintf(
            "[%s] tire-import.%s: %s%s\n",
            date('Y-m-d\TH:i:sP'),
            $level,
            $message,
            $ctx
        );

        file_put_contents(
            $this->logDir . '/app-' . date('Y-m-d') . '.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }
}
