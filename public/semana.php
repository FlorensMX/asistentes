<?php
/**
 * public/semana.php — "Mi semana" (inicio del Asistente)
 *
 * Lista los cultos fijos y los compromisos recurrentes vigentes de la semana,
 * cada uno confirmable con un toque. Navegación por semanas con ?semana=offset.
 *
 * Fase 3 añadió dos cosas sobre la base de Fase 2:
 *   (a) FUNCIONES DE CULTO. Al confirmar un culto se pueden marcar las funciones
 *       desempeñadas (con fruto donde aplica) en un panel desplegable. "Solo
 *       asistí" = confirmar sin marcar funciones. La Junta de Asistentes
 *       (fin_variable) admite hora de salida. Todo vía api/guardar_funcion_culto.php.
 *   (b) SUSPENSIÓN POR CAMPAÑA. Los días cubiertos por un periodo de campaña del
 *       asistente muestran sus cultos y recurrentes como «En campaña — suspendido»,
 *       fuera de los pendientes y sin contar como no realizados.
 *
 * El toque rápido sobre el círculo del culto confirma/quita asistencia SIN tocar
 * las funciones ya marcadas (no envía el campo 'funciones'); el panel sí las
 * sincroniza.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogos_repo.php';
require_once __DIR__ . '/../includes/recurrentes_repo.php';
require_once __DIR__ . '/../includes/cultos_repo.php';
require_once __DIR__ . '/../includes/campanias_repo.php';
require_once __DIR__ . '/../includes/semana.php';

$usuario = requerirLogin();
$id      = (int) $usuario['id'];

$offset = (int) ($_GET['semana'] ?? 0);
$lunes  = lunesDeSemana(null, $offset);
$dias   = diasDeSemana($lunes);
$desde  = $lunes->format('Y-m-d');
$hasta  = $dias[6]->format('Y-m-d');

$hoy = (new DateTimeImmutable('now', tzApp()))->format('Y-m-d');

// --- Cultos fijos (todos aplican cada semana) ---
$cultos      = listarCultos();
$asistencias = asistenciasEnRango($id, $desde, $hasta);

// Funciones realizadas de la semana (una sola consulta sobre las asistencias).
$asistenciaIds = array_map(static fn($a) => (int) $a['id'], array_values($asistencias));
$funcionesPorAsistencia = funcionesRealizadasDeAsistencias($asistenciaIds);

// Catálogo de funciones de culto, agrupado para el panel.
$funcsPorGrupo = ['ministerial' => [], 'servicio' => []];
foreach (listarFuncionesCulto() as $f) {
    $funcsPorGrupo[$f['grupo']][] = $f;
}

// --- Recurrentes vigentes durante la semana ---
$recurrentes    = listarRecurrentes($id, true);
$confirmaciones = confirmacionesEnRango($id, $desde, $hasta);

// --- Suspensión por campaña ---
$suspendidos = array_flip(diasSuspendidos($id, $desde, $hasta)); // 'Y-m-d' => idx

/** ¿El recurrente está vigente en la fecha dada? */
$vigenteEn = static function (array $r, string $fecha): bool {
    if ($r['vigente_desde'] !== null && $fecha < $r['vigente_desde']) return false;
    if ($r['vigente_hasta'] !== null && $fecha > $r['vigente_hasta']) return false;
    return true;
};

// Armamos las ocurrencias agrupadas por día para esta semana.
$porFecha = [];
foreach ($dias as $d) {
    $porFecha[$d->format('Y-m-d')] = [];
}

