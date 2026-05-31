<?php
/**
 * includes/actividad_repo.php
 *
 * DAL de los registros de actividad variable (fuera de culto, no recurrente).
 * Cada registro lleva su duración (inicio/fin o minutos) y, si la actividad
 * lo lleva, una cantidad de fruto. Opcionalmente cuelga de un ministerio
 * nombrado y/o de un proyecto.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/**
 * Crea un registro de actividad del asistente.
 *
 * @param array{
 *   actividad_id:int, fecha:string, hora_inicio:?string, hora_fin:?string,
 *   duracion_min:?int, fruto_cantidad:?int, ministerio_id:?int,
 *   proyecto_id:?int, nota:?string
 * } $d
 */
function crearRegistroActividad(int $asistenteId, array $d): int
{
    $stmt = db()->prepare(
        'INSERT INTO registros_actividad
            (asistente_id, actividad_id, ministerio_id, fecha,
             hora_inicio, hora_fin, duracion_min, fruto_cantidad,
             proyecto_id, nota)
         VALUES (:a, :act, :min, :f, :hi, :hf, :dur, :fr, :pry, :nota)
         RETURNING id'
    );
    $stmt->execute([
        'a'    => $asistenteId,
        'act'  => $d['actividad_id'],
        'min'  => $d['ministerio_id'] ?? null,
        'f'    => $d['fecha'],
        'hi'   => $d['hora_inicio'] ?? null,
        'hf'   => $d['hora_fin'] ?? null,
        'dur'  => $d['duracion_min'] ?? null,
        'fr'   => $d['fruto_cantidad'] ?? null,
        'pry'  => $d['proyecto_id'] ?? null,
        'nota' => (isset($d['nota']) && trim((string) $d['nota']) !== '') ? trim((string) $d['nota']) : null,
    ]);
    return (int) $stmt->fetchColumn();
}

/**
 * Registros recientes del asistente (para el historial corto de "Mi semana"
 * y la confirmación tras guardar). Incluye nombres legibles.
 */
function registrosRecientes(int $asistenteId, int $limite = 15): array
{
    $stmt = db()->prepare(
        'SELECT ra.id, ra.fecha, ra.hora_inicio, ra.hora_fin, ra.duracion_min,
                ra.fruto_cantidad, ra.nota,
                act.nombre AS actividad, act.etiqueta_fruto,
                cat.nombre AS categoria,
                m.nombre   AS ministerio,
                p.nombre   AS proyecto
         FROM registros_actividad ra
         JOIN actividades act ON act.id = ra.actividad_id
         JOIN categorias  cat ON cat.id = act.categoria_id
         LEFT JOIN ministerios m ON m.id = ra.ministerio_id
         LEFT JOIN proyectos   p ON p.id = ra.proyecto_id
         WHERE ra.asistente_id = :a
         ORDER BY ra.fecha DESC, ra.id DESC
         LIMIT :lim'
    );
    $stmt->bindValue('a', $asistenteId, PDO::PARAM_INT);
    $stmt->bindValue('lim', $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Borra un registro propio del asistente (deshacer reciente). */
function eliminarRegistroActividad(int $id, int $asistenteId): void
{
    db()->prepare('DELETE FROM registros_actividad WHERE id = :id AND asistente_id = :a')
        ->execute(['id' => $id, 'a' => $asistenteId]);
}

/** Proyectos activos disponibles para asociar a un registro. */
function listarProyectosActivos(): array
{
    return db()->query(
        'SELECT id, nombre FROM proyectos WHERE activo = TRUE ORDER BY nombre'
    )->fetchAll();
}
