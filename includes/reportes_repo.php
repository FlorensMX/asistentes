<?php
/**
 * includes/reportes_repo.php
 *
 * DAL SOLO-LECTURA: agregados para los dashboards (Fase 4). No escribe nada,
 * no toca catálogos, no cambia el esquema.
 *
 * Reglas de agregación (sección 6 del brief, NO reinterpretar):
 *  - Horas ministeriales = recurrentes confirmados + actividad variable + cultos,
 *    todo en horas. Los cultos NO tienen categoría: van en su bucket "Cultos".
 *  - SUSPENSIÓN por campaña: una fila de recurrente, de culto o de actividad
 *    variable NO cuenta si su fecha cae dentro de un periodo_campania del mismo
 *    asistente (patrón NOT EXISTS), consistente con campanias_repo::diasSuspendidos().
 *    La actividad variable también se EXCLUYE en días de campaña (decisión §10.1,
 *    cerrada: excluir): un registro de actividad no guarda dónde ocurrió, así que
 *    en campaña se atribuiría como local y corrompería el total de horas; por eso
 *    esos días valen solo como días de misión (+ su fruto declarado y su reporte).
 *  - Campañas = DÍAS DE MISIÓN (se reusa campanias_repo::diasDeMision()); nunca
 *    se convierten a horas.
 *  - Trabajo secular = carga semanal declarada; se muestra aparte, NO se suma.
 *  - Frutos solo donde lleva_fruto=TRUE, con su etiqueta.
 *  - Cero dinero. Informa, no juzga.
 *
 * Nota PDO (EMULATE_PREPARES=false): no se reusa un placeholder nombrado dos
 * veces en una misma consulta; las subconsultas de campaña referencian columnas,
 * no :ini/:fin de nuevo.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/semana.php';            // tzApp()
require_once __DIR__ . '/catalogos_repo.php';    // listarCategorias()
require_once __DIR__ . '/campanias_repo.php';    // diasDeMision(), campaniasDeAsistente()
require_once __DIR__ . '/asistentes_repo.php';   // listarAsistentes(), asistentePorId()

// ===========================================================================
// Utilidades
// ===========================================================================

/** Límites de un mes 'YYYY-MM' → ['ini' => 'Y-m-01', 'fin' => 'Y-m-t']. */
function mesLimites(string $ym): array
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
    if ($d === false) {
        // Fallback: mes actual en hora de México.
        $d = (new DateTimeImmutable('now', tzApp()))->modify('first day of this month');
    }
    return [
        'ini' => $d->format('Y-m-01'),
        'fin' => $d->format('Y-m-t'),
    ];
}

/** Mes actual 'YYYY-MM' en hora de México. */
function mesActual(): string
{
    return (new DateTimeImmutable('now', tzApp()))->format('Y-m');
}

/** Nombres de ministerios del asistente (activos e inactivos): id => nombre. */
function ministerioNombres(int $aid): array
{
    $stmt = db()->prepare('SELECT id, nombre FROM ministerios WHERE asistente_id = :a');
    $stmt->execute(['a' => $aid]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['id']] = $r['nombre'];
    }
    return $out;
}

// ===========================================================================
// Filas crudas de horas (se agregan en PHP por categoría y por ministerio)
// ===========================================================================

/** Recurrentes confirmados del mes, excluyendo días de campaña. */
function _recurrentesHorasRaw(int $aid, string $ini, string $fin): array
{
    $stmt = db()->prepare(
        'SELECT comp.categoria_id, comp.ministerio_id,
                EXTRACT(EPOCH FROM (comp.hora_fin - comp.hora_inicio)) / 3600.0 AS horas
         FROM confirmaciones_recurrente cr
         JOIN compromisos_recurrentes comp ON comp.id = cr.compromiso_id
         WHERE comp.asistente_id = :aid
           AND cr.confirmado = TRUE
           AND cr.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = comp.asistente_id
                             AND cr.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)'
    );
    $stmt->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);
    return $stmt->fetchAll();
}

