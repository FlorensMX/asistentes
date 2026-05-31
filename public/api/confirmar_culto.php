<?php
/**
 * public/api/confirmar_culto.php
 *
 * Confirma o desmarca la asistencia del asistente a un culto en una fecha.
 *
 * ALCANCE FASE 2: solo asistencia. Las funciones desempeñadas en el culto
 * se agregan en Fase 3 (api/guardar_funcion_culto.php).
 *
 * POST: culto_id, fecha (Y-m-d), asistio (1|0), hora_salida (opcional, HH:MM)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/cultos_repo.php';

$u = apiInit();

$cultoId    = (int) ($_POST['culto_id'] ?? 0);
$fecha      = (string) ($_POST['fecha'] ?? '');
$asistio    = ((string) ($_POST['asistio'] ?? '1')) === '1';
$horaSalida = (string) ($_POST['hora_salida'] ?? '');

if ($cultoId <= 0) {
    jsonError('Culto inválido.');
}
$d = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
if (!$d || $d->format('Y-m-d') !== $fecha) {
    jsonError('Fecha inválida.');
}
if ($horaSalida !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaSalida)) {
    jsonError('Hora de salida inválida.');
}

try {
    confirmarAsistenciaCulto((int) $u['id'], $cultoId, $fecha, $asistio, $horaSalida);
} catch (Throwable $e) {
    error_log('[api/confirmar_culto] ' . $e->getMessage());
    jsonError('No se pudo guardar la asistencia.', 500);
}

jsonOk(['asistio' => $asistio]);
