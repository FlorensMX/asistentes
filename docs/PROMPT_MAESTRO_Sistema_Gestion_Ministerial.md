# PROMPT_MAESTRO — Sistema de Gestión Ministerial
### Brief de construcción para Claude Code

**Proyecto:** Sistema de Gestión Ministerial · **Plataforma:** montesion.cloud
**Cliente:** Iglesia / movimiento IFB · **Responsable:** Florencio Martínez

---

## 0. Cómo usar este documento

Este es el brief completo para construir el sistema. Léelo entero antes de escribir código. El modelo conceptual está cerrado; aquí está la especificación técnica. Construye por fases (sección 9). Si una decisión no está especificada, sigue las convenciones de la sección 8 y pregunta antes de improvisar estructura de datos.

---

## 1. Contexto y objetivo

La iglesia tiene 33+ Pastores Asistentes que sirven en múltiples ministerios. El Pastor necesita una imagen real de **qué hace cada Asistente, cuánto tiempo le dedica y cómo se reparte ese tiempo**, para decidir sobre carga, delegación y acompañamiento.

Principio rector: **el sistema muestra, el Pastor juzga.** No emite veredictos ni banderas automáticas; presenta datos claros.

El tiempo se clasifica en dos: **Ministerio** (todo lo que se hace para el movimiento —servicio en la iglesia, Escuela Cristiana/CPMS, Colegio Bíblico/CBFMS, Proyecto Emaús, Ganadores de Almas, etc.—, remunerado o no) y **trabajo secular externo** (lo ajeno al movimiento). El sistema mide tiempo y actividad; **nunca dinero** (la nómina la lleva Tesorería, aparte).

---

## 2. Stack y entorno

- **Backend:** PHP 8.x
- **Base de datos:** PostgreSQL
- **Servidor:** Nginx sobre el VPS de montesion.cloud
- **Frontend:** mobile-first; la captura debe ser cómoda en celular (la app la usan 33+ personas no técnicas desde el teléfono)
- **Autenticación:** usuario y contraseña propios del sistema, contraseñas con `password_hash()` (bcrypt/argon2id) y sesiones PHP

---

## 3. Principios de producto

1. **Mobile-first y baja fricción.** Registrar algo debe tomar 2–3 toques. Esto es la prioridad número uno: dos intentos anteriores murieron por fricción.
2. **Lo fijo no se teclea.** Cultos y compromisos recurrentes se precargan; el Asistente solo confirma.
3. **Captura incremental, corte mensual.** Se registra en el momento; el sistema consolida cada mes.
4. **Registro por evento, no por etiqueta fija.** Las funciones de culto y la actividad variable se registran cada vez que ocurren.
5. **Cero dinero.** Ni salarios ni montos en ninguna tabla.
6. **Informa, no juzga.** Sin banderas automáticas (preparar el terreno para reglas configurables a futuro, pero no implementarlas ahora).
7. **Roster flexible.** Altas de Asistentes desde el primer día.

---

## 4. Modelo de datos (PostgreSQL)

Nombres en `snake_case`, español. DDL:

