<?php
/**
 * public/api/guardar_recurrente.php
 *
 * Alta o edición de un compromiso recurrente del asistente.
 *
 * POST: id (0=alta), nombre, categoria_id, dia_semana (0..6),
 *       hora_inicio (HH:MM), hora_fin (HH:MM), ministerio_id (opcional),
 *       vigente_desde (Y-m-d, opcional → hoy), vigente_hasta (Y-m-d, opcional)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/recurrentes_repo.php';
require_once __DIR__ . '/../../includes/ministerios_repo.php';

$u  = apiInit();
$id = (int) ($_POST['id'] ?? 0);

$nombre      = trim((string) ($_POST['nombre'] ?? ''));
$categoriaId = (int) ($_POST['categoria_id'] ?? 0);
$diaSemana   = (int) ($_POST['dia_semana'] ?? -1);
$horaInicio  = (string) ($_POST['hora_inicio'] ?? '');
$horaFin     = (string) ($_POST['hora_fin'] ?? '');
$ministerioId= postIntOpcional('ministerio_id');
$vigDesde    = (string) ($_POST['vigente_desde'] ?? '');
$vigHasta    = (string) ($_POST['vigente_hasta'] ?? '');

// --- validaciones ---
$reHora = '/^([01]\d|2[0-3]):[0-5]\d$/';
$reFecha = static function (string $f): bool {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
};

if ($nombre === '')                     jsonError('El nombre es obligatorio.');
if ($categoriaId <= 0)                  jsonError('Selecciona una categoría.');
if ($diaSemana < 0 || $diaSemana > 6)   jsonError('Día de la semana inválido.');
if (!preg_match($reHora, $horaInicio))  jsonError('Hora de inicio inválida.');
if (!preg_match($reHora, $horaFin))     jsonError('Hora de fin inválida.');
if ($horaFin <= $horaInicio)            jsonError('La hora de fin debe ser posterior a la de inicio.');

if ($vigDesde === '') {
    $vigDesde = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
} elseif (!$reFecha($vigDesde)) {
    jsonError('Fecha "vigente desde" inválida.');
}
$vigHastaVal = null;
if ($vigHasta !== '') {
    if (!$reFecha($vigHasta)) jsonError('Fecha "vigente hasta" inválida.');
    if ($vigHasta < $vigDesde) jsonError('"Vigente hasta" no puede ser anterior a "vigente desde".');
    $vigHastaVal = $vigHasta;
}

// El ministerio (si se indica) debe ser del propio asistente.
if ($ministerioId !== null && !ministerioEsDe($ministerioId, (int) $u['id'])) {
    jsonError('Ese ministerio no es tuyo.', 403);
}

try {
    if ($id > 0) {
        $okEdit = actualizarRecurrente(
            $id, (int) $u['id'], $nombre, $categoriaId, $diaSemana,
            $horaInicio, $horaFin, $ministerioId, $vigDesde, $vigHastaVal
        );
        if (!$okEdit) {
            jsonError('Ese compromiso no es tuyo o no existe.', 403);
        }
        jsonOk(['id' => $id, 'modo' => 'editado']);
    } else {
        $nuevo = crearRecurrente(
            (int) $u['id'], $nombre, $categoriaId, $diaSemana,
            $horaInicio, $horaFin, $ministerioId, $vigDesde, $vigHastaVal
        );
        jsonOk(['id' => $nuevo, 'modo' => 'creado']);
    }
} catch (PDOException $e) {
    error_log('[api/guardar_recurrente] ' . $e->getMessage());
    jsonError('No se pudo guardar el compromiso (datos inválidos).');
} catch (Throwable $e) {
    error_log('[api/guardar_recurrente] ' . $e->getMessage());
    jsonError('No se pudo guardar el compromiso.', 500);
}
