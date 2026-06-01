<?php
/**
 * public/mi_dashboard.php — Mi resumen del mes (móvil)
 *
 * El propio Asistente ve SUS horas del mes por categoría y por ministerio, sus
 * cultos, días de misión y frutos, con filtro de mes. Scope ESTRICTO a la
 * sesión: jamás se acepta un ?asistente_id de otro.
 *
 * Solo lectura. "Informa, no juzga": números y barras neutrales, sin umbrales,
 * sin colores bueno/malo, sin dinero.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reportes_repo.php';

$usuario = requerirLogin();
$id      = (int) $usuario['id'];   // SIEMPRE el de la sesión

// Mes filtrado (YYYY-MM), por defecto el actual en hora de México.
$mes = (string) ($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = mesActual();
}
['ini' => $ini, 'fin' => $fin] = mesLimites($mes);

$resumen   = resumenMesAsistente($id, $ini, $fin);
$tendencia = tendenciaMensual($id, 6, $mes);

// Navegación de meses.
$d        = DateTimeImmutable::createFromFormat('Y-m-d', $mes . '-01');
$mesPrev  = $d->modify('-1 month')->format('Y-m');
$mesNext  = $d->modify('+1 month')->format('Y-m');
$esActual = $mes === mesActual();

$nombresMes = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
               7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$mesLabel = ucfirst($nombresMes[(int) $d->format('n')]) . ' ' . $d->format('Y');

/** Pinta una barra horizontal neutral (etiqueta · valor · barra proporcional). */
$barra = static function (string $label, float $valor, float $max, string $sufijo = ' h'): void {
    $pct = $max > 0 ? max(2, (int) round($valor / $max * 100)) : 0;
    $val = rtrim(rtrim(number_format($valor, 1), '0'), '.');
    echo '<div class="mb-2">'
       . '<div class="flex justify-between text-sm mb-0.5">'
       . '<span class="text-slate-700 truncate pr-2">' . h($label) . '</span>'
       . '<span class="text-slate-500 shrink-0">' . h($val . $sufijo) . '</span>'
       . '</div>'
       . '<div class="h-2 bg-slate-100 rounded-full overflow-hidden">'
       . '<div class="h-2 bg-emerald-500 rounded-full" style="width: ' . $pct . '%"></div>'
       . '</div></div>';
};

$tituloPagina = 'Mi resumen';
$navActiva    = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>

<!-- Navegación de meses -->
<div class="flex items-center justify-between mb-4">
  <a href="?mes=<?= h($mesPrev) ?>" class="px-3 py-1.5 rounded-md bg-white border text-slate-600 text-sm">← Anterior</a>
  <div class="text-center">
    <div class="text-sm font-medium text-slate-800"><?= h($mesLabel) ?></div>
    <?php if (!$esActual): ?>
      <a href="?mes=<?= h(mesActual()) ?>" class="text-xs text-emerald-700">Ir al mes actual</a>
    <?php else: ?>
      <span class="text-xs text-slate-400">Mes actual</span>
    <?php endif; ?>
  </div>
  <a href="?mes=<?= h($mesNext) ?>" class="px-3 py-1.5 rounded-md bg-white border text-slate-600 text-sm">Siguiente →</a>
</div>

<!-- Resumen de horas -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-4">
  <div class="flex items-baseline justify-between">
    <h2 class="font-semibold text-slate-800">Horas ministeriales</h2>
    <span class="text-2xl font-bold text-emerald-700"><?= h(rtrim(rtrim(number_format((float) $resumen['horas_total'], 1), '0'), '.')) ?> h</span>
  </div>
  <p class="text-xs text-slate-500 mt-1">
    <?= h(rtrim(rtrim(number_format((float) $resumen['horas_categorias'], 1), '0'), '.')) ?> h en actividades/recurrentes ·
    <?= h(rtrim(rtrim(number_format((float) $resumen['horas_cultos'], 1), '0'), '.')) ?> h en cultos
  </p>
  <p class="text-xs text-slate-400 mt-1">Los días de campaña no cuentan aquí; se muestran como días de misión.</p>
</section>

