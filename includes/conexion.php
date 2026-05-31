<?php
/**
 * includes/conexion.php
 *
 * Bootstrap compartido por web y CLI:
 *   - Carga config/config.php una sola vez (singleton estático).
 *   - Expone db() que devuelve un PDO singleton a PostgreSQL.
 *
 * Uso:
 *   require_once __DIR__ . '/../includes/conexion.php';
 *   $stmt = db()->prepare('SELECT 1');
 */

declare(strict_types=1);

function configAsistentes(): array
{
    static $config = null;
    if ($config === null) {
        $ruta = __DIR__ . '/../config/config.php';
        if (!is_file($ruta)) {
            fwrite(STDERR, "FATAL: falta config/config.php (copia config.example.php).\n");
            exit(1);
        }
        $config = require $ruta;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c   = configAsistentes();
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $c['db']['host'], $c['db']['port'], $c['db']['database']
        );
        $pdo = new PDO($dsn, $c['db']['user'], $c['db']['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // Toda la app trabaja en hora de México.
        $pdo->exec("SET TIME ZONE 'America/Mexico_City'");
    }
    return $pdo;
}