/** Actividad variable del mes, excluyendo días de campaña (§10.1). */
function _actividadHorasRaw(int $aid, string $ini, string $fin): array
{
    $stmt = db()->prepare(
        'SELECT act.categoria_id, ra.ministerio_id,
                COALESCE(EXTRACT(EPOCH FROM (ra.hora_fin - ra.hora_inicio)) / 3600.0,
                         ra.duracion_min / 60.0) AS horas
         FROM registros_actividad ra
         JOIN actividades act ON act.id = ra.actividad_id
         WHERE ra.asistente_id = :aid AND ra.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ra.asistente_id
                             AND ra.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)'
    );
    $stmt->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);
    return $stmt->fetchAll();
}

/**
 * Asistencia a cultos del mes, excluyendo días de campaña. Por culto: número
 * de asistencias y horas (la Junta usa hora_salida; un fin_variable sin
 * hora_salida deja horas NULL → cuenta como asistencia, 0/desconocido en horas).
 */
function _cultosResumen(int $aid, string $ini, string $fin): array
{
    $stmt = db()->prepare(
        'SELECT cu.id AS culto_id, cu.nombre,
                COUNT(*) AS asistencias,
                SUM((EXTRACT(EPOCH FROM (COALESCE(ac.hora_salida, cu.hora_fin) - cu.hora_inicio)) + CASE WHEN COALESCE(ac.hora_salida, cu.hora_fin) < cu.hora_inicio THEN 86400 ELSE 0 END) / 3600.0) AS horas
         FROM asistencia_culto ac
         JOIN cultos cu ON cu.id = ac.culto_id
         WHERE ac.asistente_id = :aid
           AND ac.asistio = TRUE
           AND ac.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ac.asistente_id
                             AND ac.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY cu.id, cu.nombre
         ORDER BY horas DESC NULLS LAST, cu.nombre'
    );
    $stmt->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);

    $items = [];
    $horasTotal = 0.0;
    $asisTotal  = 0;
    foreach ($stmt->fetchAll() as $r) {
        $horas = $r['horas'] !== null ? (float) $r['horas'] : null;
        $items[] = [
            'culto_id'    => (int) $r['culto_id'],
            'nombre'      => $r['nombre'],
            'asistencias' => (int) $r['asistencias'],
            'horas'       => $horas !== null ? round($horas, 2) : null,
        ];
        if ($horas !== null) $horasTotal += $horas;
        $asisTotal += (int) $r['asistencias'];
    }
    return [
        'items'             => $items,
        'horas_total'       => round($horasTotal, 2),
        'asistencias_total' => $asisTotal,
    ];
}

/** Frutos del mes (actividad + funciones de culto), unidos por etiqueta. */
function _frutosMes(int $aid, string $ini, string $fin): array
{
    // (a) de actividad variable (excl. campaña, §10.1: en campaña no cuenta el fruto)
    $a = db()->prepare(
        'SELECT act.etiqueta_fruto AS etiqueta, SUM(ra.fruto_cantidad) AS total
         FROM registros_actividad ra JOIN actividades act ON act.id = ra.actividad_id
         WHERE ra.asistente_id = :aid AND ra.fecha BETWEEN :ini AND :fin
           AND act.lleva_fruto = TRUE AND ra.fruto_cantidad IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ra.asistente_id
                             AND ra.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY act.etiqueta_fruto'
    );
    $a->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);

    // (b) de funciones en culto (mismo filtro de campaña que la asistencia)
    $b = db()->prepare(
        'SELECT fc.etiqueta_fruto AS etiqueta, SUM(fr.fruto_cantidad) AS total
         FROM funciones_realizadas fr
         JOIN funciones_culto fc ON fc.id = fr.funcion_culto_id
         JOIN asistencia_culto ac ON ac.id = fr.asistencia_culto_id
         WHERE ac.asistente_id = :aid AND ac.fecha BETWEEN :ini AND :fin
           AND fc.lleva_fruto = TRUE AND fr.fruto_cantidad IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ac.asistente_id
                             AND ac.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY fc.etiqueta_fruto'
    );
    $b->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);

    // Se fusionan por etiqueta (p. ej. "decisiones" de actividad y de culto se
    // suman). Solo el respaldo de etiqueta NULL distingue el origen, para no
    // colapsar frutos de distinto significado bajo una etiqueta genérica.
    $merged = [];
    foreach ([['set' => $a->fetchAll(), 'def' => 'fruto (actividad)'],
              ['set' => $b->fetchAll(), 'def' => 'fruto (culto)']] as $grupo) {
        foreach ($grupo['set'] as $r) {
            $etq = ($r['etiqueta'] !== null && $r['etiqueta'] !== '') ? $r['etiqueta'] : $grupo['def'];
            $merged[$etq] = ($merged[$etq] ?? 0) + (int) $r['total'];
        }
    }
    $out = [];
    foreach ($merged as $etq => $total) {
        $out[] = ['etiqueta' => $etq, 'total' => $total];
    }
    return $out;
}

