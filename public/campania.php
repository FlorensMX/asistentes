<?php
/**
 * public/campania.php — Declarar viaje / campaña (móvil, baja fricción)
 *
 * El Asistente declara un periodo de campaña/misión: rango de fechas, lugar,
 * descripción, categoría (por defecto Evangelismo) y fruto. Los días del rango
 * quedan SUSPENDIDOS en "Mi semana": sus cultos y recurrentes no se piden ni
 * cuentan en contra (la campaña representa esa dedicación, medida en días).
 *
 * Alta/edición/eliminación vía api/guardar_campania.php (fetch).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogos_repo.php';
require_once __DIR__ . '/../includes/campanias_repo.php';
require_once __DIR__ . '/../includes/semana.php';

$usuario = requerirLogin();
$id      = (int) $usuario['id'];

$categorias = listarCategorias();
$campanias  = campaniasDeAsistente($id);
$hoy        = (new DateTimeImmutable('now', tzApp()))->format('Y-m-d');
$csrf       = csrfToken();

// Categoría por defecto sugerida en el alta.
$catEvangelismo = categoriaIdPorNombre('Evangelismo y alcance');

$tituloPagina = 'Declarar campaña';
$navActiva    = 'semana';
$csrfMeta     = $csrf;
require __DIR__ . '/../includes/header.php';
?>

<div id="msg" class="hidden text-sm rounded-md px-3 py-2 mb-4"></div>

<p class="text-xs text-slate-500 mb-3">
  Declara un viaje o campaña y sus días aparecerán como
  <span class="font-medium text-amber-700">«En campaña — suspendido»</span> en
  <a href="semana.php" class="text-emerald-700 underline">Mi semana</a>:
  esos cultos y recurrentes no se piden ni cuentan en contra.
</p>

<!-- Alta / edición -->
<form id="form-camp" class="bg-white rounded-xl shadow-sm p-5 mb-6 space-y-3">
  <input type="hidden" id="camp-id" name="id" value="0">
  <h2 id="form-titulo" class="font-semibold text-slate-800">Nueva campaña</h2>

  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="fecha_inicio">Desde</label>
      <input id="fecha_inicio" name="fecha_inicio" type="date" required value="<?= h($hoy) ?>"
             class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="fecha_fin">Hasta</label>
      <input id="fecha_fin" name="fecha_fin" type="date" required value="<?= h($hoy) ?>"
             class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
  </div>

  <div>
    <label class="block text-sm text-slate-700 mb-1" for="lugar">Lugar</label>
    <input id="lugar" name="lugar" type="text" placeholder="p. ej. Oaxaca, Guatemala…"
           class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
  </div>

  <div>
    <label class="block text-sm text-slate-700 mb-1" for="descripcion">Descripción</label>
    <textarea id="descripcion" name="descripcion" rows="2" placeholder="¿Qué se hará en estos días?"
              class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
  </div>

  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="categoria_id">Categoría</label>
      <select id="categoria_id" name="categoria_id"
              class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
        <?php foreach ($categorias as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= ($catEvangelismo !== null && (int) $c['id'] === $catEvangelismo) ? 'selected' : '' ?>>
            <?= h($c['nombre']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="fruto_cantidad">Fruto (opcional)</label>
      <input id="fruto_cantidad" name="fruto_cantidad" type="number" min="0" placeholder="decisiones…"
             class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
  </div>

  <div class="flex gap-2">
    <button type="submit" class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar</button>
    <button type="button" id="btn-cancelar" class="hidden text-slate-500 py-2 px-4">Cancelar edición</button>
  </div>
</form>

<!-- Listado -->
<section>
  <h2 class="text-sm font-semibold text-slate-500 mb-2">Tus campañas</h2>
  <?php if (!$campanias): ?>
    <p class="text-xs text-slate-300">Aún no has declarado campañas.</p>
  <?php else: ?>
    <ul class="space-y-2">
      <?php foreach ($campanias as $c):
          $mismaFecha = $c['fecha_inicio'] === $c['fecha_fin'];
          $rango = $mismaFecha
              ? $c['fecha_inicio']
              : $c['fecha_inicio'] . ' – ' . $c['fecha_fin'];
      ?>
        <li class="bg-white rounded-xl shadow-sm p-4">
          <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
              <div class="font-medium text-slate-800">
                <?= h($c['lugar'] ?: 'Campaña') ?>
              </div>
              <div class="text-xs text-slate-500"><?= h($rango) ?></div>
              <?php if (!empty($c['categoria'])): ?>
                <div class="text-xs text-slate-400 mt-0.5"><?= h($c['categoria']) ?></div>
              <?php endif; ?>
              <?php if ($c['descripcion'] !== null && $c['descripcion'] !== ''): ?>
                <div class="text-xs text-slate-500 mt-1"><?= h($c['descripcion']) ?></div>
              <?php endif; ?>
              <?php if ($c['fruto_cantidad'] !== null): ?>
                <div class="text-xs text-emerald-700 mt-0.5">Fruto: <?= (int) $c['fruto_cantidad'] ?></div>
              <?php endif; ?>
            </div>
            <div class="flex flex-col items-end gap-1 shrink-0">
              <button class="js-editar text-emerald-700 text-sm"
                      data-id="<?= (int) $c['id'] ?>"
                      data-fi="<?= h($c['fecha_inicio']) ?>"
                      data-ff="<?= h($c['fecha_fin']) ?>"
                      data-lugar="<?= h($c['lugar'] ?? '') ?>"
                      data-desc="<?= h($c['descripcion'] ?? '') ?>"
                      data-cat="<?= $c['categoria_id'] !== null ? (int) $c['categoria_id'] : '' ?>"
                      data-fruto="<?= $c['fruto_cantidad'] !== null ? (int) $c['fruto_cantidad'] : '' ?>">Editar</button>
              <button class="js-eliminar text-rose-600 text-sm" data-id="<?= (int) $c['id'] ?>">Eliminar</button>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<script>
(function () {
  const csrf   = document.querySelector('meta[name="csrf-token"]').content;
  const form   = document.getElementById('form-camp');
  const msg    = document.getElementById('msg');
  const titulo = document.getElementById('form-titulo');
  const cancel = document.getElementById('btn-cancelar');
  const fi     = document.getElementById('fecha_inicio');
  const ff     = document.getElementById('fecha_fin');

  function mostrarMsg(texto, ok) {
    msg.textContent = texto;
    msg.className = 'text-sm rounded-md px-3 py-2 mb-4 ' +
      (ok ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
          : 'bg-rose-50 border border-rose-200 text-rose-800');
    msg.classList.remove('hidden');
  }

  function resetForm() {
    form.reset();
    document.getElementById('camp-id').value = '0';
    titulo.textContent = 'Nueva campaña';
    cancel.classList.add('hidden');
  }

  // Mantén "hasta" >= "desde" al elegir el inicio.
  fi.addEventListener('change', () => {
    if (ff.value && ff.value < fi.value) ff.value = fi.value;
  });

  document.querySelectorAll('.js-editar').forEach(b => {
    b.addEventListener('click', () => {
      document.getElementById('camp-id').value       = b.dataset.id;
      fi.value                                        = b.dataset.fi;
      ff.value                                        = b.dataset.ff;
      document.getElementById('lugar').value          = b.dataset.lugar || '';
      document.getElementById('descripcion').value    = b.dataset.desc || '';
      if (b.dataset.cat) document.getElementById('categoria_id').value = b.dataset.cat;
      document.getElementById('fruto_cantidad').value = b.dataset.fruto || '';
      titulo.textContent = 'Editar campaña';
      cancel.classList.remove('hidden');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  });

  cancel.addEventListener('click', resetForm);

  document.querySelectorAll('.js-eliminar').forEach(b => {
    b.addEventListener('click', async () => {
      if (!confirm('¿Eliminar esta campaña? Sus días dejarán de estar suspendidos.')) return;
      const fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('accion', 'eliminar');
      fd.append('id', b.dataset.id);
      b.disabled = true;
      try {
        const r = await fetch('api/guardar_campania.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (j.ok) { location.reload(); }
        else { mostrarMsg(j.error || 'No se pudo eliminar.', false); b.disabled = false; }
      } catch (e) {
        mostrarMsg('Error de red. Intenta de nuevo.', false);
        b.disabled = false;
      }
    });
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('csrf', csrf);
    fd.append('accion', 'guardar');
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      const r = await fetch('api/guardar_campania.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        mostrarMsg('Campaña guardada. Esos días aparecerán suspendidos en Mi semana.', true);
        setTimeout(() => location.reload(), 700);
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
