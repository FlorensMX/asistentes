<?php
/**
 * public/api/foto.php
 *
 * Sube/reemplaza la foto familiar del asistente autenticado (multipart).
 * Responde SIEMPRE JSON. El asistente solo puede tocar su propia foto.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/fotos.php';
require_once __DIR__ . '/../../includes/asistentes_repo.php';

header('Content-Type: application/json; charset=utf-8');

$u = usuarioActual();
if (!$u) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'No autenticado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}

if (!csrfValidar($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token CSRF inválido. Recarga la página.']);
    exit;
}

$archivo = $_FILES['foto'] ?? null;
if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No se recibió ninguna foto.']);
    exit;
}

$res = guardarFotoAsistente($archivo, (int) $u['id']);
if (!$res['ok'] || $res['ruta_relativa'] === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'No se pudo guardar la foto.']);
    exit;
}

actualizarFotoUrl((int) $u['id'], $res['ruta_relativa']);

echo json_encode(['ok' => true, 'url' => $res['ruta_relativa']]);
