<?php
/**
 * includes/catalogos_repo.php
 *
 * DAL de los catálogos del sistema: categorías, actividades, cultos, funciones
 * de culto y proyectos.
 *
 * Nació de solo-lectura en Fase 1 (login, registro de actividad, "Mi semana").
 * En Fase 3 se amplió con FUNCIONES DE ESCRITURA para admin_catalogos.php
 * (rol pastor/admin) y con lectores que incluyen registros inactivos. Lectura
 * y escritura se mantienen agrupadas por entidad en este mismo repo.
 *
 * Borrado SUAVE por defecto (activo=FALSE) en las tablas que tienen 'activo',
 * para no romper referencias históricas. 'categorias' NO tiene 'activo': su
 * borrado es duro y solo procede si no está referenciada (la FK lo impide).
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

// ===========================================================================
// CATEGORÍAS
// ===========================================================================

/** Categorías ordenadas. */
function listarCategorias(): array
{
    return db()->query('SELECT id, nombre, orden FROM categorias ORDER BY orden, nombre')->fetchAll();
}

/** Id de la categoría con ese nombre exacto, o null. */
function categoriaIdPorNombre(string $nombre): ?int
{
    $stmt = db()->prepare('SELECT id FROM categorias WHERE nombre = :n');
    $stmt->execute(['n' => $nombre]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

function crearCategoria(string $nombre, int $orden = 0): int
{
    $stmt = db()->prepare('INSERT INTO categorias (nombre, orden) VALUES (:n, :o) RETURNING id');
    $stmt->execute(['n' => trim($nombre), 'o' => $orden]);
    return (int) $stmt->fetchColumn();
}

/** Renombra y/o reordena una categoría. */
function actualizarCategoria(int $id, string $nombre, int $orden): bool
{
    $stmt = db()->prepare('UPDATE categorias SET nombre = :n, orden = :o WHERE id = :id');
    $stmt->execute(['n' => trim($nombre), 'o' => $orden, 'id' => $id]);
    return $stmt->rowCount() > 0;
}

/**
 * Borra una categoría. Solo procede si no está referenciada: si alguna FK lo
 * impide, PostgreSQL lanza 23503 y el llamador lo traduce a un mensaje amable.
 */
function eliminarCategoria(int $id): void
{
    db()->prepare('DELETE FROM categorias WHERE id = :id')->execute(['id' => $id]);
}

// ===========================================================================
// ACTIVIDADES
// ===========================================================================

/**
 * Actividades, opcionalmente filtradas por categoría. Por defecto solo activas
 * (lo que consume la captura); pásalo en false para el mantenimiento admin.
 */
function listarActividades(?int $categoriaId = null, bool $soloActivas = true): array
{
    $sql = 'SELECT a.id, a.categoria_id, a.nombre, a.lleva_fruto, a.etiqueta_fruto, a.activo,
                   c.nombre AS categoria
            FROM actividades a
            JOIN categorias c ON c.id = a.categoria_id
            WHERE 1 = 1';
    $params = [];
    if ($soloActivas) {
        $sql .= ' AND a.activo = TRUE';
    }
    if ($categoriaId !== null) {
        $sql .= ' AND a.categoria_id = :c';
        $params['c'] = $categoriaId;
    }
    $sql .= ' ORDER BY c.orden, a.nombre';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function crearActividad(int $categoriaId, string $nombre, bool $llevaFruto, ?string $etiquetaFruto): int
{
    $stmt = db()->prepare(
        'INSERT INTO actividades (categoria_id, nombre, lleva_fruto, etiqueta_fruto)
         VALUES (:c, :n, :lf, :ef) RETURNING id'
    );
    $stmt->execute([
        'c'  => $categoriaId,
        'n'  => trim($nombre),
        'lf' => $llevaFruto ? 'true' : 'false',
        'ef' => $llevaFruto ? (($etiquetaFruto !== null && trim($etiquetaFruto) !== '') ? trim($etiquetaFruto) : null) : null,
    ]);
    return (int) $stmt->fetchColumn();
}

function actualizarActividad(int $id, int $categoriaId, string $nombre, bool $llevaFruto, ?string $etiquetaFruto): bool
{
    $stmt = db()->prepare(
        'UPDATE actividades
            SET categoria_id = :c, nombre = :n, lleva_fruto = :lf, etiqueta_fruto = :ef
          WHERE id = :id'
    );
    $stmt->execute([
        'c'  => $categoriaId,
        'n'  => trim($nombre),
        'lf' => $llevaFruto ? 'true' : 'false',
        'ef' => $llevaFruto ? (($etiquetaFruto !== null && trim($etiquetaFruto) !== '') ? trim($etiquetaFruto) : null) : null,
        'id' => $id,
    ]);
    return $stmt->rowCount() > 0;
}

/** Activa/desactiva (baja blanda) una actividad. */
function fijarActivoActividad(int $id, bool $activo): void
{
    db()->prepare('UPDATE actividades SET activo = :v WHERE id = :id')
        ->execute(['v' => $activo ? 'true' : 'false', 'id' => $id]);
}

// ===========================================================================
// CULTOS
// ===========================================================================

/** Cultos del calendario fijo. Por defecto solo activos. */
function listarCultos(bool $soloActivos = true): array
{
    $sql = 'SELECT id, nombre, dia_semana, hora_inicio, hora_fin, fin_variable, es_reunion, activo
            FROM cultos';
    if ($soloActivos) {
        $sql .= ' WHERE activo = TRUE';
    }
    $sql .= ' ORDER BY dia_semana, hora_inicio';
    return db()->query($sql)->fetchAll();
}

function crearCulto(string $nombre, int $diaSemana, string $horaInicio, ?string $horaFin, bool $finVariable, bool $esReunion): int
{
    $stmt = db()->prepare(
        'INSERT INTO cultos (nombre, dia_semana, hora_inicio, hora_fin, fin_variable, es_reunion)
         VALUES (:n, :d, :hi, :hf, :fv, :er) RETURNING id'
    );
    $stmt->execute([
        'n'  => trim($nombre),
        'd'  => $diaSemana,
        'hi' => $horaInicio,
        'hf' => $finVariable ? null : (($horaFin !== null && trim($horaFin) !== '') ? $horaFin : null),
        'fv' => $finVariable ? 'true' : 'false',
        'er' => $esReunion ? 'true' : 'false',
    ]);
    return (int) $stmt->fetchColumn();
}

function actualizarCulto(int $id, string $nombre, int $diaSemana, string $horaInicio, ?string $horaFin, bool $finVariable, bool $esReunion): bool
{
    $stmt = db()->prepare(
        'UPDATE cultos
            SET nombre = :n, dia_semana = :d, hora_inicio = :hi, hora_fin = :hf,
                fin_variable = :fv, es_reunion = :er
          WHERE id = :id'
    );
    $stmt->execute([
        'n'  => trim($nombre),
        'd'  => $diaSemana,
        'hi' => $horaInicio,
        'hf' => $finVariable ? null : (($horaFin !== null && trim($horaFin) !== '') ? $horaFin : null),
        'fv' => $finVariable ? 'true' : 'false',
        'er' => $esReunion ? 'true' : 'false',
        'id' => $id,
    ]);
    return $stmt->rowCount() > 0;
}

/** Activa/desactiva (baja blanda) un culto. */
function fijarActivoCulto(int $id, bool $activo): void
{
    db()->prepare('UPDATE cultos SET activo = :v WHERE id = :id')
        ->execute(['v' => $activo ? 'true' : 'false', 'id' => $id]);
}

// ===========================================================================
// FUNCIONES DE CULTO
// ===========================================================================

/**
 * Funciones de culto, opcionalmente por grupo (ministerial|servicio).
 * Por defecto solo activas (lo que consume "Mi semana").
 */
function listarFuncionesCulto(?string $grupo = null, bool $soloActivas = true): array
{
    $sql = 'SELECT id, nombre, grupo, lleva_fruto, etiqueta_fruto, activo
            FROM funciones_culto WHERE 1 = 1';
    $params = [];
    if ($soloActivas) {
        $sql .= ' AND activo = TRUE';
    }
    if ($grupo !== null) {
        $sql .= ' AND grupo = :g';
        $params['g'] = $grupo;
    }
    $sql .= ' ORDER BY grupo, nombre';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function crearFuncionCulto(string $nombre, string $grupo, bool $llevaFruto, ?string $etiquetaFruto): int
{
    $stmt = db()->prepare(
        'INSERT INTO funciones_culto (nombre, grupo, lleva_fruto, etiqueta_fruto)
         VALUES (:n, :g, :lf, :ef) RETURNING id'
    );
    $stmt->execute([
        'n'  => trim($nombre),
        'g'  => $grupo,
        'lf' => $llevaFruto ? 'true' : 'false',
        'ef' => $llevaFruto ? (($etiquetaFruto !== null && trim($etiquetaFruto) !== '') ? trim($etiquetaFruto) : null) : null,
    ]);
    return (int) $stmt->fetchColumn();
}

function actualizarFuncionCulto(int $id, string $nombre, string $grupo, bool $llevaFruto, ?string $etiquetaFruto): bool
{
    $stmt = db()->prepare(
        'UPDATE funciones_culto
            SET nombre = :n, grupo = :g, lleva_fruto = :lf, etiqueta_fruto = :ef
          WHERE id = :id'
    );
    $stmt->execute([
        'n'  => trim($nombre),
        'g'  => $grupo,
        'lf' => $llevaFruto ? 'true' : 'false',
        'ef' => $llevaFruto ? (($etiquetaFruto !== null && trim($etiquetaFruto) !== '') ? trim($etiquetaFruto) : null) : null,
        'id' => $id,
    ]);
    return $stmt->rowCount() > 0;
}

/** Activa/desactiva (baja blanda) una función de culto. */
function fijarActivoFuncionCulto(int $id, bool $activo): void
{
    db()->prepare('UPDATE funciones_culto SET activo = :v WHERE id = :id')
        ->execute(['v' => $activo ? 'true' : 'false', 'id' => $id]);
}

// ===========================================================================
// PROYECTOS
// ===========================================================================

/**
 * Proyectos con su categoría (si tiene). Por defecto TODOS (admin); pásalo en
 * true para solo activos. (La captura usa listarProyectosActivos() del
 * actividad_repo, que devuelve solo id+nombre de los activos.)
 */
function listarProyectos(bool $soloActivos = false): array
{
    $sql = 'SELECT p.id, p.nombre, p.observaciones, p.categoria_id,
                   p.fecha_inicio, p.fecha_fin, p.activo,
                   c.nombre AS categoria
            FROM proyectos p
            LEFT JOIN categorias c ON c.id = p.categoria_id';
    if ($soloActivos) {
        $sql .= ' WHERE p.activo = TRUE';
    }
    $sql .= ' ORDER BY p.activo DESC, p.nombre';
    return db()->query($sql)->fetchAll();
}

function crearProyecto(string $nombre, ?string $observaciones, ?int $categoriaId, ?string $fechaInicio, ?string $fechaFin): int
{
    $stmt = db()->prepare(
        'INSERT INTO proyectos (nombre, observaciones, categoria_id, fecha_inicio, fecha_fin)
         VALUES (:n, :o, :c, :fi, :ff) RETURNING id'
    );
    $stmt->execute([
        'n'  => trim($nombre),
        'o'  => ($observaciones !== null && trim($observaciones) !== '') ? trim($observaciones) : null,
        'c'  => $categoriaId,
        'fi' => ($fechaInicio !== null && $fechaInicio !== '') ? $fechaInicio : null,
        'ff' => ($fechaFin !== null && $fechaFin !== '') ? $fechaFin : null,
    ]);
    return (int) $stmt->fetchColumn();
}

function actualizarProyecto(int $id, string $nombre, ?string $observaciones, ?int $categoriaId, ?string $fechaInicio, ?string $fechaFin): bool
{
    $stmt = db()->prepare(
        'UPDATE proyectos
            SET nombre = :n, observaciones = :o, categoria_id = :c,
                fecha_inicio = :fi, fecha_fin = :ff
          WHERE id = :id'
    );
    $stmt->execute([
        'n'  => trim($nombre),
        'o'  => ($observaciones !== null && trim($observaciones) !== '') ? trim($observaciones) : null,
        'c'  => $categoriaId,
        'fi' => ($fechaInicio !== null && $fechaInicio !== '') ? $fechaInicio : null,
        'ff' => ($fechaFin !== null && $fechaFin !== '') ? $fechaFin : null,
        'id' => $id,
    ]);
    return $stmt->rowCount() > 0;
}

/** Activa/desactiva (baja blanda) un proyecto. */
function fijarActivoProyecto(int $id, bool $activo): void
{
    db()->prepare('UPDATE proyectos SET activo = :v WHERE id = :id')
        ->execute(['v' => $activo ? 'true' : 'false', 'id' => $id]);
}
