<?php
/**
 * includes/cultos_repo.php
 *
 * DAL de asistencia a cultos.
 *
 * ALCANCE FASE 2: solo confirmación de asistencia (una fila por culto/fecha).
 * Las FUNCIONES desempeñadas en el culto y la lógica de SUSPENSIÓN por
 * campaña llegan en Fase 3 y extenderán este repo (funciones_realizadas).
 *
 * "Solo asistí" = una fila aquí con asistio=TRUE y SIN funciones_realizadas.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/**
 * Asistencias del asistente en un rango de fechas, indexadas por
 * "culto_id|fecha" para resolver O(1) al armar la semana.
 */
function asistenciasEnRango(int $asistenteId, string $desde, string $hasta): array
{
    $stmt = db()->prepare(
        'SELECT id, culto_id, fecha, asistio, hora_salida
         FROM asistencia_culto
         WHERE asistente_id = :a AND fecha BETWEEN :d AND :h'
    );
    $stmt->execute(['a' => $asistenteId, 'd' => $desde, 'h' => $hasta]);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[$row['culto_id'] . '|' . $row['fecha']] = $row;
    }
    return $out;
}

/**
 * Marca/actualiza la asistencia a un culto en una fecha (upsert).
 * hora_salida solo aplica a cultos de fin variable (Junta de Asistentes).
 */
function confirmarAsistenciaCulto(int $asistenteId, int $cultoId, string $fecha, bool $asistio, ?string $horaSalida): void
{
    $stmt = db()->prepare(
        'INSERT INTO asistencia_culto (asistente_id, culto_id, fecha, asistio, hora_salida)
         VALUES (:a, :c, :f, :as, :hs)
         ON CONFLICT (asistente_id, culto_id, fecha)
         DO UPDATE SET asistio = EXCLUDED.asistio, hora_salida = EXCLUDED.hora_salida'
    );
    $stmt->execute([
        'a'  => $asistenteId,
        'c'  => $cultoId,
        'f'  => $fecha,
        'as' => $asistio ? 'true' : 'false',
        'hs' => ($horaSalida !== null && trim($horaSalida) !== '') ? $horaSalida : null,
    ]);
}
