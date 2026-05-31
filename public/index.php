<?php
/**
 * public/index.php
 *
 * Dispatcher: según sesión y rol, manda a la "casa" de cada quien.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

$u = usuarioActual();
if (!$u) {
    header('Location: login.php');
    exit;
}

switch ($u['rol']) {
    case 'admin':
        header('Location: admin_asistentes.php');
        break;
    case 'pastor':
        // El consolidado del Pastor llega en Fase 4; por ahora a su perfil.
        header('Location: perfil.php');
        break;
    default: // asistente
        header('Location: semana.php');
}
exit;
