<?php
/**
 * scripts/crear_admin.php
 *
 * CLI para crear/promover un usuario administrador o pastor (primer acceso).
 *
 * Uso interactivo:
 *   php scripts/crear_admin.php
 *
 * No-interactivo:
 *   php scripts/crear_admin.php --usuario=florencio --nombre="Florencio Martínez" \
 *       --rol=admin --password='Sup3rS3gur4!'
 *
 * Nunca commitear contraseñas. Tras correrlo, limpia el historial del shell.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/conexion.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "Este script solo se ejecuta desde CLI.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parseo de flags
// ---------------------------------------------------------------------------
$flags = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([^=]+)=(.*)$/', $arg, $m)) {
        $flags[$m[1]] = $m[2];
    }
}

function leerLinea(string $prompt, bool $oculto = false): string
{
    fwrite(STDOUT, $prompt);
    if ($oculto && DIRECTORY_SEPARATOR === '/') {
        @system('stty -echo');
        $linea = rtrim((string) fgets(STDIN), "\r\n");
        @system('stty echo');
        echo "\n";
    } else {
        $linea = rtrim((string) fgets(STDIN), "\r\n");
    }
    return $linea;
}

echo "═══════════════════════════════════════════════════════════════════════\n";
echo "  Crear usuario — Sistema de Gestión Ministerial\n";
echo "═══════════════════════════════════════════════════════════════════════\n\n";

$nombre  = $flags['nombre']  ?? leerLinea('Nombre completo: ');
$usuario = strtolower($flags['usuario'] ?? leerLinea('Usuario (para entrar): '));
$rol     = strtolower($flags['rol']     ?? leerLinea('Rol [admin|pastor|asistente] (default: admin): '));
if ($rol === '') $rol = 'admin';

// ---------------------------------------------------------------------------
// Validaciones
// ---------------------------------------------------------------------------
if ($nombre === '') {
    fwrite(STDERR, "ERROR: nombre vacío.\n"); exit(1);
}
if (!preg_match('/^[a-z0-9._-]{3,60}$/', $usuario)) {
    fwrite(STDERR, "ERROR: usuario inválido (3-60 chars: letras, números, . _ -).\n"); exit(1);
}
if (!in_array($rol, ['admin', 'pastor', 'asistente'], true)) {
    fwrite(STDERR, "ERROR: rol debe ser 'admin', 'pastor' o 'asistente'.\n"); exit(1);
}

if (isset($flags['password'])) {
    $pass = (string) $flags['password'];
} else {
    $pass1 = leerLinea('Contraseña: ', true);
    $pass2 = leerLinea('Confirmar:   ', true);
    if ($pass1 !== $pass2) {
        fwrite(STDERR, "ERROR: las contraseñas no coinciden.\n"); exit(1);
    }
    $pass = $pass1;
}

if (strlen($pass) < 10) {
    fwrite(STDERR, "ERROR: la contraseña debe tener al menos 10 caracteres.\n"); exit(1);
}

$hash = password_hash($pass, PASSWORD_BCRYPT);

// ---------------------------------------------------------------------------
// Insertar
// ---------------------------------------------------------------------------
try {
    $stmt = db()->prepare(
        'INSERT INTO asistentes (nombre, usuario, password_hash, rol, activo)
         VALUES (:n, :u, :p, :r, TRUE)
         RETURNING id'
    );
    $stmt->execute(['n' => $nombre, 'u' => $usuario, 'p' => $hash, 'r' => $rol]);
    $id = $stmt->fetchColumn();
    echo "\n✓ Usuario creado:  id=$id  usuario=$usuario  rol=$rol\n";
    echo "  Entra en https://montesion.cloud/apps/asistentes/login.php\n";
} catch (PDOException $e) {
    if ($e->getCode() === '23505') { // unique violation
        fwrite(STDERR, "ERROR: ya existe un usuario '$usuario'.\n");
    } else {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    }
    exit(1);
}
