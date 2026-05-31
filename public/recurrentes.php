<?php
/**
 * public/recurrentes.php — Mis compromisos recurrentes
 *
 * El Asistente declara el patrón semanal de sus compromisos (nombre o
 * ministerio, categoría, día, horas y vigencia). Estos generan las
 * ocurrencias confirmables en "Mi semana". Alta/edición vía
 * api/guardar_recurrente.php; baja con POST.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogos_repo.php';
require_once __DIR__ . '/../includes/ministerios_repo.php';
require_once __DIR__ . '/../includes/recurrentes_repo.php';
require_once __DIR__ . '/../includes/semana.php';

$usuario = requerirLogin();
$id      = (int) $usuario['id'];

// Baja blanda.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'desactivar') {
    if (csrfValidar($_POST['csrf'] ?? null)) {
        desactivarRecurrente((int) ($_POST['id'] ?? 0), $id);
    }
    header('Location: recurrentes.php');
    exit;
}

$categorias  = listarCategorias();
$ministerios = listarMinisterios($id, true);
$recurrentes = listarRecurrentes($id, false);
$csrf        = csrfToken();

$tituloPagina = 'Mis recurrentes';
$navActiva    = 'ministerios'; // comparten pestaña en la barra inferior
$csrfMeta     = $csrf;
require __DIR__ . '/../includes/header.php';
?>

<div id="msg" class="hidden text-sm rounded-md px-3 py-2 mb-4"></div>

<div class="flex gap-2 mb-4 text-sm">
  <a href="ministerios.php" class="px-3 py-1.5 rounded-full bg-white border text-slate-600">Ministerios</a>
  <span class="px-3 py-1.5 rounded-full bg-emerald-600 text-white">Recurrentes</span>
</div>

<p class="text-xs text-slate-500 mb-3">
  Los compromisos recurrentes aparecen cada semana en
  <a href="semana.php" class="text-emerald-700 underline">Mi semana</a> para confirmarlos con un toque.
</p>

<!-- Alta / edición -->
<form id="form-rec" class="bg-white rounded-xl shadow-sm p-5 mb-6 space-y-3">
  <input type="hidden" id="rec-id" name="id" value="0">
  <h2 id="form-titulo" class="font-semibold text-slate-800">Nuevo recurrente</h2>

  <div>
    <label class="block text-sm text-slate-700 mb-1" for="nombre">Nombre</label>
    <input id="nombre" name="nombre" type="text" required placeholder="p. ej. Clase CPMS"
           class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
  </div>

  <div class="grid grid-cols-2 gap-2">
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
      <label class="block text-sm text-slate-700 mb-1" for="dia_semana">Día</label>
      <select id="dia_semana" name="dia_semana" required
              class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
        <?php
          // Presentar de lunes a domingo, pero con el valor 0..6 del esquema.
          foreach ([1,2,3,4,5,6,0] as $ds): ?>
          <option value="<?= $ds ?>"><?= h(nombreDiaSemana($ds)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <?php if ($ministerios): ?>
  <div>
    <label class="block text-sm text-slate-700 mb-1" for="ministerio_id">Ministerio (opcional)</label>
    <select id="ministerio_id" name="ministerio_id"
            class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="">—</option>
      <?php foreach ($ministerios as $m): ?>
        <option value="<?= (int) $m['id'] ?>"><?= h($m['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="hora_inicio">Hora inicio</label>
      <input id="hora_inicio" name="hora_inicio" type="time" required
             class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="hora_fin">Hora fin</label>
      <input id="hora_fin" name="hora_fin" type="time" required
             class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
  </div>

  <div class="grid grid-cols-2 gap-2">
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="vigente_desde">Vigente desde</label>
      <input id="vigente_desde" name="vigente_desde" type="date"
             class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
    <div>
      <label class="block text-sm text-slate-700 mb-1" for="vigente_hasta">Vigente hasta (opcional)</label>
      <input id="vigente_hasta" name="vigente_hasta" type="date"
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
  <h2 class="text-sm font-semibold text-slate-500 mb-2">Tus recurrentes</h2>
  <?php if (!$recurrentes): ?>
    <p class="text-xs text-slate-300">Aún no has declarado compromisos recurrentes.</p>
  <?php else: ?>
    <ul class="space-y-2">
      <?php foreach ($recurrentes as $r): ?>
        <li class="bg-white rounded-xl shadow-sm p-4 <?= $r['activo'] ? '' : 'opacity-50' ?>">
          <div class="flex justify-between items-start gap-3">
            <div class="min-w-0">
              <div class="font-medium text-slate-800"><?= h($r['nombre']) ?></div>
              <div class="text-xs text-slate-500">
                <?= h(nombreDiaSemana((int) $r['dia_semana'])) ?>
                <?= h(hhmm($r['hora_inicio'])) ?>–<?= h(hhmm($r['hora_fin'])) ?>
                · <?= h($r['categoria']) ?>
                <?php if ($r['ministerio']): ?> · <?= h($r['ministerio']) ?><?php endif; ?>
              </div>
              <div class="text-xs text-slate-400 mt-0.5">
                desde <?= h($r['vigente_desde']) ?><?php if ($r['vigente_hasta']): ?> hasta <?= h($r['vigente_hasta']) ?><?php endif; ?>
              </div>
            </div>
            <?php if ($r['activo']): ?>
              <div class="flex flex-col items-end gap-1 shrink-0">
                <button class="js-editar text-emerald-700 text-sm"
                        data-id="<?= (int) $r['id'] ?>"
                        data-nombre="<?= h($r['nombre']) ?>"
                        data-categoria="<?= (int) $r['categoria_id'] ?>"
                        data-dia="<?= (int) $r['dia_semana'] ?>"
                        data-ministerio="<?= $r['ministerio_id'] !== null ? (int) $r['ministerio_id'] : '' ?>"
                        data-hi="<?= h(hhmm($r['hora_inicio'])) ?>"
                        data-hf="<?= h(hhmm($r['hora_fin'])) ?>"
                        data-vd="<?= h($r['vigente_desde'] ?? '') ?>"
                        data-vh="<?= h($r['vigente_hasta'] ?? '') ?>">Editar</button>
                <form method="post" onsubmit="return confirm('¿Desactivar este recurrente?');">
                  <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                  <input type="hidden" name="accion" value="desactivar">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
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
  const form   = document.getElementById('form-rec');
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
    document.getElementById('rec-id').value = '0';
    titulo.textContent = 'Nuevo recurrente';
    cancel.classList.add('hidden');
  }

  document.querySelectorAll('.js-editar').forEach(b => {
    b.addEventListener('click', () => {
      document.getElementById('rec-id').value       = b.dataset.id;
      document.getElementById('nombre').value       = b.dataset.nombre;
      document.getElementById('categoria_id').value = b.dataset.categoria;
      document.getElementById('dia_semana').value   = b.dataset.dia;
      const selMin = document.getElementById('ministerio_id');
      if (selMin) selMin.value = b.dataset.ministerio || '';
      document.getElementById('hora_inicio').value  = b.dataset.hi;
      document.getElementById('hora_fin').value     = b.dataset.hf;
      document.getElementById('vigente_desde').value= b.dataset.vd;
      document.getElementById('vigente_hasta').value= b.dataset.vh;
      titulo.textContent = 'Editar recurrente';
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
      const r = await fetch('api/guardar_recurrente.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        mostrarMsg('Compromiso guardado.', true);
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
