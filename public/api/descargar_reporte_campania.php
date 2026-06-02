<?php
/**
 * public/api/descargar_reporte_campania.php?campania_id=N
 *
 * Entrega el archivo del reporte de una campaña desde el almacenamiento
 * protegido (storage/, fuera de public/). GET de solo lectura.
 *
 * Permiso: requiere sesión y, además, ser pastor/admin O el dueño de la
 * campaña. El asistente_id se lee de la BD (reporteDeCampania), nunca del
 * cliente. La ruta se resuelve con directorioReportes() + basename() para que
 * sea imposible salir del directorio de reportes (path traversal).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/campanias_repo.php';
require_once __DIR__ . '/../../includes/reportes_campania.php';

$u = requerirLogin();   // sin sesión: redirige a login

$campaniaId = (int) ($_GET['campania_id'] ?? 0);
if ($campaniaId <= 0) {
    http_response_code(404);
    exit('Reporte no encontrado.');
}

$row = reporteDeCampania($campaniaId);
if (!$row) {
    http_response_code(404);
    exit('Reporte no encontrado.');
}

$esPastor = in_array($u['rol'], ['pastor', 'admin'], true);
$esDueno  = (int) $row['asistente_id'] === (int) $u['id'];
if (!$esPastor && !$esDueno) {
    http_response_code(403);
    exit('Acceso restringido.');
}

$ruta = $row['reporte_ruta'] ?? null;
if (!is_string($ruta) || $ruta === '') {
    http_response_code(404);
    exit('Esta campaña aún no tiene reporte.');
}

// basename() evita cualquier traversal aunque el valor en BD estuviese alterado.
$path = directorioReportes() . '/' . basename($ruta);
if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('El archivo del reporte no está disponible.');
}

$ext         = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
$contentType = REPORTE_CONTENT_TYPE[$ext] ?? 'application/octet-stream';

$nombre      = sanearNombreReporte((string) ($row['reporte_nombre'] ?? ('reporte.' . $ext)));
$nombreAscii = preg_replace('/[^\x20-\x7E]/', '_', $nombre) ?: ('reporte.' . $ext);

header('Content-Type: ' . $contentType);
header('Content-Disposition: attachment; filename="' . $nombreAscii . '"; '
     . "filename*=UTF-8''" . rawurlencode($nombre));
header('Content-Length: ' . (string) filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private');

readfile($path);
