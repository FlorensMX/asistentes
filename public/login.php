<?php
/**
 * public/login.php
 *
 * Vista + handler de login (por usuario). Si ya hay sesión, redirige al inicio.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (usuarioActual()) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página e intenta de nuevo.';
    } else {
        $u = intentarLogin(
            (string) ($_POST['usuario']  ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        if ($u) {
            header('Location: index.php');
            exit;
        }
        usleep(300_000); // pequeño delay anti-fuerza-bruta
        $error = 'Usuario o contraseña incorrectos.';
    }
}

$csrf = csrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Gestión Ministerial — Acceso</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center">
  <div class="max-w-sm w-full mx-auto px-4">
    <div class="bg-white rounded-2xl shadow-xl p-8">
      <h1 class="text-xl font-semibold text-slate-800 text-center mb-1">
        Gestión Ministerial
      </h1>
      <p class="text-sm text-slate-500 text-center mb-6">Monte Sión · Acceso</p>

      <?php if ($error !== null): ?>
        <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-md px-3 py-2 mb-4">
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

        <label class="block text-sm text-slate-700 mb-1" for="usuario">Usuario</label>
        <input id="usuario" type="text" name="usuario" required autofocus autocomplete="username"
               class="w-full border border-slate-300 rounded-md px-3 py-2 mb-3 focus:outline-none focus:ring-2 focus:ring-emerald-500"
               value="<?= h((string) ($_POST['usuario'] ?? '')) ?>">

        <label class="block text-sm text-slate-700 mb-1" for="password">Contraseña</label>
        <input id="password" type="password" name="password" required autocomplete="current-password"
               class="w-full border border-slate-300 rounded-md px-3 py-2 mb-5 focus:outline-none focus:ring-2 focus:ring-emerald-500">

        <button type="submit"
                class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2.5 rounded-md transition">
          Entrar
        </button>
      </form>
    </div>
    <p class="text-xs text-slate-400 text-center mt-4">
      Para uso interno · MonteSion Cloud<br>
      P.A. Florencio Martínez
    </p>
  </div>
</body>
</html>
