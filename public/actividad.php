<?php
/**
 * public/actividad.php — Registrar actividad variable (baja fricción)
 *
 * Flujo: categoría → actividad → (ministerio, si aplica) → duración
 *        (inicio/fin o minutos) → fruto (solo si la actividad lo lleva)
 *        → proyecto (opcional) → nota → guardar.
 *
 * El guardado va por api/guardar_actividad.php (JSON). Tras éxito, se limpia
 * el formulario y se muestra el registro en el historial corto sin recargar.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogos_repo.php';
require_once __DIR__ . '/../includes/ministerios_repo.php';
require_once __DIR__ . '/../includes/actividad_repo.php';
require_once __DIR__ . '/../includes/semana.php';

$usuario = requerirLogin();
$id      = (int) $usuario['id'];

$categorias  = listarCategorias();
$actividades = listarActividades();         // todas, con categoria_id y fruto
$ministerios = listarMinisterios($id, true);
$proyectos   = listarProyectosActivos();
$recientes   = registrosRecientes($id, 10);

$hoy  = (new DateTimeImmutable('now', tzApp()))->format('Y-m-d');
$csrf = csrfToken();

// Empaquetamos las actividades para el JS (filtrado por categoría + fruto).
$actJson = array_map(static fn($a) => [
    'id'            => (int) $a['id'],
    'categoria_id'  => (int) $a['categoria_id'],
    'nombre'        => $a['nombre'],
    'lleva_fruto'   => (bool) $a['lleva_fruto'],
    'etiqueta_fruto'=> $a['etiqueta_fruto'],
], $actividades);

$tituloPagina = 'Registrar actividad';
$navActiva    = 'actividad';
$csrfMeta     = $csrf;
require __DIR__ . '/../includes/header.php';
?>

<div id="msg" class="hidden text-sm rounded-md px-3 py-2 mb-4"></div>

<form id="form-actividad" class="bg-white rounded-xl shadow-sm p-5 mb-6 space-y-4">
  <!-- Categoría -->
  <div>
    <label class="block text-sm text-slate-700 mb-1" for="categoria">Categoría</label>
    <select id="categoria" required
            class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="">Elige…</option>
      <?php foreach ($categorias as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= h($c['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- Actividad -->
  <div>
    <label class="block text-sm text-slate-700 mb-1" for="actividad">Actividad</label>
    <select id="actividad" name="actividad_id" required disabled
            class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 disabled:bg-slate-50">
      <option value="">Elige una categoría primero…</option>
    </select>
  </div>

  <!-- Ministerio (opcional) -->
  <?php if ($ministerios): ?>
  <div>
    <label class="block text-sm text-slate-700 mb-1" for="ministerio">Ministerio (opcional)</label>
    <select id="ministerio" name="ministerio_id"
            class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="">—</option>
      <?php foreach ($ministerios as $m): ?>
        <option value="<?= (int) $m['id'] ?>"><?= h($m['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <!-- Fecha -->
  <div>
    <label class="block text-sm text-slate-700 mb-1" for="fecha">Fecha</label>
    <input id="fecha" name="fecha" type="date" value="<?= h($hoy) ?>" required
           class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
  </div>

  <!-- Duración -->
  <div>
    <span class="block text-sm text-slate-700 mb-1">Duración</span>
    <div class="flex gap-2 mb-2 text-xs">
      <button type="button" id="modo-horas" class="px-3 py-1 rounded-full bg-emerald-600 text-white">Inicio / fin</button>
      <button type="button" id="modo-min"   class="px-3 py-1 rounded-full bg-slate-100 text-slate-600">Minutos</button>
    </div>
    <div id="bloque-horas" class="grid grid-cols-2 gap-2">
      <input id="hora_inicio" name="hora_inicio" type="time"
             class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <input id="hora_fin" name="hora_fin" type="time"
             class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
    <div id="bloque-min" class="hidden">
      <input id="duracion_min" name="duracion_min" type="number" min="1" max="1440" placeholder="Minutos"
             class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
  </div>

  <!-- Fruto (solo si aplica) -->
  <div id="bloque-fruto" class="hidden">
    <label class="block text-sm text-slate-700 mb-1" for="fruto_cantidad">
      Fruto (<span id="etiqueta-fruto">cantidad</span>)
    </label>
    <input id="fruto_cantidad" name="fruto_cantidad" type="number" min="0" placeholder="0"
           class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
  </div>

  <!-- Proyecto (opcional) -->
  <?php if ($proyectos): ?>
  <div>
    <label class="block text-sm text-slate-700 mb-1" for="proyecto">Proyecto (opcional)</label>
    <select id="proyecto" name="proyecto_id"
            class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="">—</option>
      <?php foreach ($proyectos as $p): ?>
        <option value="<?= (int) $p['id'] ?>"><?= h($p['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>

  <!-- Nota -->
  <div>
    <label class="block text-sm text-slate-700 mb-1" for="nota">Nota (opcional)</label>
    <textarea id="nota" name="nota" rows="2"
              class="w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"></textarea>
  </div>

  <button type="submit"
          class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-3 rounded-xl">
    Guardar actividad
  </button>
</form>

<!-- Historial corto -->
<section>
  <h2 class="text-sm font-semibold text-slate-500 mb-2">Tus últimos registros</h2>
  <ul id="historial" class="space-y-2">
    <?php foreach ($recientes as $r): ?>
      <li class="bg-white rounded-lg shadow-sm p-3 text-sm">
        <div class="flex justify-between">
          <span class="font-medium text-slate-800"><?= h($r['actividad']) ?></span>
          <span class="text-slate-400 text-xs"><?= h($r['fecha']) ?></span>
        </div>
        <div class="text-xs text-slate-500">
          <?= h($r['categoria']) ?>
          <?php if ($r['ministerio']): ?> · <?= h($r['ministerio']) ?><?php endif; ?>
          <?php
            if ($r['hora_inicio'] && $r['hora_fin']) {
                echo ' · ' . h(hhmm($r['hora_inicio'])) . '–' . h(hhmm($r['hora_fin']));
            } elseif ($r['duracion_min']) {
                echo ' · ' . (int) $r['duracion_min'] . ' min';
            }
          ?>
          <?php if ($r['fruto_cantidad'] !== null): ?>
            · <?= (int) $r['fruto_cantidad'] ?> <?= h($r['etiqueta_fruto'] ?? '') ?>
          <?php endif; ?>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (!$recientes): ?>
    <p class="text-xs text-slate-300">Aún no has registrado actividades.</p>
  <?php endif; ?>
</section>

<script>
const ACTIVIDADES = <?= json_encode($actJson, JSON_UNESCAPED_UNICODE) ?>;

(function () {
  const csrf       = document.querySelector('meta[name="csrf-token"]').content;
  const selCat     = document.getElementById('categoria');
  const selAct     = document.getElementById('actividad');
  const bloqueFr   = document.getElementById('bloque-fruto');
  const etqFruto   = document.getElementById('etiqueta-fruto');
  const inFruto    = document.getElementById('fruto_cantidad');
  const form       = document.getElementById('form-actividad');
  const msg        = document.getElementById('msg');

  // Filtrar actividades al elegir categoría.
  selCat.addEventListener('change', () => {
    const cat = parseInt(selCat.value, 10);
    selAct.innerHTML = '<option value="">Elige…</option>';
    const lista = ACTIVIDADES.filter(a => a.categoria_id === cat);
    for (const a of lista) {
      const o = document.createElement('option');
      o.value = a.id;
      o.textContent = a.nombre;
      selAct.appendChild(o);
    }
    selAct.disabled = lista.length === 0;
    actualizarFruto();
  });

  selAct.addEventListener('change', actualizarFruto);

  function actualizarFruto() {
    const a = ACTIVIDADES.find(x => x.id === parseInt(selAct.value, 10));
    if (a && a.lleva_fruto) {
      etqFruto.textContent = a.etiqueta_fruto || 'cantidad';
      bloqueFr.classList.remove('hidden');
    } else {
      bloqueFr.classList.add('hidden');
      inFruto.value = '';
    }
  }

  // Conmutador inicio/fin vs minutos.
  const modoHoras = document.getElementById('modo-horas');
  const modoMin   = document.getElementById('modo-min');
  const bHoras    = document.getElementById('bloque-horas');
  const bMin      = document.getElementById('bloque-min');
  function setModo(horas) {
    bHoras.classList.toggle('hidden', !horas);
    bMin.classList.toggle('hidden', horas);
    modoHoras.className = 'px-3 py-1 rounded-full ' + (horas ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600');
    modoMin.className   = 'px-3 py-1 rounded-full ' + (!horas ? 'bg-emerald-600 text-white' : 'bg-slate-100 text-slate-600');
    if (horas) { document.getElementById('duracion_min').value = ''; }
    else { document.getElementById('hora_inicio').value = ''; document.getElementById('hora_fin').value = ''; }
  }
  modoHoras.addEventListener('click', () => setModo(true));
  modoMin.addEventListener('click', () => setModo(false));

  function mostrarMsg(texto, ok) {
    msg.textContent = texto;
    msg.className = 'text-sm rounded-md px-3 py-2 mb-4 ' +
      (ok ? 'bg-emerald-50 border border-emerald-200 text-emerald-800'
          : 'bg-rose-50 border border-rose-200 text-rose-800');
    msg.classList.remove('hidden');
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    fd.append('csrf', csrf);
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    try {
      const r = await fetch('api/guardar_actividad.php', { method: 'POST', body: fd });
      const j = await r.json();
      if (j.ok) {
        mostrarMsg('Actividad guardada.', true);
        // Reset suave: conserva fecha y categoría para registros consecutivos.
        form.querySelector('#nota').value = '';
        inFruto.value = '';
        document.getElementById('hora_inicio').value = '';
        document.getElementById('hora_fin').value = '';
        document.getElementById('duracion_min').value = '';
        setTimeout(() => location.reload(), 600);
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
