<?php

declare(strict_types=1);

return static function (PDO $pdo): void {

    // Verify the Stage 20 panel table.

    $columns = $pdo->query(<<<'SQL'
SELECT column_name
FROM information_schema.columns
WHERE table_schema = 'public'
  AND table_name = 'nr_vpn_panels'
SQL)->fetchAll(PDO::FETCH_COLUMN);

    foreach (
        ['owner_id', 'panel_id', 'is_enabled']
        as $required
    ) {
        if (!in_array($required, $columns, true)) {
            throw new RuntimeException(
                'Panel schema is incompatible: ' . $required
            );
        }
    }

    // Store one audit record per successful operation.

    $pdo->exec(<<<'SQL'
CREATE TABLE public.nr_panel_status_audit (
    operation_id CHAR(32) PRIMARY KEY,
    owner_id BIGINT NOT NULL,
    panel_id VARCHAR(64) NOT NULL,
    previous_enabled BOOLEAN NOT NULL,
    new_enabled BOOLEAN NOT NULL,
    changed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT nr_panel_status_change_required
        CHECK (previous_enabled <> new_enabled)
)
SQL);

    $pdo->exec(<<<'SQL'
CREATE INDEX nr_panel_status_audit_owner_date_idx
ON public.nr_panel_status_audit (
    owner_id,
    changed_at DESC
)
SQL);
};