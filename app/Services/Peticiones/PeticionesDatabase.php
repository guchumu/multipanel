<?php

declare(strict_types=1);

namespace App\Services\Peticiones;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Segunda conexión PDO hacia la BD remota legacy (`series` / tablas peticiones).
 * Independiente de Core\Database (MultiPanel).
 */
final class PeticionesDatabase
{
    private static ?self $instance = null;

    private PDO $pdo;

    private function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function getInstance(?int $tenantId = null): self
    {
        if (self::$instance === null) {
            self::$instance = self::connect($tenantId);
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * Prueba de conexión sin cachear la instancia.
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(?int $tenantId = null, ?array $override = null): array
    {
        try {
            $cfg = $override ?? PeticionesConfig::forTenant($tenantId);
            if (empty($cfg['host']) || empty($cfg['database']) || empty($cfg['username'])) {
                return ['ok' => false, 'message' => 'Faltan host, base de datos o usuario.'];
            }
            if (($cfg['password'] ?? '') === '') {
                return ['ok' => false, 'message' => 'Falta la contraseña de la BD remota.'];
            }

            $pdo = self::createPdo($cfg);
            $pdo->query('SELECT 1');

            return [
                'ok' => true,
                'message' => sprintf(
                    'Conexión OK a %s:%d / %s como %s',
                    $cfg['host'],
                    (int) $cfg['port'],
                    $cfg['database'],
                    $cfg['username']
                ),
            ];
        } catch (PDOException $e) {
            return ['ok' => false, 'message' => 'Error de conexión: ' . $e->getMessage()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();

        return $result ?: null;
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    private static function connect(?int $tenantId): self
    {
        $cfg = PeticionesConfig::forTenant($tenantId);
        if (!$cfg['configured']) {
            throw new \RuntimeException(
                'BD remota de peticiones no configurada. Ve a Configuración → Peticiones / BD remota.'
            );
        }

        return new self(self::createPdo($cfg));
    }

    /** @param array<string, mixed> $cfg */
    private static function createPdo(array $cfg): PDO
    {
        $host = (string) $cfg['host'];
        $port = (int) ($cfg['port'] ?? 3306);
        $dbname = (string) $cfg['database'];
        $charset = (string) ($cfg['charset'] ?? 'utf8mb4');
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

        return new PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 8,
        ]);
    }
}
