<?php
/**
 * public/api/reportes.php
 *
 * Endpoint GET de SOLO LECTURA: devuelve agregados en JSON para los dashboards
 * (p. ej. gráficas cargadas por fetch). Nunca emite HTML.
 *
 * Seguridad: la protección es autenticación + rol + scope (no CSRF; es un GET
 * idempotente de solo lectura). Un asistente solo obtiene LO SUYO; los datos de
 * otro asistente y el consolidado exigen rol pastor/admin.
 *
 * GET:
 *   tipo          resumen (def.) | tendencia | consolidado | detalle
 *   mes           YYYY-MM (def. mes actual, hora de México)
 *   asistente_id  int (solo pastor/admin para ver a otro; si no, se ignora)
 *   n             int (meses de tendencia, def. 6)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/api.php';          // jsonOk(), jsonError(), auth
require_once __DIR__ . '/../../includes/reportes_repo.php';

$u = usuarioActual();
if (!$u) {
    jsonError('No autenticado.', 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Método no permitido.', 405);
}

$self     = (int) $u['id'];
$esElevado = in_array($u['rol'], ['pastor', 'admin'], true);

// Mes: validar YYYY-MM, si no, mes actual.
$mes = (string) ($_GET['mes'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) {
    $mes = mesActual();
}
['ini' => $ini, 'fin' => $fin] = mesLimites($mes);

/**
 * Resuelve el asistente objetivo aplicando el scope: un asistente normal solo
 * puede pedir lo suyo; pedir a otro exige rol elevado.
 */
$pedirAsistente = static function () use ($self, $esElevado): int {
    $req = (int) ($_GET['asistente_id'] ?? 0);
    if ($req > 0 && $req !== $self) {
        if (!$esElevado) {
            jsonError('No autorizado para ver a otro asistente.', 403);
        }
        return $req;
    }
    return $self;
};

$tipo = (string) ($_GET['tipo'] ?? 'resumen');

try {
    switch ($tipo) {
        case 'resumen':
            $aid = $pedirAsistente();
            jsonOk(['mes' => $mes, 'asistente_id' => $aid, 'resumen' => resumenMesAsistente($aid, $ini, $fin)]);

        case 'tendencia':
            $aid = $pedirAsistente();
            $n   = (int) ($_GET['n'] ?? 6);
            jsonOk(['asistente_id' => $aid, 'tendencia' => tendenciaMensual($aid, $n, $mes)]);

        case 'consolidado':
            if (!$esElevado) {
                jsonError('No autorizado.', 403);
            }
            jsonOk(['mes' => $mes, 'filas' => consolidadoMes($ini, $fin)]);

        case 'detalle':
            $aid = $pedirAsistente();
            jsonOk(['mes' => $mes, 'detalle' => detalleAsistente($aid, $ini, $fin)]);

        default:
            jsonError('Tipo de reporte no reconocido.');
    }
} catch (Throwable $e) {
    error_log('[api/reportes] ' . $e->getMessage());
    jsonError('No se pudo generar el reporte.', 500);
}
