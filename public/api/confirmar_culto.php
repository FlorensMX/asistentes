<?php
/**
 * public/api/confirmar_culto.php
 *
 * Confirma o desmarca la asistencia del asistente a un culto en una fecha
 * (SIN funciones). Endpoint heredado de Fase 2: "Mi semana" ya usa
 * api/guardar_funcion_culto.php (que además marca funciones), pero este se
 * mantiene alineado con la misma semántica para no dejar un camino divergente:
 *   - asistio=true  → upsert de asistencia (hora_salida solo si fin_variable),
 *   - asistio=false → se BORRA la fila (la cascada limpia sus funciones).
 *
 * POST: culto_id, fecha (Y-m-d), asistio (1|0), hora_salida (opcional, HH:MM)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/cultos_repo.php';
require_once __DIR__ . '/../../includes/campanias_repo.php';

$u   = apiInit();
$uid = (int) $u['id'];

$cultoId    = (int) ($_POST['culto_id'] ?? 0);
$fecha      = (string) ($_POST['fecha'] ?? '');
$asistio    = ((string) ($_POST['asistio'] ?? '1')) === '1';
$horaSalida = trim((string) ($_POST['hora_salida'] ?? ''));

if ($cultoId <= 0) {
    jsonError('Culto inválido.');
}
$d = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
if (!$d || $d->format('Y-m-d') !== $fecha) {
    jsonError('Fecha inválida.');
}

// El culto debe existir y estar activo.
$stmt = db()->prepare('SELECT dia_semana, fin_variable FROM cultos WHERE id = :id AND activo = TRUE');
$stmt->execute(['id' => $cultoId]);
$culto = $stmt->fetch();
if (!$culto) {
    jsonError('Ese culto no existe o está inactivo.');
}
if ((int) $d->format('w') !== (int) $culto['dia_semana']) {
    jsonError('La fecha no corresponde al día de este culto.');
}
if ($asistio && estaEnCampania($uid, $fecha)) {
    jsonError('Ese día está en campaña (suspendido): sus cultos no se piden ni cuentan.', 409);
}

// hora_salida solo aplica a cultos de fin variable (Junta de Asistentes).
$horaSalidaVal = null;
if ((bool) $culto['fin_variable'] && $horaSalida !== '') {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaSalida)) {
        jsonError('Hora de salida inválida.');
    }
    $horaSalidaVal = $horaSalida;
}

try {
    if ($asistio) {
        confirmarAsistenciaCulto($uid, $cultoId, $fecha, true, $horaSalidaVal, $horaSalidaVal !== null);
    } else {
        quitarAsistencia($uid, $cultoId, $fecha);
    }
} catch (Throwable $e) {
    error_log('[api/confirmar_culto] ' . $e->getMessage());
    jsonError('No se pudo guardar la asistencia.', 500);
}

jsonOk(['asistio' => $asistio]);
