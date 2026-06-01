<?php
/**
 * public/api/guardar_campania.php
 *
 * Alta / edición / eliminación de un periodo de campaña del asistente.
 *
 * Transporte: POST FormData (CSRF en 'csrf'), responde SIEMPRE JSON. Todo
 * scopeado al asistente de la sesión: nadie toca campañas de otro.
 *
 * POST:
 *   accion         'guardar' (def.) | 'eliminar'
 *   id             int (>0 = edición / objetivo de eliminación; 0 = alta)
 *   fecha_inicio   Y-m-d
 *   fecha_fin      Y-m-d   (>= fecha_inicio)
 *   lugar          texto (opcional)
 *   descripcion    texto (opcional)
 *   categoria_id   int (opcional → por defecto "Evangelismo y alcance")
 *   fruto_cantidad int (opcional, >= 0)
 *
 * Las campañas se miden como días de misión; NO se capturan horas ni montos.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/campanias_repo.php';
require_once __DIR__ . '/../../includes/catalogos_repo.php';

$u   = apiInit();
$uid = (int) $u['id'];

$accion = (string) ($_POST['accion'] ?? 'guardar');
$id     = (int) ($_POST['id'] ?? 0);

// --- Eliminación ---
if ($accion === 'eliminar') {
    if ($id <= 0) {
        jsonError('Campaña inválida.');
    }
    try {
        if (!eliminarCampania($id, $uid)) {
            jsonError('Esa campaña no es tuya o no existe.', 403);
        }
    } catch (Throwable $e) {
        error_log('[api/guardar_campania] ' . $e->getMessage());
        jsonError('No se pudo eliminar la campaña.', 500);
    }
    jsonOk(['id' => $id, 'modo' => 'eliminado']);
}

// --- Alta / edición ---
$fechaInicio = (string) ($_POST['fecha_inicio'] ?? '');
$fechaFin    = (string) ($_POST['fecha_fin'] ?? '');
$lugar       = (string) ($_POST['lugar'] ?? '');
$descripcion = (string) ($_POST['descripcion'] ?? '');
$categoriaId = postIntOpcional('categoria_id');
$frutoCant   = trim((string) ($_POST['fruto_cantidad'] ?? ''));

$reFecha = static function (string $f): bool {
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $f);
    return $d !== false && $d->format('Y-m-d') === $f;
};

if (!$reFecha($fechaInicio)) {
    jsonError('Fecha de inicio inválida.');
}
if (!$reFecha($fechaFin)) {
    jsonError('Fecha de fin inválida.');
}
if ($fechaFin < $fechaInicio) {
    jsonError('La fecha de fin no puede ser anterior a la de inicio.');
}

// Categoría: por defecto "Evangelismo y alcance"; si se indica, debe existir.
if ($categoriaId === null) {
    $categoriaId = categoriaIdPorNombre('Evangelismo y alcance');
} else {
    $existe = db()->prepare('SELECT 1 FROM categorias WHERE id = :c');
    $existe->execute(['c' => $categoriaId]);
    if ($existe->fetch() === false) {
        jsonError('Categoría inválida.');
    }
}

$frutoVal = null;
if ($frutoCant !== '') {
    $fc = (int) $frutoCant;
    if ($fc < 0) {
        jsonError('La cantidad de fruto no puede ser negativa.');
    }
    if ($fc > 1000000) { // tope sano: evita overflow de INTEGER en PostgreSQL
        jsonError('La cantidad de fruto es demasiado alta.');
    }
    $frutoVal = $fc;
}

try {
    if ($id > 0) {
        $ok = editarCampania($id, $uid, $fechaInicio, $fechaFin, $lugar, $descripcion, $categoriaId, $frutoVal);
        if (!$ok) {
            jsonError('Esa campaña no es tuya o no existe.', 403);
        }
        $modo = 'editado';
    } else {
        $id   = crearCampania($uid, $fechaInicio, $fechaFin, $lugar, $descripcion, $categoriaId, $frutoVal);
        $modo = 'creado';
    }
} catch (PDOException $e) {
    error_log('[api/guardar_campania] ' . $e->getMessage());
    jsonError('No se pudo guardar la campaña (datos inválidos).');
} catch (Throwable $e) {
    error_log('[api/guardar_campania] ' . $e->getMessage());
    jsonError('No se pudo guardar la campaña.', 500);
}

jsonOk(['modo' => $modo, 'campania' => campaniaPorId($id, $uid)]);