foreach ($cultos as $c) {
    $fecha = fechaDeDiaSemana($lunes, (int) $c['dia_semana']);
    $key   = $c['id'] . '|' . $fecha;
    $a     = $asistencias[$key] ?? null;

    $asistenciaId = $a !== null ? (int) $a['id'] : null;
    $marcadas = [];
    if ($asistenciaId !== null && isset($funcionesPorAsistencia[$asistenciaId])) {
        foreach ($funcionesPorAsistencia[$asistenciaId] as $fr) {
            $marcadas[(int) $fr['funcion_culto_id']] = $fr['fruto_cantidad'];
        }
    }

    $porFecha[$fecha][] = [
        'tipo'         => 'culto',
        'orden'        => $c['hora_inicio'],
        'id'           => (int) $c['id'],
        'nombre'       => $c['nombre'],
        'hora'         => hhmm($c['hora_inicio']) . ($c['hora_fin'] ? '–' . hhmm($c['hora_fin']) : ''),
        'fin_variable' => (bool) $c['fin_variable'],
        'fecha'        => $fecha,
        'confirmado'   => $a !== null && $a['asistio'],
        'hora_salida'  => $a['hora_salida'] ?? null,
        'marcadas'     => $marcadas,
        'num_func'     => count($marcadas),
    ];
}

foreach ($recurrentes as $r) {
    $fecha = fechaDeDiaSemana($lunes, (int) $r['dia_semana']);
    if (!$vigenteEn($r, $fecha)) continue;
    $key = $r['id'] . '|' . $fecha;
    $cf  = $confirmaciones[$key] ?? null;
    $porFecha[$fecha][] = [
        'tipo'       => 'recurrente',
        'orden'      => $r['hora_inicio'],
        'id'         => (int) $r['id'],
        'nombre'     => $r['nombre'],
        'detalle'    => $r['ministerio'] ?: $r['categoria'],
        'hora'       => hhmm($r['hora_inicio']) . '–' . hhmm($r['hora_fin']),
        'fecha'      => $fecha,
        'confirmado' => $cf !== null && $cf['confirmado'],
        'nota'       => $cf['nota'] ?? null,
    ];
}

// Ordenar cada día por hora.
foreach ($porFecha as &$items) {
    usort($items, static fn($a, $b) => strcmp($a['orden'], $b['orden']));
}
unset($items);

$rangoLabel = $dias[0]->format('d M') . ' – ' . $dias[6]->format('d M Y');

$csrf         = csrfToken();
$tituloPagina = 'Mi semana';
$navActiva    = 'semana';
$csrfMeta     = $csrf;
require __DIR__ . '/../includes/header.php';
?>

<!-- Navegación de semanas -->
<div class="flex items-center justify-between mb-4">
  <a href="?semana=<?= $offset - 1 ?>" class="px-3 py-1.5 rounded-md bg-white border text-slate-600 text-sm">← Anterior</a>
  <div class="text-center">
    <div class="text-sm font-medium text-slate-800"><?= h($rangoLabel) ?></div>
    <?php if ($offset !== 0): ?>
      <a href="?semana=0" class="text-xs text-emerald-700">Ir a esta semana</a>
    <?php else: ?>
      <span class="text-xs text-slate-400">Semana actual</span>
    <?php endif; ?>
  </div>
  <a href="?semana=<?= $offset + 1 ?>" class="px-3 py-1.5 rounded-md bg-white border text-slate-600 text-sm">Siguiente →</a>
</div>

<!-- Acciones rápidas -->
<div class="grid grid-cols-2 gap-2 mb-5">
  <a href="actividad.php" class="bg-emerald-700 hover:bg-emerald-800 text-white text-center font-medium py-3 rounded-xl shadow-sm">
    + Registrar actividad
  </a>
  <a href="campania.php" class="bg-amber-600 hover:bg-amber-700 text-white text-center font-medium py-3 rounded-xl shadow-sm">
    Declarar viaje/campaña
  </a>
</div>

<?php foreach ($dias as $d):
    $fecha = $d->format('Y-m-d');
    $items = $porFecha[$fecha];
    $esHoy = $fecha === $hoy;
    $diaSuspendido = isset($suspendidos[$fecha]);
