<?php

declare(strict_types=1);

return static function (PDO $pdo): void {

    $pdo->exec(<<<'SQL'
CREATE TABLE public.nr_system_metadata (
    metadata_key VARCHAR(100) PRIMARY KEY,
    metadata_value TEXT NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

};