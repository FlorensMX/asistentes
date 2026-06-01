<?php
/**
 * scripts/smoke_fase4.php
 *
 * Smoke test de los AGREGADOS de la Fase 4 (includes/reportes_repo.php) contra
 * la BASE DE DATOS REAL.
 *
 * SEGURO: siembra un fixture conocido y corre TODO dentro de una transacción
 * que se revierte (ROLLBACK) al final. No persiste nada. Re-ejecutable.
 *
 * Ejecutar en el VPS, como el usuario que puede leer config/config.php:
 *   cd /var/www/montesion.cloud/apps/asistentes
 *   sudo -u www-data php scripts/smoke_fase4.php
 *
 * El fixture (asistente de prueba, mes objetivo 2025-03) está calculado a mano:
 *
 *   Recurrente "Clase" 18:00–20:00 = 2 h/confirmación, categoría Enseñanza, ministerio M
 *     · 03-05 confirmado            → +2 h
 *     · 03-12 confirmado            → +2 h
 *     · 03-21 confirmado, EN CAMPAÑA → EXCLUIDO
 *     · 03-19 NO confirmado          → no cuenta
 *     ⇒ recurrentes netos = 4 h   (sin exclusión serían 6 h)
 *   Actividad "Ganar almas" (Evangelismo, contactos), ministerio M
 *     · 03-06  90 min → 1.5 h, fruto 4, proyecto P
 *     · 03-21  60 min → 1.0 h, fruto 2  (EN CAMPAÑA pero §10.1: SÍ cuenta)
 *     ⇒ actividad = 2.5 h ; contactos = 6
 *   Cultos
 *     · Miércoles 03-05 (19:30–21:00) → 1.5 h, 1 asistencia
 *     · Junta 03-08, salida 13:30 (fin_variable) → 2.0 h, 1 asistencia
 *     · Miércoles 03-21, EN CAMPAÑA → EXCLUIDO
 *     ⇒ cultos = 3.5 h, 2 asistencias
 *   Funciones en culto
 *     · Pescadores (decisiones) fruto 3 en Junta 03-08 → cuenta
 *     · Bautizar (bautizados) fruto 5 en Miércoles 03-21 → EXCLUIDO (campaña)
 *     ⇒ frutos de culto: decisiones = 3 ; bautizados AUSENTE
 *   Campaña 03-20…03-22, fruto 7  ⇒ días de misión = 3 ; fruto_campanias = 7
 *   Secular 20 h/sem
 *
 *   ⇒ horas_total = categorías(6.5) + cultos(3.5) = 10.0
 *      Este único número valida a la vez: exclusión de recurrentes y cultos en
 *      campaña, §10.1 (actividad SÍ cuenta), fin_variable con hora_salida, y la
 *      duración de un culto normal.
 */

declare(strict_types=1);
date_default_timezone_set('America/Mexico_City');

$root = dirname(__DIR__);
require_once $root . '/includes/conexion.php';
require_once $root . '/includes/reportes_repo.php';   // arrastra semana/catalogos/campanias/asistentes

// ---- mini framework de aserciones (no lanza; acumula) -----------------------
$PASS = 0; $FAIL = 0;
function ok(string $m): void   { global $PASS; $PASS++; fwrite(STDOUT, "[OK]    $m" . PHP_EOL); }
function ko(string $m): void   { global $FAIL; $FAIL++; fwrite(STDOUT, "[FALLA] $m" . PHP_EOL); }
function note(string $m): void { fwrite(STDOUT, "[NOTA]  $m" . PHP_EOL); }
function check(bool $c, string $m): void { $c ? ok($m) : ko($m); }
function eq($e, $g, string $m): void {
    $e === $g ? ok($m) : ko($m . '  (esp=' . var_export($e, true) . ' obt=' . var_export($g, true) . ')');
}
function eqf(float $e, $g, string $m, float $eps = 0.001): void {
    (is_numeric($g) && abs($e - (float) $g) < $eps)
        ? ok($m) : ko($m . "  (esp≈$e obt=" . var_export($g, true) . ')');
}
function scalar(string $s, array $p = []) { $x = db()->prepare($s); $x->execute($p); return $x->fetchColumn(); }
function mapBy(array $arr, string $k, string $v): array { $o = []; foreach ($arr as $r) { $o[$r[$k]] = $r[$v]; } return $o; }

$pdo = db();
$pdo->beginTransaction();

