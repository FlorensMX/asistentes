<?php
/**
 * public/api/guardar_ministerio.php
 *
 * Alta o edición de un ministerio nombrado del asistente.
 *
 * POST: id (0=alta), nombre, categoria_id, observaciones (opcional)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/ministerios_repo.php';

$u = apiInit();

$id          = (int) ($_POST['id'] ?? 0);
$nombre      = trim((string) ($_POST['nombre'] ?? ''));
$categoriaId = (int) ($_POST['categoria_id'] ?? 0);
$obs         = (string) ($_POST['observaciones'] ?? '');

if ($nombre === '') {
    jsonError('El nombre del ministerio es obligatorio.');
}
if ($categoriaId <= 0) {
    jsonError('Selecciona una categoría.');
}

try {
    if ($id > 0) {
        if (!actualizarMinisterio($id, (int) $u['id'], $nombre, $categoriaId, $obs)) {
            jsonError('Ese ministerio no es tuyo o no existe.', 403);
        }
        jsonOk(['id' => $id, 'modo' => 'editado']);
    } else {
        $nuevo = crearMinisterio((int) $u['id'], $nombre, $categoriaId, $obs);
        jsonOk(['id' => $nuevo, 'modo' => 'creado']);
    }
} catch (PDOException $e) {
    // FK de categoría inexistente, etc.
    error_log('[api/guardar_ministerio] ' . $e->getMessage());
    jsonError('No se pudo guardar el ministerio (datos inválidos).');
} catch (Throwable $e) {
    error_log('[api/guardar_ministerio] ' . $e->getMessage());
    jsonError('No se pudo guardar el ministerio.', 500);
}