<!-- Por categoría (incluye el bucket "Cultos", que no tiene categoría) -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-4">
  <h2 class="font-semibold text-slate-800 mb-3">Por categoría</h2>
  <?php
    $barsCat = [];
    foreach ($resumen['horas_por_categoria'] as $r) {
        $barsCat[] = ['label' => $r['categoria'], 'horas' => (float) $r['horas']];
    }
    if ((float) $resumen['horas_cultos'] > 0) {
        $barsCat[] = ['label' => 'Cultos', 'horas' => (float) $resumen['horas_cultos']];
    }
  ?>
  <?php if (!$barsCat): ?>
    <p class="text-xs text-slate-400">Sin horas registradas este mes.</p>
  <?php else:
      $maxCat = max(array_map(static fn($r) => $r['horas'], $barsCat));
      foreach ($barsCat as $r) $barra($r['label'], $r['horas'], $maxCat);
  endif; ?>
</section>

<!-- Por ministerio -->
<?php if ($resumen['horas_por_ministerio']): ?>
<section class="bg-white rounded-xl shadow-sm p-5 mb-4">
  <h2 class="font-semibold text-slate-800 mb-3">Por ministerio</h2>
  <?php
    $maxMin = max(array_map(static fn($r) => (float) $r['horas'], $resumen['horas_por_ministerio']));
    foreach ($resumen['horas_por_ministerio'] as $r) $barra($r['ministerio'], (float) $r['horas'], $maxMin);
  ?>
</section>
<?php endif; ?>

<!-- Cultos -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-4">
  <div class="flex items-baseline justify-between mb-2">
    <h2 class="font-semibold text-slate-800">Cultos</h2>
    <span class="text-sm text-slate-500"><?= (int) $resumen['cultos']['asistencias_total'] ?> asistencias</span>
  </div>
  <?php if (!$resumen['cultos']['items']): ?>
    <p class="text-xs text-slate-400">Sin asistencias a culto este mes.</p>
  <?php else: ?>
    <ul class="divide-y text-sm">
      <?php foreach ($resumen['cultos']['items'] as $c): ?>
        <li class="py-1.5 flex justify-between">
          <span class="text-slate-700"><?= h($c['nombre']) ?> <span class="text-slate-400">· <?= (int) $c['asistencias'] ?>×</span></span>
          <span class="text-slate-500"><?= $c['horas'] !== null ? h(rtrim(rtrim(number_format((float) $c['horas'], 1), '0'), '.')) . ' h' : '—' ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<!-- Días de misión + frutos -->
<section class="grid grid-cols-2 gap-3 mb-4">
  <div class="bg-white rounded-xl shadow-sm p-5 text-center">
    <div class="text-3xl font-bold text-amber-600"><?= (int) $resumen['dias_mision'] ?></div>
    <div class="text-xs text-slate-500 mt-1">días de misión</div>
  </div>
  <div class="bg-white rounded-xl shadow-sm p-5">
    <h3 class="text-sm font-semibold text-slate-700 mb-1">Frutos del mes</h3>
    <?php if (!$resumen['frutos'] && $resumen['fruto_campanias'] === null): ?>
      <p class="text-xs text-slate-400">Sin frutos registrados.</p>
    <?php else: ?>
      <ul class="text-sm text-slate-600 space-y-0.5">
        <?php foreach ($resumen['frutos'] as $f): ?>
          <li><span class="font-semibold text-emerald-700"><?= (int) $f['total'] ?></span> <?= h($f['etiqueta']) ?></li>
        <?php endforeach; ?>
        <?php if ($resumen['fruto_campanias'] !== null): ?>
          <li><span class="font-semibold text-emerald-700"><?= (int) $resumen['fruto_campanias'] ?></span> de campañas</li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- Tendencia -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-4">
  <h2 class="font-semibold text-slate-800 mb-3">Tendencia (6 meses)</h2>
  <?php
    $maxTend = max(array_map(static fn($t) => (float) $t['horas'], $tendencia));
    $maxTend = $maxTend > 0 ? $maxTend : 1.0;
  ?>
  <div class="flex items-end justify-between gap-2 h-32">
    <?php foreach ($tendencia as $t):
        $alt = max(4, (int) round((float) $t['horas'] / $maxTend * 100));
        $etq = DateTimeImmutable::createFromFormat('Y-m-d', $t['mes'] . '-01');
    ?>
      <div class="flex-1 flex flex-col items-center justify-end h-full">
        <span class="text-[10px] text-slate-500 mb-1"><?= h(rtrim(rtrim(number_format((float) $t['horas'], 1), '0'), '.')) ?></span>
        <div class="w-full bg-emerald-500 rounded-t" style="height: <?= $alt ?>%"></div>
        <span class="text-[10px] text-slate-400 mt-1"><?= h(ucfirst(substr($nombresMes[(int) $etq->format('n')], 0, 3))) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
