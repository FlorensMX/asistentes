<?php
/**
 * public/detalle.php — Detalle por asistente (escritorio, rol pastor/admin)
 *
 * Por ?asistente_id=: foto familiar, composición por categoría y por ministerio
 * nombrado, funciones en cultos, proyectos (con observaciones), días/campañas y
 * tendencia mensual. Filtro de mes.
 *
 * Solo lectura. "El sistema muestra, el Pastor juzga": sin umbrales ni colores
 * de juicio, sin dinero. Secular se ve en su perfil; aquí el foco es el tiempo
 * ministerial. Los días de campaña no inflan horas (van como días de misión).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reportes_repo.php';
require_once __DIR__ . '/../includes/semana.php'; // hhmm()

$usuario = requerirRol('pastor', 'admin');

$aid = (int) ($_GET['asistente_id'] ?? 0);

$mes = (string) ($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = mesActual();
}
['ini' => $ini, 'fin' => $fin] = mesLimites($mes);

$detalle = $aid > 0 ? detalleAsistente($aid, $ini, $fin) : ['asistente' => null];
$a = $detalle['asistente'];

$d        = DateTimeImmutable::createFromFormat('Y-m-d', $mes . '-01');
$mesPrev  = $d->modify('-1 month')->format('Y-m');
$mesNext  = $d->modify('+1 month')->format('Y-m');
$esActual = $mes === mesActual();

$nombresMes = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
               7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$mesLabel = ucfirst($nombresMes[(int) $d->format('n')]) . ' ' . $d->format('Y');

$fmt = static fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.');

$barra = static function (string $label, float $valor, float $max, string $sufijo = ' h') use ($fmt): void {
    $pct = $max > 0 ? max(2, (int) round($valor / $max * 100)) : 0;
    echo '<div class="mb-2">'
       . '<div class="flex justify-between text-sm mb-0.5">'
       . '<span class="text-slate-700 truncate pr-2">' . h($label) . '</span>'
       . '<span class="text-slate-500 shrink-0">' . h($fmt($valor) . $sufijo) . '</span>'
       . '</div>'
       . '<div class="h-2 bg-slate-100 rounded-full overflow-hidden">'
       . '<div class="h-2 bg-emerald-500 rounded-full" style="width: ' . $pct . '%"></div>'
       . '</div></div>';
};

$tituloPagina = 'Detalle';
$anchoMax     = 'max-w-4xl';
$navActiva    = 'consolidado';
require __DIR__ . '/../includes/header.php';
?>

<a href="consolidado.php?mes=<?= h($mes) ?>" class="text-sm text-emerald-700 hover:text-emerald-900">← Volver al consolidado</a>

<?php if ($a === null): ?>
  <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-md px-3 py-2 mt-4">Asistente no encontrado.</div>
  <?php require __DIR__ . '/../includes/footer.php'; return; ?>
<?php endif; ?>

<?php $r = $detalle['resumen']; ?>

<!-- Navegación de meses -->
<div class="flex items-center justify-between my-4">
  <a href="?asistente_id=<?= $aid ?>&mes=<?= h($mesPrev) ?>" class="px-3 py-1.5 rounded-md bg-white border text-slate-600 text-sm">← Anterior</a>
  <div class="text-center">
    <div class="text-sm font-medium text-slate-800"><?= h($mesLabel) ?></div>
    <?php if (!$esActual): ?>
      <a href="?asistente_id=<?= $aid ?>&mes=<?= h(mesActual()) ?>" class="text-xs text-emerald-700">Ir al mes actual</a>
    <?php else: ?>
      <span class="text-xs text-slate-400">Mes actual</span>
    <?php endif; ?>
  </div>
  <a href="?asistente_id=<?= $aid ?>&mes=<?= h($mesNext) ?>" class="px-3 py-1.5 rounded-md bg-white border text-slate-600 text-sm">Siguiente →</a>
</div>

<!-- Cabecera del asistente -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-4 flex items-center gap-4">
  <img src="<?= $a['foto_familiar_url'] ? h($a['foto_familiar_url']) . '?v=' . time() : 'data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2280%22 height=%2280%22><rect width=%22100%25%22 height=%22100%25%22 fill=%22%23e2e8f0%22/></svg>' ?>"
       alt="Foto familiar" class="w-20 h-20 rounded-lg object-cover bg-slate-200 border shrink-0">
  <div class="min-w-0">
    <h1 class="text-lg font-semibold text-slate-800">
      <?= h($a['nombre']) ?>
      <?php if (!$a['activo']): ?>
        <span class="ml-1 align-middle text-xs bg-slate-200 text-slate-600 px-2 py-0.5 rounded-full">inactivo</span>
      <?php endif; ?>
    </h1>
    <div class="text-xs text-slate-500">
      <?= h($a['rol']) ?>
      <?php if (!empty($a['telefono'])): ?> · <?= h($a['telefono']) ?><?php endif; ?>
    </div>
    <div class="mt-2 flex gap-4 text-sm">
      <span><span class="font-bold text-emerald-700"><?= h($fmt($r['horas_total'])) ?></span> h ministeriales</span>
      <span><span class="font-bold text-amber-600"><?= (int) $r['dias_mision'] ?></span> días de misión</span>
      <span><span class="font-bold text-indigo-600"><?= (int) $r['cultos']['asistencias_total'] ?></span> asistencias</span>
    </div>
  </div>
</section>

<div class="grid md:grid-cols-2 gap-4">
  <!-- Por categoría -->
  <section class="bg-white rounded-xl shadow-sm p-5">
    <h2 class="font-semibold text-slate-800 mb-3">Por categoría</h2>
    <?php
      $barsCat = [];
      foreach ($r['horas_por_categoria'] as $c) $barsCat[] = ['label' => $c['categoria'], 'horas' => (float) $c['horas']];
      if ((float) $r['horas_cultos'] > 0) $barsCat[] = ['label' => 'Cultos', 'horas' => (float) $r['horas_cultos']];
    ?>
    <?php if (!$barsCat): ?>
      <p class="text-xs text-slate-400">Sin horas este mes.</p>
    <?php else:
        $maxCat = max(array_map(static fn($x) => $x['horas'], $barsCat));
        foreach ($barsCat as $x) $barra($x['label'], $x['horas'], $maxCat);
    endif; ?>
  </section>

  <!-- Por ministerio -->
  <section class="bg-white rounded-xl shadow-sm p-5">
    <h2 class="font-semibold text-slate-800 mb-3">Por ministerio</h2>
    <?php if (!$r['horas_por_ministerio']): ?>
      <p class="text-xs text-slate-400">Sin ministerios con horas este mes.</p>
    <?php else:
        $maxMin = max(array_map(static fn($x) => (float) $x['horas'], $r['horas_por_ministerio']));
        foreach ($r['horas_por_ministerio'] as $x) $barra($x['ministerio'], (float) $x['horas'], $maxMin);
    endif; ?>
  </section>

  <!-- Funciones en cultos -->
  <section class="bg-white rounded-xl shadow-sm p-5">
    <h2 class="font-semibold text-slate-800 mb-3">Funciones en cultos</h2>
    <?php if (!$detalle['funciones']): ?>
      <p class="text-xs text-slate-400">Sin funciones marcadas este mes.</p>
    <?php else: ?>
      <?php foreach (['ministerial' => 'Ministeriales', 'servicio' => 'Servicio'] as $g => $titG):
          $delGrupo = array_filter($detalle['funciones'], static fn($f) => $f['grupo'] === $g);
          if (!$delGrupo) continue; ?>
        <div class="mb-2">
          <div class="text-xs font-semibold text-slate-500 mb-1"><?= h($titG) ?></div>
          <ul class="text-sm divide-y">
            <?php foreach ($delGrupo as $f): ?>
              <li class="py-1 flex justify-between">
                <span class="text-slate-700"><?= h($f['nombre']) ?> <span class="text-slate-400">· <?= (int) $f['veces'] ?>×</span></span>
                <?php if ($f['fruto'] !== null): ?>
                  <span class="text-emerald-700"><?= (int) $f['fruto'] ?> <?= h($f['etiqueta'] ?? '') ?></span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </section>

  <!-- Cultos -->
  <section class="bg-white rounded-xl shadow-sm p-5">
    <h2 class="font-semibold text-slate-800 mb-3">Cultos</h2>
    <?php if (!$r['cultos']['items']): ?>
      <p class="text-xs text-slate-400">Sin asistencias este mes.</p>
    <?php else: ?>
      <ul class="text-sm divide-y">
        <?php foreach ($r['cultos']['items'] as $c): ?>
          <li class="py-1 flex justify-between">
            <span class="text-slate-700"><?= h($c['nombre']) ?> <span class="text-slate-400">· <?= (int) $c['asistencias'] ?>×</span></span>
            <span class="text-slate-500"><?= $c['horas'] !== null ? h($fmt($c['horas'])) . ' h' : '—' ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<!-- Proyectos -->
<section class="bg-white rounded-xl shadow-sm p-5 mt-4">
  <h2 class="font-semibold text-slate-800 mb-3">Proyectos tocados</h2>
  <?php if (!$detalle['proyectos']): ?>
    <p class="text-xs text-slate-400">Sin actividad asociada a proyectos este mes.</p>
  <?php else: ?>
    <ul class="divide-y text-sm">
      <?php foreach ($detalle['proyectos'] as $p): ?>
        <li class="py-2">
          <div class="flex justify-between">
            <span class="font-medium text-slate-800"><?= h($p['nombre']) ?></span>
            <span class="text-slate-500"><?= $p['horas'] !== null ? h($fmt($p['horas'])) . ' h' : '—' ?> · <?= (int) $p['registros'] ?> reg.</span>
          </div>
          <?php if ($p['observaciones'] !== null && $p['observaciones'] !== ''): ?>
            <div class="text-xs text-slate-400 mt-0.5"><?= h($p['observaciones']) ?></div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<!-- Campañas del mes -->
<section class="bg-white rounded-xl shadow-sm p-5 mt-4">
  <div class="flex items-baseline justify-between mb-3">
    <h2 class="font-semibold text-slate-800">Campañas del mes</h2>
    <span class="text-sm text-amber-600"><?= (int) $r['dias_mision'] ?> días de misión</span>
  </div>
  <?php if (!$detalle['campanias']): ?>
    <p class="text-xs text-slate-400">Sin campañas que toquen este mes.</p>
  <?php else: ?>
    <ul class="divide-y text-sm">
      <?php foreach ($detalle['campanias'] as $c):
          $rango = $c['fecha_inicio'] === $c['fecha_fin'] ? $c['fecha_inicio'] : $c['fecha_inicio'] . ' – ' . $c['fecha_fin'];
      ?>
        <li class="py-2">
          <div class="flex justify-between">
            <span class="font-medium text-slate-800"><?= h($c['lugar'] ?: 'Campaña') ?></span>
            <span class="text-slate-500"><?= h($rango) ?></span>
          </div>
          <?php if ($c['descripcion'] !== null && $c['descripcion'] !== ''): ?>
            <div class="text-xs text-slate-500 mt-0.5"><?= h($c['descripcion']) ?></div>
          <?php endif; ?>
          <?php if ($c['fruto_cantidad'] !== null): ?>
            <div class="text-xs text-emerald-700 mt-0.5">Fruto declarado: <?= (int) $c['fruto_cantidad'] ?></div>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<!-- Tendencia -->
<section class="bg-white rounded-xl shadow-sm p-5 mt-4">
  <h2 class="font-semibold text-slate-800 mb-3">Tendencia (6 meses)</h2>
  <?php
    $tend = $detalle['tendencia'];
    $maxTend = max(array_map(static fn($t) => (float) $t['horas'], $tend));
    $maxTend = $maxTend > 0 ? $maxTend : 1.0;
  ?>
  <div class="flex items-end justify-between gap-3 h-36">
    <?php foreach ($tend as $t):
        $alt = max(4, (int) round((float) $t['horas'] / $maxTend * 100));
        $etq = DateTimeImmutable::createFromFormat('Y-m-d', $t['mes'] . '-01');
    ?>
      <div class="flex-1 flex flex-col items-center justify-end h-full">
        <span class="text-xs text-slate-500 mb-1"><?= h($fmt($t['horas'])) ?></span>
        <div class="w-full bg-emerald-500 rounded-t" style="height: <?= $alt ?>%"></div>
        <span class="text-xs text-slate-400 mt-1"><?= h(ucfirst(substr($nombresMes[(int) $etq->format('n')], 0, 3))) ?> <?= h($etq->format('y')) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
