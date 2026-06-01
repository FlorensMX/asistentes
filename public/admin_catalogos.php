<?php
/**
 * public/admin_catalogos.php — Mantenimiento de catálogos (rol pastor/admin)
 *
 * CRUD de categorías, actividades, cultos, funciones de culto y proyectos.
 * Escritorio. Sigue el patrón de admin_asistentes.php: POST clásico con CSRF y
 * un switch por entidad/acción; re-render con mensaje de ok/error.
 *
 * Borrado SUAVE (activo=FALSE) en las tablas con 'activo', para no romper
 * referencias históricas. 'categorias' no tiene 'activo': borrado duro y solo
 * si no está referenciada (si la FK lo impide, se avisa y no se borra).
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/catalogos_repo.php';
require_once __DIR__ . '/../includes/semana.php'; // nombreDiaSemana(), hhmm()

$usuario = requerirRol('pastor', 'admin');

const SECCIONES   = ['categorias', 'actividades', 'cultos', 'funciones', 'proyectos'];
const GRUPOS_FUNC = ['ministerial', 'servicio'];

$ok    = null;
$error = null;

// Sección activa (se conserva tras un POST).
$seccion = (string) ($_POST['seccion'] ?? ($_GET['seccion'] ?? 'categorias'));
if (!in_array($seccion, SECCIONES, true)) {
    $seccion = 'categorias';
}

$reHora  = '/^([01]\d|2[0-3]):[0-5]\d$/';
$reFecha = static function (string $f): bool {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
};
/** Lee una fecha opcional Y-m-d de $_POST; null si vacía. Lanza si inválida. */
$fechaOpc = static function (string $clave) use ($reFecha): ?string {
    $v = trim((string) ($_POST[$clave] ?? ''));
    if ($v === '') return null;
    if (!$reFecha($v)) throw new InvalidArgumentException('fecha');
    return $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página e intenta de nuevo.';
    } else {
        $entidad = (string) ($_POST['entidad'] ?? '');
        $accion  = (string) ($_POST['accion'] ?? '');
        $id      = (int) ($_POST['id'] ?? 0);
        try {
            switch ($entidad) {

                // ---------------- CATEGORÍAS ----------------
                case 'categoria':
                    $nombre = trim((string) ($_POST['nombre'] ?? ''));
                    $orden  = (int) ($_POST['orden'] ?? 0);
                    if ($accion === 'eliminar') {
                        try {
                            eliminarCategoria($id);
                            $ok = 'Categoría eliminada.';
                        } catch (PDOException $e) {
                            if ($e->getCode() === '23503') {
                                $error = 'No se puede eliminar: la categoría está en uso. Quítala de sus referencias primero.';
                            } else {
                                throw $e;
                            }
                        }
                    } elseif ($nombre === '') {
                        $error = 'El nombre de la categoría es obligatorio.';
                    } elseif ($accion === 'editar' && $id > 0) {
                        if (actualizarCategoria($id, $nombre, $orden)) $ok = 'Categoría actualizada.';
                        else $error = 'No se encontró la categoría.';
                    } else {
                        crearCategoria($nombre, $orden);
                        $ok = 'Categoría creada.';
                    }
                    break;

                // ---------------- ACTIVIDADES ----------------
                case 'actividad':
                    if ($accion === 'activo') {
                        fijarActivoActividad($id, ((string) ($_POST['valor'] ?? '')) === '1');
                        $ok = 'Estado de la actividad actualizado.';
                        break;
                    }
                    $categoriaId = (int) ($_POST['categoria_id'] ?? 0);
                    $nombre      = trim((string) ($_POST['nombre'] ?? ''));
                    $llevaFruto  = ((string) ($_POST['lleva_fruto'] ?? '')) === '1';
                    $etiqueta    = trim((string) ($_POST['etiqueta_fruto'] ?? ''));
                    if ($categoriaId <= 0)      { $error = 'Selecciona una categoría.'; break; }
                    if ($nombre === '')         { $error = 'El nombre de la actividad es obligatorio.'; break; }
                    if ($accion === 'editar' && $id > 0) {
                        if (actualizarActividad($id, $categoriaId, $nombre, $llevaFruto, $etiqueta)) $ok = 'Actividad actualizada.';
                        else $error = 'No se encontró la actividad.';
                    } else {
                        crearActividad($categoriaId, $nombre, $llevaFruto, $etiqueta);
                        $ok = 'Actividad creada.';
                    }
                    break;

                // ---------------- CULTOS ----------------
                case 'culto':
                    if ($accion === 'activo') {
                        fijarActivoCulto($id, ((string) ($_POST['valor'] ?? '')) === '1');
                        $ok = 'Estado del culto actualizado.';
                        break;
                    }
                    $nombre      = trim((string) ($_POST['nombre'] ?? ''));
                    $diaSemana   = (int) ($_POST['dia_semana'] ?? -1);
                    $horaInicio  = trim((string) ($_POST['hora_inicio'] ?? ''));
                    $horaFin     = trim((string) ($_POST['hora_fin'] ?? ''));
                    $finVariable = ((string) ($_POST['fin_variable'] ?? '')) === '1';
                    $esReunion   = ((string) ($_POST['es_reunion'] ?? '')) === '1';
                    if ($nombre === '')                       { $error = 'El nombre del culto es obligatorio.'; break; }
                    if ($diaSemana < 0 || $diaSemana > 6)     { $error = 'Día de la semana inválido.'; break; }
                    if (!preg_match($reHora, $horaInicio))    { $error = 'Hora de inicio inválida.'; break; }
                    if (!$finVariable) {
                        if (!preg_match($reHora, $horaFin))   { $error = 'Hora de fin inválida (o marca «fin variable»).'; break; }
                        if ($horaFin <= $horaInicio)          { $error = 'La hora de fin debe ser posterior a la de inicio.'; break; }
                    } else {
                        $horaFin = ''; // fin variable: sin hora_fin
                    }
                    if ($accion === 'editar' && $id > 0) {
                        if (actualizarCulto($id, $nombre, $diaSemana, $horaInicio, $horaFin ?: null, $finVariable, $esReunion)) $ok = 'Culto actualizado.';
                        else $error = 'No se encontró el culto.';
                    } else {
                        crearCulto($nombre, $diaSemana, $horaInicio, $horaFin ?: null, $finVariable, $esReunion);
                        $ok = 'Culto creado.';
                    }
                    break;

                // ---------------- FUNCIONES DE CULTO ----------------
                case 'funcion':
                    if ($accion === 'activo') {
                        fijarActivoFuncionCulto($id, ((string) ($_POST['valor'] ?? '')) === '1');
                        $ok = 'Estado de la función actualizado.';
                        break;
                    }
                    $nombre     = trim((string) ($_POST['nombre'] ?? ''));
                    $grupo      = (string) ($_POST['grupo'] ?? '');
                    $llevaFruto = ((string) ($_POST['lleva_fruto'] ?? '')) === '1';
                    $etiqueta   = trim((string) ($_POST['etiqueta_fruto'] ?? ''));
                    if ($nombre === '')                         { $error = 'El nombre de la función es obligatorio.'; break; }
                    if (!in_array($grupo, GRUPOS_FUNC, true))   { $error = 'Grupo inválido.'; break; }
                    if ($accion === 'editar' && $id > 0) {
                        if (actualizarFuncionCulto($id, $nombre, $grupo, $llevaFruto, $etiqueta)) $ok = 'Función actualizada.';
                        else $error = 'No se encontró la función.';
                    } else {
                        crearFuncionCulto($nombre, $grupo, $llevaFruto, $etiqueta);
                        $ok = 'Función creada.';
                    }
                    break;

                // ---------------- PROYECTOS ----------------
                case 'proyecto':
                    if ($accion === 'activo') {
                        fijarActivoProyecto($id, ((string) ($_POST['valor'] ?? '')) === '1');
                        $ok = 'Estado del proyecto actualizado.';
                        break;
                    }
                    $nombre   = trim((string) ($_POST['nombre'] ?? ''));
                    $obs      = trim((string) ($_POST['observaciones'] ?? ''));
                    $catId    = ($_POST['categoria_id'] ?? '') === '' ? null : (int) $_POST['categoria_id'];
                    $fInicio  = $fechaOpc('fecha_inicio');
                    $fFin     = $fechaOpc('fecha_fin');
                    if ($nombre === '') { $error = 'El nombre del proyecto es obligatorio.'; break; }
                    if ($fInicio !== null && $fFin !== null && $fFin < $fInicio) {
                        $error = 'La fecha de fin no puede ser anterior a la de inicio.'; break;
                    }
                    if ($accion === 'editar' && $id > 0) {
                        if (actualizarProyecto($id, $nombre, $obs, $catId, $fInicio, $fFin)) $ok = 'Proyecto actualizado.';
                        else $error = 'No se encontró el proyecto.';
                    } else {
                        crearProyecto($nombre, $obs, $catId, $fInicio, $fFin);
                        $ok = 'Proyecto creado.';
                    }
                    break;

                default:
                    $error = 'Entidad no reconocida.';
            }
        } catch (InvalidArgumentException $e) {
            $error = 'Fecha inválida.';
        } catch (PDOException $e) {
            $error = $e->getCode() === '23505'
                ? 'Ya existe un registro con ese nombre.'
                : 'Error de base de datos.';
            if ($e->getCode() !== '23505') error_log('[admin_catalogos] ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('[admin_catalogos] ' . $e->getMessage());
            $error = 'Ocurrió un error. Intenta de nuevo.';
        }
    }
}

