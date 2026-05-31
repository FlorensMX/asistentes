<?php
/**
 * public/semana.php — "Mi semana" (inicio del Asistente)
 *
 * Lista los cultos fijos y los compromisos recurrentes vigentes de la semana,
 * cada uno confirmable con un toque (vía api/confirmar_culto.php y
 * api/confirmar_recurrente.php). Navegación por semanas con ?semana=offset.
 *
 * Botones grandes: + Registrar actividad y (Fase 3) Declarar viaje/campaña.
 *
 * Nota: la SUSPENSIÓN por campaña llega en Fase 3; aquí aún no se filtra.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogos_repo.php';
require_once __DIR__ . '/../includes/recurrentes_repo.php';
require_once __DIR__ . '/../includes/cultos_repo.php';
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
$cultos       = listarCultos();
$asistencias  = asistenciasEnRango($id, $desde, $hasta);

// --- Recurrentes vigentes durante la semana ---
$recurrentes   = listarRecurrentes($id, true);
$confirmaciones = confirmacionesEnRango($id, $desde, $hasta);

/** ¿El recurrente está vigente en la fecha dada? */
$vigenteEn = static function (array $r, string $fecha): bool {
    if ($r['vigente_desde'] !== null && $fecha < $r['vigente_desde']) return false;
    if ($r['vigente_hasta'] !== null && $fecha > $r['vigente_hasta']) return false;
    return true;
};

// Armamos las ocurrencias agrupadas por día (0=Dom..6=Sáb) para esta semana.
// Cada item: ['tipo'=>'culto'|'recurrente', 'fecha'=>..., datos..., 'estado'=>...]
$porFecha = [];   // 'Y-m-d' => lista de items
foreach ($dias as $d) {
    $porFecha[$d->format('Y-m-d')] = [];
}

foreach ($cultos as $c) {
    $fecha = fechaDeDiaSemana($lunes, (int) $c['dia_semana']);
    $key   = $c['id'] . '|' . $fecha;
    $a     = $asistencias[$key] ?? null;
    $porFecha[$fecha][] = [
        'tipo'        => 'culto',
        'orden'       => $c['hora_inicio'],
        'id'          => (int) $c['id'],
        'nombre'      => $c['nombre'],
        'hora'        => hhmm($c['hora_inicio']) . ($c['hora_fin'] ? '–' . hhmm($c['hora_fin']) : ''),
        'fin_variable'=> (bool) $c['fin_variable'],
        'fecha'       => $fecha,
        'confirmado'  => $a !== null && $a['asistio'],
        'hora_salida' => $a['hora_salida'] ?? null,
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
<div class="grid grid-cols-1 gap-2 mb-5">
  <a href="actividad.php" class="bg-emerald-700 hover:bg-emerald-800 text-white text-center font-medium py-3 rounded-xl shadow-sm">
    + Registrar actividad
  </a>
</div>

<?php foreach ($dias as $d):
    $fecha = $d->format('Y-m-d');
    $items = $porFecha[$fecha];
    $esHoy = $fecha === $hoy;
?>
  <section class="mb-5">
    <h2 class="text-sm font-semibold <?= $esHoy ? 'text-emerald-700' : 'text-slate-500' ?> mb-2">
      <?= h(nombreDiaSemana((int) $d->format('w'))) ?> <?= h($d->format('d/m')) ?>
      <?php if ($esHoy): ?><span class="ml-1 text-xs bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded">hoy</span><?php endif; ?>
    </h2>

    <?php if (!$items): ?>
      <p class="text-xs text-slate-300 pl-1">Sin cultos ni compromisos.</p>
    <?php else: ?>
      <ul class="space-y-2">
        <?php foreach ($items as $it): ?>
          <li class="bg-white rounded-xl shadow-sm p-3 flex items-center gap-3" data-fecha="<?= h($it['fecha']) ?>">
            <button
              class="js-toggle shrink-0 w-10 h-10 rounded-full border-2 flex items-center justify-center text-lg transition
                     <?= $it['confirmado'] ? 'bg-emerald-600 border-emerald-600 text-white' : 'border-slate-300 text-slate-300' ?>"
              data-tipo="<?= h($it['tipo']) ?>"
              data-id="<?= (int) $it['id'] ?>"
              data-fecha="<?= h($it['fecha']) ?>"
              data-confirmado="<?= $it['confirmado'] ? '1' : '0' ?>"
              <?= ($it['tipo'] === 'culto' && $it['fin_variable']) ? 'data-finvariable="1"' : '' ?>
              aria-label="Confirmar">
              <span class="js-check"><?= $it['confirmado'] ? '✓' : '' ?></span>
            </button>
            <div class="min-w-0 flex-1">
              <div class="font-medium text-slate-800 truncate">
                <?= h($it['nombre']) ?>
                <?php if ($it['tipo'] === 'culto'): ?>
                  <span class="ml-1 text-xs text-indigo-500">culto</span>
                <?php endif; ?>
              </div>
              <div class="text-xs text-slate-500">
                <?= h($it['hora']) ?>
                <?php if ($it['tipo'] === 'recurrente' && !empty($it['detalle'])): ?>
                  · <?= h($it['detalle']) ?>
                <?php endif; ?>
                <?php if ($it['tipo'] === 'culto' && $it['fin_variable'] && !empty($it['hora_salida'])): ?>
                  · salí <?= h(hhmm($it['hora_salida'])) ?>
                <?php endif; ?>
              </div>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<script>
(function () {
  const csrf = document.querySelector('meta[name="csrf-token"]').content;

  async function enviar(url, params) {
    const fd = new FormData();
    fd.append('csrf', csrf);
    for (const k in params) fd.append(k, params[k]);
    const r = await fetch(url, { method: 'POST', body: fd });
    return r.json();
  }

  document.querySelectorAll('.js-toggle').forEach(btn => {
    btn.addEventListener('click', async () => {
      const tipo       = btn.dataset.tipo;
      const id         = btn.dataset.id;
      const fecha      = btn.dataset.fecha;
      const confirmado = btn.dataset.confirmado === '1';
      const nuevo      = !confirmado;

      // Junta de Asistentes (fin variable): al confirmar, pedir hora de salida.
      let horaSalida = '';
      if (tipo === 'culto' && btn.dataset.finvariable === '1' && nuevo) {
        horaSalida = prompt('¿A qué hora saliste? (HH:MM, opcional)') || '';
      }

      btn.disabled = true;
      try {
        let j;
        if (tipo === 'culto') {
          j = await enviar('api/confirmar_culto.php', {
            culto_id: id, fecha, asistio: nuevo ? '1' : '0', hora_salida: horaSalida
          });
        } else {
          j = await enviar('api/confirmar_recurrente.php', {
            compromiso_id: id, fecha, confirmado: nuevo ? '1' : '0'
          });
        }
        if (j.ok) {
          btn.dataset.confirmado = nuevo ? '1' : '0';
          btn.querySelector('.js-check').textContent = nuevo ? '✓' : '';
          btn.classList.toggle('bg-emerald-600', nuevo);
          btn.classList.toggle('border-emerald-600', nuevo);
          btn.classList.toggle('text-white', nuevo);
          btn.classList.toggle('border-slate-300', !nuevo);
          btn.classList.toggle('text-slate-300', !nuevo);
        } else {
          alert(j.error || 'No se pudo guardar.');
        }
      } catch (e) {
        alert('Error de red. Intenta de nuevo.');
      } finally {
        btn.disabled = false;
      }
    });
  });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
