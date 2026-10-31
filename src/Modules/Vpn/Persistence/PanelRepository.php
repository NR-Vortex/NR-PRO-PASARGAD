<?php

declare(strict_types=1);

namespace NRPro\Pasargad\Modules\Vpn\Persistence;

use InvalidArgumentException;
use NRPro\Pasargad\Modules\Vpn\DTO\PanelConnection;
use NRPro\Pasargad\Modules\Vpn\Security\PanelCredentialCipher;
use PDO;
use RuntimeException;

/** PostgreSQL repository. All operations require an explicit, trusted owner ID. */
final class PanelRepository
{
    public function __construct(private readonly PDO $db, private readonly PanelCredentialCipher $cipher)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new InvalidArgumentException('PanelRepository requires PostgreSQL.');
        }
    }

    /** Create one panel; never overwrite existing credentials implicitly. */
    public function create(int $ownerId, string $name, PanelConnection $connection): void
    {
        self::checkOwner($ownerId);
        self::checkName($name);
        if (parse_url($connection->baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('Stored VPN panel connections must use HTTPS.');
        }
        if (strlen($connection->baseUrl) > 1024) {
            throw new InvalidArgumentException('Panel base URL exceeds supported length.');
        }
        $credentials = $connection->exportSecretsForEncryptedStorage();
        $aad = PanelCredentialCipher::context($ownerId, $connection->id, $connection->type, $connection->baseUrl);
        $encrypted = $this->cipher->encrypt($credentials, $aad);
        $query = $this->db->prepare(<<<'SQL'
INSERT INTO public.nr_vpn_panels
    (owner_id, panel_id, display_name, driver_type, base_url, encrypted_credentials)
VALUES (:owner_id, :panel_id, :display_name, :driver_type, :base_url, :encrypted_credentials)
SQL);
        $query->execute([
            'owner_id' => $ownerId,
            'panel_id' => $connection->id,
            'display_name' => $name,
            'driver_type' => $connection->type,
            'base_url' => $connection->baseUrl,
            'encrypted_credentials' => $encrypted,
        ]);
    }

    /** No secrets or encrypted credential values are returned by listing. */
    public function listMetadata(int $ownerId): array
    {
        self::checkOwner($ownerId);
        $query = $this->db->prepare(<<<'SQL'
SELECT panel_id, display_name, driver_type, base_url, is_enabled, created_at, updated_at
FROM public.nr_vpn_panels
WHERE owner_id = :owner_id
ORDER BY display_name ASC, panel_id ASC
SQL);
        $query->execute(['owner_id' => $ownerId]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Returns decrypted connection only to trusted backend callers, not API/UI JSON. */
    public function getEnabledConnection(int $ownerId, string $panelId): ?PanelConnection
    {
        self::checkOwner($ownerId);
        self::checkPanelId($panelId);
        $query = $this->db->prepare(<<<'SQL'
SELECT panel_id, driver_type, base_url, encrypted_credentials
FROM public.nr_vpn_panels
WHERE owner_id = :owner_id AND panel_id = :panel_id AND is_enabled = TRUE
SQL);
        $query->execute(['owner_id' => $ownerId, 'panel_id' => $panelId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $aad = PanelCredentialCipher::context($ownerId, $row['panel_id'], $row['driver_type'], $row['base_url']);
        $credentials = $this->cipher->decrypt($row['encrypted_credentials'], $aad);
        return new PanelConnection($row['panel_id'], $row['driver_type'], $row['base_url'], $credentials);
    }

    /** Administrative disable/enable, always scoped to owner. */
    public function setEnabled(int $ownerId, string $panelId, bool $enabled): bool
    {
        self::checkOwner($ownerId);
        self::checkPanelId($panelId);
        $query = $this->db->prepare(<<<'SQL'
UPDATE public.nr_vpn_panels
SET is_enabled = :is_enabled, updated_at = CURRENT_TIMESTAMP
WHERE owner_id = :owner_id AND panel_id = :panel_id
SQL);
        $query->bindValue(':is_enabled', $enabled, PDO::PARAM_BOOL);
        $query->bindValue(':owner_id', $ownerId, PDO::PARAM_INT);
        $query->bindValue(':panel_id', $panelId, PDO::PARAM_STR);
        $query->execute();
        return $query->rowCount() === 1;
    }

    /** Explicit deletion only; does not delete VPN accounts on the remote panel. */
    public function delete(int $ownerId, string $panelId): bool
    {
        self::checkOwner($ownerId);
        self::checkPanelId($panelId);
        $query = $this->db->prepare(<<<'SQL'
DELETE FROM public.nr_vpn_panels
WHERE owner_id = :owner_id AND panel_id = :panel_id
SQL);
        $query->execute(['owner_id' => $ownerId, 'panel_id' => $panelId]);
        return $query->rowCount() === 1;
    }

    private static function checkOwner(int $ownerId): void
    {
        if ($ownerId <= 0) {
            throw new InvalidArgumentException('Invalid owner identifier.');
        }
    }

    private static function checkPanelId(string $panelId): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/i', $panelId)) {
            throw new InvalidArgumentException('Invalid panel identifier.');
        }
    }

    private static function checkName(string $name): void
    {
        if (trim($name) === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Invalid panel display name.');
        }
    }

}