// --- Datos para render ---
$categorias  = listarCategorias();
$actividades = listarActividades(null, false);
$cultos      = listarCultos(false);
$funciones   = listarFuncionesCulto(null, false);
$proyectos   = listarProyectos(false);
$csrf        = csrfToken();

$diasOrden = [1, 2, 3, 4, 5, 6, 0]; // presentar lunes→domingo con valores 0..6

$tituloPagina = 'Catálogos';
$anchoMax     = 'max-w-5xl';
$navActiva    = 'catalogos';
require __DIR__ . '/../includes/header.php';

/** Clase de pestaña activa/inactiva para el selector de secciones. */
$pill = static function (string $s) use ($seccion): string {
    return $s === $seccion
        ? 'px-3 py-1.5 rounded-full bg-emerald-600 text-white text-sm'
        : 'px-3 py-1.5 rounded-full bg-white border text-slate-600 text-sm hover:border-emerald-400';
};
$etiquetas = [
    'categorias'  => 'Categorías',
    'actividades' => 'Actividades',
    'cultos'      => 'Cultos',
    'funciones'   => 'Funciones de culto',
    'proyectos'   => 'Proyectos',
];
?>

<?php if ($ok !== null): ?>
  <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-md px-3 py-2 mb-4"><?= h($ok) ?></div>
