-- =====================================================================
-- 002_seed_catalogos.sql
-- Sistema de Gestión Ministerial — catálogos base (PostgreSQL)
--
-- Aplicar (después de 001):
--   psql -U asi_app -d asistentes -f migrations/002_seed_catalogos.sql
--
-- Idempotente: puede re-ejecutarse sin duplicar (ON CONFLICT DO NOTHING).
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- Categorías
-- ---------------------------------------------------------------------
INSERT INTO categorias (nombre, orden) VALUES
    ('Evangelismo y alcance',        1),
    ('Cuidado pastoral',             2),
    ('Enseñanza y discipulado',      3),
    ('Servicio en cultos y eventos', 4),
    ('Administración',               5),
    ('Otro',                         6)
ON CONFLICT (nombre) DO NOTHING;

-- ---------------------------------------------------------------------
-- Actividades (fuera de culto). El fruto se define por actividad.
-- La FK de categoría se resuelve por nombre para no depender de IDs.
-- ---------------------------------------------------------------------

-- Evangelismo y alcance
INSERT INTO actividades (categoria_id, nombre, lleva_fruto, etiqueta_fruto) VALUES
    ((SELECT id FROM categorias WHERE nombre='Evangelismo y alcance'), 'Ganar almas',                       TRUE,  'contactos'),
    ((SELECT id FROM categorias WHERE nombre='Evangelismo y alcance'), 'Invitación y seguimiento',          TRUE,  'contactos'),
    ((SELECT id FROM categorias WHERE nombre='Evangelismo y alcance'), 'Campañas (dentro y fuera del país)', TRUE,  'decisiones'),
    ((SELECT id FROM categorias WHERE nombre='Evangelismo y alcance'), 'Media de alcance / contenido digital', TRUE, 'contactos'),
    ((SELECT id FROM categorias WHERE nombre='Evangelismo y alcance'), 'Alcance Evangelístico',             TRUE,  'contactos')
ON CONFLICT (categoria_id, nombre) DO NOTHING;

-- Cuidado pastoral
INSERT INTO actividades (categoria_id, nombre, lleva_fruto, etiqueta_fruto) VALUES
    ((SELECT id FROM categorias WHERE nombre='Cuidado pastoral'), 'Consejería',          TRUE, 'personas'),
    ((SELECT id FROM categorias WHERE nombre='Cuidado pastoral'), 'Visita pastoral',     TRUE, 'personas'),
    ((SELECT id FROM categorias WHERE nombre='Cuidado pastoral'), 'Atención a personas', TRUE, 'personas'),
    ((SELECT id FROM categorias WHERE nombre='Cuidado pastoral'), 'Emergencias',         TRUE, 'personas')
ON CONFLICT (categoria_id, nombre) DO NOTHING;

-- Enseñanza y discipulado (solo Discipulado lleva fruto)
INSERT INTO actividades (categoria_id, nombre, lleva_fruto, etiqueta_fruto) VALUES
    ((SELECT id FROM categorias WHERE nombre='Enseñanza y discipulado'), 'Discipulado',                       TRUE,  'discípulos'),
    ((SELECT id FROM categorias WHERE nombre='Enseñanza y discipulado'), 'Predicación / enseñanza',           FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Enseñanza y discipulado'), 'Colegio bíblico / Instituto',       FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Enseñanza y discipulado'), 'Escuela Cristiana (clases académicas)', FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Enseñanza y discipulado'), 'Preparación de material',           FALSE, NULL)
ON CONFLICT (categoria_id, nombre) DO NOTHING;

-- Servicio en cultos y eventos
INSERT INTO actividades (categoria_id, nombre, lleva_fruto, etiqueta_fruto) VALUES
    ((SELECT id FROM categorias WHERE nombre='Servicio en cultos y eventos'), 'Eventos especiales',  FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Servicio en cultos y eventos'), 'Rutas (transporte)',  FALSE, NULL)
ON CONFLICT (categoria_id, nombre) DO NOTHING;

-- Administración
INSERT INTO actividades (categoria_id, nombre, lleva_fruto, etiqueta_fruto) VALUES
    ((SELECT id FROM categorias WHERE nombre='Administración'), 'Trabajo administrativo',          FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Administración'), 'Tecnología / sistemas',           FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Administración'), 'Supervisión',                     FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Administración'), 'Mantenimiento / limpieza / cocina', FALSE, NULL),
    ((SELECT id FROM categorias WHERE nombre='Administración'), 'Reuniones',                       FALSE, NULL)
ON CONFLICT (categoria_id, nombre) DO NOTHING;

-- Otro
INSERT INTO actividades (categoria_id, nombre, lleva_fruto, etiqueta_fruto) VALUES
    ((SELECT id FROM categorias WHERE nombre='Otro'), 'Otro', FALSE, NULL)
ON CONFLICT (categoria_id, nombre) DO NOTHING;

-- ---------------------------------------------------------------------
-- Cultos (calendario fijo).  dia_semana: 0=domingo ... 6=sábado
-- ---------------------------------------------------------------------
INSERT INTO cultos (nombre, dia_semana, hora_inicio, hora_fin, fin_variable, es_reunion) VALUES
    ('Miércoles',            3, '19:30', '21:00', FALSE, FALSE),
    ('Jueves',               4, '19:30', '21:00', FALSE, FALSE),
    ('Sábado (culto)',       6, '10:30', '11:30', FALSE, FALSE),
    ('Junta de Asistentes',  6, '11:30',  NULL,   TRUE,  TRUE),
    ('Culto A',              0, '08:30', '11:00', FALSE, FALSE),
    ('Culto B',              0, '11:45', '13:00', FALSE, FALSE),
    ('Culto PM',             0, '19:00', '21:00', FALSE, FALSE)
ON CONFLICT (nombre) DO NOTHING;

-- ---------------------------------------------------------------------
-- Funciones de culto
-- (No se siembra "Asistir": "solo asistí" = asistencia sin funciones.)
-- ---------------------------------------------------------------------

-- Ministeriales
INSERT INTO funciones_culto (nombre, grupo, lleva_fruto, etiqueta_fruto) VALUES
    ('Dirigir el culto',                                'ministerial', FALSE, NULL),
    ('Predicar (suplente)',                             'ministerial', FALSE, NULL),
    ('Escuela Dominical / clase de niños',              'ministerial', FALSE, NULL),
    ('Captación tipo Pescadores',                       'ministerial', TRUE,  'decisiones'),
    ('Bautizar',                                        'ministerial', TRUE,  'bautizados'),
    ('Cantos / dirección de alabanza / especial musical', 'ministerial', FALSE, NULL),
    ('Dirección de orquesta o coro',                    'ministerial', FALSE, NULL)
ON CONFLICT (nombre) DO NOTHING;

-- Servicio
INSERT INTO funciones_culto (nombre, grupo, lleva_fruto, etiqueta_fruto) VALUES
    ('Ujieres / organización',                'servicio', FALSE, NULL),
    ('Audio / técnica / media',               'servicio', FALSE, NULL),
    ('Estacionamiento / tráfico',             'servicio', FALSE, NULL),
    ('Entrega de despensas',                  'servicio', FALSE, NULL),
    ('Entrega de juguetes',                   'servicio', FALSE, NULL),
    ('Registro de invitados en la entrada',   'servicio', FALSE, NULL),
    ('Reporte de asistencia de rutas',        'servicio', FALSE, NULL),
    ('Tesorería / pagos',                     'servicio', FALSE, NULL)
ON CONFLICT (nombre) DO NOTHING;

COMMIT;