/** Fruto declarado en campañas que solapan el mes (decisiones del periodo). */
function _frutoCampanias(int $aid, string $ini, string $fin): ?int
{
    $stmt = db()->prepare(
        'SELECT SUM(fruto_cantidad) AS total
         FROM periodos_campania
         WHERE asistente_id = :aid AND fruto_cantidad IS NOT NULL
           AND fecha_inicio <= :fin AND fecha_fin >= :ini'
    );
    $stmt->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);
    $t = $stmt->fetchColumn();
    return $t === null || $t === false ? null : (int) $t;
}

// ===========================================================================
// API pública del repo
// ===========================================================================

/**
 * Resumen del mes de un asistente: horas por categoría, por ministerio, cultos,
 * días de misión y frutos. Lo consumen mi_dashboard.php y detalle.php.
 */
function resumenMesAsistente(int $aid, string $ini, string $fin): array
{
    $rec = _recurrentesHorasRaw($aid, $ini, $fin);
    $act = _actividadHorasRaw($aid, $ini, $fin);

    // Agregación por categoría y por ministerio (recurrentes + actividad).
    $porCategoria = [];   // categoria_id => horas
    $porMinisterio = [];  // ministerio_id => horas
    foreach ([$rec, $act] as $set) {
        foreach ($set as $r) {
            if ($r['horas'] === null) continue;
            $h = (float) $r['horas'];
            if ($r['categoria_id'] !== null) {
                $cid = (int) $r['categoria_id'];
                $porCategoria[$cid] = ($porCategoria[$cid] ?? 0) + $h;
            }
            if ($r['ministerio_id'] !== null) {
                $mid = (int) $r['ministerio_id'];
                $porMinisterio[$mid] = ($porMinisterio[$mid] ?? 0) + $h;
            }
        }
    }

    // Nombres de categoría (ordenadas) y de ministerio.
    $horasPorCategoria = [];
    foreach (listarCategorias() as $c) {
        $cid = (int) $c['id'];
        if (!isset($porCategoria[$cid])) continue;
        $horasPorCategoria[] = [
            'categoria_id' => $cid,
            'categoria'    => $c['nombre'],
            'horas'        => round($porCategoria[$cid], 2),
        ];
    }

    $minNombres = ministerioNombres($aid);
    $horasPorMinisterio = [];
    foreach ($porMinisterio as $mid => $h) {
        $horasPorMinisterio[] = [
            'ministerio_id' => $mid,
            'ministerio'    => $minNombres[$mid] ?? ('Ministerio #' . $mid),
            'horas'         => round($h, 2),
        ];
    }
    usort($horasPorMinisterio, static fn($a, $b) => $b['horas'] <=> $a['horas']);

    $cultos = _cultosResumen($aid, $ini, $fin);

    $horasCategorias = array_sum($porCategoria);
    $horasCultos     = (float) $cultos['horas_total'];

    return [
        'horas_por_categoria'  => $horasPorCategoria,
        'horas_por_ministerio' => $horasPorMinisterio,
        'cultos'               => $cultos,
        'horas_categorias'     => round($horasCategorias, 2),
        'horas_cultos'         => round($horasCultos, 2),
        'horas_total'          => round($horasCategorias + $horasCultos, 2),
        'dias_mision'          => diasDeMision($aid, $ini, $fin),
        'frutos'               => _frutosMes($aid, $ini, $fin),
        'fruto_campanias'      => _frutoCampanias($aid, $ini, $fin),
    ];
}

