<?php
/**
 * includes/cultos_repo.php
 *
 * DAL de asistencia a cultos + funciones desempeñadas en ellos.
 *
 * "Solo asistí" = una fila en asistencia_culto (asistio=TRUE) y SIN filas en
 * funciones_realizadas. La ausencia de función es la señal; no existe una
 * función "Asistir".
 *
 * El FRUTO solo aplica a funciones con lleva_fruto=TRUE (p. ej. Captación tipo
 * Pescadores → decisiones, Bautizar → bautizados). El llamador (el endpoint)
 * filtra el fruto antes de persistir; aquí solo se guarda lo recibido.
 *
 * Historia: la confirmación de asistencia nació en Fase 2; las funciones de
 * culto y su sincronización se añadieron en Fase 3 (este archivo se amplió).
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

// ---------------------------------------------------------------------------
// Asistencia a cultos
// ---------------------------------------------------------------------------

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
 * Marca/actualiza la asistencia a un culto en una fecha (upsert sobre la única
 * clave (asistente_id, culto_id, fecha)). Devuelve el asistencia_culto_id.
 *
 * hora_salida solo aplica a cultos de fin variable (Junta de Asistentes).
 *
 * $tocarHoraSalida controla si una RE-confirmación pisa la hora_salida ya
 * guardada: el toque rápido de "Mi semana" confirma sin enviar hora_salida, y
 * en ese caso NO debe borrar la que se capturó antes en el panel. Con el flag
 * en false, el upsert deja hora_salida intacta al actualizar (solo la fija al
 * insertar una fila nueva). Con true (def.) sincroniza el valor recibido.
 */
function confirmarAsistenciaCulto(
    int $asistenteId,
    int $cultoId,
    string $fecha,
    bool $asistio,
    ?string $horaSalida,
    bool $tocarHoraSalida = true
): int {
    $sql = 'INSERT INTO asistencia_culto (asistente_id, culto_id, fecha, asistio, hora_salida)
            VALUES (:a, :c, :f, :as, :hs)
            ON CONFLICT (asistente_id, culto_id, fecha)
            DO UPDATE SET asistio = EXCLUDED.asistio';
    if ($tocarHoraSalida) {
        $sql .= ', hora_salida = EXCLUDED.hora_salida';
    }
    $sql .= ' RETURNING id';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        'a'  => $asistenteId,
        'c'  => $cultoId,
        'f'  => $fecha,
        'as' => $asistio ? 'true' : 'false',
        'hs' => ($horaSalida !== null && trim($horaSalida) !== '') ? $horaSalida : null,
    ]);
    return (int) $stmt->fetchColumn();
}

/**
 * Borra la asistencia del asistente a un culto en una fecha (para "desmarcar").
 * La cascada de la FK elimina también sus funciones_realizadas.
 * Scopeado al dueño: nadie borra la asistencia de otro.
 */
function quitarAsistencia(int $asistenteId, int $cultoId, string $fecha): void
{
    db()->prepare(
        'DELETE FROM asistencia_culto
         WHERE asistente_id = :a AND culto_id = :c AND fecha = :f'
    )->execute(['a' => $asistenteId, 'c' => $cultoId, 'f' => $fecha]);
}

// ---------------------------------------------------------------------------
// Funciones realizadas en culto
// ---------------------------------------------------------------------------

/**
 * Funciones realizadas en una asistencia concreta, con datos legibles del
 * catálogo (nombre, grupo, etiqueta de fruto) para pintarlas y devolverlas.
 */
function funcionesDeAsistencia(int $asistenciaCultoId): array
{
    $stmt = db()->prepare(
        'SELECT fr.funcion_culto_id, fr.fruto_cantidad,
                fc.nombre, fc.grupo, fc.lleva_fruto, fc.etiqueta_fruto
         FROM funciones_realizadas fr
         JOIN funciones_culto fc ON fc.id = fr.funcion_culto_id
         WHERE fr.asistencia_culto_id = :ac
         ORDER BY fc.grupo, fc.nombre'
    );
    $stmt->execute(['ac' => $asistenciaCultoId]);
    return $stmt->fetchAll();
}

/**
 * Funciones realizadas de un conjunto de asistencias, indexadas por
 * asistencia_culto_id → lista de funciones. Pensado para armar "Mi semana"
 * en una sola consulta (evita N+1).
 *
 * @param int[] $asistenciaIds
 * @return array<int, array<int, array>>
 */
