<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(<<<'SQL'
CREATE TABLE public.nr_vpn_panels (
    owner_id BIGINT NOT NULL CHECK (owner_id > 0),
    panel_id VARCHAR(64) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    driver_type VARCHAR(50) NOT NULL,
    base_url VARCHAR(1024) NOT NULL,
    encrypted_credentials TEXT NOT NULL,
    is_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT nr_vpn_panels_pk PRIMARY KEY (owner_id, panel_id),
    CONSTRAINT nr_vpn_panels_panel_id_chk CHECK (panel_id ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$'),
    CONSTRAINT nr_vpn_panels_https_chk CHECK (base_url LIKE 'https://%')
)
SQL);
    $pdo->exec('CREATE INDEX nr_vpn_panels_owner_enabled_idx ON public.nr_vpn_panels (owner_id, is_enabled)');
};
