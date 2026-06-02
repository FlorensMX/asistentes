<?php
/**
 * public/api/subir_reporte_campania.php
 *
 * Sube/reemplaza el reporte de resultados de UNA campaña del asistente
 * autenticado (multipart). Responde SIEMPRE JSON.
 *
 * Seguridad: solo el dueño de la campaña puede subir su reporte. La pertenencia
 * se verifica contra periodos_campania.asistente_id (vía campaniaPorId), nunca
 * se confía en un asistente_id del cliente. CSRF como el resto de escrituras.
 * El archivo se guarda FUERA de public/ (storage/), un reporte por campaña:
 * re-subir reemplaza y borra el anterior.
 *
 * POST (multipart/form-data): csrf, campania_id, reporte (archivo)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/campanias_repo.php';
require_once __DIR__ . '/../../includes/reportes_campania.php';

$u   = apiInit();
$uid = (int) $u['id'];

$campaniaId = (int) ($_POST['campania_id'] ?? 0);
if ($campaniaId <= 0) {
    jsonError('Campaña inválida.');
}

// Pertenencia: la campaña debe existir y ser del asistente autenticado.
$camp = campaniaPorId($campaniaId, $uid);
if (!$camp) {
    jsonError('Esa campaña no es tuya.', 403);
}

$archivo = $_FILES['reporte'] ?? null;
if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    jsonError('No se recibió ningún archivo.');
}

$res = guardarReporteCampania($archivo, $campaniaId);
if (!$res['ok'] || $res['archivo'] === null) {
    jsonError($res['error'] ?? 'No se pudo guardar el reporte.');
}

$nombreOriginal = sanearNombreReporte((string) ($archivo['name'] ?? 'reporte'));
$previo         = $camp['reporte_ruta'] ?? null;

try {
    if (!guardarRutaReporte($campaniaId, $uid, $res['archivo'], $nombreOriginal)) {
        // No debería pasar (ya validamos pertenencia), pero si la fila no se
        // actualizó, no dejamos el archivo recién escrito huérfano.
        eliminarArchivoReporte($res['archivo']);
        jsonError('No se pudo registrar el reporte.', 500);
    }
} catch (Throwable $e) {
    error_log('[api/subir_reporte_campania] ' . $e->getMessage());
    eliminarArchivoReporte($res['archivo']);
    jsonError('No se pudo registrar el reporte.', 500);
}

// Reemplazo: borra el archivo anterior (si lo había y es distinto al nuevo).
if (is_string($previo) && $previo !== '' && $previo !== $res['archivo']) {
    eliminarArchivoReporte($previo);
}

// La vista recarga tras el éxito y re-renderiza el estado autoritativo
// (reporte_subido_en) desde la BD, que vive en hora de México.
jsonOk(['nombre' => $nombreOriginal]);