function funcionesRealizadasDeAsistencias(array $asistenciaIds): array
{
    $ids = array_values(array_unique(array_map('intval', $asistenciaIds)));
    if (!$ids) {
        return [];
    }
    $ph = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $ph[]            = ':a' . $i;
        $params['a' . $i] = $id;
    }
    $sql = 'SELECT fr.asistencia_culto_id, fr.funcion_culto_id, fr.fruto_cantidad,
                   fc.nombre, fc.grupo, fc.lleva_fruto, fc.etiqueta_fruto
            FROM funciones_realizadas fr
            JOIN funciones_culto fc ON fc.id = fr.funcion_culto_id
            WHERE fr.asistencia_culto_id IN (' . implode(',', $ph) . ')
            ORDER BY fc.grupo, fc.nombre';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int) $row['asistencia_culto_id']][] = $row;
    }
    return $out;
}

/**
 * Sincroniza las funciones de una asistencia con la lista recibida:
 *   - inserta las nuevas,
 *   - actualiza el fruto de las que ya existían,
 *   - borra las que ya no vienen,
 * respetando la única clave (asistencia_culto_id, funcion_culto_id).
 *
 * Cada elemento de $funciones: ['funcion_culto_id' => int, 'fruto_cantidad' => ?int].
 * El fruto ya debe venir filtrado por el llamador (null donde no aplica).
 *
 * $borrablesIds ACOTA qué funciones puede borrar este sync: solo se eliminan
 * las que están en ese conjunto y dejaron de venir. Sirve para preservar
 * capturas históricas cuya función se desactivó después en el catálogo (el
 * panel solo muestra/reenvía funciones activas; sin esta cota, "Guardar"
 * borraría lo histórico). Pasa las funciones ACTIVAS como conjunto borrable.
 * Con null (sync total / compat) se borra todo lo que no venga.
 *
 * El llamador es responsable de envolver esto en una transacción si lo combina
 * con confirmarAsistenciaCulto().
 */
function sincronizarFunciones(int $asistenciaCultoId, array $funciones, ?array $borrablesIds = null): void
{
    // Normaliza: una entrada por funcion_culto_id (la última gana).
    $deseadas = [];
    foreach ($funciones as $f) {
        $fid = (int) ($f['funcion_culto_id'] ?? 0);
        if ($fid <= 0) {
            continue;
        }
        $fruto = $f['fruto_cantidad'] ?? null;
        $deseadas[$fid] = ($fruto === null || $fruto === '') ? null : (int) $fruto;
    }
    $deseadasIds = array_keys($deseadas);

    // 1) Borra las que ya no se desean (acotado por $borrablesIds si se dio).
    $params = ['ac' => $asistenciaCultoId];
    $where  = 'asistencia_culto_id = :ac';
    $hacerBorrado = true;

    if ($borrablesIds !== null) {
        $scope = array_values(array_unique(array_map('intval', $borrablesIds)));
        if (!$scope) {
            $hacerBorrado = false; // nada borrable en alcance
        } else {
            $ph = [];
            $i = 0;
            foreach ($scope as $fid) {
                $ph[]             = ':s' . $i;
                $params['s' . $i] = $fid;
                $i++;
            }
            $where .= ' AND funcion_culto_id IN (' . implode(',', $ph) . ')';
        }
    }

    if ($hacerBorrado) {
        if ($deseadasIds) {
            $ph = [];
            $j = 0;
            foreach ($deseadasIds as $fid) {
                $ph[]             = ':d' . $j;
                $params['d' . $j] = $fid;
                $j++;
            }
            $where .= ' AND funcion_culto_id NOT IN (' . implode(',', $ph) . ')';
        }
        db()->prepare('DELETE FROM funciones_realizadas WHERE ' . $where)->execute($params);
    }

    // 2) Upsert de cada función deseada (inserta o actualiza el fruto).
    if (!$deseadas) {
        return;
    }
    $up = db()->prepare(
        'INSERT INTO funciones_realizadas (asistencia_culto_id, funcion_culto_id, fruto_cantidad)
         VALUES (:ac, :fc, :fr)
         ON CONFLICT (asistencia_culto_id, funcion_culto_id)
         DO UPDATE SET fruto_cantidad = EXCLUDED.fruto_cantidad'
    );
    foreach ($deseadas as $fid => $fruto) {
        $up->execute(['ac' => $asistenciaCultoId, 'fc' => $fid, 'fr' => $fruto]);
    }
}
