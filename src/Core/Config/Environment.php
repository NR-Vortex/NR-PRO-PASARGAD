<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Config;

use RuntimeException;

final class Environment
{
    private array $values = [];

    public function __construct(
        private readonly string $filePath
    ) {
    }

    public function load(): void
    {
        if (!is_file($this->filePath)) {
            return;
        }

        $lines = file(
            $this->filePath,
            FILE_IGNORE_NEW_LINES
        );

        if ($lines === false) {
            throw new RuntimeException(
                'Unable to read environment file.'
            );
        }

        foreach ($lines as $index => $line) {

            // Remove UTF-8 BOM if present.
            if ($index === 0) {
                $line = preg_replace(
                    '/^\xEF\xBB\xBF/',
                    '',
                    $line
                );
            }

            $line = trim($line);

            if (
                $line === '' ||
                str_starts_with($line, '#')
            ) {
                continue;
            }

            if (!preg_match(
                '/^([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/',
                $line,
                $matches
            )) {
                throw new RuntimeException(
                    'Invalid environment variable at line ' .
                    ($index + 1)
                );
            }

            $key = $matches[1];
            $value = trim($matches[2]);

            // Handle quoted values.
            if (
                strlen($value) >= 2 &&
                (
                    ($value[0] === '"' && str_ends_with($value, '"')) ||
                    ($value[0] === "'" && str_ends_with($value, "'"))
                )
            ) {
                $value = substr($value, 1, -1);
            } else {
                // Remove comments from unquoted values.
                $value = preg_replace(
                    '/\s+#.*$/',
                    '',
                    $value
                );
            }

            // Existing system environment variables take priority.
            if (getenv($key) !== false) {
                continue;
            }

            $this->values[$key] = $value;
        }
    }

    public function get(
        string $key,
        string $default = ''
    ): string {
        $systemValue = getenv($key);

        if ($systemValue !== false) {
            return $systemValue;
        }

        return $this->values[$key] ?? $default;
    }

    public function boolean(
        string $key,
        bool $default = false
    ): bool {
        $value = $this->get(
            $key,
            $default ? 'true' : 'false'
        );

        return filter_var(
            $value,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        ) ?? $default;
    }
}