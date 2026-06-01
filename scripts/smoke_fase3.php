<?php
/**
 * scripts/smoke_fase3.php
 *
 * Smoke test de la FASE 3 (cultos + funciones, campañas + suspensión,
 * escrituras de catálogos) contra la BASE DE DATOS REAL.
 *
 * SEGURO: todo corre dentro de UNA transacción que se revierte (ROLLBACK)
 * al final. No persiste nada — ni el asistente de prueba, ni asistencias,
 * ni campañas, ni catálogos. Es re-ejecutable sin ensuciar datos.
 *
 * Ejecutar en el VPS, como el usuario que puede leer config/config.php:
 *   cd /ruta/al/repo
 *   sudo -u www-data php scripts/smoke_fase3.php
 *
 * Sale con código 0 si TODAS las pruebas pasan, 1 si alguna falla.
 *
 * Nota: las firmas de función se tomaron del transcript de la Fase 3. Si
 * alguna difiere (p. ej. otro nombre), PHP abortará con "undefined function":
 * eso ya te dice exactamente qué ajustar.
 */

declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

$root = dirname(__DIR__);
require_once $root . '/includes/conexion.php';
require_once $root . '/includes/cultos_repo.php';
require_once $root . '/includes/campanias_repo.php';
require_once $root . '/includes/catalogos_repo.php';

// ---- mini framework de aserciones (no lanza; acumula) -----------------------
$PASS = 0; $FAIL = 0;
function _line(string $tag, string $m): void { fwrite(STDOUT, $tag . ' ' . $m . PHP_EOL); }
function ok(string $m): void   { global $PASS; $PASS++; _line('[OK]   ', $m); }
function ko(string $m): void   { global $FAIL; $FAIL++; _line('[FALLA]', $m); }
function check(bool $c, string $m): void { $c ? ok($m) : ko($m); }
function eq($exp, $got, string $m): void {
    ($exp === $got)
        ? ok($m)
        : ko($m . '  (esperado=' . var_export($exp, true) . ' obtenido=' . var_export($got, true) . ')');
}
function note(string $m): void { _line('[NOTA] ', $m); }