/**
 * Horas ministeriales por mes en los últimos $nMeses, terminando en $hastaYm
 * (o el mes actual). Misma exclusión por campaña que el resumen.
 * @return array<int, array{mes:string, horas:float}>
 */
function tendenciaMensual(int $aid, int $nMeses = 6, ?string $hastaYm = null): array
{
    $nMeses = max(1, min(24, $nMeses));
    $ancla = $hastaYm !== null
        ? DateTimeImmutable::createFromFormat('Y-m-d', $hastaYm . '-01')
        : null;
    if ($ancla === false || $ancla === null) {
        $ancla = (new DateTimeImmutable('now', tzApp()));
    }
    $ancla   = $ancla->modify('first day of this month')->setTime(0, 0, 0);
    $inicio  = $ancla->modify('-' . ($nMeses - 1) . ' months');
    $ini     = $inicio->format('Y-m-01');
    $fin     = $ancla->format('Y-m-t');

    // Acumulador ordenado de los N meses.
    $meses = [];
    for ($i = 0; $i < $nMeses; $i++) {
        $meses[$inicio->modify("+{$i} months")->format('Y-m')] = 0.0;
    }

    // Recurrentes por mes (excl. campaña).
    $q1 = db()->prepare(
        "SELECT to_char(date_trunc('month', cr.fecha), 'YYYY-MM') AS mes,
                SUM(EXTRACT(EPOCH FROM (comp.hora_fin - comp.hora_inicio)) / 3600.0) AS horas
         FROM confirmaciones_recurrente cr
         JOIN compromisos_recurrentes comp ON comp.id = cr.compromiso_id
         WHERE comp.asistente_id = :aid AND cr.confirmado = TRUE
           AND cr.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = comp.asistente_id
                             AND cr.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY 1"
    );
    $q1->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);

    // Actividad variable por mes (excl. campaña, §10.1).
    $q2 = db()->prepare(
        "SELECT to_char(date_trunc('month', ra.fecha), 'YYYY-MM') AS mes,
                SUM(COALESCE(EXTRACT(EPOCH FROM (ra.hora_fin - ra.hora_inicio)) / 3600.0,
                             ra.duracion_min / 60.0)) AS horas
         FROM registros_actividad ra
         WHERE ra.asistente_id = :aid AND ra.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ra.asistente_id
                             AND ra.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY 1"
    );
    $q2->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);

    // Cultos por mes (excl. campaña).
    $q3 = db()->prepare(
        "SELECT to_char(date_trunc('month', ac.fecha), 'YYYY-MM') AS mes,
                SUM((EXTRACT(EPOCH FROM (COALESCE(ac.hora_salida, cu.hora_fin) - cu.hora_inicio)) + CASE WHEN COALESCE(ac.hora_salida, cu.hora_fin) < cu.hora_inicio THEN 86400 ELSE 0 END) / 3600.0) AS horas
         FROM asistencia_culto ac
         JOIN cultos cu ON cu.id = ac.culto_id
         WHERE ac.asistente_id = :aid AND ac.asistio = TRUE
           AND ac.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ac.asistente_id
                             AND ac.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY 1"
    );
    $q3->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);

    foreach ([$q1->fetchAll(), $q2->fetchAll(), $q3->fetchAll()] as $set) {
        foreach ($set as $r) {
            $m = (string) $r['mes'];
            if (array_key_exists($m, $meses) && $r['horas'] !== null) {
                $meses[$m] += (float) $r['horas'];
            }
        }
    }

    $out = [];
    foreach ($meses as $m => $h) {
        $out[] = ['mes' => $m, 'horas' => round($h, 2)];
    }
    return $out;
}

