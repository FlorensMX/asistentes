<?php
/**
 * includes/catalogos_repo.php
 *
 * DAL solo-lectura de los catálogos sembrados: categorías, actividades,
 * cultos y funciones de culto. Otras fases agregan su propio repo.
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/** Categorías ordenadas. */
function listarCategorias(): array
{
    return db()->query('SELECT id, nombre, orden FROM categorias ORDER BY orden, nombre')->fetchAll();
}

/**
 * Actividades activas, opcionalmente filtradas por categoría.
 * Incluye nombre de categoría para mostrarlas agrupadas.
 */
function listarActividades(?int $categoriaId = null): array
{
    $sql = 'SELECT a.id, a.categoria_id, a.nombre, a.lleva_fruto, a.etiqueta_fruto,
                   c.nombre AS categoria
            FROM actividades a
            JOIN categorias c ON c.id = a.categoria_id
            WHERE a.activo = TRUE';
    $params = [];
    if ($categoriaId !== null) {
        $sql .= ' AND a.categoria_id = :c';
        $params['c'] = $categoriaId;
    }
    $sql .= ' ORDER BY c.orden, a.nombre';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Cultos activos del calendario fijo, ordenados por día y hora. */
function listarCultos(): array
{
    return db()->query(
        'SELECT id, nombre, dia_semana, hora_inicio, hora_fin, fin_variable, es_reunion
         FROM cultos WHERE activo = TRUE
         ORDER BY dia_semana, hora_inicio'
    )->fetchAll();
}

/** Funciones de culto activas, opcionalmente por grupo (ministerial|servicio). */
function listarFuncionesCulto(?string $grupo = null): array
{
    $sql = 'SELECT id, nombre, grupo, lleva_fruto, etiqueta_fruto
            FROM funciones_culto WHERE activo = TRUE';
    $params = [];
    if ($grupo !== null) {
        $sql .= ' AND grupo = :g';
        $params['g'] = $grupo;
    }
    $sql .= ' ORDER BY grupo, nombre';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