```sql
-- Usuarios / Asistentes (incluye perfil y rol)
CREATE TABLE asistentes (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(150) NOT NULL,
    usuario         VARCHAR(60)  UNIQUE NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    rol             VARCHAR(20)  NOT NULL DEFAULT 'asistente'
                    CHECK (rol IN ('asistente','pastor','admin')),
    telefono        VARCHAR(30),
    disponibilidad  TEXT,
    foto_familiar_url VARCHAR(255),            -- foto familiar que sube el propio Asistente
    activo          BOOLEAN NOT NULL DEFAULT TRUE,
    fecha_alta      DATE NOT NULL DEFAULT CURRENT_DATE,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Trabajo secular externo (declarativo, 0..n por asistente). SIN monto.
CREATE TABLE trabajo_secular (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    descripcion     VARCHAR(200) NOT NULL,        -- p. ej. "Taxi", "Comercio"
    horario         VARCHAR(200),                 -- texto de días/horas
    horas_semana    NUMERIC(5,1),                 -- carga aproximada
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- Catálogo: categorías de actividad
CREATE TABLE categorias (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(80) NOT NULL,
    orden           INTEGER NOT NULL DEFAULT 0
);

-- Catálogo: actividades fuera de culto (el fruto se define por actividad)
CREATE TABLE actividades (
    id              SERIAL PRIMARY KEY,
    categoria_id    INTEGER NOT NULL REFERENCES categorias(id),
    nombre          VARCHAR(120) NOT NULL,
    lleva_fruto     BOOLEAN NOT NULL DEFAULT FALSE,
    etiqueta_fruto  VARCHAR(60),                  -- "contactos", "personas atendidas"...
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- Catálogo: cultos y eventos fijos (incluye la Junta de Asistentes)
CREATE TABLE cultos (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(80) NOT NULL,         -- "Culto A", "Miércoles", "Junta de Asistentes"
    dia_semana      SMALLINT NOT NULL,            -- 0=domingo ... 6=sábado
    hora_inicio     TIME NOT NULL,
    hora_fin        TIME,                         -- NULL si fin variable
    fin_variable    BOOLEAN NOT NULL DEFAULT FALSE,
    es_reunion      BOOLEAN NOT NULL DEFAULT FALSE,
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- Catálogo: funciones que se desempeñan en culto
CREATE TABLE funciones_culto (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(120) NOT NULL,
    grupo           VARCHAR(20) NOT NULL CHECK (grupo IN ('ministerial','servicio')),
    lleva_fruto     BOOLEAN NOT NULL DEFAULT FALSE,
    etiqueta_fruto  VARCHAR(60),
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- Proyectos (iniciativas con nombre y temporada)
CREATE TABLE proyectos (
    id              SERIAL PRIMARY KEY,
    nombre          VARCHAR(200) NOT NULL,
    observaciones   TEXT,                         -- detalle/contexto (p. ej. título del libro)
    categoria_id    INTEGER REFERENCES categorias(id),
    fecha_inicio    DATE,
    fecha_fin       DATE,
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- Ministerios nombrados del Asistente (los da de alta él mismo desde su panel)
CREATE TABLE ministerios (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    nombre          VARCHAR(150) NOT NULL,        -- "Pescadores", "Uno por uno", "La Buena Semilla Anexos"
    categoria_id    INTEGER NOT NULL REFERENCES categorias(id),  -- normalmente Evangelismo o Cuidado pastoral
    observaciones   TEXT,
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- Compromisos ministeriales recurrentes (el patrón que declara el Asistente)
CREATE TABLE compromisos_recurrentes (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    ministerio_id   INTEGER REFERENCES ministerios(id),          -- si el recurrente es un ministerio nombrado
    nombre          VARCHAR(150) NOT NULL,        -- "Todos Transformados", "Clase CPMS"
    categoria_id    INTEGER NOT NULL REFERENCES categorias(id),
    dia_semana      SMALLINT NOT NULL,            -- 0..6
    hora_inicio     TIME NOT NULL,
    hora_fin        TIME NOT NULL,
    vigente_desde   DATE NOT NULL DEFAULT CURRENT_DATE,
    vigente_hasta   DATE,                         -- NULL = indefinido
    activo          BOOLEAN NOT NULL DEFAULT TRUE
);

-- Confirmación semanal de cada recurrente (el Asistente confirma con un toque)
CREATE TABLE confirmaciones_recurrente (
    id              SERIAL PRIMARY KEY,
    compromiso_id   INTEGER NOT NULL REFERENCES compromisos_recurrentes(id) ON DELETE CASCADE,
    fecha           DATE NOT NULL,                -- fecha de la ocurrencia
    confirmado      BOOLEAN NOT NULL DEFAULT TRUE,
    nota            TEXT,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (compromiso_id, fecha)
);

-- Registros de actividad variable (fuera de culto, no recurrente)
CREATE TABLE registros_actividad (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    actividad_id    INTEGER NOT NULL REFERENCES actividades(id),
    ministerio_id   INTEGER REFERENCES ministerios(id),   -- opcional, si pertenece a un ministerio nombrado
    fecha           DATE NOT NULL,
    hora_inicio     TIME,
    hora_fin        TIME,
    duracion_min    INTEGER,                      -- alternativa a inicio/fin
    fruto_cantidad  INTEGER,                      -- solo si la actividad lleva fruto
    proyecto_id     INTEGER REFERENCES proyectos(id),
    nota            TEXT,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Asistencia a cultos (por fecha)
CREATE TABLE asistencia_culto (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    culto_id        INTEGER NOT NULL REFERENCES cultos(id),
    fecha           DATE NOT NULL,
    asistio         BOOLEAN NOT NULL DEFAULT TRUE,
    hora_salida     TIME,                         -- para fin variable (Junta de Asistentes)
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (asistente_id, culto_id, fecha)
);

-- Funciones realizadas en ese culto (1..n por asistencia)
CREATE TABLE funciones_realizadas (
    id                  SERIAL PRIMARY KEY,
    asistencia_culto_id INTEGER NOT NULL REFERENCES asistencia_culto(id) ON DELETE CASCADE,
    funcion_culto_id    INTEGER NOT NULL REFERENCES funciones_culto(id),
    fruto_cantidad      INTEGER
);

-- Periodos de campaña / misión (suspenden recurrentes y cultos del Asistente)
CREATE TABLE periodos_campania (
    id              SERIAL PRIMARY KEY,
    asistente_id    INTEGER NOT NULL REFERENCES asistentes(id) ON DELETE CASCADE,
    categoria_id    INTEGER REFERENCES categorias(id),  -- normalmente Evangelismo
    fecha_inicio    DATE NOT NULL,
    fecha_fin       DATE NOT NULL,
    lugar           VARCHAR(150),
    descripcion     TEXT,
    fruto_cantidad  INTEGER,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

**Índices recomendados:** `(asistente_id, fecha)` en `registros_actividad`, `asistencia_culto` y `periodos_campania`; `(compromiso_id, fecha)` ya es UNIQUE; `(categoria_id)` en `actividades`.

---

## 5. Catálogos a sembrar (seed)

### Categorías
Evangelismo y alcance · Cuidado pastoral · Enseñanza y discipulado · Servicio en cultos y eventos · Administración · Otro.

### Actividades (con su fruto)
- **Evangelismo y alcance:** Ganar almas *(fruto: contactos)* · Invitación y seguimiento *(contactos)* · Campañas dentro/fuera del país *(decisiones)* · Media de alcance / contenido digital *(contactos)* · Alcance Evangelístico *(contactos)* — categoría paraguas; el ministerio específico (Pescadores, La Buena Semilla, Uno por uno, etc.) lo nombra el propio Asistente en su catálogo de ministerios (ver tabla `ministerios` y sección 7).
- **Cuidado pastoral:** Consejería *(personas)* · Visita pastoral *(personas)* · Atención a personas *(personas)* · Emergencias *(personas)*.
- **Enseñanza y discipulado:** Discipulado *(discípulos/sesiones)* · Predicación / enseñanza · Colegio bíblico / Instituto · Escuela Cristiana (clases académicas) · Preparación de material. *(solo Discipulado lleva fruto)*
- **Servicio en cultos y eventos:** Eventos especiales · Rutas (transporte). *(sin fruto; las funciones de culto van en su propio catálogo)*
- **Administración:** Trabajo administrativo · Tecnología / sistemas · Supervisión · Mantenimiento / limpieza / cocina · Reuniones. *(sin fruto)*
- **Otro:** Otro *(sin fruto; nota obligatoria en la UI)*.

### Cultos (calendario fijo)
| nombre | dia_semana | hora_inicio | hora_fin | fin_variable | es_reunion |
|---|---|---|---|---|---|
| Miércoles | 3 | 19:30 | 21:00 | false | false |
| Jueves | 4 | 19:30 | 21:00 | false | false |
| Sábado (culto) | 6 | 10:30 | 11:30 | false | false |
| Junta de Asistentes | 6 | 11:30 | NULL | true | true |
| Culto A | 0 | 08:30 | 11:00 | false | false |
| Culto B | 0 | 11:45 | 13:00 | false | false |
| Culto PM | 0 | 19:00 | 21:00 | false | false |

### Funciones de culto
- **Ministeriales:** Dirigir el culto · Predicar (suplente) · Escuela Dominical / clase de niños · Captación tipo Pescadores *(fruto: decisiones)* · Bautizar *(fruto: bautizados)* · Cantos / dirección de alabanza / especial musical · Dirección de orquesta o coro.
- **Servicio:** Ujieres / organización · Audio / técnica / media · Estacionamiento / tráfico · Entrega de despensas · Entrega de juguetes · Registro de invitados en la entrada · Reporte de asistencia de rutas · Tesorería / pagos.
- **Sin función:** Asistir (sin función asignada).

---

## 6. Lógica clave

**Confirmación semanal de recurrentes.** Para cada `compromiso_recurrente` vigente, el sistema genera la ocurrencia de la semana (según `dia_semana`). En "Mi semana", el Asistente confirma cada una con un toque → inserta en `confirmaciones_recurrente (confirmado=true)`. Las no confirmadas cuentan como no realizadas. Permitir también desmarcar / agregar nota.

**Suspensión por campaña.** Si una fecha cae dentro de un `periodo_campania` del Asistente, sus recurrentes y cultos de esos días **no se piden ni cuentan en contra** (estaba fuera). Su dedicación de esos días la representa el propio periodo de campaña.

**Cómputo de horas ministeriales (por mes, por asistente):**
- Recurrentes confirmados: `hora_fin - hora_inicio` de cada confirmación.
- `registros_actividad`: `hora_fin - hora_inicio` o `duracion_min`.
- `asistencia_culto`: duración del culto (en `Junta de Asistentes`, usar `hora_salida - hora_inicio`).
- `periodos_campania`: representarlos como **días de misión** (no convertir a horas artificialmente); mostrarlos como bloque de dedicación intensiva junto al total de horas.

**Ministerio vs. secular.** El trabajo secular externo es declarativo: **no se suma** al tiempo ministerial. Se muestra aparte, como contrapeso para la lectura del Pastor.

**Corte mensual.** Job/consulta que consolida el mes y alimenta el dashboard del Pastor.

---

## 7. Pantallas

### App del Asistente (mobile-first)
1. **Login** (usuario / contraseña).
2. **Mi semana** (inicio): lista los cultos y los recurrentes de la semana; cada uno se confirma con un toque. Botones grandes: **+ Registrar actividad** y **Declarar viaje/campaña**.
3. **Registrar actividad:** categoría → actividad → (ministerio, si aplica) → duración (inicio/fin o minutos) → fruto (solo si la actividad lo lleva) → proyecto (opcional) → nota → guardar.
4. **En culto:** al confirmar asistencia a un culto, marcar la(s) función(es) desempeñada(s) y, donde aplique, el fruto.
5. **Mis ministerios:** el Asistente da de alta y nombra sus ministerios (p. ej. "Pescadores", "Uno por uno", "La Buena Semilla Anexos"), cada uno con su categoría y observaciones. Quedan disponibles para elegirlos al registrar actividad o al definir un recurrente.
6. **Mis recurrentes:** agregar / editar / desactivar (nombre o ministerio, categoría, día, hora).
7. **Declarar campaña:** fechas, lugar, descripción, fruto.
8. **Mi dashboard:** horas del mes por categoría y por ministerio, cultos, proyectos, historial.
9. **Mi perfil:** datos, trabajo secular y **subir la foto familiar** (validar el tipo de imagen; convertir HEIC→JPG).

### Panel del Pastor
1. **Consolidado:** tabla de Asistentes con horas ministeriales del mes, secular declarado, asistencia a cultos; comparativo; filtro por mes.
2. **Detalle por Asistente:** foto familiar y composición del ministerio por categoría y por ministerio nombrado, funciones en cultos, proyectos (con observaciones), días de campaña, tendencia mensual.

### Administración
1. **Asistentes:** altas/bajas, reseteo de contraseña, asignación de rol.
2. **Catálogos:** mantener categorías, actividades, cultos, funciones y proyectos.

---

## 8. Convenciones técnicas

- PostgreSQL; tablas y columnas en `snake_case`, español.
- Zona horaria `America/Mexico_City`; fechas/horas con `date` / `time` / `timestamptz`.
- Interfaz íntegramente en español (México).
- Diseño responsive, mobile-first (la mayoría de la captura ocurre en celular).
- Contraseñas con `password_hash()` / `password_verify()`; nunca en texto plano.
- Sin almacenar dinero ni montos en ninguna tabla.
- **Foto familiar:** subida multipart validando el tipo real de imagen (no solo la extensión); convertir HEIC→JPG; guardar en `public/fotos_asistentes/{id}.{ext}` y la ruta en `asistentes.foto_familiar_url`. Reusar el patrón de `fotos.php` de conferenciapastores.

---

## 9. Fases de implementación

1. **Base:** esquema + seed de catálogos + autenticación + administración de Asistentes.
2. **Captura del Asistente:** Mi semana (cultos + recurrentes con confirmación), registrar actividad, gestión de recurrentes.
3. **Cultos, campañas y proyectos:** funciones en culto, periodos de campaña (con su suspensión), proyectos.
4. **Dashboards:** del Asistente y del Pastor.
5. **Cierre mensual y pulido mobile.**

---

## 10. Notas y pendientes

- Los ministerios nombrados (Pescadores, La Buena Semilla, Uno por uno, etc.) los da de alta cada Asistente en su catálogo de ministerios (tabla `ministerios`), con un campo de observaciones para el detalle. Esto mantiene el tiempo agregable por ministerio en lugar de texto libre disperso.
- "Visitas" quedó resuelto como **visita pastoral** (Cuidado pastoral), no evangelística.
- Las **banderas/umbrales automáticos** quedan fuera de esta versión; dejar el modelo listo para añadir reglas configurables sin rehacer tablas.
- La representación de **horas de campaña** quedó como días de misión; confirmar con el responsable si se desea una estimación de horas.
