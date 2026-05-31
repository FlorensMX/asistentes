<?php
/**
 * public/logout.php
 *
 * Destruye la sesión y vuelve al login.
 */

declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

cerrarSesion();
header('Location: login.php');
exit;
