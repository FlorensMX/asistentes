<?php
/**
 * includes/api.php
 *
 * Utilidades comunes para los endpoints de public/api/*: siempre JSON,
 * exigen POST autenticado con CSRF válido. Reduce el boilerplate repetido.
 *
 * Uso típico:
 *   require_once __DIR__ . '/../../includes/api.php';
 *   $u = apiInit();                  // exige sesión + POST + CSRF; o corta con JSON
 *   ... lógica ...
 *   jsonOk(['id' => $nuevoId]);
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Emite JSON de éxito y termina. */
function jsonOk(array $datos = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true] + $datos);
    exit;
}

/** Emite JSON de error con código HTTP y termina. */
function jsonError(string $mensaje, int $http = 400): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $mensaje]);
    exit;
}

/**
 * Inicializa un endpoint: exige sesión, método POST y CSRF válido.
 * Devuelve el usuario actual. Corta con JSON en cualquier fallo.
 *
 * @param string[] $roles Si se pasa, restringe a esos roles.
 */
function apiInit(array $roles = []): array
{
    $u = usuarioActual();
    if (!$u) {
        jsonError('No autenticado.', 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError('Método no permitido.', 405);
    }
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        jsonError('Token CSRF inválido. Recarga la página.', 403);
    }
    if ($roles && !in_array($u['rol'], $roles, true)) {
        jsonError('Acceso restringido.', 403);
    }
    return $u;
}

/** Lee un entero opcional de $_POST; null si vacío/ausente. */
function postIntOpcional(string $clave): ?int
{
    $v = $_POST[$clave] ?? '';
    return ($v === '' || $v === null) ? null : (int) $v;
}