// lectura directa (vemos nuestras propias escrituras NO commiteadas)
function scalar(string $sql, array $p = []) { $s = db()->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function rows(string $sql, array $p = []): array { $s = db()->prepare($sql); $s->execute($p); return $s->fetchAll(); }

$pdo = db();
$pdo->beginTransaction();

try {
    // ===== Catálogo sembrado (también valida que 002 está aplicado) ==========
    $cMiercoles = scalar('SELECT id FROM cultos WHERE nombre = :n', ['n' => 'Miércoles']);
    $cJunta     = scalar('SELECT id FROM cultos WHERE nombre = :n', ['n' => 'Junta de Asistentes']);
    $fPesc      = scalar('SELECT id FROM funciones_culto WHERE nombre = :n', ['n' => 'Captación tipo Pescadores']);
    $fBaut      = scalar('SELECT id FROM funciones_culto WHERE nombre = :n', ['n' => 'Bautizar']);
    $fUjier     = scalar('SELECT id FROM funciones_culto WHERE nombre = :n', ['n' => 'Ujieres / organización']);

    foreach ([
        'Miércoles' => $cMiercoles, 'Junta de Asistentes' => $cJunta,
        'Captación tipo Pescadores' => $fPesc, 'Bautizar' => $fBaut,
        'Ujieres / organización' => $fUjier,
    ] as $nombre => $id) {
        if ($id === false || $id === null) {
            throw new RuntimeException("No se encontró en catálogo: '$nombre'. ¿Se aplicó 002_seed_catalogos.sql?");
        }
    }
    $cMiercoles = (int) $cMiercoles; $cJunta = (int) $cJunta;
    $fPesc = (int) $fPesc; $fBaut = (int) $fBaut; $fUjier = (int) $fUjier;
    ok('Catálogo sembrado presente (cultos + funciones de prueba resueltos)');

    // ===== Asistente de prueba (desechable; el rollback lo elimina) ==========
    $usuario = '__smoke_fase3__' . bin2hex(random_bytes(4));
    $asis = (int) scalar(
        "INSERT INTO asistentes (nombre, usuario, password_hash, rol)
         VALUES ('SMOKE Fase3', :u, 'x', 'asistente') RETURNING id",
        ['u' => $usuario]
    );
    check($asis > 0, "Alta de asistente de prueba (id=$asis)");

    // ===== Fechas de trabajo (todo se revierte; la semana actual sirve) ======
    $tz = new DateTimeZone('America/Mexico_City');
    $hoy = new DateTimeImmutable('now', $tz);
    $dow = (int) $hoy->format('N');                       // 1=lun .. 7=dom
    $lunes = $hoy->modify('-' . ($dow - 1) . ' days');
    $f = static fn(int $d) => $lunes->modify("+$d days")->format('Y-m-d');
    $miercoles = $f(2);
    $jueves    = $f(3);
    $viernes   = $f(4);
    $sabado    = $f(5);   // Junta de Asistentes (dia_semana 6)
    $domingo   = $f(6);
    $lunesStr  = $f(0);

    // ========================================================================
    // A. Asistencia: RETURNING + upsert idempotente + "solo asistí"
    // ========================================================================
    $a = confirmarAsistenciaCulto($asis, $cMiercoles, $miercoles, true, null);
    check(is_int($a) && $a > 0, "A1 confirmarAsistenciaCulto devuelve id (RETURNING) = $a");

    $a2 = confirmarAsistenciaCulto($asis, $cMiercoles, $miercoles, true, null);
    eq($a, $a2, 'A2 reenviar misma asistencia = mismo id (upsert idempotente sobre UNIQUE)');

    eq(0, (int) scalar('SELECT count(*) FROM funciones_realizadas WHERE asistencia_culto_id = :a', ['a' => $a]),
        'A3 "solo asistí": asistencia sin funciones_realizadas');

    // ========================================================================
    // B. sincronizarFunciones: insertar / actualizar fruto / borrar no-presentes
    //    + alcance borrable que preserva funciones históricas (fix F7)
    // ========================================================================
    sincronizarFunciones($a, [
        ['funcion_culto_id' => $fPesc,  'fruto_cantidad' => 5],
        ['funcion_culto_id' => $fUjier, 'fruto_cantidad' => null],
    ]);
    $map = [];
    foreach (rows('SELECT funcion_culto_id, fruto_cantidad FROM funciones_realizadas WHERE asistencia_culto_id = :a', ['a' => $a]) as $r) {
        $map[(int) $r['funcion_culto_id']] = $r['fruto_cantidad'];
    }
    eq(2, count($map), 'B1 dos funciones sincronizadas');
    eq(5, (int) ($map[$fPesc] ?? -1), 'B1 fruto de Pescadores = 5');
    check(array_key_exists($fUjier, $map) && $map[$fUjier] === null, 'B1 Ujieres sin fruto (null)');

    sincronizarFunciones($a, [['funcion_culto_id' => $fPesc, 'fruto_cantidad' => 8]]);
    $map = [];
    foreach (rows('SELECT funcion_culto_id, fruto_cantidad FROM funciones_realizadas WHERE asistencia_culto_id = :a', ['a' => $a]) as $r) {
        $map[(int) $r['funcion_culto_id']] = $r['fruto_cantidad'];
    }
    eq(1, count($map), 'B2 re-sync: queda solo Pescadores (Ujieres borrado)');
    eq(8, (int) ($map[$fPesc] ?? -1), 'B2 fruto de Pescadores actualizado a 8');

    // F7: con Pescadores + Bautizar presentes, sincronizar [Pescadores]
    //     pero acotando el borrado SOLO a Pescadores => Bautizar sobrevive.
    sincronizarFunciones($a, [
        ['funcion_culto_id' => $fPesc, 'fruto_cantidad' => 8],
        ['funcion_culto_id' => $fBaut, 'fruto_cantidad' => 2],
    ]);
    sincronizarFunciones(
        $a,
        [['funcion_culto_id' => $fPesc, 'fruto_cantidad' => 8]], // deseadas
        [$fPesc]                                                 // borrables (alcance)
    );
    $pres = array_map('intval', array_column(
        rows('SELECT funcion_culto_id FROM funciones_realizadas WHERE asistencia_culto_id = :a', ['a' => $a]),
        'funcion_culto_id'
    ));
    sort($pres); $esp = [$fPesc, $fBaut]; sort($esp);
    eq($esp, $pres, 'B3 (F7) borrado acotado preserva función histórica fuera de alcance (Bautizar sobrevive)');

    sincronizarFunciones($a, [], null); // sync total vacío => "solo asistí"
    eq(0, (int) scalar('SELECT count(*) FROM funciones_realizadas WHERE asistencia_culto_id = :a', ['a' => $a]),
        'B4 sync vacío (alcance null) borra todo => vuelve a "solo asistí"');

    // ========================================================================
    // C. quitarAsistencia + cascada de funciones_realizadas
    // ========================================================================
    sincronizarFunciones($a, [['funcion_culto_id' => $fPesc, 'fruto_cantidad' => 1]]);
    quitarAsistencia($asis, $cMiercoles, $miercoles);
    eq(0, (int) scalar('SELECT count(*) FROM asistencia_culto WHERE id = :a', ['a' => $a]), 'C1 quitarAsistencia borra la fila');
    eq(0, (int) scalar('SELECT count(*) FROM funciones_realizadas WHERE asistencia_culto_id = :a', ['a' => $a]),
        'C1 la cascada borra sus funciones_realizadas');

    // ========================================================================
    // D. hora_salida (fin variable) + flag $tocarHoraSalida (fix F1)
    // ========================================================================
    $j = confirmarAsistenciaCulto($asis, $cJunta, $sabado, true, '12:30', true);
    eq('12:30:00', (string) scalar('SELECT hora_salida FROM asistencia_culto WHERE id = :a', ['a' => $j]),
        'D1 hora_salida guardada (12:30)');

    confirmarAsistenciaCulto($asis, $cJunta, $sabado, true, null, false); // toque rápido: NO debe pisarla
    eq('12:30:00', (string) scalar('SELECT hora_salida FROM asistencia_culto WHERE id = :a', ['a' => $j]),
        'D2 (F1) re-confirmar con tocar=false CONSERVA hora_salida');

    confirmarAsistenciaCulto($asis, $cJunta, $sabado, true, '13:00', true); // edición explícita SÍ
    eq('13:00:00', (string) scalar('SELECT hora_salida FROM asistencia_culto WHERE id = :a', ['a' => $j]),
        'D3 editar con tocar=true ACTUALIZA hora_salida (13:00)');
    quitarAsistencia($asis, $cJunta, $sabado);

    // ========================================================================
    // E. Campañas + suspensión (días de misión, NO horas) + scope por dueño
    // ========================================================================
    crearCampania($asis, $lunesStr, $viernes, 'Sierra', 'Campaña de prueba');
    $camp = (int) scalar(
        'SELECT id FROM periodos_campania WHERE asistente_id = :a AND fecha_inicio = :i AND fecha_fin = :f',
        ['a' => $asis, 'i' => $lunesStr, 'f' => $viernes]
    );
    check($camp > 0, "E1 crearCampania inserta el periodo (id=$camp)");

    check((bool) estaEnCampania($asis, $miercoles),  'E2 estaEnCampania=true dentro del rango (miércoles)');
    check(!(bool) estaEnCampania($asis, $domingo),   'E2 estaEnCampania=false fuera del rango (domingo)');

    $susp = array_map('strval', diasSuspendidos($asis, $lunesStr, $domingo));
    eq(5, count($susp), 'E3 diasSuspendidos: 5 días (lun–vie) en la semana');
    check(in_array($miercoles, $susp, true), 'E3 miércoles está suspendido');
    check(!in_array($domingo, $susp, true),  'E3 domingo NO está suspendido');

    // dedup: una segunda campaña solapada (mié–jue) NO debe inflar el conjunto
    crearCampania($asis, $miercoles, $jueves, 'Anexo', 'Solape de prueba');
    eq(5, count(diasSuspendidos($asis, $lunesStr, $domingo)),
        'E4 campañas solapadas NO duplican días (generate_series deduplicado)');

    // días de misión del mes (sin convertir a horas): días distintos en el mes
    $mIni = $lunes->format('Y-m-01');
    $mFin = $lunes->format('Y-m-t');
    $mes  = $lunes->format('Y-m');
    $espMision = 0;
    foreach ([0, 1, 2, 3, 4] as $d) { if ($lunes->modify("+$d days")->format('Y-m') === $mes) $espMision++; }
    eq($espMision, (int) diasDeMision($asis, $mIni, $mFin),
        "E5 diasDeMision cuenta días distintos del mes (esperado=$espMision)");

    // scope por dueño: eliminar con otro asistente_id NO borra
    eliminarCampania($camp, $asis + 999999);
    eq(1, (int) scalar('SELECT count(*) FROM periodos_campania WHERE id = :i', ['i' => $camp]),
        'E6 eliminarCampania con dueño equivocado NO borra (scope)');
    eliminarCampania($camp, $asis);
    eq(0, (int) scalar('SELECT count(*) FROM periodos_campania WHERE id = :i', ['i' => $camp]),
        'E6 eliminarCampania del dueño SÍ borra');

    // ========================================================================
    // F. Escrituras de catálogos + reglas (fruto / fin_variable / borrado / FK)
    // ========================================================================
    $cat = crearCategoria('__cat_smoke__', 99);
    check($cat > 0, "F1 crearCategoria (id=$cat)");
    check(actualizarCategoria($cat, '__cat_smoke2__', 50) === true, 'F1 actualizarCategoria existente => true');
    check(actualizarCategoria(-1, 'nada', 0) === false, 'F1 actualizarCategoria inexistente => false (rowCount)');

    $actSin = crearActividad($cat, '__act_sinfruto__', false, 'no-debe-guardarse');
    eq(null, scalar('SELECT etiqueta_fruto FROM actividades WHERE id = :i', ['i' => $actSin]),
        'F2 actividad con lleva_fruto=false guarda etiqueta_fruto NULL');
    $actCon = crearActividad($cat, '__act_confruto__', true, 'contactos');
    eq('contactos', scalar('SELECT etiqueta_fruto FROM actividades WHERE id = :i', ['i' => $actCon]),
        'F2 actividad con lleva_fruto=true conserva la etiqueta');

    fijarActivoActividad($actCon, false);
    eq('f', (string) scalar('SELECT activo::text FROM actividades WHERE id = :i', ['i' => $actCon]),
        'F3 fijarActivoActividad(false) => activo=false (borrado suave)');
    $idsAct = array_map('intval', array_column(listarActividades(null, true), 'id'));
    check(!in_array($actCon, $idsAct, true), 'F3 listarActividades(soloActivas=true) excluye la inactiva');
    $idsTodas = array_map('intval', array_column(listarActividades(null, false), 'id'));
    check(in_array($actCon, $idsTodas, true), 'F3 listarActividades(soloActivas=false) la incluye');

    $cultoFV = crearCulto('__culto_fv__', 6, '11:30', '21:00', true, true);
    eq(null, scalar('SELECT hora_fin FROM cultos WHERE id = :i', ['i' => $cultoFV]),
        'F4 crearCulto fin_variable=true guarda hora_fin NULL');

    $proj = crearProyecto('__proj_smoke__', '', null, null, null);
    eq(null, scalar('SELECT observaciones FROM proyectos WHERE id = :i', ['i' => $proj]),
        'F5 crearProyecto con observaciones vacías guarda NULL');

    // FK 23503: borrar categoría referenciada por una actividad debe fallar.
    // Se aísla en SAVEPOINT para no abortar la transacción del resto.
    if (db()->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION) {
        db()->exec('SAVEPOINT sp_fk');
        try {
            eliminarCategoria($cat); // $cat tiene actividades => 23503
            db()->exec('RELEASE SAVEPOINT sp_fk');
            ko('F6 eliminarCategoria referenciada DEBIÓ lanzar excepción FK (23503)');
        } catch (PDOException $e) {
            db()->exec('ROLLBACK TO SAVEPOINT sp_fk');
            eq('23503', (string) $e->getCode(), 'F6 borrar categoría referenciada lanza FK 23503 (manejable)');
        }
    } else {
        note('F6 omitida: PDO::ATTR_ERRMODE no es EXCEPTION; revisa que db() lance excepciones (lo asumen los endpoints).');
    }

} catch (Throwable $e) {
    ko('EXCEPCIÓN no esperada: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

fwrite(STDOUT, PHP_EOL . "RESULTADO: $PASS pasaron, $FAIL fallaron." . PHP_EOL);
fwrite(STDOUT, '(No se persistió nada: la transacción se revirtió.)' . PHP_EOL);
exit($FAIL === 0 ? 0 : 1);
