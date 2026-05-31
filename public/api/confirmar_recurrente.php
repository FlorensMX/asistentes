<?php
/**
 * public/api/confirmar_recurrente.php
 *
 * Confirma o desmarca UNA ocurrencia (fecha) de un compromiso recurrente.
 * Valida que el compromiso pertenezca al asistente autenticado.
 *
 * POST: compromiso_id, fecha (Y-m-d), confirmado (1|0), nota (opcional)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/recurrentes_repo.php';

$u = apiInit();

$compromisoId = (int) ($_POST['compromiso_id'] ?? 0);
$fecha        = (string) ($_POST['fecha'] ?? '');
$confirmado   = ((string) ($_POST['confirmado'] ?? '1')) === '1';
$nota         = (string) ($_POST['nota'] ?? '');

if ($compromisoId <= 0) {
    jsonError('Compromiso inválido.');
}
$d = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
if (!$d || $d->format('Y-m-d') !== $fecha) {
    jsonError('Fecha inválida.');
}
if (!recurrenteEsDe($compromisoId, (int) $u['id'])) {
    jsonError('Ese compromiso no es tuyo.', 403);
}

try {
    confirmarRecurrente($compromisoId, $fecha, $confirmado, $nota);
} catch (Throwable $e) {
    error_log('[api/confirmar_recurrente] ' . $e->getMessage());
    jsonError('No se pudo guardar la confirmación.', 500);
}

jsonOk(['confirmado' => $confirmado]);
