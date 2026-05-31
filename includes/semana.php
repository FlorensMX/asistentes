<?php
/**
 * includes/semana.php
 *
 * Utilidades de fechas para "Mi semana". Toda la app trabaja en
 * America/Mexico_City (fijado además en la conexión PDO).
 *
 * Convención dia_semana (heredada del esquema): 0=domingo ... 6=sábado.
 * La semana se presenta de LUNES a DOMINGO.
 */

declare(strict_types=1);

const TZ_APP = 'America/Mexico_City';

/** DateTimeZone de la app (singleton ligero). */
function tzApp(): DateTimeZone
{
    static $tz = null;
    if ($tz === null) {
        $tz = new DateTimeZone(TZ_APP);
    }
    return $tz;
}

/**
 * Lunes de la semana que contiene $ref (o la semana actual si null),
 * desplazada $offsetSemanas semanas. Devuelve un DateTimeImmutable a las 00:00.
 */
function lunesDeSemana(?string $ref = null, int $offsetSemanas = 0): DateTimeImmutable
{
    $hoy = $ref !== null
        ? new DateTimeImmutable($ref, tzApp())
        : new DateTimeImmutable('now', tzApp());
    $hoy = $hoy->setTime(0, 0, 0);
    // 'N' = 1 (lunes) .. 7 (domingo)
    $diasDesdeLunes = (int) $hoy->format('N') - 1;
    $lunes = $hoy->modify("-{$diasDesdeLunes} days");
    if ($offsetSemanas !== 0) {
        $lunes = $lunes->modify(($offsetSemanas > 0 ? '+' : '-') . abs($offsetSemanas) . ' weeks');
    }
    return $lunes;
}

/**
 * Las 7 fechas (lunes..domingo) de la semana que arranca en $lunes.
 * @return DateTimeImmutable[]
 */
function diasDeSemana(DateTimeImmutable $lunes): array
{
    $dias = [];
    for ($i = 0; $i < 7; $i++) {
        $dias[] = $lunes->modify("+{$i} days");
    }
    return $dias;
}

/**
 * Mapea un dia_semana del esquema (0=domingo..6=sábado) a la fecha concreta
 * dentro de la semana que arranca en $lunes. Devuelve 'Y-m-d'.
 */
function fechaDeDiaSemana(DateTimeImmutable $lunes, int $diaSemana): string
{
    // 'w' de PHP: 0=domingo..6=sábado (igual que el esquema).
    // lunes tiene w=1; offset desde lunes = (diaSemana + 6) % 7.
    $offset = ($diaSemana + 6) % 7;
    return $lunes->modify("+{$offset} days")->format('Y-m-d');
}

/** Nombre corto del día en español para un dia_semana 0..6. */
function nombreDiaSemana(int $diaSemana): string
{
    static $nombres = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
    return $nombres[$diaSemana] ?? '';
}

/** "HH:MM" a partir de un TIME de Postgres ("HH:MM:SS"). */
function hhmm(?string $time): string
{
    if ($time === null || $time === '') return '';
    return substr($time, 0, 5);
}