try {
    // ===== Resolver catálogo sembrado (valida que 002 está aplicado) =========
    $catEvang = scalar('SELECT id FROM categorias WHERE nombre = :n', ['n' => 'Evangelismo y alcance']);
    $catEns   = scalar('SELECT id FROM categorias WHERE nombre = :n', ['n' => 'Enseñanza y discipulado']);
    $actGanar = scalar('SELECT id FROM actividades WHERE nombre = :n AND categoria_id = :c',
                       ['n' => 'Ganar almas', 'c' => $catEvang]);
    $cMier    = scalar('SELECT id FROM cultos WHERE nombre = :n', ['n' => 'Miércoles']);
    $cJunta   = scalar('SELECT id FROM cultos WHERE nombre = :n', ['n' => 'Junta de Asistentes']);
    $fPesc    = scalar('SELECT id FROM funciones_culto WHERE nombre = :n', ['n' => 'Captación tipo Pescadores']);
    $fBaut    = scalar('SELECT id FROM funciones_culto WHERE nombre = :n', ['n' => 'Bautizar']);

    foreach (['Evangelismo' => $catEvang, 'Enseñanza' => $catEns, 'Ganar almas' => $actGanar,
              'Miércoles' => $cMier, 'Junta' => $cJunta, 'Pescadores' => $fPesc, 'Bautizar' => $fBaut] as $n => $id) {
        if ($id === false || $id === null) {
            throw new RuntimeException("No se encontró en catálogo: '$n'. ¿Se aplicó 002_seed_catalogos.sql?");
        }
    }
    $catEvang = (int) $catEvang; $catEns = (int) $catEns; $actGanar = (int) $actGanar;
    $cMier = (int) $cMier; $cJunta = (int) $cJunta; $fPesc = (int) $fPesc; $fBaut = (int) $fBaut;
    ok('Catálogo sembrado presente (categorías, actividad, cultos, funciones)');

    // ===== Sembrar fixture (desechable; el rollback lo elimina) ==============
    $asis = (int) scalar(
        "INSERT INTO asistentes (nombre, usuario, password_hash, rol)
         VALUES ('SMOKE Fase4', :u, 'x', 'asistente') RETURNING id",
        ['u' => '__smoke_fase4__' . bin2hex(random_bytes(4))]
    );
    $min = (int) scalar(
        "INSERT INTO ministerios (asistente_id, nombre, categoria_id)
         VALUES (:a, 'Pescadores SMOKE', :c) RETURNING id",
        ['a' => $asis, 'c' => $catEvang]
    );
    $proj = (int) scalar(
        "INSERT INTO proyectos (nombre, observaciones)
         VALUES ('Proyecto SMOKE', 'obs de prueba') RETURNING id"
    );

    // Recurrente: Enseñanza, ministerio M, 18:00–20:00 = 2 h
    $rec = (int) scalar(
        "INSERT INTO compromisos_recurrentes
            (asistente_id, ministerio_id, nombre, categoria_id, dia_semana, hora_inicio, hora_fin, vigente_desde)
         VALUES (:a, :m, 'Clase SMOKE', :c, 3, '18:00', '20:00', '2025-01-01') RETURNING id",
        ['a' => $asis, 'm' => $min, 'c' => $catEns]
    );
    foreach ([['2025-03-05', 'true'], ['2025-03-12', 'true'], ['2025-03-21', 'true'], ['2025-03-19', 'false']] as $cf) {
        db()->prepare('INSERT INTO confirmaciones_recurrente (compromiso_id, fecha, confirmado) VALUES (:r, :f, :o)')
            ->execute(['r' => $rec, 'f' => $cf[0], 'o' => $cf[1]]);
    }

    // Asistencia a cultos
    db()->prepare("INSERT INTO asistencia_culto (asistente_id, culto_id, fecha, asistio) VALUES (:a, :c, '2025-03-05', TRUE)")
        ->execute(['a' => $asis, 'c' => $cMier]);
    $aMier21 = (int) scalar("INSERT INTO asistencia_culto (asistente_id, culto_id, fecha, asistio) VALUES (:a, :c, '2025-03-21', TRUE) RETURNING id",
        ['a' => $asis, 'c' => $cMier]);
    $aJunta08 = (int) scalar("INSERT INTO asistencia_culto (asistente_id, culto_id, fecha, asistio, hora_salida) VALUES (:a, :c, '2025-03-08', TRUE, '13:30') RETURNING id",
        ['a' => $asis, 'c' => $cJunta]);

    // Funciones: Pescadores en Junta (cuenta) ; Bautizar en Miércoles 03-21 (campaña → excluido)
    db()->prepare('INSERT INTO funciones_realizadas (asistencia_culto_id, funcion_culto_id, fruto_cantidad) VALUES (:ac, :f, 3)')
        ->execute(['ac' => $aJunta08, 'f' => $fPesc]);
    db()->prepare('INSERT INTO funciones_realizadas (asistencia_culto_id, funcion_culto_id, fruto_cantidad) VALUES (:ac, :f, 5)')
        ->execute(['ac' => $aMier21, 'f' => $fBaut]);

    // Actividad variable (Ganar almas, contactos), ministerio M
    db()->prepare("INSERT INTO registros_actividad (asistente_id, actividad_id, ministerio_id, fecha, duracion_min, fruto_cantidad, proyecto_id)
                   VALUES (:a, :act, :m, '2025-03-06', 90, 4, :p)")
        ->execute(['a' => $asis, 'act' => $actGanar, 'm' => $min, 'p' => $proj]);
    db()->prepare('INSERT INTO registros_actividad (asistente_id, actividad_id, ministerio_id, fecha, duracion_min, fruto_cantidad)
                   VALUES (:a, :act, :m, \'2025-03-21\', 60, 2)')
        ->execute(['a' => $asis, 'act' => $actGanar, 'm' => $min]);

    // Campaña 03-20…03-22 (fruto 7) y secular 20 h/sem
    db()->prepare("INSERT INTO periodos_campania (asistente_id, categoria_id, fecha_inicio, fecha_fin, lugar, descripcion, fruto_cantidad)
                   VALUES (:a, :c, '2025-03-20', '2025-03-22', 'Sierra SMOKE', 'campaña de prueba', 7)")
        ->execute(['a' => $asis, 'c' => $catEvang]);
    db()->prepare("INSERT INTO trabajo_secular (asistente_id, descripcion, horas_semana) VALUES (:a, 'Taxi SMOKE', 20)")
        ->execute(['a' => $asis]);

    $lim = mesLimites('2025-03');
    $ini = $lim['ini']; $fin = $lim['fin'];
    eq('2025-03-01', $ini, 'mesLimites: inicio de marzo');
    eq('2025-03-31', $fin, 'mesLimites: fin de marzo');

    // ========================================================================
    // A. resumenMesAsistente
    // ========================================================================
    $r = resumenMesAsistente($asis, $ini, $fin);

    eqf(10.0, $r['horas_total'], 'A horas_total = 10.0  [exclusión + §10.1 + fin_variable + culto normal]');
    eqf(6.5,  $r['horas_categorias'], 'A horas_categorias = 6.5 (recurrentes 4 + actividad 2.5)');
    eqf(3.5,  $r['horas_cultos'], 'A horas_cultos = 3.5 (Miércoles 1.5 + Junta 2.0; 03-21 excluido)');

    $cats = mapBy($r['horas_por_categoria'], 'categoria', 'horas');
    eq(2, count($cats), 'A horas_por_categoria: 2 categorías');
    eqf(4.0, $cats['Enseñanza y discipulado'] ?? -1, 'A categoría Enseñanza = 4.0 (recurrente, sin el 03-21)');
    eqf(2.5, $cats['Evangelismo y alcance'] ?? -1, 'A categoría Evangelismo = 2.5 (actividad)');

    $minh = mapBy($r['horas_por_ministerio'], 'ministerio', 'horas');
    eqf(6.5, $minh['Pescadores SMOKE'] ?? -1, 'A ministerio "Pescadores SMOKE" = 6.5 (recurrente 4 + actividad 2.5)');

    eq(2, $r['cultos']['asistencias_total'], 'A cultos: 2 asistencias (03-21 excluida)');
    eqf(3.5, $r['cultos']['horas_total'], 'A cultos: 3.5 h');
    eq(2, count($r['cultos']['items']), 'A cultos: 2 items (Miércoles, Junta)');

    eqf(3.0, $r['dias_mision'], 'A días de misión = 3 (03-20..03-22)');
    eq(7, $r['fruto_campanias'], 'A fruto_campanias = 7');

    $fr = mapBy($r['frutos'], 'etiqueta', 'total');
    eq(6, $fr['contactos'] ?? -1, 'A frutos: contactos = 6 (actividad, incl. 03-21 por §10.1)');
    eq(3, $fr['decisiones'] ?? -1, 'A frutos: decisiones = 3 (Pescadores en Junta)');
    check(!array_key_exists('bautizados', $fr), 'A frutos: bautizados AUSENTE (Bautizar del 03-21 excluido por campaña)');

    // ========================================================================
    // B. tendenciaMensual
    // ========================================================================
    $tend = tendenciaMensual($asis, 6, '2025-03');
    $tmap = mapBy($tend, 'mes', 'horas');
    eq(6, count($tend), 'B tendencia: 6 meses');
    eqf(10.0, $tmap['2025-03'] ?? -1, 'B tendencia: 2025-03 = 10.0 h');
    $restoTend = array_sum(array_map('floatval', $tmap)) - (float) ($tmap['2025-03'] ?? 0);
    eqf(0.0, $restoTend, 'B tendencia: meses sin datos = 0');

    // ========================================================================
    // C. consolidadoMes  (busca la fila del asistente de prueba)
    // ========================================================================
    $fila = null;
    foreach (consolidadoMes($ini, $fin) as $row) {
        if ((int) $row['asistente_id'] === $asis) { $fila = $row; break; }
    }
    check($fila !== null, 'C consolidado incluye al asistente de prueba');
    if ($fila !== null) {
        eqf(10.0, $fila['horas_ministerial'], 'C horas_ministerial = 10.0');
        eqf(20.0, $fila['secular_h_sem'], 'C secular_h_sem = 20.0 (aparte, no sumado)');
        eq(2, $fila['asistencias_culto'], 'C asistencias_culto = 2');
        eq(3, (int) $fila['dias_mision'], 'C días de misión = 3');
    }

    // ========================================================================
    // D. detalleAsistente
    // ========================================================================
    $d = detalleAsistente($asis, $ini, $fin);
    eq($asis, (int) ($d['asistente']['id'] ?? 0), 'D asistente correcto');
    eqf(10.0, $d['resumen']['horas_total'], 'D resumen.horas_total = 10.0');

    eq(1, count($d['funciones']), 'D funciones: 1 (solo Pescadores; Bautizar del 03-21 excluido)');
    if ($d['funciones']) {
        eq('Captación tipo Pescadores', $d['funciones'][0]['nombre'], 'D función = Captación tipo Pescadores');
        eq(1, $d['funciones'][0]['veces'], 'D función: 1 vez');
        eq(3, $d['funciones'][0]['fruto'], 'D función: fruto 3');
    }
    eq(1, count($d['proyectos']), 'D proyectos: 1 (Proyecto SMOKE)');
    if ($d['proyectos']) {
        eq(1, $d['proyectos'][0]['registros'], 'D proyecto: 1 registro');
        eqf(1.5, $d['proyectos'][0]['horas'], 'D proyecto: 1.5 h');
    }
    eq(1, count($d['campanias']), 'D campañas: 1');
    eq(6, count($d['tendencia']), 'D tendencia: 6 meses');

    // ========================================================================
    // E. Control de la exclusión por campaña
    // ========================================================================
    $recSinExcl = (float) scalar(
        'SELECT SUM(EXTRACT(EPOCH FROM (comp.hora_fin - comp.hora_inicio)) / 3600.0)
         FROM confirmaciones_recurrente cr JOIN compromisos_recurrentes comp ON comp.id = cr.compromiso_id
         WHERE comp.asistente_id = :a AND cr.confirmado = TRUE AND cr.fecha BETWEEN :i AND :f',
        ['a' => $asis, 'i' => $ini, 'f' => $fin]
    );
    eqf(6.0, $recSinExcl, 'E control: recurrentes SIN exclusión = 6.0 h (3 confirmaciones)');
    note('La exclusión por campaña quitó 2.0 h de recurrentes (la confirmación del 03-21) → 4.0 h netas.');

    // ========================================================================
    // F. Demostración del +86400 (cruce de medianoche en hora_salida)
    // ========================================================================
    $normal = (float) scalar(
        "SELECT (EXTRACT(EPOCH FROM (TIME '13:30' - TIME '11:30'))
                 + CASE WHEN TIME '13:30' < TIME '11:30' THEN 86400 ELSE 0 END) / 3600.0"
    );
    eqf(2.0, $normal, 'F fórmula de culto: salida 13:30 desde 11:30 = 2.0 h (caso normal)');

    $cruce = (float) scalar(
        "SELECT (EXTRACT(EPOCH FROM (TIME '00:30' - TIME '11:30'))
                 + CASE WHEN TIME '00:30' < TIME '11:30' THEN 86400 ELSE 0 END) / 3600.0"
    );
    note("Salida 00:30 desde 11:30 computa {$cruce} h (el +86400). Si fue un typo de 13:00 (debería ser 1.5 h),");
    note('queda inflado y "plausible". Recomendación: validar hora_salida > hora_inicio en guardar_funcion_culto.php.');

} catch (Throwable $e) {
    ko('EXCEPCIÓN no esperada: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
}

fwrite(STDOUT, PHP_EOL . "RESULTADO: $PASS pasaron, $FAIL fallaron." . PHP_EOL);
fwrite(STDOUT, '(No se persistió nada: la transacción se revirtió.)' . PHP_EOL);
exit($FAIL === 0 ? 0 : 1);