<?php endif; ?>
<?php if ($error !== null): ?>
  <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-md px-3 py-2 mb-4"><?= h($error) ?></div>
<?php endif; ?>

<nav class="flex flex-wrap gap-2 mb-6">
  <?php foreach ($etiquetas as $clave => $txt): ?>
    <a href="?seccion=<?= h($clave) ?>" class="<?= $pill($clave) ?>"><?= h($txt) ?></a>
  <?php endforeach; ?>
</nav>

<?php /* ======================= CATEGORÍAS ======================= */ ?>
<?php if ($seccion === 'categorias'): ?>
<section class="bg-white rounded-xl shadow-sm p-5 mb-6">
  <h2 id="cat-titulo" class="font-semibold text-slate-800 mb-3">Nueva categoría</h2>
  <form method="post" class="grid gap-2 sm:grid-cols-[1fr_8rem_auto]">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="entidad" value="categoria">
    <input type="hidden" name="seccion" value="categorias">
    <input type="hidden" name="accion" id="cat-accion" value="crear">
    <input type="hidden" name="id" id="cat-id" value="0">
    <input type="text" name="nombre" id="cat-nombre" placeholder="Nombre" required
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <input type="number" name="orden" id="cat-orden" value="0" placeholder="Orden"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <div class="flex gap-2">
      <button class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar</button>
      <button type="button" id="cat-cancelar" class="hidden text-slate-500 py-2 px-2">Cancelar</button>
    </div>
  </form>
  <p class="text-xs text-slate-400 mt-2">Las categorías no se desactivan: solo se eliminan si no están en uso.</p>
