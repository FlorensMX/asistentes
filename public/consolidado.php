<?php
/**
 * public/consolidado.php — Consolidado del mes (escritorio, rol pastor/admin)
 *
 * Tabla comparativa de todos los asistentes activos: horas ministeriales,
 * trabajo secular declarado (h/sem, aparte), asistencias a culto y días de
 * misión, con filtro de mes y enlace al detalle.
 *
 * Solo lectura. "El sistema muestra, el Pastor juzga": números y barras
 * neutrales, sin rankings con juicio, sin umbrales, sin dinero. El secular es
 * una carga SEMANAL declarada y se muestra aparte; nunca se suma al ministerial.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reportes_repo.php';

$usuario = requerirRol('pastor', 'admin');

$mes = (string) ($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = mesActual();
}
['ini' => $ini, 'fin' => $fin] = mesLimites($mes);

$filas = consolidadoMes($ini, $fin);

$d        = DateTimeImmutable::createFromFormat('Y-m-d', $mes . '-01');
$mesPrev  = $d->modify('-1 month')->format('Y-m');
$mesNext  = $d->modify('+1 month')->format('Y-m');
$esActual = $mes === mesActual();

$nombresMes = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
               7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
$mesLabel = ucfirst($nombresMes[(int) $d->format('n')]) . ' ' . $d->format('Y');

$maxHoras = 0.0;
foreach ($filas as $f) $maxHoras = max($maxHoras, (float) $f['horas_ministerial']);

$fmt = static fn($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');

$tituloPagina = 'Consolidado';
$anchoMax     = 'max-w-5xl';
$navActiva    = 'consolidado';
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

<p class="text-xs text-slate-500 mb-3">
  Horas ministeriales del mes (recurrentes + actividad + cultos; los días de campaña no cuentan).
  El trabajo secular es una carga <strong>semanal declarada</strong> y se muestra aparte.
</p>

<section class="bg-white rounded-xl shadow-sm p-5">
  <h2 class="font-semibold text-slate-800 mb-3">Asistentes (<?= count($filas) ?>)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-slate-500 border-b">
          <th class="py-2 pr-3">Asistente</th>
          <th class="py-2 pr-3">Horas ministeriales</th>
          <th class="py-2 pr-3 text-right">Secular (h/sem)</th>
          <th class="py-2 pr-3 text-right">Asistencias</th>
          <th class="py-2 pr-3 text-right">Días misión</th>
          <th class="py-2 pr-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($filas as $f):
            $pct = $maxHoras > 0 ? (int) round((float) $f['horas_ministerial'] / $maxHoras * 100) : 0;
        ?>
          <tr>
            <td class="py-2 pr-3 font-medium text-slate-800"><?= h($f['nombre']) ?></td>
            <td class="py-2 pr-3">
              <div class="flex items-center gap-2">
                <span class="w-12 text-slate-700 tabular-nums"><?= h($fmt($f['horas_ministerial'])) ?> h</span>
                <div class="flex-1 min-w-[80px] h-2 bg-slate-100 rounded-full overflow-hidden">
                  <div class="h-2 bg-emerald-500 rounded-full" style="width: <?= $pct ?>%"></div>
                </div>
              </div>
            </td>
            <td class="py-2 pr-3 text-right text-slate-500 tabular-nums">
              <?= $f['secular_h_sem'] !== null ? h($fmt($f['secular_h_sem'])) : '—' ?>
            </td>
            <td class="py-2 pr-3 text-right text-slate-600 tabular-nums"><?= (int) $f['asistencias_culto'] ?></td>
            <td class="py-2 pr-3 text-right text-amber-600 tabular-nums"><?= (int) $f['dias_mision'] ?></td>
            <td class="py-2 pr-3 text-right">
              <a href="detalle.php?asistente_id=<?= (int) $f['asistente_id'] ?>&mes=<?= h($mes) ?>"
                 class="text-emerald-700 hover:text-emerald-900">Detalle →</a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$filas): ?>
          <tr><td colspan="6" class="py-4 text-center text-slate-400">Sin asistentes activos.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