?>
  <section class="mb-5">
    <h2 class="text-sm font-semibold <?= $esHoy ? 'text-emerald-700' : 'text-slate-500' ?> mb-2">
      <?= h(nombreDiaSemana((int) $d->format('w'))) ?> <?= h($d->format('d/m')) ?>
      <?php if ($esHoy): ?><span class="ml-1 text-xs bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded">hoy</span><?php endif; ?>
    </h2>

    <?php if ($diaSuspendido): ?>
      <div class="mb-2 text-xs bg-amber-50 border border-amber-200 text-amber-800 rounded-md px-3 py-1.5">
        En campaña — suspendido. Cultos y recurrentes de este día no se piden ni cuentan en contra.
      </div>
    <?php endif; ?>

    <?php if (!$items): ?>
      <p class="text-xs text-slate-300 pl-1">Sin cultos ni compromisos.</p>
    <?php else: ?>
      <ul class="space-y-2">
        <?php foreach ($items as $it): ?>

          <?php if ($diaSuspendido): /* ---- Día suspendido: ítems estáticos ---- */ ?>
            <li class="bg-white rounded-xl shadow-sm p-3 flex items-center gap-3 opacity-60">
              <span class="shrink-0 w-10 h-10 rounded-full border-2 border-amber-300 flex items-center justify-center text-amber-500 text-base">✈</span>
              <div class="min-w-0 flex-1">
                <div class="font-medium text-slate-700 truncate">
                  <?= h($it['nombre']) ?>
                  <?php if ($it['tipo'] === 'culto'): ?><span class="ml-1 text-xs text-indigo-400">culto</span><?php endif; ?>
                </div>
                <div class="text-xs text-amber-700">
                  <?= h($it['hora']) ?> · suspendido<?php if ($it['confirmado']): ?> · tenías confirmado<?php endif; ?>
                </div>
              </div>
            </li>

          <?php elseif ($it['tipo'] === 'recurrente'): /* ---- Recurrente: un toque ---- */ ?>
            <li class="bg-white rounded-xl shadow-sm p-3 flex items-center gap-3">
              <button
                class="js-rec-toggle shrink-0 w-10 h-10 rounded-full border-2 flex items-center justify-center text-lg transition
                       <?= $it['confirmado'] ? 'bg-emerald-600 border-emerald-600 text-white' : 'border-slate-300 text-slate-300' ?>"
                data-id="<?= (int) $it['id'] ?>"
                data-fecha="<?= h($it['fecha']) ?>"
                data-confirmado="<?= $it['confirmado'] ? '1' : '0' ?>"
                aria-label="Confirmar">
                <span class="js-check"><?= $it['confirmado'] ? '✓' : '' ?></span>
              </button>
              <div class="min-w-0 flex-1">
                <div class="font-medium text-slate-800 truncate"><?= h($it['nombre']) ?></div>
                <div class="text-xs text-slate-500">
                  <?= h($it['hora']) ?>
                  <?php if (!empty($it['detalle'])): ?> · <?= h($it['detalle']) ?><?php endif; ?>
                </div>
              </div>
            </li>

          <?php else: /* ---- Culto: toque rápido + panel de funciones ---- */ ?>
            <li class="culto-card bg-white rounded-xl shadow-sm p-3"
                data-culto-id="<?= (int) $it['id'] ?>"
                data-fecha="<?= h($it['fecha']) ?>"
                data-finvariable="<?= $it['fin_variable'] ? '1' : '0' ?>">
              <div class="flex items-center gap-3">
                <button
                  class="js-culto-toggle shrink-0 w-10 h-10 rounded-full border-2 flex items-center justify-center text-lg transition
                         <?= $it['confirmado'] ? 'bg-emerald-600 border-emerald-600 text-white' : 'border-slate-300 text-slate-300' ?>"
                  data-culto-id="<?= (int) $it['id'] ?>"
                  data-fecha="<?= h($it['fecha']) ?>"
                  data-confirmado="<?= $it['confirmado'] ? '1' : '0' ?>"
                  data-finvariable="<?= $it['fin_variable'] ? '1' : '0' ?>"
                  data-numfunc="<?= (int) $it['num_func'] ?>"
                  aria-label="Confirmar asistencia">
                  <span class="js-check"><?= $it['confirmado'] ? '✓' : '' ?></span>
                </button>
                <div class="min-w-0 flex-1">
                  <div class="font-medium text-slate-800 truncate">
                    <?= h($it['nombre']) ?> <span class="ml-1 text-xs text-indigo-500">culto</span>
                  </div>
                  <div class="text-xs text-slate-500">
                    <?= h($it['hora']) ?>
                    <?php if ($it['confirmado']): ?>
                      · <?= $it['num_func'] > 0 ? (int) $it['num_func'] . ' función(es)' : 'solo asistí' ?>
                      <?php if ($it['fin_variable'] && !empty($it['hora_salida'])): ?>
                        · salí <?= h(hhmm($it['hora_salida'])) ?>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
                <button type="button" class="js-abrir-panel text-emerald-700 text-sm shrink-0">Funciones ▾</button>
              </div>

              <!-- Panel de funciones (oculto por defecto) -->
              <div class="js-panel hidden mt-3 border-t pt-3 space-y-3">
                <?php if ($it['fin_variable']): ?>
                  <label class="block text-sm text-slate-700">
                    Hora de salida
                    <input type="time" class="js-hora-salida mt-1 w-full border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                           value="<?= h(hhmm($it['hora_salida'] ?? '')) ?>">
                  </label>
                <?php endif; ?>

                <?php foreach (['ministerial' => 'Ministeriales', 'servicio' => 'Servicio'] as $grupo => $tituloGrupo): ?>
                  <?php if ($funcsPorGrupo[$grupo]): ?>
                    <fieldset>
                      <legend class="text-xs font-semibold text-slate-500 mb-1"><?= h($tituloGrupo) ?></legend>
                      <div class="space-y-1.5">
                        <?php foreach ($funcsPorGrupo[$grupo] as $f):
                            $fid     = (int) $f['id'];
                            $checked = array_key_exists($fid, $it['marcadas']);
                            $fruto   = $checked ? $it['marcadas'][$fid] : null;
                        ?>
                          <div class="flex items-center gap-2">
                            <label class="flex items-center gap-2 flex-1 min-w-0 text-sm text-slate-700">
                              <input type="checkbox" class="js-func rounded" data-fid="<?= $fid ?>" <?= $checked ? 'checked' : '' ?>>
                              <span class="truncate"><?= h($f['nombre']) ?></span>
                            </label>
                            <?php if ($f['lleva_fruto']): ?>
                              <input type="number" min="0"
                                     class="js-fruto w-24 border border-slate-300 rounded-md px-2 py-1 text-sm disabled:bg-slate-50"
                                     data-fid="<?= $fid ?>"
                                     placeholder="<?= h($f['etiqueta_fruto'] ?: 'fruto') ?>"
                                     value="<?= $fruto !== null ? (int) $fruto : '' ?>"
                                     <?= $checked ? '' : 'disabled' ?>>
                            <?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </fieldset>
                  <?php endif; ?>
                <?php endforeach; ?>

                <button type="button" class="js-guardar-funciones w-full bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 rounded-md">
                  Guardar
                </button>
                <p class="text-xs text-slate-400">Guardar confirma tu asistencia. Sin funciones marcadas = «solo asistí».</p>
              </div>
            </li>
          <?php endif; ?>

        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  async function post(url, params) {
    const fd = new FormData();
    fd.append('csrf', csrf);
    for (const k in params) fd.append(k, params[k]);
    const r = await fetch(url, { method: 'POST', body: fd });
    return r.json();
  }

  function pintarToggle(btn, on) {
    btn.dataset.confirmado = on ? '1' : '0';
    btn.querySelector('.js-check').textContent = on ? '✓' : '';
    btn.classList.toggle('bg-emerald-600', on);
    btn.classList.toggle('border-emerald-600', on);
    btn.classList.toggle('text-white', on);
    btn.classList.toggle('border-slate-300', !on);
    btn.classList.toggle('text-slate-300', !on);
  }

  // --- Recurrentes: confirmar/desmarcar en sitio (un toque) ---
  document.querySelectorAll('.js-rec-toggle').forEach(btn => {
    btn.addEventListener('click', async () => {
      const nuevo = btn.dataset.confirmado !== '1';
      btn.disabled = true;
      try {
        const j = await post('api/confirmar_recurrente.php', {
          compromiso_id: btn.dataset.id, fecha: btn.dataset.fecha, confirmado: nuevo ? '1' : '0'
        });
        if (j.ok) pintarToggle(btn, nuevo);
        else alert(j.error || 'No se pudo guardar.');
      } catch (e) { alert('Error de red. Intenta de nuevo.'); }
      finally { btn.disabled = false; }
    });
  });

  // --- Cultos: toque rápido = confirmar "solo asistí" / quitar (no toca funciones) ---
  document.querySelectorAll('.js-culto-toggle').forEach(btn => {
    btn.addEventListener('click', async () => {
      const nuevo = btn.dataset.confirmado !== '1';
      // Desmarcar borra la asistencia y, por cascada, las funciones capturadas.
      if (!nuevo && parseInt(btn.dataset.numfunc || '0', 10) > 0 &&
          !confirm('Tienes funciones marcadas en este culto. ¿Quitar tu asistencia y borrarlas?')) {
        return;
      }
      const params = { culto_id: btn.dataset.cultoId, fecha: btn.dataset.fecha, asistio: nuevo ? '1' : '0' };
      if (nuevo && btn.dataset.finvariable === '1') {
        const hs = prompt('¿A qué hora saliste? (HH:MM, opcional)') || '';
        if (hs) params.hora_salida = hs;
      }
      btn.disabled = true;
      try {
        const j = await post('api/guardar_funcion_culto.php', params);
        if (j.ok) location.reload();
        else { alert(j.error || 'No se pudo guardar.'); btn.disabled = false; }
      } catch (e) { alert('Error de red. Intenta de nuevo.'); btn.disabled = false; }
    });
  });

  // --- Panel de funciones: abrir/cerrar ---
  document.querySelectorAll('.js-abrir-panel').forEach(b => b.addEventListener('click', () => {
    b.closest('.culto-card').querySelector('.js-panel').classList.toggle('hidden');
  }));

  // --- Fruto habilitado solo si su función está marcada ---
  document.querySelectorAll('.js-func').forEach(chk => chk.addEventListener('change', () => {
    const panel = chk.closest('.js-panel');
    const fruto = panel.querySelector('.js-fruto[data-fid="' + chk.dataset.fid + '"]');
    if (fruto) { fruto.disabled = !chk.checked; if (!chk.checked) fruto.value = ''; }
  }));

  // --- Guardar funciones (sincroniza el conjunto y confirma asistencia) ---
  document.querySelectorAll('.js-guardar-funciones').forEach(btn => btn.addEventListener('click', async () => {
    const card  = btn.closest('.culto-card');
    const panel = btn.closest('.js-panel');
    const funciones = [];
    panel.querySelectorAll('.js-func:checked').forEach(chk => {
      const fid = parseInt(chk.dataset.fid, 10);
      const obj = { funcion_culto_id: fid };
      const frutoEl = panel.querySelector('.js-fruto[data-fid="' + fid + '"]');
      if (frutoEl && frutoEl.value !== '') obj.fruto_cantidad = parseInt(frutoEl.value, 10);
      funciones.push(obj);
    });
    const params = {
      culto_id: card.dataset.cultoId,
      fecha:    card.dataset.fecha,
      asistio:  '1',
      funciones: JSON.stringify(funciones)
    };
    if (card.dataset.finvariable === '1') {
      const hs = panel.querySelector('.js-hora-salida');
      if (hs && hs.value) params.hora_salida = hs.value;
    }
    btn.disabled = true;
    try {
      const j = await post('api/guardar_funcion_culto.php', params);
      if (j.ok) location.reload();
      else { alert(j.error || 'No se pudo guardar.'); btn.disabled = false; }
    } catch (e) { alert('Error de red. Intenta de nuevo.'); btn.disabled = false; }
  }));
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
