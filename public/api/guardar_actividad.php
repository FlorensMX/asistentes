<?php
/**
 * public/api/guardar_actividad.php
 *
 * Alta de un registro de actividad variable del asistente.
 *
 * Duración: se acepta inicio/fin (HH:MM) o duración en minutos. Al menos una.
 * Fruto: solo se guarda si la actividad lo lleva (se valida contra el catálogo).
 *
 * POST: actividad_id, fecha (Y-m-d), hora_inicio?, hora_fin?, duracion_min?,
 *       fruto_cantidad?, ministerio_id?, proyecto_id?, nota?
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/actividad_repo.php';
require_once __DIR__ . '/../../includes/ministerios_repo.php';
require_once __DIR__ . '/../../includes/campanias_repo.php';

$u = apiInit();

$actividadId = (int) ($_POST['actividad_id'] ?? 0);
$fecha       = (string) ($_POST['fecha'] ?? '');
$horaInicio  = trim((string) ($_POST['hora_inicio'] ?? ''));
$horaFin     = trim((string) ($_POST['hora_fin'] ?? ''));
$duracionMin = trim((string) ($_POST['duracion_min'] ?? ''));
$frutoCant   = trim((string) ($_POST['fruto_cantidad'] ?? ''));
$ministerioId= postIntOpcional('ministerio_id');
$proyectoId  = postIntOpcional('proyecto_id');
$nota        = (string) ($_POST['nota'] ?? '');

$reHora = '/^([01]\d|2[0-3]):[0-5]\d$/';

if ($actividadId <= 0) jsonError('Selecciona una actividad.');

$d = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
if (!$d || $d->format('Y-m-d') !== $fecha) jsonError('Fecha inválida.');

// Suspensión por campaña (§10.1, cerrada: excluir). En días dentro de un periodo
// del asistente, la actividad variable NO cuenta como horas ni fruto: esos días
// se representan solo como días de misión. Se bloquea en captura para no perder
// silenciosamente el registro después en los agregados (mismo guard 409 que
// recurrentes/cultos). El agregado vuelve a excluir por si la campaña se declara
// después de capturar (fuente de verdad en reportes_repo.php).
if (estaEnCampania((int) $u['id'], $fecha)) {
    jsonError('Estás en campaña esos días; cuentan como días de misión, no como horas.', 409);
}

// La actividad debe existir y estar activa; tomamos lleva_fruto y nombre.
$stmt = db()->prepare(
    'SELECT nombre, lleva_fruto FROM actividades WHERE id = :id AND activo = TRUE'
);
$stmt->execute(['id' => $actividadId]);
$act = $stmt->fetch();
if (!$act) jsonError('Esa actividad no existe.');

// --- duración: inicio/fin o minutos (al menos una vía) ---
$horaInicioVal = null;
$horaFinVal    = null;
$duracionVal   = null;

if ($horaInicio !== '' || $horaFin !== '') {
    if (!preg_match($reHora, $horaInicio) || !preg_match($reHora, $horaFin)) {
        jsonError('Horas de inicio/fin inválidas.');
    }
    if ($horaFin <= $horaInicio) {
        jsonError('La hora de fin debe ser posterior a la de inicio.');
    }
    $horaInicioVal = $horaInicio;
    $horaFinVal    = $horaFin;
} elseif ($duracionMin !== '') {
    $dm = (int) $duracionMin;
    if ($dm <= 0 || $dm > 1440) jsonError('Duración en minutos inválida.');
    $duracionVal = $dm;
} else {
    jsonError('Indica la duración: inicio y fin, o minutos.');
}

// --- fruto: solo si la actividad lo lleva ---
$frutoVal = null;
if ($act['lleva_fruto'] && $frutoCant !== '') {
    $fc = (int) $frutoCant;
    if ($fc < 0) jsonError('La cantidad de fruto no puede ser negativa.');
    $frutoVal = $fc;
}

// --- ministerio: si se indica, debe ser del asistente ---
if ($ministerioId !== null && !ministerioEsDe($ministerioId, (int) $u['id'])) {
    jsonError('Ese ministerio no es tuyo.', 403);
}

try {
    $nuevo = crearRegistroActividad((int) $u['id'], [
        'actividad_id'   => $actividadId,
        'fecha'          => $fecha,
        'hora_inicio'    => $horaInicioVal,
        'hora_fin'       => $horaFinVal,
        'duracion_min'   => $duracionVal,
        'fruto_cantidad' => $frutoVal,
        'ministerio_id'  => $ministerioId,
        'proyecto_id'    => $proyectoId,
        'nota'           => $nota,
    ]);
    jsonOk(['id' => $nuevo]);
} catch (PDOException $e) {
    error_log('[api/guardar_actividad] ' . $e->getMessage());
    jsonError('No se pudo guardar la actividad (datos inválidos).');
} catch (Throwable $e) {
    error_log('[api/guardar_actividad] ' . $e->getMessage());
    jsonError('No se pudo guardar la actividad.', 500);
}
