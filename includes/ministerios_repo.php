<?php
/**
 * includes/ministerios_repo.php
 *
 * DAL de los ministerios nombrados que cada Asistente da de alta para sí
 * (p. ej. "Pescadores", "Uno por uno", "La Buena Semilla Anexos").
 *
 * Mantener el tiempo agregable por ministerio en lugar de texto libre.
 * Todas las escrituras validan que el ministerio pertenezca al asistente.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/** Ministerios del asistente, con el nombre de su categoría. */
function listarMinisterios(int $asistenteId, bool $soloActivos = true): array
{
    $sql = 'SELECT m.id, m.nombre, m.categoria_id, m.observaciones, m.activo,
                   c.nombre AS categoria
            FROM ministerios m
            JOIN categorias c ON c.id = m.categoria_id
            WHERE m.asistente_id = :a';
    if ($soloActivos) {
        $sql .= ' AND m.activo = TRUE';
    }
    $sql .= ' ORDER BY m.activo DESC, m.nombre';
    $stmt = db()->prepare($sql);
    $stmt->execute(['a' => $asistenteId]);
    return $stmt->fetchAll();
}

/** Un ministerio por id, solo si pertenece al asistente. null en otro caso. */
function ministerioPorId(int $id, int $asistenteId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, nombre, categoria_id, observaciones, activo
         FROM ministerios WHERE id = :id AND asistente_id = :a'
    );
    $stmt->execute(['id' => $id, 'a' => $asistenteId]);
    $m = $stmt->fetch();
    return $m ?: null;
}

/** ¿Este ministerio es del asistente? (para validar FKs en otras escrituras). */
function ministerioEsDe(int $id, int $asistenteId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM ministerios WHERE id = :id AND asistente_id = :a');
    $stmt->execute(['id' => $id, 'a' => $asistenteId]);
    return $stmt->fetch() !== false;
}

function crearMinisterio(int $asistenteId, string $nombre, int $categoriaId, ?string $observaciones): int
{
    $stmt = db()->prepare(
        'INSERT INTO ministerios (asistente_id, nombre, categoria_id, observaciones)
         VALUES (:a, :n, :c, :o) RETURNING id'
    );
    $stmt->execute([
        'a' => $asistenteId,
        'n' => trim($nombre),
        'c' => $categoriaId,
        'o' => ($observaciones !== null && trim($observaciones) !== '') ? trim($observaciones) : null,
    ]);
    return (int) $stmt->fetchColumn();
}

/** Edita un ministerio del asistente. Devuelve true si afectó una fila. */
function actualizarMinisterio(int $id, int $asistenteId, string $nombre, int $categoriaId, ?string $observaciones): bool
{
    $stmt = db()->prepare(
        'UPDATE ministerios SET nombre = :n, categoria_id = :c, observaciones = :o
         WHERE id = :id AND asistente_id = :a'
    );
    $stmt->execute([
        'n'  => trim($nombre),
        'c'  => $categoriaId,
        'o'  => ($observaciones !== null && trim($observaciones) !== '') ? trim($observaciones) : null,
        'id' => $id,
        'a'  => $asistenteId,
    ]);
    return $stmt->rowCount() > 0;
}

/** Baja blanda de un ministerio del asistente. */
function desactivarMinisterio(int $id, int $asistenteId): void
{
    db()->prepare('UPDATE ministerios SET activo = FALSE WHERE id = :id AND asistente_id = :a')
        ->execute(['id' => $id, 'a' => $asistenteId]);
}
