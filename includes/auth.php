<?php
/**
 * includes/auth.php
 *
 * Autenticación basada en sesiones PHP nativas. Login por usuario (no email).
 *
 * Decisiones clave (heredadas de conferenciapastores):
 *  - session.cookie_path = BASE_PATH  → la cookie NO viaja a otras apps del dominio.
 *  - session.name        = ASIMINIST  → no choca con otras apps que usen PHPSESSID.
 *  - Secure + HttpOnly + SameSite=Lax → buenas prácticas mínimas.
 *  - session_regenerate_id(true) al login → mitiga session fixation.
 *  - CSRF token por sesión             → protege POSTs.
 *
 * Uso típico en cada página:
 *   require_once __DIR__ . '/../includes/auth.php';
 *   $usuario = requerirLogin();              // cualquier rol
 *   $usuario = requerirRol('admin');         // solo admin
 *   $usuario = requerirRol('pastor','admin'); // pastor o admin
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/** Subpath base del sistema en el dominio. Si se mueve, se cambia aquí. */
const BASE_PATH = '/apps/asistentes';

/**
 * Inicia sesión con parámetros scoped al subpath. Idempotente.
 */
function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('ASIMINIST');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH,
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/**
 * Intenta autenticar por usuario + contraseña.
 * Devuelve el asistente (sin hash) en éxito, null en fallo.
 * Side-effects en éxito: regenera id de sesión y llena $_SESSION.
 */
function intentarLogin(string $usuario, string $password): ?array
{
    $usuario = strtolower(trim($usuario));
    if ($usuario === '' || $password === '') return null;

    $stmt = db()->prepare(
        'SELECT id, nombre, usuario, password_hash, rol, activo
         FROM asistentes WHERE lower(usuario) = :u LIMIT 1'
    );
    $stmt->execute(['u' => $usuario]);
    $a = $stmt->fetch();

    if (!$a || !$a['activo']) return null;
    if (!password_verify($password, $a['password_hash'])) return null;

    iniciarSesion();
    session_regenerate_id(true);

    $_SESSION['asistente_id']     = (int) $a['id'];
    $_SESSION['asistente_nombre'] = $a['nombre'];
    $_SESSION['asistente_usuario']= $a['usuario'];
    $_SESSION['asistente_rol']    = $a['rol'];
    $_SESSION['login_at']         = time();

    unset($a['password_hash']);
    return $a;
}

/**
 * Devuelve el asistente actual desde la sesión, o null.
 */
function usuarioActual(): ?array
{
    iniciarSesion();
    if (empty($_SESSION['asistente_id'])) return null;
    return [
        'id'      => $_SESSION['asistente_id'],
        'nombre'  => $_SESSION['asistente_nombre'],
        'usuario' => $_SESSION['asistente_usuario'],
        'rol'     => $_SESSION['asistente_rol'],
    ];
}

/**
 * Guard para páginas protegidas. Si no hay sesión, redirige a login.
 */
function requerirLogin(): array
{
    $u = usuarioActual();
    if (!$u) {
        header('Location: ' . BASE_PATH . '/login.php');
        exit;
    }
    return $u;
}

/**
 * Guard por rol. Acepta uno o varios roles permitidos.
 *   requerirRol('admin')              → solo admin
 *   requerirRol('pastor', 'admin')    → pastor o admin
 */
function requerirRol(string ...$roles): array
{
    $u = requerirLogin();
    if (!in_array($u['rol'], $roles, true)) {
        http_response_code(403);
        exit('Acceso restringido.');
    }
    return $u;
}

/**
 * Destruye la sesión y borra la cookie.
 */
function cerrarSesion(): void
{
    iniciarSesion();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(
            session_name(), '', time() - 42000,
            $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']
        );
    }
    session_destroy();
}

/**
 * CSRF — un token por sesión, persistente hasta logout.
 */
function csrfToken(): string
{
    iniciarSesion();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfValidar(?string $token): bool
{
    iniciarSesion();
    return !empty($_SESSION['csrf'])
        && is_string($token)
        && hash_equals($_SESSION['csrf'], $token);
}

/**
 * Helper de escape — útil en todas las vistas.
 */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
