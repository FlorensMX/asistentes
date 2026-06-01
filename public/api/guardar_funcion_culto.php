<?php
/**
 * public/api/guardar_funcion_culto.php
 *
 * Confirma (o quita) la asistencia del asistente a un culto en una fecha y, en
 * el mismo flujo, sincroniza las FUNCIONES desempeñadas en él.
 *
 * Transporte: POST application/x-www-form-urlencoded (FormData), igual que el
 * resto de api/ del sistema; CSRF en el campo 'csrf' (lo valida apiInit()).
 * Responde SIEMPRE JSON.
 *
 * POST:
 *   culto_id     int
 *   fecha        Y-m-d   (su día de semana debe coincidir con cultos.dia_semana)
 *   asistio      1|0
 *   hora_salida  HH:MM   (solo se guarda si el culto es fin_variable)
 *   funciones    JSON    (opcional) array de {funcion_culto_id:int, fruto_cantidad?:int}
 *
 * Semántica de 'funciones':
 *   - AUSENTE  → solo se confirma/actualiza la asistencia; las funciones ya
 *                marcadas NO se tocan (permite el toque rápido "solo asistí"
 *                sin borrar lo capturado en el panel).
 *   - PRESENTE → se sincroniza el conjunto exacto (incluido "[]" = sin funciones).
 *
 * Reglas: el culto existe y está activo; las funciones existen y están activas;
 * el fruto solo se acepta donde lleva_fruto=TRUE. Todo scopeado al asistente de
 * la sesión. Idempotente: reenviar el mismo cuerpo deja el mismo estado.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';
require_once __DIR__ . '/../../includes/cultos_repo.php';
require_once __DIR__ . '/../../includes/catalogos_repo.php';
require_once __DIR__ . '/../../includes/campanias_repo.php';

$u   = apiInit();
$uid = (int) $u['id'];

$cultoId    = (int) ($_POST['culto_id'] ?? 0);
$fecha      = (string) ($_POST['fecha'] ?? '');
$asistio    = ((string) ($_POST['asistio'] ?? '1')) === '1';
$horaSalida = trim((string) ($_POST['hora_salida'] ?? ''));

if ($cultoId <= 0) {
    jsonError('Culto inválido.');
}

$d = DateTimeImmutable::createFromFormat('Y-m-d', $fecha);
if (!$d || $d->format('Y-m-d') !== $fecha) {
    jsonError('Fecha inválida.');
}

// El culto debe existir y estar activo. Tomamos dia_semana, fin_variable y hora_inicio.
$stmt = db()->prepare(
    'SELECT dia_semana, fin_variable, hora_inicio FROM cultos WHERE id = :id AND activo = TRUE'
);
$stmt->execute(['id' => $cultoId]);
$culto = $stmt->fetch();
if (!$culto) {
    jsonError('Ese culto no existe o está inactivo.');
}

// El día de la semana de la fecha debe coincidir con el del culto (0=Dom..6=Sáb).
if ((int) $d->format('w') !== (int) $culto['dia_semana']) {
    jsonError('La fecha no corresponde al día de este culto.');
}

// Suspensión por campaña: si el día cae en un periodo del asistente, sus cultos
// "no se piden ni cuentan". No se permite REGISTRAR asistencia en esos días
// (sí se permite quitarla, para poder limpiar). Defensa de fondo: la UI ya los
// muestra suspendidos, pero un POST directo o una página vieja no deben colar.
if ($asistio && estaEnCampania($uid, $fecha)) {
    jsonError('Ese día está en campaña (suspendido): sus cultos no se piden ni cuentan.', 409);
}

$finVariable = (bool) $culto['fin_variable'];

// hora_salida: solo tiene sentido en cultos de fin variable (Junta de Asistentes).
// Se asume que el culto de fin variable ocurre en un mismo día (cierto para la
// Junta, 11:30). Si algún día se agrega un culto de fin variable nocturno que
// cruce medianoche a propósito, habría que revisar esta regla.
$horaSalidaVal = null;
if ($finVariable && $horaSalida !== '') {
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $horaSalida)) {
        jsonError('Hora de salida inválida.');
    }
    // La salida debe ser POSTERIOR al inicio del culto: ataja el typo en captura
    // (p. ej. 00:30 por 13:00, que el agregado inflaría a ~13 h). Ambas quedan en
    // "HH:MM" con cero a la izquierda, así que el orden alfabético == cronológico.
    if (substr($horaSalida, 0, 5) <= substr((string) $culto['hora_inicio'], 0, 5)) {
        jsonError('La hora de salida debe ser posterior a la hora de inicio del culto.');
    }
    $horaSalidaVal = $horaSalida;
}

// ¿Vienen funciones a sincronizar? (presencia del campo, no su contenido)
$sincronizar   = array_key_exists('funciones', $_POST);
$funcionesSync = [];
$activasIds    = [];   // funciones activas: alcance borrable del sync (preserva históricas)

const FRUTO_MAX = 1000000; // tope sano: evita overflow de INTEGER en PostgreSQL

if ($sincronizar && $asistio) {
    $crudo = (string) ($_POST['funciones'] ?? '');
    $lista = ($crudo === '') ? [] : json_decode($crudo, true);
    if (!is_array($lista)) {
        jsonError('Formato de funciones inválido.');
    }

    // Catálogo de funciones activas: id => lleva_fruto.
    $catalogo = [];
    foreach (listarFuncionesCulto(null, true) as $f) {
        $catalogo[(int) $f['id']] = (bool) $f['lleva_fruto'];
    }
    $activasIds = array_keys($catalogo);

    $vistos = [];
    foreach ($lista as $item) {
        if (!is_array($item)) {
            jsonError('Formato de funciones inválido.');
        }
        $fid = (int) ($item['funcion_culto_id'] ?? 0);
        if ($fid <= 0 || !array_key_exists($fid, $catalogo)) {
            jsonError('Función de culto inválida o inactiva.');
        }
        if (isset($vistos[$fid])) {
            continue; // evita duplicados en la entrada
        }
        $vistos[$fid] = true;

        // El fruto solo se acepta donde la función lo lleva.
        $fruto = null;
        if ($catalogo[$fid]) {
            $fc = $item['fruto_cantidad'] ?? null;
            if ($fc !== null && $fc !== '') {
                $fc = (int) $fc;
                if ($fc < 0) {
                    jsonError('La cantidad de fruto no puede ser negativa.');
                }
                if ($fc > FRUTO_MAX) {
                    jsonError('La cantidad de fruto es demasiado alta.');
                }
                $fruto = $fc;
            }
        }
        $funcionesSync[] = ['funcion_culto_id' => $fid, 'fruto_cantidad' => $fruto];
    }
}

$pdo = db();
try {
    $pdo->beginTransaction();

    $asistenciaId = null;
    if ($asistio) {
        // Solo se toca hora_salida si llegó un valor (el toque rápido no la envía
        // y no debe pisar la que se capturó antes en el panel).
        $asistenciaId = confirmarAsistenciaCulto(
            $uid, $cultoId, $fecha, true, $horaSalidaVal, $horaSalidaVal !== null
        );
        if ($sincronizar) {
            // Acota el borrado a las funciones activas: preserva capturas cuyo
            // funcion_culto_id se desactivó luego en el catálogo.
            sincronizarFunciones($asistenciaId, $funcionesSync, $activasIds);
        }
    } else {
        // Desmarcar asistencia: borra la fila (la cascada limpia sus funciones).
        quitarAsistencia($uid, $cultoId, $fecha);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[api/guardar_funcion_culto] ' . $e->getMessage());
    jsonError('No se pudo guardar la asistencia.', 500);
}

$funciones = ($asistenciaId !== null) ? funcionesDeAsistencia($asistenciaId) : [];

jsonOk([
    'asistio'       => $asistio,
    'asistencia_id' => $asistenciaId,
    'hora_salida'   => $horaSalidaVal,
    'funciones'     => $funciones,
]);