</section>
<section class="bg-white rounded-xl shadow-sm p-5">
  <h2 class="font-semibold text-slate-800 mb-3">Categorías (<?= count($categorias) ?>)</h2>
  <table class="w-full text-sm">
    <thead><tr class="text-left text-slate-500 border-b">
      <th class="py-2 pr-3">Nombre</th><th class="py-2 pr-3 w-24">Orden</th><th class="py-2 pr-3 w-40">Acciones</th>
    </tr></thead>
    <tbody class="divide-y">
      <?php foreach ($categorias as $c): ?>
        <tr>
          <td class="py-2 pr-3 font-medium text-slate-800"><?= h($c['nombre']) ?></td>
          <td class="py-2 pr-3 text-slate-600"><?= (int) $c['orden'] ?></td>
          <td class="py-2 pr-3">
            <button class="js-cat-editar text-emerald-700"
                    data-id="<?= (int) $c['id'] ?>" data-nombre="<?= h($c['nombre']) ?>" data-orden="<?= (int) $c['orden'] ?>">Editar</button>
            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la categoría «<?= h($c['nombre']) ?>»?');">
              <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
              <input type="hidden" name="entidad" value="categoria">
              <input type="hidden" name="seccion" value="categorias">
              <input type="hidden" name="accion" value="eliminar">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button class="text-rose-600 ml-2">Eliminar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<script>