/**
 * Una fila por asistente ACTIVO: horas ministeriales, secular declarado
 * (h/sem), asistencias a culto (excl. campaña) y días de misión.
 * Lo consume consolidado.php.
 */
function consolidadoMes(string $ini, string $fin): array
{
    // Horas de recurrentes por asistente (excl. campaña).
    $qr = db()->prepare(
        'SELECT comp.asistente_id AS aid,
                SUM(EXTRACT(EPOCH FROM (comp.hora_fin - comp.hora_inicio)) / 3600.0) AS horas
         FROM confirmaciones_recurrente cr
         JOIN compromisos_recurrentes comp ON comp.id = cr.compromiso_id
         WHERE cr.confirmado = TRUE AND cr.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = comp.asistente_id
                             AND cr.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY comp.asistente_id'
    );
    $qr->execute(['ini' => $ini, 'fin' => $fin]);
    $recur = [];
    foreach ($qr->fetchAll() as $r) $recur[(int) $r['aid']] = (float) $r['horas'];

    // Horas de actividad variable por asistente (excl. campaña, §10.1).
    $qa = db()->prepare(
        'SELECT ra.asistente_id AS aid,
                SUM(COALESCE(EXTRACT(EPOCH FROM (ra.hora_fin - ra.hora_inicio)) / 3600.0,
                             ra.duracion_min / 60.0)) AS horas
         FROM registros_actividad ra
         WHERE ra.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ra.asistente_id
                             AND ra.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY ra.asistente_id'
    );
    $qa->execute(['ini' => $ini, 'fin' => $fin]);
    $activ = [];
    foreach ($qa->fetchAll() as $r) $activ[(int) $r['aid']] = (float) ($r['horas'] ?? 0);

    // Horas y asistencias de cultos por asistente (excl. campaña).
    $qc = db()->prepare(
        'SELECT ac.asistente_id AS aid, COUNT(*) AS asistencias,
                SUM((EXTRACT(EPOCH FROM (COALESCE(ac.hora_salida, cu.hora_fin) - cu.hora_inicio)) + CASE WHEN COALESCE(ac.hora_salida, cu.hora_fin) < cu.hora_inicio THEN 86400 ELSE 0 END) / 3600.0) AS horas
         FROM asistencia_culto ac JOIN cultos cu ON cu.id = ac.culto_id
         WHERE ac.asistio = TRUE AND ac.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ac.asistente_id
                             AND ac.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY ac.asistente_id'
    );
    $qc->execute(['ini' => $ini, 'fin' => $fin]);
    $cultoH = [];
    $cultoA = [];
    foreach ($qc->fetchAll() as $r) {
        $cultoH[(int) $r['aid']] = (float) ($r['horas'] ?? 0);
        $cultoA[(int) $r['aid']] = (int) $r['asistencias'];
    }

    // Secular declarado (h/sem) por asistente.
    $qs = db()->query(
        'SELECT asistente_id AS aid, SUM(horas_semana) AS h
         FROM trabajo_secular WHERE activo = TRUE GROUP BY asistente_id'
    );
    $secular = [];
    foreach ($qs->fetchAll() as $r) $secular[(int) $r['aid']] = (float) $r['h'];

    $filas = [];
    foreach (listarAsistentes(true) as $a) {
        $id = (int) $a['id'];
        $horasMin = ($recur[$id] ?? 0) + ($activ[$id] ?? 0) + ($cultoH[$id] ?? 0);
        $filas[] = [
            'asistente_id'      => $id,
            'nombre'            => $a['nombre'],
            'horas_ministerial' => round($horasMin, 2),
            'secular_h_sem'     => isset($secular[$id]) ? round($secular[$id], 1) : null,
            'asistencias_culto' => $cultoA[$id] ?? 0,
            'dias_mision'       => diasDeMision($id, $ini, $fin),
        ];
    }
    return $filas;
}

