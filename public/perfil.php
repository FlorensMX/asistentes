<?php
/**
 * public/perfil.php
 *
 * Mi perfil: datos de contacto/disponibilidad, cambio de contraseña,
 * trabajo secular declarado y subida de la foto familiar.
 *
 * Lo usan todos los roles (cada quien ve y edita su propio perfil).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/asistentes_repo.php';

$usuario = requerirLogin();
$id      = (int) $usuario['id'];

$ok    = null;
$error = null;

// ---------------------------------------------------------------------------
// Handlers POST (datos, contraseña, trabajo secular)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página e intenta de nuevo.';
    } else {
        $accion = (string) ($_POST['accion'] ?? '');
        try {
            switch ($accion) {
                case 'datos':
                    actualizarPerfil(
                        $id,
                        (string) ($_POST['telefono'] ?? ''),
                        (string) ($_POST['disponibilidad'] ?? '')
                    );
                    $ok = 'Datos actualizados.';
                    break;

                case 'password':
                    $nueva = (string) ($_POST['nueva'] ?? '');
                    $conf  = (string) ($_POST['confirmar'] ?? '');
                    if (strlen($nueva) < 8) {
                        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
                    } elseif ($nueva !== $conf) {
                        $error = 'La confirmación no coincide.';
                    } elseif (!cambiarPasswordPropia($id, (string) ($_POST['actual'] ?? ''), $nueva)) {
                        $error = 'La contraseña actual es incorrecta.';
                    } else {
                        $ok = 'Contraseña actualizada.';
                    }
                    break;

                case 'secular_add':
                    $desc = trim((string) ($_POST['descripcion'] ?? ''));
                    if ($desc === '') {
                        $error = 'La descripción del trabajo es obligatoria.';
                    } else {
                        $horas = (string) ($_POST['horas_semana'] ?? '');
                        agregarTrabajoSecular(
                            $id,
                            $desc,
                            (string) ($_POST['horario'] ?? ''),
                            $horas !== '' ? (float) $horas : null
                        );
                        $ok = 'Trabajo secular agregado.';
                    }
                    break;

                case 'secular_del':
                    desactivarTrabajoSecular((int) ($_POST['trabajo_id'] ?? 0), $id);
                    $ok = 'Trabajo secular eliminado.';
                    break;

                default:
                    $error = 'Acción no reconocida.';
            }
        } catch (Throwable $e) {
            error_log('[perfil] ' . $e->getMessage());
            $error = 'Ocurrió un error al guardar. Intenta de nuevo.';
        }
    }
}

$a       = asistentePorId($id);
$secular = listarTrabajoSecular($id);
$csrf    = csrfToken();

$tituloPagina = 'Mi perfil';
$navActiva    = 'perfil';
$csrfMeta     = $csrf;
require __DIR__ . '/../includes/header.php';
?>

<?php if ($ok !== null): ?>
  <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-md px-3 py-2 mb-4"><?= h($ok) ?></div>
<?php endif; ?>
<?php if ($error !== null): ?>
  <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-md px-3 py-2 mb-4"><?= h($error) ?></div>
<?php endif; ?>

<!-- Foto familiar -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-5">
  <h2 class="font-semibold text-slate-800 mb-3">Foto familiar</h2>
  <div class="flex items-center gap-4">
    <img id="foto-preview"
         src="<?= $a['foto_familiar_url'] ? h($a['foto_familiar_url']) . '?v=' . time() : 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22112%22 height=%22112%22><rect width=%22100%25%22 height=%22100%25%22 fill=%22%23e2e8f0%22/></svg>' ?>"
         alt="Foto familiar"
         class="w-28 h-28 rounded-lg object-cover bg-slate-200 border">
    <div class="flex-1">
      <input type="file" id="foto-input" accept="image/jpeg,image/png,image/webp,image/heic,image/heif"
             class="block w-full text-sm text-slate-600 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:bg-emerald-700 file:text-white">
      <p id="foto-msg" class="text-xs text-slate-500 mt-2">JPG, PNG, WebP o HEIC. Máx 10 MB.</p>
    </div>
  </div>
</section>

<!-- Datos -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-5">
  <h2 class="font-semibold text-slate-800 mb-3">Datos</h2>
  <p class="text-sm text-slate-500 mb-3">
    <span class="font-medium text-slate-700"><?= h($a['nombre']) ?></span>
    · usuario <span class="font-mono"><?= h($a['usuario']) ?></span>
    · rol <?= h($a['rol']) ?>
  </p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="accion" value="datos">

    <label class="block text-sm text-slate-700 mb-1" for="telefono">Teléfono</label>
    <input id="telefono" type="text" name="telefono" value="<?= h($a['telefono'] ?? '') ?>"
           class="w-full border border-slate-300 rounded-md px-3 py-2 mb-3 focus:outline-none focus:ring-2 focus:ring-emerald-500">

    <label class="block text-sm text-slate-700 mb-1" for="disponibilidad">Disponibilidad</label>
    <textarea id="disponibilidad" name="disponibilidad" rows="3"
              class="w-full border border-slate-300 rounded-md px-3 py-2 mb-4 focus:outline-none focus:ring-2 focus:ring-emerald-500"><?= h($a['disponibilidad'] ?? '') ?></textarea>

    <button class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar datos</button>
  </form>
</section>

<!-- Trabajo secular -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-5">
  <h2 class="font-semibold text-slate-800 mb-1">Trabajo secular</h2>
  <p class="text-xs text-slate-500 mb-3">Lo externo al movimiento. Solo se declara; no cuenta como tiempo ministerial.</p>

  <?php if ($secular): ?>
    <ul class="divide-y mb-4">
      <?php foreach ($secular as $t): ?>
        <li class="py-2 flex items-center justify-between gap-3">
          <div class="text-sm">
            <span class="font-medium text-slate-800"><?= h($t['descripcion']) ?></span>
            <?php if ($t['horario']): ?><span class="text-slate-500"> · <?= h($t['horario']) ?></span><?php endif; ?>
            <?php if ($t['horas_semana'] !== null): ?><span class="text-slate-500"> · <?= h((string) $t['horas_semana']) ?> h/sem</span><?php endif; ?>
          </div>
          <form method="post" onsubmit="return confirm('¿Eliminar este trabajo secular?');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="accion" value="secular_del">
            <input type="hidden" name="trabajo_id" value="<?= (int) $t['id'] ?>">
            <button class="text-rose-600 hover:text-rose-800 text-sm">Quitar</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php else: ?>
    <p class="text-sm text-slate-400 mb-4">Sin trabajo secular declarado.</p>
  <?php endif; ?>

  <form method="post" class="grid gap-2 sm:grid-cols-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="accion" value="secular_add">
    <input type="text" name="descripcion" placeholder="Descripción (p. ej. Taxi)" required
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <input type="text" name="horario" placeholder="Horario (texto libre)"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <input type="number" name="horas_semana" step="0.5" min="0" placeholder="Horas/semana (aprox.)"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <button class="bg-slate-700 hover:bg-slate-800 text-white font-medium py-2 px-4 rounded-md sm:col-span-2">Agregar trabajo secular</button>
  </form>
</section>

<!-- Contraseña -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-5">
  <h2 class="font-semibold text-slate-800 mb-3">Cambiar contraseña</h2>
  <form method="post" class="grid gap-3">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="accion" value="password">
    <input type="password" name="actual" placeholder="Contraseña actual" required autocomplete="current-password"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <input type="password" name="nueva" placeholder="Nueva contraseña (mín. 8)" required autocomplete="new-password"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <input type="password" name="confirmar" placeholder="Confirmar nueva" required autocomplete="new-password"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <button class="bg-slate-700 hover:bg-slate-800 text-white font-medium py-2 px-4 rounded-md justify-self-start">Cambiar contraseña</button>
  </form>
</section>

<script>
// Subida AJAX de la foto familiar (vía api/foto.php).
(function () {
  const input   = document.getElementById('foto-input');
  const preview = document.getElementById('foto-preview');
  const msg     = document.getElementById('foto-msg');
  const csrf    = document.querySelector('meta[name="csrf-token"]').content;

  input.addEventListener('change', async () => {
    const file = input.files[0];
    if (!file) return;
    msg.textContent = 'Subiendo…';
    msg.className = 'text-xs text-slate-500 mt-2';

    const fd = new FormData();
    fd.append('csrf', csrf);
    fd.append('foto', file);

    try {
      const r = await fetch('api/foto.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        preview.src = j.url + '?v=' + Date.now();
        msg.textContent = 'Foto actualizada.';
        msg.className = 'text-xs text-emerald-600 mt-2';
      } else {
        msg.textContent = j.error || 'No se pudo subir la foto.';
        msg.className = 'text-xs text-rose-600 mt-2';
      }
    } catch (e) {
      msg.textContent = 'Error de red al subir la foto.';
      msg.className = 'text-xs text-rose-600 mt-2';
    }
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
