-- =====================================================================
-- 001_schema_inicial.sql
-- Sistema de Gestión Ministerial — esquema completo (PostgreSQL)
--
-- Aplicar:
--   psql -U asi_app -d asistentes -f migrations/001_schema_inicial.sql
--
-- Convención dia_semana: 0 = domingo ... 6 = sábado
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- Catálogo: categorías de actividad
-- ---------------------------------------------------------------------
CREATE TABLE categorias (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(80) NOT NULL UNIQUE,
    orden           INTEGER NOT NULL DEFAULT 0
);

-- ---------------------------------------------------------------------
-- Usuarios / Asistentes (perfil + rol + credenciales)
-- ---------------------------------------------------------------------
CREATE TABLE asistentes (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(150) NOT NULL,
    usuario         VARCHAR(60)  NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    rol             VARCHAR(20)  NOT NULL DEFAULT 'asistente'
                    CHECK (rol IN ('asistente','pastor','admin')),
    telefono        VARCHAR(30),
    disponibilidad  TEXT,
    foto_familiar_url VARCHAR(255),                 -- foto familiar que sube el propio Asistente
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    fecha_alta      DATE NOT NULL DEFAULT CURRENT_DATE,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Catálogo: actividades fuera de culto (el fruto se define por actividad)
-- ---------------------------------------------------------------------
CREATE TABLE actividades (
    id              SERIAL PRIMARY KEY,
    categoria_id    INTEGER NOT NULL REFERENCES categorias(id),
    nombre          VARCHAR(120) NOT NULL,
    lleva_fruto     BOOLEAN NOT NULL DEFAULT FALSE,
    etiqueta_fruto  VARCHAR(60),
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE (categoria_id, nombre)
);

-- ---------------------------------------------------------------------
-- Catálogo: cultos y eventos fijos (incluye la Junta de Asistentes)
-- ---------------------------------------------------------------------
CREATE TABLE cultos (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(80) NOT NULL UNIQUE,
    dia_semana      SMALLINT NOT NULL CHECK (dia_semana BETWEEN 0 AND 6),
    hora_inicio     TIME NOT NULL,
    hora_fin        TIME,                          -- NULL si fin variable
    fin_variable    BOOLEAN NOT NULL DEFAULT FALSE,
    es_reunion      BOOLEAN NOT NULL DEFAULT FALSE,
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Catálogo: funciones que se desempeñan en culto
-- ---------------------------------------------------------------------
CREATE TABLE funciones_culto (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(120) NOT NULL UNIQUE,
    grupo           VARCHAR(20) NOT NULL CHECK (grupo IN ('ministerial','servicio')),
    lleva_fruto     BOOLEAN NOT NULL DEFAULT FALSE,
    etiqueta_fruto  VARCHAR(60),
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Proyectos (iniciativas con nombre y temporada)
-- ---------------------------------------------------------------------
CREATE TABLE proyectos (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(200) NOT NULL,
    observaciones   TEXT,
    categoria_id    INTEGER REFERENCES categorias(id),
    fecha_inicio    DATE,
    fecha_fin       DATE,
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Trabajo secular externo (declarativo; SIN monto)
-- ---------------------------------------------------------------------
CREATE TABLE trabajo_secular (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    descripcion     VARCHAR(200) NOT NULL,         -- p. ej. "Taxi", "Comercio"
    horario         VARCHAR(200),                  -- texto de días/horas
    horas_semana    NUMERIC(5,1),                  -- carga aproximada
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Ministerios nombrados del Asistente (los da de alta él mismo)
-- ---------------------------------------------------------------------
CREATE TABLE ministerios (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    nombre          VARCHAR(150) NOT NULL,         -- "Pescadores", "Uno por uno", "La Buena Semilla Anexos"
    categoria_id    INTEGER NOT NULL REFERENCES categorias(id),
    observaciones   TEXT,
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Compromisos ministeriales recurrentes (el patrón que declara el Asistente)
-- ---------------------------------------------------------------------
CREATE TABLE compromisos_recurrentes (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    ministerio_id   INTEGER REFERENCES ministerios(id),   -- si el recurrente es un ministerio nombrado
    nombre          VARCHAR(150) NOT NULL,                -- "Todos Transformados", "Clase CPMS"
    categoria_id    INTEGER NOT NULL REFERENCES categorias(id),
    dia_semana      SMALLINT NOT NULL CHECK (dia_semana BETWEEN 0 AND 6),
    hora_inicio     TIME NOT NULL,
    hora_fin        TIME NOT NULL,
    vigente_desde   DATE NOT NULL DEFAULT CURRENT_DATE,
    vigente_hasta   DATE,                                 -- NULL = indefinido
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- ---------------------------------------------------------------------
-- Confirmación semanal de cada recurrente (el Asistente confirma con un toque)
-- ---------------------------------------------------------------------
CREATE TABLE confirmaciones_recurrente (
    id              SERIAL PRIMARY KEY,
    compromiso_id   INTEGER NOT NULL REFERENCES compromisos_recurrentes(id) ON DELETE CASCADE,
    fecha           DATE NOT NULL,                        -- fecha de la ocurrencia
    confirmado      BOOLEAN NOT NULL DEFAULT TRUE,
    nota            TEXT,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (compromiso_id, fecha)
);

-- ---------------------------------------------------------------------
-- Registros de actividad variable (fuera de culto, no recurrente)
-- ---------------------------------------------------------------------
CREATE TABLE registros_actividad (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    actividad_id    INTEGER NOT NULL REFERENCES actividades(id),
    ministerio_id   INTEGER REFERENCES ministerios(id),   -- opcional, si pertenece a un ministerio nombrado
    fecha           DATE NOT NULL,
    hora_inicio     TIME,
    hora_fin        TIME,
    duracion_min    INTEGER,                              -- alternativa a inicio/fin
    fruto_cantidad  INTEGER,                              -- solo si la actividad lleva fruto
    proyecto_id     INTEGER REFERENCES proyectos(id),
    nota            TEXT,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------------
-- Asistencia a cultos (por fecha)
-- "Solo asistí" = una fila aquí con asistio = TRUE y SIN filas en
-- funciones_realizadas (la ausencia de función es la señal).
-- ---------------------------------------------------------------------
CREATE TABLE asistencia_culto (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    culto_id        INTEGER NOT NULL REFERENCES cultos(id),
    fecha           DATE NOT NULL,
    asistio         BOOLEAN NOT NULL DEFAULT TRUE,
    hora_salida     TIME,                                 -- para fin variable (Junta de Asistentes)
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (asistente_id, culto_id, fecha)
);

-- ---------------------------------------------------------------------
-- Funciones realizadas en ese culto (1..n por asistencia)
-- ---------------------------------------------------------------------
CREATE TABLE funciones_realizadas (
    id                  SERIAL PRIMARY KEY,
    asistencia_culto_id INTEGER NOT NULL REFERENCES asistencia_culto(id) ON DELETE CASCADE,
    funcion_culto_id    INTEGER NOT NULL REFERENCES funciones_culto(id),
    fruto_cantidad      INTEGER,
    UNIQUE (asistencia_culto_id, funcion_culto_id)
);

-- ---------------------------------------------------------------------
-- Periodos de campaña / misión (suspenden recurrentes y cultos del Asistente)
-- ---------------------------------------------------------------------
CREATE TABLE periodos_campania (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    categoria_id    INTEGER REFERENCES categorias(id),     -- normalmente Evangelismo
    fecha_inicio    DATE NOT NULL,
    fecha_fin       DATE NOT NULL,
    lugar           VARCHAR(150),
    descripcion     TEXT,
    fruto_cantidad  INTEGER,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now(),
    CHECK (fecha_fin >= fecha_inicio)
);

-- ---------------------------------------------------------------------
-- Índices
-- ---------------------------------------------------------------------
CREATE INDEX idx_actividades_categoria       ON actividades(categoria_id);
CREATE INDEX idx_ministerios_asistente       ON ministerios(asistente_id);
CREATE INDEX idx_recurrentes_asistente       ON compromisos_recurrentes(asistente_id);
CREATE INDEX idx_confirmaciones_fecha        ON confirmaciones_recurrente(fecha);
CREATE INDEX idx_registros_asistente_fecha   ON registros_actividad(asistente_id, fecha);
CREATE INDEX idx_asistencia_asistente_fecha  ON asistencia_culto(asistente_id, fecha);
CREATE INDEX idx_campania_asistente_fechas   ON periodos_campania(asistente_id, fecha_inicio, fecha_fin);

COMMIT;
