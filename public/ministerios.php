<?php
/**
 * public/ministerios.php — Mis ministerios nombrados
 *
 * El Asistente da de alta y nombra sus ministerios (p. ej. "Pescadores",
 * "Uno por uno", "La Buena Semilla Anexos"), cada uno con su categoría y
 * observaciones. Quedan disponibles al registrar actividad o definir un
 * recurrente. Alta/edición vía api/guardar_ministerio.php; baja con POST.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogos_repo.php';
require_once __DIR__ . '/../includes/ministerios_repo.php';

$usuario = requerirLogin();
$id      = (int) $usuario['id'];

// Baja blanda (POST clásico, con su propio CSRF).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'desactivar') {
    if (csrfValidar($_POST['csrf'] ?? null)) {
        desactivarMinisterio((int) ($_POST['id'] ?? 0), $id);
    }
    header('Location: ministerios.php');
    exit;
}

$categorias  = listarCategorias();
$ministerios = listarMinisterios($id, false);   // incluye inactivos al final
$csrf        = csrfToken();

$tituloPagina = 'Mis ministerios';
$navActiva    = 'ministerios';
$csrfMeta     = $csrf;
require __DIR__ . '/../includes/header.php';
?>

<div id="msg" class="hidden text-sm rounded-md px-3 py-2 mb-4"></div>

<div class="flex gap-2 mb-4 text-sm">
  <span class="px-3 py-1.5 rounded-full bg-emerald-600 text-white">Ministerios</span>
  <a href="recurrentes.php" class="px-3 py-1.5 rounded-full bg-white border text-slate-600">Recurrentes</a>
</div>

<!-- Alta / edición -->
<form id="form-min" class="bg-white rounded-xl shadow-sm p-5 mb-6 space-y-3">
  <input type="hidden" id="min-id" name="id" value="0">
  <h2 id="form-titulo" class="font-semibold text-slate-800">Nuevo ministerio</h2>

  <div>
    <label class="block text-sm text-slate-700 mb-1" for="nombre">Nombre</label>
    <input id="nombre" name="nombre" type="text" required placeholder="p. ej. Pescadores"
           class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
  </div>

  <div>
    <label class="block text-sm text-slate-700 mb-1" for="categoria_id">Categoría</label>
    <select id="categoria_id" name="categoria_id" required
            class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="">Elige…</option>
      <?php foreach ($categorias as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= h($c['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <div>
    <label class="block text-sm text-slate-700 mb-1" for="observaciones">Observaciones (opcional)</label>
    <textarea id="observaciones" name="observaciones" rows="2"
              class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
  </div>

  <div class="flex gap-2">
    <button type="submit" class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar</button>
    <button type="button" id="btn-cancelar" class="hidden text-slate-500 py-2 px-4">Cancelar edición</button>
  </div>
</form>

<!-- Listado -->
<section>
  <h2 class="text-sm font-semibold text-slate-500 mb-2">Tus ministerios</h2>
  <?php if (!$ministerios): ?>
    <p class="text-xs text-slate-300">Aún no has dado de alta ningún ministerio.</p>
  <?php else: ?>
    <ul class="space-y-2">
      <?php foreach ($ministerios as $m): ?>
        <li class="bg-white rounded-xl shadow-sm p-4 <?= $m['activo'] ? '' : 'opacity-50' ?>">
          <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
              <div class="font-medium text-slate-800"><?= h($m['nombre']) ?></div>
              <div class="text-xs text-slate-500"><?= h($m['categoria']) ?></div>
              <?php if ($m['observaciones']): ?>
                <div class="text-xs text-slate-400 mt-1"><?= h($m['observaciones']) ?></div>
              <?php endif; ?>
            </div>
            <?php if ($m['activo']): ?>
              <div class="flex flex-col items-end gap-1 shrink-0">
                <button class="js-editar text-emerald-700 text-sm"
                        data-id="<?= (int) $m['id'] ?>"
                        data-nombre="<?= h($m['nombre']) ?>"
                        data-categoria="<?= (int) $m['categoria_id'] ?>"
                        data-obs="<?= h($m['observaciones'] ?? '') ?>">Editar</button>
                <form method="post" onsubmit="return confirm('¿Desactivar este ministerio?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="accion" value="desactivar">
                  <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                  <button class="text-rose-600 text-sm">Desactivar</button>
                </form>
              </div>
            <?php else: ?>
              <span class="text-xs text-slate-400 shrink-0">inactivo</span>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<script>
(function () {
  const csrf   = document.querySelector('meta[name="csrf-token"]').content;
  const form   = document.getElementById('form-min');
  const msg    = document.getElementById('msg');
  const titulo = document.getElementById('form-titulo');
  const cancel = document.getElementById('btn-cancelar');

  function mostrarMsg(texto, ok) {
    msg.textContent = texto;
    msg.className = 'text-sm rounded-md px-3 py-2 mb-4 ' +
      (ok ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
          : 'bg-rose-50 border border-rose-200 text-rose-800');
    msg.classList.remove('hidden');
  }

  function resetForm() {
    form.reset();
    document.getElementById('min-id').value = '0';
    titulo.textContent = 'Nuevo ministerio';
    cancel.classList.add('hidden');
  }

  document.querySelectorAll('.js-editar').forEach(b => {
    b.addEventListener('click', () => {
      document.getElementById('min-id').value      = b.dataset.id;
      document.getElementById('nombre').value      = b.dataset.nombre;
      document.getElementById('categoria_id').value= b.dataset.categoria;
      document.getElementById('observaciones').value = b.dataset.obs;
      titulo.textContent = 'Editar ministerio';
      cancel.classList.remove('hidden');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  cancel.addEventListener('click', resetForm);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('csrf', csrf);
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      const r = await fetch('api/guardar_ministerio.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        mostrarMsg('Ministerio guardado.', true);
        setTimeout(() => location.reload(), 500);
      } else {
        mostrarMsg(j.error || 'No se pudo guardar.', false);
      }
    } catch (e) {
      mostrarMsg('Error de red. Intenta de nuevo.', false);
    } finally {
      btn.disabled = false;
    }
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
