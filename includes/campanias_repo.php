<?php
/**
 * includes/campanias_repo.php
 *
 * DAL de los periodos de campaña / misión que declara el Asistente, y de la
 * lógica de SUSPENSIÓN que de ellos se deriva.
 *
 * Regla clave (sección 6 del brief, no reinterpretar): si una fecha cae dentro
 * de un periodo_campania del asistente, sus recurrentes y cultos de esos días
 * NO se piden ni cuentan en contra (estaba fuera). La campaña representa la
 * dedicación de esos días. Las campañas se miden como DÍAS DE MISIÓN; nunca se
 * convierten a horas.
 *
 * Todas las escrituras validan que el periodo pertenezca al asistente.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

// ---------------------------------------------------------------------------
// Lectura
// ---------------------------------------------------------------------------

/**
 * Periodos de campaña del asistente, con el nombre de su categoría.
 *
 * Si se pasan $desde y $hasta (Y-m-d), filtra a los periodos que SOLAPAN ese
 * rango (útil para la pantalla y para detectar traslapes). Sin rango: todos.
 * Orden: por fecha_inicio descendente (próximas/recientes primero).
 */
function campaniasDeAsistente(int $asistenteId, ?string $desde = null, ?string $hasta = null): array
{
    $sql = 'SELECT p.id, p.categoria_id, p.fecha_inicio, p.fecha_fin,
                   p.lugar, p.descripcion, p.fruto_cantidad, p.creado_en,
                   c.nombre AS categoria
            FROM periodos_campania p
            LEFT JOIN categorias c ON c.id = p.categoria_id
            WHERE p.asistente_id = :a';
    $params = ['a' => $asistenteId];
    if ($desde !== null && $hasta !== null) {
        // Solape de intervalos cerrados: inicio <= hasta AND fin >= desde.
        $sql .= ' AND p.fecha_inicio <= :hasta::date AND p.fecha_fin >= :desde::date';
        $params['desde'] = $desde;
        $params['hasta'] = $hasta;
    }
    $sql .= ' ORDER BY p.fecha_inicio DESC, p.fecha_fin DESC, p.id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Un periodo por id, solo si pertenece al asistente. null en otro caso. */
function campaniaPorId(int $id, int $asistenteId): ?array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.categoria_id, p.fecha_inicio, p.fecha_fin,
                p.lugar, p.descripcion, p.fruto_cantidad, p.creado_en,
                c.nombre AS categoria
         FROM periodos_campania p
         LEFT JOIN categorias c ON c.id = p.categoria_id
         WHERE p.id = :id AND p.asistente_id = :a'
    );
    $stmt->execute(['id' => $id, 'a' => $asistenteId]);
    $p = $stmt->fetch();
    return $p ?: null;
}

// ---------------------------------------------------------------------------
// Escritura (alta / edición / baja)
// ---------------------------------------------------------------------------

/**
 * Crea un periodo de campaña. Exige fecha_fin >= fecha_inicio (el CHECK de la
 * BD lo refuerza; el llamador valida igual en PHP). Devuelve el id nuevo.
 */
function crearCampania(
    int $asistenteId,
    string $fechaInicio,
    string $fechaFin,
    ?string $lugar = null,
    ?string $descripcion = null,
    ?int $categoriaId = null,
    ?int $frutoCantidad = null
): int {
    $stmt = db()->prepare(
        'INSERT INTO periodos_campania
            (asistente_id, categoria_id, fecha_inicio, fecha_fin, lugar, descripcion, fruto_cantidad)
         VALUES (:a, :cat, :fi, :ff, :lu, :de, :fr)
         RETURNING id'
    );
    $stmt->execute([
        'a'   => $asistenteId,
        'cat' => $categoriaId,
        'fi'  => $fechaInicio,
        'ff'  => $fechaFin,
        'lu'  => ($lugar !== null && trim($lugar) !== '') ? trim($lugar) : null,
        'de'  => ($descripcion !== null && trim($descripcion) !== '') ? trim($descripcion) : null,
        'fr'  => $frutoCantidad,
    ]);
    return (int) $stmt->fetchColumn();
}