/** Funciones desempeñadas en cultos del mes (excl. campaña), con conteo y fruto. */
function _funcionesResumen(int $aid, string $ini, string $fin): array
{
    $stmt = db()->prepare(
        'SELECT fc.nombre, fc.grupo, fc.etiqueta_fruto,
                COUNT(*) AS veces, SUM(fr.fruto_cantidad) AS fruto
         FROM funciones_realizadas fr
         JOIN funciones_culto fc ON fc.id = fr.funcion_culto_id
         JOIN asistencia_culto ac ON ac.id = fr.asistencia_culto_id
         WHERE ac.asistente_id = :aid AND ac.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ac.asistente_id
                             AND ac.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY fc.nombre, fc.grupo, fc.etiqueta_fruto
         ORDER BY fc.grupo, veces DESC, fc.nombre'
    );
    $stmt->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'nombre'   => $r['nombre'],
            'grupo'    => $r['grupo'],
            'veces'    => (int) $r['veces'],
            'fruto'    => $r['fruto'] !== null ? (int) $r['fruto'] : null,
            'etiqueta' => $r['etiqueta_fruto'],
        ];
    }
    return $out;
}

/**
 * Proyectos tocados por la actividad del mes, con observaciones y horas.
 * Excluye los días de campaña (§10.1): si la actividad de un día en campaña ya
 * no cuenta como hora, sus horas de proyecto tampoco, para que el detalle
 * reconcilie con el resumen.
 */
function _proyectosTocados(int $aid, string $ini, string $fin): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.nombre, p.observaciones,
                COUNT(*) AS registros,
                SUM(COALESCE(EXTRACT(EPOCH FROM (ra.hora_fin - ra.hora_inicio)) / 3600.0,
                             ra.duracion_min / 60.0)) AS horas
         FROM registros_actividad ra
         JOIN proyectos p ON p.id = ra.proyecto_id
         WHERE ra.asistente_id = :aid AND ra.fecha BETWEEN :ini AND :fin
           AND NOT EXISTS (SELECT 1 FROM periodos_campania pc
                           WHERE pc.asistente_id = ra.asistente_id
                             AND ra.fecha BETWEEN pc.fecha_inicio AND pc.fecha_fin)
         GROUP BY p.id, p.nombre, p.observaciones
         ORDER BY horas DESC NULLS LAST, p.nombre'
    );
    $stmt->execute(['aid' => $aid, 'ini' => $ini, 'fin' => $fin]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[] = [
            'id'            => (int) $r['id'],
            'nombre'        => $r['nombre'],
            'observaciones' => $r['observaciones'],
            'registros'     => (int) $r['registros'],
            'horas'         => $r['horas'] !== null ? round((float) $r['horas'], 2) : null,
        ];
    }
    return $out;
}

/**
 * Detalle por asistente para el Pastor: datos + composición por categoría y
 * ministerio, funciones en cultos, proyectos (con observaciones), campañas del
 * mes (días de misión) y tendencia mensual.
 */
function detalleAsistente(int $aid, string $ini, string $fin): array
{
    return [
        'asistente'  => asistentePorId($aid),
        'resumen'    => resumenMesAsistente($aid, $ini, $fin),
        'funciones'  => _funcionesResumen($aid, $ini, $fin),
        'proyectos'  => _proyectosTocados($aid, $ini, $fin),
        'campanias'  => campaniasDeAsistente($aid, $ini, $fin),
        'tendencia'  => tendenciaMensual($aid, 6, substr($ini, 0, 7)),
    ];
}
