<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Core\Database;

use NRPro\Pasargad\Core\Config\Config;
use PDO;
use RuntimeException;

final class Connection
{
    private ?PDO $connection = null;

    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Get PostgreSQL PDO connection.
     */
    public function pdo(): PDO
    {
        if ($this->connection instanceof PDO) {
            return $this->connection;
        }

        if (!extension_loaded('pdo_pgsql')) {
            throw new RuntimeException(
                'PHP extension pdo_pgsql is not enabled.'
            );
        }

        $host = (string) $this->config->get(
            'database.host',
            '127.0.0.1'
        );

        $port = (int) $this->config->get(
            'database.port',
            5432
        );

        $database = (string) $this->config->get(
            'database.name',
            'nr_pro_pasargad'
        );

        $username = (string) $this->config->get(
            'database.username',
            ''
        );

        $password = (string) $this->config->get(
            'database.password',
            ''
        );

        $sslMode = (string) $this->config->get(
            'database.sslmode',
            'disable'
        );

        $timeout = (int) $this->config->get(
            'database.connect_timeout',
            5
        );

        if (
            $host === '' ||
            $database === '' ||
            $port < 1 ||
            $port > 65535 ||
            $timeout < 1
        ) {
            throw new RuntimeException(
                'Invalid database configuration.'
            );
        }

        foreach ([$host, $database] as $value) {
            if (preg_match('/[;\r\n]/', $value)) {
                throw new RuntimeException(
                    'Invalid database connection parameter.'
                );
            }
        }

        $allowedSslModes = [
            'disable',
            'allow',
            'prefer',
            'require',
            'verify-ca',
            'verify-full',
        ];

        if (!in_array($sslMode, $allowedSslModes, true)) {
            throw new RuntimeException(
                'Invalid PostgreSQL SSL mode.'
            );
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;connect_timeout=%d',
            $host,
            $port,
            $database,
            $sslMode,
            $timeout
        );

        $this->connection = new PDO(
            $dsn,
            $username,
            $password,
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,

                PDO::ATTR_EMULATE_PREPARES =>
                    false,
            ]
        );

        return $this->connection;
    }

    /**
     * Check database connection.
     */
    public function ping(): bool
    {
        $statement = $this->pdo()->query(
            'SELECT 1'
        );

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * Disconnect from PostgreSQL.
     */
    public function disconnect(): void
    {
        $this->connection = null;
    }
}