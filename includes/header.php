<?php
/**
 * includes/header.php
 *
 * Partial de header compartido por las vistas autenticadas. Mobile-first.
 *
 * Precondiciones (el archivo que lo incluye debe garantizarlas):
 *   - Ya llamó a requerirLogin()/requerirRol() y tiene $usuario en scope.
 *   - Opcionalmente define:
 *       $tituloPagina (string)
 *       $anchoMax     (string clase Tailwind; default 'max-w-2xl' mobile-first)
 *       $navActiva    (string clave de la pestaña activa de la barra inferior)
 *       $csrfMeta     (string token CSRF para exponer en <meta>)
 *
 * NO se incluye en login.php — esa vista es standalone.
 */

declare(strict_types=1);

$tituloPagina = $tituloPagina ?? '';
$anchoMax     = $anchoMax     ?? 'max-w-2xl';
$navActiva    = $navActiva    ?? '';
$titulo       = ($tituloPagina !== '' ? $tituloPagina . ' · ' : '') . 'Gestión Ministerial';

$rol        = $usuario['rol'];
$badgeColor = $rol === 'admin' ? 'bg-slate-700' : ($rol === 'pastor' ? 'bg-indigo-600' : 'bg-emerald-600');

// Barra inferior: una pestaña por sección principal según rol.
// (Las secciones de Fase 2+ se irán habilitando conforme existan sus páginas.)
$navAsistente = [
    ['semana',      'Semana',     'semana.php'],
    ['actividad',   'Registrar',  'actividad.php'],
    ['ministerios', 'Ministerios','ministerios.php'],
    ['dashboard',   'Resumen',    'mi_dashboard.php'],
    ['perfil',      'Perfil',     'perfil.php'],
];
$navPastor = [
    ['consolidado', 'Consolidado', 'consolidado.php'],
    ['catalogos',   'Catálogos',   'admin_catalogos.php'],
    ['perfil',      'Perfil',      'perfil.php'],
];
$navAdmin = [
    ['asistentes',  'Asistentes',  'admin_asistentes.php'],
    ['catalogos',   'Catálogos',   'admin_catalogos.php'],
    ['consolidado', 'Consolidado', 'consolidado.php'],
    ['perfil',      'Perfil',      'perfil.php'],
];
$nav = $rol === 'admin' ? $navAdmin : ($rol === 'pastor' ? $navPastor : $navAsistente);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= h($titulo) ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
<?php if (!empty($csrfMeta)): ?>
  <meta name="csrf-token" content="<?= h($csrfMeta) ?>">
<?php endif; ?>
</head>
<body class="bg-slate-50 min-h-screen pb-20">
  <header class="sticky top-0 z-10 bg-white border-b shadow-sm">
    <div class="<?= h($anchoMax) ?> mx-auto px-4 py-3 flex items-center justify-between">
      <span class="font-semibold text-slate-800">Gestión Ministerial</span>
      <div class="flex items-center gap-2 text-sm">
        <span class="text-slate-700 hidden sm:inline"><?= h($usuario['nombre']) ?></span>
        <span class="<?= $badgeColor ?> text-white text-xs px-2 py-0.5 rounded-full"><?= h($rol) ?></span>
        <a href="logout.php" class="text-slate-500 hover:text-slate-800">Salir</a>
      </div>
    </div>
  </header>
  <main class="<?= h($anchoMax) ?> mx-auto px-4 py-6">

  <?php if (!empty($tituloPagina)): ?>
    <h1 class="text-lg font-semibold text-slate-800 mb-4"><?= h($tituloPagina) ?></h1>
  <?php endif; ?>

<?php
// La barra inferior se cierra en footer.php; aquí solo abrimos main.
// Guardamos los datos de nav para el footer.
$GLOBALS['__nav']       = $nav;
$GLOBALS['__navActiva'] = $navActiva;
