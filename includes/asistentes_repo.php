<?php
/**
 * includes/asistentes_repo.php
 *
 * DAL del dominio "asistentes": perfil, trabajo secular, foto familiar,
 * roles y altas/bajas. Todas las funciones reciben/devuelven arrays planos.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

// ---------------------------------------------------------------------------
// Lectura
// ---------------------------------------------------------------------------

/** Asistente por id (sin password_hash). null si no existe. */
function asistentePorId(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT id, nombre, usuario, rol, telefono, disponibilidad,
                foto_familiar_url, activo, fecha_alta
         FROM asistentes WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);
    $a = $stmt->fetch();
    return $a ?: null;
}

/** Lista de asistentes para el panel de administración. */
function listarAsistentes(bool $soloActivos = false): array
{
    $sql = 'SELECT id, nombre, usuario, rol, telefono, foto_familiar_url, activo, fecha_alta
            FROM asistentes';
    if ($soloActivos) {
        $sql .= ' WHERE activo = TRUE';
    }
    $sql .= ' ORDER BY activo DESC, nombre';
    return db()->query($sql)->fetchAll();
}

/** ¿Está libre este usuario? (para validar altas/ediciones). */
function usuarioDisponible(string $usuario, ?int $exceptoId = null): bool
{
    $sql    = 'SELECT 1 FROM asistentes WHERE lower(usuario) = lower(:u)';
    $params = ['u' => trim($usuario)];
    if ($exceptoId !== null) {
        $sql .= ' AND id <> :id';
        $params['id'] = $exceptoId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() === false;
}

// ---------------------------------------------------------------------------
// Altas / bajas / roles
// ---------------------------------------------------------------------------

/**
 * Crea un asistente. Devuelve el id nuevo.
 * @throws PDOException (23505 si el usuario ya existe)
 */
function crearAsistente(string $nombre, string $usuario, string $password, string $rol = 'asistente', ?string $telefono = null): int
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = db()->prepare(
        'INSERT INTO asistentes (nombre, usuario, password_hash, rol, telefono)
         VALUES (:n, :u, :p, :r, :t) RETURNING id'
    );
    $stmt->execute([
        'n' => trim($nombre),
        'u' => trim($usuario),
        'p' => $hash,
        'r' => $rol,
        't' => ($telefono !== null && trim($telefono) !== '') ? trim($telefono) : null,
    ]);
    return (int) $stmt->fetchColumn();
}

/** Activa o desactiva (baja blanda) un asistente. */
function fijarActivoAsistente(int $id, bool $activo): void
{
    db()->prepare('UPDATE asistentes SET activo = :a WHERE id = :id')
        ->execute(['a' => $activo ? 'true' : 'false', 'id' => $id]);
}

/** Cambia el rol de un asistente. */
function fijarRolAsistente(int $id, string $rol): void
{
    db()->prepare('UPDATE asistentes SET rol = :r WHERE id = :id')
        ->execute(['r' => $rol, 'id' => $id]);
}

/** Resetea la contraseña de un asistente. */
function resetearPassword(int $id, string $password): void
{
    $hash = password_hash($password, PASSWORD_BCRYPT);
    db()->prepare('UPDATE asistentes SET password_hash = :p WHERE id = :id')
        ->execute(['p' => $hash, 'id' => $id]);
}

// ---------------------------------------------------------------------------
// Perfil (lo edita el propio asistente)
// ---------------------------------------------------------------------------

/** Actualiza datos editables del perfil propio. */
function actualizarPerfil(int $id, ?string $telefono, ?string $disponibilidad): void
{
    db()->prepare(
        'UPDATE asistentes SET telefono = :t, disponibilidad = :d WHERE id = :id'
    )->execute([
        't'  => ($telefono !== null && trim($telefono) !== '') ? trim($telefono) : null,
        'd'  => ($disponibilidad !== null && trim($disponibilidad) !== '') ? trim($disponibilidad) : null,
        'id' => $id,
    ]);
}

/** Cambia la contraseña verificando la actual. true si tuvo éxito. */
function cambiarPasswordPropia(int $id, string $actual, string $nueva): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM asistentes WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $hash = $stmt->fetchColumn();
    if ($hash === false || !password_verify($actual, (string) $hash)) {
        return false;
    }
    resetearPassword($id, $nueva);
    return true;
}

/** Guarda la ruta relativa de la foto familiar. */
function actualizarFotoUrl(int $id, string $rutaRelativa): void
{
    db()->prepare('UPDATE asistentes SET foto_familiar_url = :u WHERE id = :id')
        ->execute(['u' => $rutaRelativa, 'id' => $id]);
}

// ---------------------------------------------------------------------------
// Trabajo secular (declarativo, 0..n por asistente)
// ---------------------------------------------------------------------------

function listarTrabajoSecular(int $asistenteId, bool $soloActivos = true): array
{
    $sql = 'SELECT id, descripcion, horario, horas_semana, activo
            FROM trabajo_secular WHERE asistente_id = :a';
    if ($soloActivos) {
        $sql .= ' AND activo = TRUE';
    }
    $sql .= ' ORDER BY id';
    $stmt = db()->prepare($sql);
    $stmt->execute(['a' => $asistenteId]);
    return $stmt->fetchAll();
}

function agregarTrabajoSecular(int $asistenteId, string $descripcion, ?string $horario, ?float $horasSemana): int
{
    $stmt = db()->prepare(
        'INSERT INTO trabajo_secular (asistente_id, descripcion, horario, horas_semana)
         VALUES (:a, :d, :h, :hs) RETURNING id'
    );
    $stmt->execute([
        'a'  => $asistenteId,
        'd'  => trim($descripcion),
        'h'  => ($horario !== null && trim($horario) !== '') ? trim($horario) : null,
        'hs' => $horasSemana,
    ]);
    return (int) $stmt->fetchColumn();
}

/** Baja blanda de un trabajo secular, validando dueño. */
function desactivarTrabajoSecular(int $id, int $asistenteId): void
{
    db()->prepare(
        'UPDATE trabajo_secular SET activo = FALSE WHERE id = :id AND asistente_id = :a'
    )->execute(['id' => $id, 'a' => $asistenteId]);
}