/** Edita un periodo del asistente. true si afectó una fila (era suyo). */
function editarCampania(
    int $id,
    int $asistenteId,
    string $fechaInicio,
    string $fechaFin,
    ?string $lugar,
    ?string $descripcion,
    ?int $categoriaId,
    ?int $frutoCantidad
): bool {
    $stmt = db()->prepare(
        'UPDATE periodos_campania
            SET categoria_id = :cat, fecha_inicio = :fi, fecha_fin = :ff,
                lugar = :lu, descripcion = :de, fruto_cantidad = :fr
          WHERE id = :id AND asistente_id = :a'
    );
    $stmt->execute([
        'cat' => $categoriaId,
        'fi'  => $fechaInicio,
        'ff'  => $fechaFin,
        'lu'  => ($lugar !== null && trim($lugar) !== '') ? trim($lugar) : null,
        'de'  => ($descripcion !== null && trim($descripcion) !== '') ? trim($descripcion) : null,
        'fr'  => $frutoCantidad,
        'id'  => $id,
        'a'   => $asistenteId,
    ]);
    return $stmt->rowCount() > 0;
}

/** Elimina un periodo del asistente. true si existía y era suyo. */
function eliminarCampania(int $id, int $asistenteId): bool
{
    $stmt = db()->prepare(
        'DELETE FROM periodos_campania WHERE id = :id AND asistente_id = :a'
    );
    $stmt->execute(['id' => $id, 'a' => $asistenteId]);
    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Suspensión (lo que consume "Mi semana")
// ---------------------------------------------------------------------------

/**
 * Conjunto de fechas (Y-m-d) del rango [lunes, domingo] cubiertas por algún
 * periodo de campaña del asistente. Esto es lo que usa semana.php para
 * suspender cultos y recurrentes de esos días.
 *
 * Se expande con generate_series acotado al solape de cada periodo con el
 * rango pedido, y se deduplica (varios periodos pueden tapar el mismo día).
 *
 * @return string[] fechas 'Y-m-d' ordenadas ascendente
 */
function diasSuspendidos(int $asistenteId, string $lunes, string $domingo): array
{
    $stmt = db()->prepare(
        "SELECT to_char(g.d, 'YYYY-MM-DD') AS fecha
         FROM periodos_campania p
         CROSS JOIN LATERAL generate_series(
                GREATEST(p.fecha_inicio, :lunes1::date),
                LEAST(p.fecha_fin,    :domingo1::date),
                interval '1 day'
         ) AS g(d)
         WHERE p.asistente_id = :a
           AND p.fecha_inicio <= :domingo2::date
           AND p.fecha_fin    >= :lunes2::date
         GROUP BY g.d
         ORDER BY g.d"
    );
    $stmt->execute([
        'a'        => $asistenteId,
        'lunes1'   => $lunes,
        'lunes2'   => $lunes,
        'domingo1' => $domingo,
        'domingo2' => $domingo,
    ]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** ¿La fecha cae dentro de algún periodo de campaña del asistente? */
function estaEnCampania(int $asistenteId, string $fecha): bool
{
    $stmt = db()->prepare(
        'SELECT 1 FROM periodos_campania
         WHERE asistente_id = :a AND :f::date BETWEEN fecha_inicio AND fecha_fin
         LIMIT 1'
    );
    $stmt->execute(['a' => $asistenteId, 'f' => $fecha]);
    return $stmt->fetch() !== false;
}

/**
 * Días de misión del asistente dentro de un mes (rango [inicioMes, finMes]),
 * deduplicando solapes y acotando cada periodo al mes. La Fase 4 (dashboard)
 * lo consumirá: las campañas se reportan como días de misión, no como horas.
 */
function diasDeMision(int $asistenteId, string $inicioMes, string $finMes): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM (
            SELECT DISTINCT g.d
            FROM periodos_campania p
            CROSS JOIN LATERAL generate_series(
                   GREATEST(p.fecha_inicio, :ini1::date),
                   LEAST(p.fecha_fin,    :fin1::date),
                   interval '1 day'
            ) AS g(d)
            WHERE p.asistente_id = :a
              AND p.fecha_inicio <= :fin2::date
              AND p.fecha_fin    >= :ini2::date
         ) AS dias"
    );
    $stmt->execute([
        'a'    => $asistenteId,
        'ini1' => $inicioMes,
        'ini2' => $inicioMes,
        'fin1' => $finMes,
        'fin2' => $finMes,
    ]);
    return (int) $stmt->fetchColumn();
}
