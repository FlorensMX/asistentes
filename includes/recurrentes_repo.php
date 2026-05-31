<?php
/**
 * includes/recurrentes_repo.php
 *
 * DAL de los compromisos ministeriales recurrentes (el patrón semanal que
 * declara el Asistente) y de su confirmación semanal por ocurrencia.
 *
 * Lógica de "Mi semana": para cada compromiso vigente, el sistema genera la
 * ocurrencia de la semana según dia_semana; el Asistente confirma con un toque.
 * Las no confirmadas cuentan como no realizadas (ausencia de fila).
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/** Compromisos recurrentes del asistente, con ministerio y categoría. */
function listarRecurrentes(int $asistenteId, bool $soloActivos = true): array
{
    $sql = 'SELECT r.id, r.ministerio_id, r.nombre, r.categoria_id,
                   r.dia_semana, r.hora_inicio, r.hora_fin,
                   r.vigente_desde, r.vigente_hasta, r.activo,
                   c.nombre AS categoria,
                   m.nombre AS ministerio
            FROM compromisos_recurrentes r
            JOIN categorias c ON c.id = r.categoria_id
            LEFT JOIN ministerios m ON m.id = r.ministerio_id
            WHERE r.asistente_id = :a';
    if ($soloActivos) {
        $sql .= ' AND r.activo = TRUE';
    }
    $sql .= ' ORDER BY r.activo DESC, r.dia_semana, r.hora_inicio';
    $stmt = db()->prepare($sql);
    $stmt->execute(['a' => $asistenteId]);
    return $stmt->fetchAll();
}

/** Un recurrente por id, solo si pertenece al asistente. null si no. */
function recurrentePorId(int $id, int $asistenteId): ?array
{
    $stmt = db()->prepare(
        'SELECT id, ministerio_id, nombre, categoria_id, dia_semana,
                hora_inicio, hora_fin, vigente_desde, vigente_hasta, activo
         FROM compromisos_recurrentes WHERE id = :id AND asistente_id = :a'
    );
    $stmt->execute(['id' => $id, 'a' => $asistenteId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

/** ¿El recurrente pertenece al asistente? */
function recurrenteEsDe(int $id, int $asistenteId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM compromisos_recurrentes WHERE id = :id AND asistente_id = :a');
    $stmt->execute(['id' => $id, 'a' => $asistenteId]);
    return $stmt->fetch() !== false;
}

function crearRecurrente(
    int $asistenteId,
    string $nombre,
    int $categoriaId,
    int $diaSemana,
    string $horaInicio,
    string $horaFin,
    ?int $ministerioId,
    string $vigenteDesde,
    ?string $vigenteHasta
): int {
    $stmt = db()->prepare(
        'INSERT INTO compromisos_recurrentes
            (asistente_id, ministerio_id, nombre, categoria_id,
             dia_semana, hora_inicio, hora_fin, vigente_desde, vigente_hasta)
         VALUES (:a, :m, :n, :c, :d, :hi, :hf, :vd, :vh)
         RETURNING id'
    );
    $stmt->execute([
        'a'  => $asistenteId,
        'm'  => $ministerioId,
        'n'  => trim($nombre),
        'c'  => $categoriaId,
        'd'  => $diaSemana,
        'hi' => $horaInicio,
        'hf' => $horaFin,
        'vd' => $vigenteDesde,
        'vh' => $vigenteHasta,
    ]);
    return (int) $stmt->fetchColumn();
}

/** Edita un recurrente del asistente. true si afectó una fila. */
function actualizarRecurrente(
    int $id,
    int $asistenteId,
    string $nombre,
    int $categoriaId,
    int $diaSemana,
    string $horaInicio,
    string $horaFin,
    ?int $ministerioId,
    string $vigenteDesde,
    ?string $vigenteHasta
): bool {
    $stmt = db()->prepare(
        'UPDATE compromisos_recurrentes
            SET ministerio_id = :m, nombre = :n, categoria_id = :c,
                dia_semana = :d, hora_inicio = :hi, hora_fin = :hf,
                vigente_desde = :vd, vigente_hasta = :vh
          WHERE id = :id AND asistente_id = :a'
    );
    $stmt->execute([
        'm'  => $ministerioId,
        'n'  => trim($nombre),
        'c'  => $categoriaId,
        'd'  => $diaSemana,
        'hi' => $horaInicio,
        'hf' => $horaFin,
        'vd' => $vigenteDesde,
        'vh' => $vigenteHasta,
        'id' => $id,
        'a'  => $asistenteId,
    ]);
    return $stmt->rowCount() > 0;
}

/** Baja blanda de un recurrente del asistente. */
function desactivarRecurrente(int $id, int $asistenteId): void
{
    db()->prepare('UPDATE compromisos_recurrentes SET activo = FALSE WHERE id = :id AND asistente_id = :a')
        ->execute(['id' => $id, 'a' => $asistenteId]);
}

// ---------------------------------------------------------------------------
// Confirmaciones semanales
// ---------------------------------------------------------------------------

/**
 * Confirmaciones del asistente en un rango de fechas, indexadas por
 * "compromiso_id|fecha" para resolver O(1) al armar la semana.
 */
function confirmacionesEnRango(int $asistenteId, string $desde, string $hasta): array
{
    $stmt = db()->prepare(
        'SELECT cr.id, cr.compromiso_id, cr.fecha, cr.confirmado, cr.nota
         FROM confirmaciones_recurrente cr
         JOIN compromisos_recurrentes r ON r.id = cr.compromiso_id
         WHERE r.asistente_id = :a AND cr.fecha BETWEEN :d AND :h'
    );
    $stmt->execute(['a' => $asistenteId, 'd' => $desde, 'h' => $hasta]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['compromiso_id'] . '|' . $row['fecha']] = $row;
    }
    return $out;
}

/**
 * Confirma o desmarca una ocurrencia de un recurrente (upsert por fecha).
 * El llamador DEBE haber validado que el compromiso es del asistente.
 */
function confirmarRecurrente(int $compromisoId, string $fecha, bool $confirmado, ?string $nota): void
{
    $stmt = db()->prepare(
        'INSERT INTO confirmaciones_recurrente (compromiso_id, fecha, confirmado, nota)
         VALUES (:c, :f, :co, :n)
         ON CONFLICT (compromiso_id, fecha)
         DO UPDATE SET confirmado = EXCLUDED.confirmado, nota = EXCLUDED.nota'
    );
    $stmt->execute([
        'c'  => $compromisoId,
        'f'  => $fecha,
        'co' => $confirmado ? 'true' : 'false',
        'n'  => ($nota !== null && trim($nota) !== '') ? trim($nota) : null,
    ]);
}