(function () {
  document.querySelectorAll('.js-cat-editar').forEach(b => b.addEventListener('click', () => {
    document.getElementById('cat-id').value     = b.dataset.id;
    document.getElementById('cat-nombre').value = b.dataset.nombre;
    document.getElementById('cat-orden').value  = b.dataset.orden;
    document.getElementById('cat-accion').value = 'editar';
    document.getElementById('cat-titulo').textContent = 'Editar categoría';
    document.getElementById('cat-cancelar').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.getElementById('cat-cancelar').addEventListener('click', () => location.href = '?seccion=categorias');
})();
</script>
<?php endif; ?>

<?php /* ======================= ACTIVIDADES ======================= */ ?>
<?php if ($seccion === 'actividades'): ?>
<section class="bg-white rounded-xl shadow-sm p-5 mb-6">
  <h2 id="act-titulo" class="font-semibold text-slate-800 mb-3">Nueva actividad</h2>
  <form method="post" class="grid gap-2 sm:grid-cols-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="entidad" value="actividad">
    <input type="hidden" name="seccion" value="actividades">
    <input type="hidden" name="accion" id="act-accion" value="crear">
    <input type="hidden" name="id" id="act-id" value="0">
    <select name="categoria_id" id="act-categoria" required
            class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="">Categoría…</option>
      <?php foreach ($categorias as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= h($c['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="text" name="nombre" id="act-nombre" placeholder="Nombre de la actividad" required
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <label class="flex items-center gap-2 text-sm text-slate-700">
      <input type="checkbox" name="lleva_fruto" id="act-fruto" value="1" class="rounded"> Lleva fruto
    </label>
    <input type="text" name="etiqueta_fruto" id="act-etiqueta" placeholder="Etiqueta de fruto (p. ej. contactos)"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <div class="flex gap-2 sm:col-span-2">
      <button class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar</button>
      <button type="button" id="act-cancelar" class="hidden text-slate-500 py-2 px-2">Cancelar</button>
    </div>
  </form>
</section>
<section class="bg-white rounded-xl shadow-sm p-5">
  <h2 class="font-semibold text-slate-800 mb-3">Actividades (<?= count($actividades) ?>)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-slate-500 border-b">
        <th class="py-2 pr-3">Actividad</th><th class="py-2 pr-3">Categoría</th>
        <th class="py-2 pr-3">Fruto</th><th class="py-2 pr-3">Estado</th><th class="py-2 pr-3 w-40">Acciones</th>
      </tr></thead>
      <tbody class="divide-y">
        <?php foreach ($actividades as $a): ?>
          <tr class="<?= $a['activo'] ? '' : 'opacity-50' ?>">
            <td class="py-2 pr-3 font-medium text-slate-800"><?= h($a['nombre']) ?></td>
            <td class="py-2 pr-3 text-slate-600"><?= h($a['categoria']) ?></td>
            <td class="py-2 pr-3 text-slate-600"><?= $a['lleva_fruto'] ? h($a['etiqueta_fruto'] ?: 'sí') : '—' ?></td>
            <td class="py-2 pr-3"><?= $a['activo'] ? '<span class="text-emerald-700">activa</span>' : '<span class="text-slate-400">inactiva</span>' ?></td>
            <td class="py-2 pr-3">
              <button class="js-act-editar text-emerald-700"
                      data-id="<?= (int) $a['id'] ?>" data-categoria="<?= (int) $a['categoria_id'] ?>"
                      data-nombre="<?= h($a['nombre']) ?>" data-fruto="<?= $a['lleva_fruto'] ? '1' : '0' ?>"
                      data-etiqueta="<?= h($a['etiqueta_fruto'] ?? '') ?>">Editar</button>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="entidad" value="actividad">
                <input type="hidden" name="seccion" value="actividades">
                <input type="hidden" name="accion" value="activo">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="valor" value="<?= $a['activo'] ? '0' : '1' ?>">
                <button class="ml-2 <?= $a['activo'] ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $a['activo'] ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script>
(function () {
  document.querySelectorAll('.js-act-editar').forEach(b => b.addEventListener('click', () => {
    document.getElementById('act-id').value        = b.dataset.id;
    document.getElementById('act-categoria').value = b.dataset.categoria;
    document.getElementById('act-nombre').value    = b.dataset.nombre;
    document.getElementById('act-fruto').checked   = b.dataset.fruto === '1';
    document.getElementById('act-etiqueta').value  = b.dataset.etiqueta;
    document.getElementById('act-accion').value    = 'editar';
    document.getElementById('act-titulo').textContent = 'Editar actividad';
    document.getElementById('act-cancelar').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.getElementById('act-cancelar').addEventListener('click', () => location.href = '?seccion=actividades');
})();
</script>
<?php endif; ?>

<?php /* ======================= CULTOS ======================= */ ?>
<?php if ($seccion === 'cultos'): ?>
<section class="bg-white rounded-xl shadow-sm p-5 mb-6">
  <h2 id="cul-titulo" class="font-semibold text-slate-800 mb-3">Nuevo culto</h2>
  <form method="post" class="grid gap-2 sm:grid-cols-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="entidad" value="culto">
    <input type="hidden" name="seccion" value="cultos">
    <input type="hidden" name="accion" id="cul-accion" value="crear">
    <input type="hidden" name="id" id="cul-id" value="0">
    <input type="text" name="nombre" id="cul-nombre" placeholder="Nombre (p. ej. Culto A)" required
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:col-span-2">
    <select name="dia_semana" id="cul-dia" required
            class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <?php foreach ($diasOrden as $ds): ?>
        <option value="<?= $ds ?>"><?= h(nombreDiaSemana($ds)) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="grid grid-cols-2 gap-2">
      <input type="time" name="hora_inicio" id="cul-hi" required
             class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <input type="time" name="hora_fin" id="cul-hf"
             class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
    <label class="flex items-center gap-2 text-sm text-slate-700">
      <input type="checkbox" name="fin_variable" id="cul-fv" value="1" class="rounded"> Fin variable (sin hora de fin)
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-700">
      <input type="checkbox" name="es_reunion" id="cul-er" value="1" class="rounded"> Es reunión (no culto público)
    </label>
    <div class="flex gap-2 sm:col-span-2">
      <button class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar</button>
      <button type="button" id="cul-cancelar" class="hidden text-slate-500 py-2 px-2">Cancelar</button>
    </div>
  </form>
</section>
<section class="bg-white rounded-xl shadow-sm p-5">
  <h2 class="font-semibold text-slate-800 mb-3">Cultos (<?= count($cultos) ?>)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-slate-500 border-b">
        <th class="py-2 pr-3">Nombre</th><th class="py-2 pr-3">Día</th><th class="py-2 pr-3">Horario</th>
        <th class="py-2 pr-3">Tipo</th><th class="py-2 pr-3">Estado</th><th class="py-2 pr-3 w-40">Acciones</th>
      </tr></thead>
      <tbody class="divide-y">
        <?php foreach ($cultos as $c): ?>
          <tr class="<?= $c['activo'] ? '' : 'opacity-50' ?>">
            <td class="py-2 pr-3 font-medium text-slate-800"><?= h($c['nombre']) ?></td>
            <td class="py-2 pr-3 text-slate-600"><?= h(nombreDiaSemana((int) $c['dia_semana'])) ?></td>
            <td class="py-2 pr-3 text-slate-600">
              <?= h(hhmm($c['hora_inicio'])) ?><?= $c['fin_variable'] ? ' (fin variable)' : ('–' . h(hhmm($c['hora_fin']))) ?>
            </td>
            <td class="py-2 pr-3 text-slate-600"><?= $c['es_reunion'] ? 'reunión' : 'culto' ?></td>
            <td class="py-2 pr-3"><?= $c['activo'] ? '<span class="text-emerald-700">activo</span>' : '<span class="text-slate-400">inactivo</span>' ?></td>
            <td class="py-2 pr-3">
              <button class="js-cul-editar text-emerald-700"
                      data-id="<?= (int) $c['id'] ?>" data-nombre="<?= h($c['nombre']) ?>" data-dia="<?= (int) $c['dia_semana'] ?>"
                      data-hi="<?= h(hhmm($c['hora_inicio'])) ?>" data-hf="<?= h(hhmm($c['hora_fin'])) ?>"
                      data-fv="<?= $c['fin_variable'] ? '1' : '0' ?>" data-er="<?= $c['es_reunion'] ? '1' : '0' ?>">Editar</button>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="entidad" value="culto">
                <input type="hidden" name="seccion" value="cultos">
                <input type="hidden" name="accion" value="activo">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                <input type="hidden" name="valor" value="<?= $c['activo'] ? '0' : '1' ?>">
                <button class="ml-2 <?= $c['activo'] ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $c['activo'] ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script>
(function () {
  document.querySelectorAll('.js-cul-editar').forEach(b => b.addEventListener('click', () => {
    document.getElementById('cul-id').value     = b.dataset.id;
    document.getElementById('cul-nombre').value = b.dataset.nombre;
    document.getElementById('cul-dia').value    = b.dataset.dia;
    document.getElementById('cul-hi').value     = b.dataset.hi;
    document.getElementById('cul-hf').value     = b.dataset.hf;
    document.getElementById('cul-fv').checked   = b.dataset.fv === '1';
    document.getElementById('cul-er').checked   = b.dataset.er === '1';
    document.getElementById('cul-accion').value = 'editar';
    document.getElementById('cul-titulo').textContent = 'Editar culto';
    document.getElementById('cul-cancelar').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.getElementById('cul-cancelar').addEventListener('click', () => location.href = '?seccion=cultos');
})();
</script>
<?php endif; ?>

<?php /* ======================= FUNCIONES DE CULTO ======================= */ ?>
<?php if ($seccion === 'funciones'): ?>
<section class="bg-white rounded-xl shadow-sm p-5 mb-6">
  <h2 id="fun-titulo" class="font-semibold text-slate-800 mb-3">Nueva función de culto</h2>
  <form method="post" class="grid gap-2 sm:grid-cols-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="entidad" value="funcion">
    <input type="hidden" name="seccion" value="funciones">
    <input type="hidden" name="accion" id="fun-accion" value="crear">
    <input type="hidden" name="id" id="fun-id" value="0">
    <input type="text" name="nombre" id="fun-nombre" placeholder="Nombre de la función" required
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <select name="grupo" id="fun-grupo" required
            class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="ministerial">ministerial</option>
      <option value="servicio">servicio</option>
    </select>
    <label class="flex items-center gap-2 text-sm text-slate-700">
      <input type="checkbox" name="lleva_fruto" id="fun-fruto" value="1" class="rounded"> Lleva fruto
    </label>
    <input type="text" name="etiqueta_fruto" id="fun-etiqueta" placeholder="Etiqueta de fruto (p. ej. decisiones)"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <div class="flex gap-2 sm:col-span-2">
      <button class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar</button>
      <button type="button" id="fun-cancelar" class="hidden text-slate-500 py-2 px-2">Cancelar</button>
    </div>
  </form>
</section>
<section class="bg-white rounded-xl shadow-sm p-5">
  <h2 class="font-semibold text-slate-800 mb-3">Funciones de culto (<?= count($funciones) ?>)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-slate-500 border-b">
        <th class="py-2 pr-3">Función</th><th class="py-2 pr-3">Grupo</th>
        <th class="py-2 pr-3">Fruto</th><th class="py-2 pr-3">Estado</th><th class="py-2 pr-3 w-40">Acciones</th>
      </tr></thead>
      <tbody class="divide-y">
        <?php foreach ($funciones as $f): ?>
          <tr class="<?= $f['activo'] ? '' : 'opacity-50' ?>">
            <td class="py-2 pr-3 font-medium text-slate-800"><?= h($f['nombre']) ?></td>
            <td class="py-2 pr-3 text-slate-600"><?= h($f['grupo']) ?></td>
            <td class="py-2 pr-3 text-slate-600"><?= $f['lleva_fruto'] ? h($f['etiqueta_fruto'] ?: 'sí') : '—' ?></td>
            <td class="py-2 pr-3"><?= $f['activo'] ? '<span class="text-emerald-700">activa</span>' : '<span class="text-slate-400">inactiva</span>' ?></td>
            <td class="py-2 pr-3">
              <button class="js-fun-editar text-emerald-700"
                      data-id="<?= (int) $f['id'] ?>" data-nombre="<?= h($f['nombre']) ?>" data-grupo="<?= h($f['grupo']) ?>"
                      data-fruto="<?= $f['lleva_fruto'] ? '1' : '0' ?>" data-etiqueta="<?= h($f['etiqueta_fruto'] ?? '') ?>">Editar</button>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="entidad" value="funcion">
                <input type="hidden" name="seccion" value="funciones">
                <input type="hidden" name="accion" value="activo">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <input type="hidden" name="valor" value="<?= $f['activo'] ? '0' : '1' ?>">
                <button class="ml-2 <?= $f['activo'] ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $f['activo'] ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script>
(function () {
  document.querySelectorAll('.js-fun-editar').forEach(b => b.addEventListener('click', () => {
    document.getElementById('fun-id').value       = b.dataset.id;
    document.getElementById('fun-nombre').value   = b.dataset.nombre;
    document.getElementById('fun-grupo').value    = b.dataset.grupo;
    document.getElementById('fun-fruto').checked  = b.dataset.fruto === '1';
    document.getElementById('fun-etiqueta').value = b.dataset.etiqueta;
    document.getElementById('fun-accion').value   = 'editar';
    document.getElementById('fun-titulo').textContent = 'Editar función';
    document.getElementById('fun-cancelar').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.getElementById('fun-cancelar').addEventListener('click', () => location.href = '?seccion=funciones');
})();
</script>
<?php endif; ?>

<?php /* ======================= PROYECTOS ======================= */ ?>
<?php if ($seccion === 'proyectos'): ?>
<section class="bg-white rounded-xl shadow-sm p-5 mb-6">
  <h2 id="pro-titulo" class="font-semibold text-slate-800 mb-3">Nuevo proyecto</h2>
  <form method="post" class="grid gap-2 sm:grid-cols-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="entidad" value="proyecto">
    <input type="hidden" name="seccion" value="proyectos">
    <input type="hidden" name="accion" id="pro-accion" value="crear">
    <input type="hidden" name="id" id="pro-id" value="0">
    <input type="text" name="nombre" id="pro-nombre" placeholder="Nombre del proyecto" required
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:col-span-2">
    <select name="categoria_id" id="pro-categoria"
            class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="">Categoría (opcional)…</option>
      <?php foreach ($categorias as $c): ?>
        <option value="<?= (int) $c['id'] ?>"><?= h($c['nombre']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="grid grid-cols-2 gap-2">
      <input type="date" name="fecha_inicio" id="pro-fi"
             class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <input type="date" name="fecha_fin" id="pro-ff"
             class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    </div>
    <textarea name="observaciones" id="pro-obs" rows="2" placeholder="Observaciones (p. ej. título del libro)"
              class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:col-span-2"></textarea>
    <div class="flex gap-2 sm:col-span-2">
      <button class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md">Guardar</button>
      <button type="button" id="pro-cancelar" class="hidden text-slate-500 py-2 px-2">Cancelar</button>
    </div>
  </form>
</section>
<section class="bg-white rounded-xl shadow-sm p-5">
  <h2 class="font-semibold text-slate-800 mb-3">Proyectos (<?= count($proyectos) ?>)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-slate-500 border-b">
        <th class="py-2 pr-3">Nombre</th><th class="py-2 pr-3">Categoría</th>
        <th class="py-2 pr-3">Temporada</th><th class="py-2 pr-3">Estado</th><th class="py-2 pr-3 w-40">Acciones</th>
      </tr></thead>
      <tbody class="divide-y">
        <?php foreach ($proyectos as $p): ?>
          <tr class="<?= $p['activo'] ? '' : 'opacity-50' ?>">
            <td class="py-2 pr-3">
              <span class="font-medium text-slate-800"><?= h($p['nombre']) ?></span>
              <?php if ($p['observaciones'] !== null && $p['observaciones'] !== ''): ?>
                <div class="text-xs text-slate-400"><?= h($p['observaciones']) ?></div>
              <?php endif; ?>
            </td>
            <td class="py-2 pr-3 text-slate-600"><?= h($p['categoria'] ?? '—') ?></td>
            <td class="py-2 pr-3 text-slate-600">
              <?php if ($p['fecha_inicio'] || $p['fecha_fin']): ?>
                <?= h($p['fecha_inicio'] ?? '…') ?> – <?= h($p['fecha_fin'] ?? '…') ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="py-2 pr-3"><?= $p['activo'] ? '<span class="text-emerald-700">activo</span>' : '<span class="text-slate-400">inactivo</span>' ?></td>
            <td class="py-2 pr-3">
              <button class="js-pro-editar text-emerald-700"
                      data-id="<?= (int) $p['id'] ?>" data-nombre="<?= h($p['nombre']) ?>"
                      data-categoria="<?= $p['categoria_id'] !== null ? (int) $p['categoria_id'] : '' ?>"
                      data-fi="<?= h($p['fecha_inicio'] ?? '') ?>" data-ff="<?= h($p['fecha_fin'] ?? '') ?>"
                      data-obs="<?= h($p['observaciones'] ?? '') ?>">Editar</button>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="entidad" value="proyecto">
                <input type="hidden" name="seccion" value="proyectos">
                <input type="hidden" name="accion" value="activo">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <input type="hidden" name="valor" value="<?= $p['activo'] ? '0' : '1' ?>">
                <button class="ml-2 <?= $p['activo'] ? 'text-rose-600' : 'text-emerald-600' ?>"><?= $p['activo'] ? 'Desactivar' : 'Activar' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<script>
(function () {
  document.querySelectorAll('.js-pro-editar').forEach(b => b.addEventListener('click', () => {
    document.getElementById('pro-id').value        = b.dataset.id;
    document.getElementById('pro-nombre').value    = b.dataset.nombre;
    document.getElementById('pro-categoria').value = b.dataset.categoria || '';
    document.getElementById('pro-fi').value        = b.dataset.fi;
    document.getElementById('pro-ff').value        = b.dataset.ff;
    document.getElementById('pro-obs').value       = b.dataset.obs;
    document.getElementById('pro-accion').value    = 'editar';
    document.getElementById('pro-titulo').textContent = 'Editar proyecto';
    document.getElementById('pro-cancelar').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }));
  document.getElementById('pro-cancelar').addEventListener('click', () => location.href = '?seccion=proyectos');
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
