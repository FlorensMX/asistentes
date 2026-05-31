<?php
/**
 * public/admin_asistentes.php
 *
 * Administración de asistentes (solo admin): altas, baja/alta blanda,
 * cambio de rol y reseteo de contraseña.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/asistentes_repo.php';

$usuario = requerirRol('admin');

$ok    = null;
$error = null;

const ROLES_VALIDOS = ['asistente', 'pastor', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidar($_POST['csrf'] ?? null)) {
        $error = 'Sesión expirada. Recarga la página e intenta de nuevo.';
    } else {
        $accion = (string) ($_POST['accion'] ?? '');
        try {
            switch ($accion) {
                case 'crear':
                    $nombre  = trim((string) ($_POST['nombre'] ?? ''));
                    $usr     = strtolower(trim((string) ($_POST['usuario'] ?? '')));
                    $rol     = (string) ($_POST['rol'] ?? 'asistente');
                    $pass    = (string) ($_POST['password'] ?? '');
                    $tel     = (string) ($_POST['telefono'] ?? '');
                    if ($nombre === '' || $usr === '') {
                        $error = 'Nombre y usuario son obligatorios.';
                    } elseif (!preg_match('/^[a-z0-9._-]{3,60}$/', $usr)) {
                        $error = 'Usuario inválido (3-60 chars: letras, números, . _ -).';
                    } elseif (!in_array($rol, ROLES_VALIDOS, true)) {
                        $error = 'Rol inválido.';
                    } elseif (strlen($pass) < 8) {
                        $error = 'La contraseña inicial debe tener al menos 8 caracteres.';
                    } elseif (!usuarioDisponible($usr)) {
                        $error = "El usuario '$usr' ya existe.";
                    } else {
                        $nuevoId = crearAsistente($nombre, $usr, $pass, $rol, $tel);
                        $ok = "Asistente creado (id $nuevoId).";
                    }
                    break;

                case 'rol':
                    $rol = (string) ($_POST['rol'] ?? '');
                    if (!in_array($rol, ROLES_VALIDOS, true)) {
                        $error = 'Rol inválido.';
                    } else {
                        fijarRolAsistente((int) ($_POST['id'] ?? 0), $rol);
                        $ok = 'Rol actualizado.';
                    }
                    break;

                case 'activo':
                    fijarActivoAsistente(
                        (int) ($_POST['id'] ?? 0),
                        ((string) ($_POST['valor'] ?? '')) === '1'
                    );
                    $ok = 'Estado actualizado.';
                    break;

                case 'reset':
                    $pass = (string) ($_POST['password'] ?? '');
                    if (strlen($pass) < 8) {
                        $error = 'La nueva contraseña debe tener al menos 8 caracteres.';
                    } else {
                        resetearPassword((int) ($_POST['id'] ?? 0), $pass);
                        $ok = 'Contraseña restablecida.';
                    }
                    break;

                default:
                    $error = 'Acción no reconocida.';
            }
        } catch (PDOException $e) {
            $error = $e->getCode() === '23505'
                ? 'Ese usuario ya existe.'
                : 'Error de base de datos.';
            if ($e->getCode() !== '23505') error_log('[admin_asistentes] ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('[admin_asistentes] ' . $e->getMessage());
            $error = 'Ocurrió un error. Intenta de nuevo.';
        }
    }
}

$asistentes = listarAsistentes();
$csrf       = csrfToken();

$tituloPagina = 'Asistentes';
$anchoMax     = 'max-w-5xl';
$navActiva    = 'asistentes';
require __DIR__ . '/../includes/header.php';
?>

<?php if ($ok !== null): ?>
  <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 text-sm rounded-md px-3 py-2 mb-4"><?= h($ok) ?></div>
<?php endif; ?>
<?php if ($error !== null): ?>
  <div class="bg-rose-50 border border-rose-200 text-rose-800 text-sm rounded-md px-3 py-2 mb-4"><?= h($error) ?></div>
<?php endif; ?>

<!-- Alta -->
<section class="bg-white rounded-xl shadow-sm p-5 mb-6">
  <h2 class="font-semibold text-slate-800 mb-3">Dar de alta un asistente</h2>
  <form method="post" class="grid gap-2 sm:grid-cols-2">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="accion" value="crear">
    <input type="text" name="nombre" placeholder="Nombre completo" required
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <input type="text" name="usuario" placeholder="usuario" required autocomplete="off"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <input type="text" name="telefono" placeholder="Teléfono (opcional)"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
    <select name="rol" class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
      <option value="asistente">asistente</option>
      <option value="pastor">pastor</option>
      <option value="admin">admin</option>
    </select>
    <input type="text" name="password" placeholder="Contraseña inicial (mín. 8)" required autocomplete="off"
           class="border border-slate-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500 sm:col-span-2">
    <button class="bg-emerald-700 hover:bg-emerald-800 text-white font-medium py-2 px-4 rounded-md sm:col-span-2 justify-self-start">Crear</button>
  </form>
</section>

<!-- Listado -->
<section class="bg-white rounded-xl shadow-sm p-5">
  <h2 class="font-semibold text-slate-800 mb-3">Roster (<?= count($asistentes) ?>)</h2>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="text-left text-slate-500 border-b">
          <th class="py-2 pr-3">Nombre</th>
          <th class="py-2 pr-3">Usuario</th>
          <th class="py-2 pr-3">Rol</th>
          <th class="py-2 pr-3">Estado</th>
          <th class="py-2 pr-3">Contraseña</th>
        </tr>
      </thead>
      <tbody class="divide-y">
        <?php foreach ($asistentes as $a): ?>
          <tr class="<?= $a['activo'] ? '' : 'opacity-50' ?>">
            <td class="py-2 pr-3">
              <span class="font-medium text-slate-800"><?= h($a['nombre']) ?></span>
              <?php if ($a['telefono']): ?><div class="text-xs text-slate-400"><?= h($a['telefono']) ?></div><?php endif; ?>
            </td>
            <td class="py-2 pr-3 font-mono text-slate-600"><?= h($a['usuario']) ?></td>
            <td class="py-2 pr-3">
              <form method="post" class="flex items-center gap-1">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="accion" value="rol">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <select name="rol" onchange="this.form.submit()" class="border border-slate-300 rounded px-2 py-1 text-xs">
                  <?php foreach (ROLES_VALIDOS as $r): ?>
                    <option value="<?= $r ?>" <?= $a['rol'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="py-2 pr-3">
              <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="accion" value="activo">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="hidden" name="valor" value="<?= $a['activo'] ? '0' : '1' ?>">
                <?php if ($a['activo']): ?>
                  <button class="text-xs text-rose-600 hover:text-rose-800">Dar de baja</button>
                <?php else: ?>
                  <button class="text-xs text-emerald-600 hover:text-emerald-800">Reactivar</button>
                <?php endif; ?>
              </form>
            </td>
            <td class="py-2 pr-3">
              <form method="post" class="flex items-center gap-1" onsubmit="return confirm('¿Restablecer la contraseña de <?= h($a['nombre']) ?>?');">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="accion" value="reset">
                <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                <input type="text" name="password" placeholder="nueva (mín. 8)" required autocomplete="off"
                       class="border border-slate-300 rounded px-2 py-1 text-xs w-32">
                <button class="text-xs text-slate-600 hover:text-slate-900">Resetear</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
