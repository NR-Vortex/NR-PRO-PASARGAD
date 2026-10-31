<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Logging;

use RuntimeException;

final class Logger
{
    public function __construct(
        private readonly string $filePath
    ) {
    }

    public function info(
        string $message,
        array $context = []
    ): void {
        $this->write('INFO', $message, $context);
    }

    public function warning(
        string $message,
        array $context = []
    ): void {
        $this->write('WARNING', $message, $context);
    }

    public function error(
        string $message,
        array $context = []
    ): void {
        $this->write('ERROR', $message, $context);
    }

    private function write(
        string $level,
        string $message,
        array $context
    ): void {
        $directory = dirname($this->filePath);

        if (!is_dir($directory)) {

            if (
                !mkdir($directory, 0770, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Unable to create log directory.'
                );
            }
        }

        $date = gmdate('Y-m-d H:i:s');

        $contextJson = '';

        if ($context !== []) {
            $contextJson = ' ' . json_encode(
                $context,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES |
                JSON_THROW_ON_ERROR
            );
        }

        $line = sprintf(
            "[%s UTC] [%s] %s%s%s",
            $date,
            $level,
            $message,
            $contextJson,
            PHP_EOL
        );

        $result = file_put_contents(
            $this->filePath,
            $line,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            throw new RuntimeException(
                'Unable to write application log.'
            );
        }
    }
}