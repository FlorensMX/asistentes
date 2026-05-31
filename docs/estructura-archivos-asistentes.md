asistentes/                                   # raíz del repo — montesion.cloud/apps/asistentes
│
├── .gitignore                                [Fase 1]   ignora config.php y logs; conserva .gitkeep
│
├── config/
│   ├── config.example.php                    [Fase 1]   template de secretos commiteado (db PostgreSQL, app)
│   └── config.php                            [runtime]  secretos reales (GITIGNORED; no está en el repo)
│
├── docs/
│   ├── sistema-gestion-ministerial-brief.md  [Fase 1]   el PROMPT_MAESTRO — fuente de verdad del proyecto
│   └── estructura-archivos.md                [Fase 1]   ESTE documento
│
├── migrations/                               # aplicadas con psql -f, en orden numérico
│   ├── 001_schema_inicial.sql                [Fase 1]   todas las tablas del brief (asistentes, ministerios,
│   │                                                    compromisos_recurrentes, confirmaciones, registros,
│   │                                                    cultos, asistencia, funciones, proyectos, campañas)
│   ├── 002_seed_catalogos.sql                [Fase 1]   categorías, actividades, cultos y funciones de culto
│   └── 003_*.sql                             [futuro]   cambios incrementales
│
├── scripts/                                  # CLI: sudo -u www-data php scripts/...
│   └── crear_admin.php                       [Fase 1]   crea/promueve un usuario pastor/admin (password_hash bcrypt)
│
├── includes/                                 # lógica compartida (require_once); SIN Composer
│   ├── conexion.php                          [Fase 1]   bootstrap: configAsistentes() + db() (PDO singleton, EMULATE_PREPARES=false)
│   ├── auth.php                              [Fase 1]   sesión scopeada + login/logout + requerirLogin()/requerirRol() + CSRF + h()
│   ├── header.php                            [Fase 1]   shell HTML superior (Tailwind CDN, barra de usuario, <meta csrf>)
│   ├── footer.php                            [Fase 1]   cierre del layout abierto por header.php
│   ├── fotos.php                             [Fase 1]   guardarFotoAsistente(): valida mime real, convierte HEIC→JPG, guarda fotos_asistentes/{id}.{ext}
│   ├── asistentes_repo.php                   [Fase 1]   DAL: perfil, trabajo secular, foto familiar, roles, altas/bajas
│   ├── catalogos_repo.php                    [Fase 1]   DAL solo-lectura: categorías, actividades, cultos, funciones
│   ├── ministerios_repo.php                  [Fase 2]   DAL de los ministerios nombrados del asistente
│   ├── recurrentes_repo.php                  [Fase 2]   DAL de compromisos recurrentes + confirmaciones semanales
│   ├── actividad_repo.php                    [Fase 2]   DAL de registros de actividad fuera de culto
│   ├── cultos_repo.php                       [Fase 3]   DAL de asistencia a cultos + funciones realizadas
│   ├── campanias_repo.php                    [Fase 3]   DAL de periodos de campaña (incluye la suspensión de recurrentes)
│   └── reportes_repo.php                     [Fase 4]   DAL solo-lectura: agregados para el dashboard del Pastor
│
└── public/                                   # document root nginx (front-controller @asifront)
    ├── index.php                             [Fase 1]   dispatcher: redirige según sesión y rol
    ├── login.php                             [Fase 1]   vista + handler de login (CSRF, delay anti-fuerza-bruta)
    ├── logout.php                            [Fase 1]   destruye la sesión y vuelve al login
    │
    │   ── App del Asistente (mobile-first) ──
    ├── perfil.php                            [Fase 1]   mi perfil: datos, trabajo secular y subir la foto familiar
    ├── semana.php                            [Fase 2]   "Mi semana": cultos + recurrentes; confirmar con un toque
    ├── actividad.php                         [Fase 2]   registrar actividad variable (categoría → actividad → duración → fruto/proyecto)
    ├── ministerios.php                       [Fase 2]   alta/edición de mis ministerios nombrados
    ├── recurrentes.php                       [Fase 2]   alta/edición de mis compromisos recurrentes
    ├── campania.php                          [Fase 3]   declarar viaje/campaña (suspende recurrentes en el rango)
    ├── mi_dashboard.php                      [Fase 4]   mi resumen del mes (horas por categoría y por ministerio)
    │
    │   ── Panel del Pastor (escritorio) ──
    ├── consolidado.php                       [Fase 4]   tabla comparativa de todos los asistentes + filtro por mes
    ├── detalle.php                           [Fase 4]   detalle por asistente (composición, funciones, campañas, tendencia)
    │
    │   ── Administración ──
    ├── admin_asistentes.php                  [Fase 1]   altas/bajas, roles, reseteo de contraseña
    ├── admin_catalogos.php                   [Fase 3]   mantener categorías, actividades, cultos, funciones, proyectos
    │
    ├── api/                                   # endpoints AJAX JSON (idempotentes; nunca emiten HTML)
    │   ├── foto.php                          [Fase 1]   sube/reemplaza la foto familiar del asistente (multipart)
    │   ├── confirmar_recurrente.php          [Fase 2]   confirma/desmarca un recurrente de la semana (solo esa ocurrencia)
    │   ├── guardar_actividad.php             [Fase 2]   alta de un registro de actividad
    │   ├── guardar_ministerio.php            [Fase 2]   alta/edición de un ministerio
    │   ├── guardar_recurrente.php            [Fase 2]   alta/edición de un compromiso recurrente
    │   ├── guardar_funcion_culto.php         [Fase 3]   marca las funciones desempeñadas en un culto
    │   ├── guardar_campania.php              [Fase 3]   alta de un periodo de campaña
    │   └── reportes.php                      [Fase 4]   GET solo-lectura: agregados para el dashboard del Pastor
    │
    └── fotos_asistentes/                      # fotos familiares servidas por nginx (GITIGNORED su contenido)
        └── .gitkeep                           [Fase 1]   conserva el directorio; las fotos {id}.{ext} no se versionan


══════════════════════════════════════════════════════════════════════════════
NOTAS — diferencias conscientes respecto a conferenciapastores
══════════════════════════════════════════════════════════════════════════════

· Foto familiar del asistente: la sube el propio Asistente desde perfil.php (vía api/foto.php),
  se guarda en public/fotos_asistentes/{id}.{ext} y la ruta queda en asistentes.foto_familiar_url.
  Reusa el patrón de fotos.php de conferenciapastores (valida el mime real, convierte HEIC→JPG).
  El contenido de la carpeta va GITIGNORED; el directorio se conserva con .gitkeep.

· SIN import_jotform.php ni descubrir_campos.php. Este sistema no importa de
  Jotform: los asistentes se dan de alta desde admin_asistentes.php. Se conserva
  crear_admin.php para el primer usuario administrador/pastor.

· Mismo esqueleto que ya conoces: config/ (example + real gitignored),
  docs/, migrations/ numeradas aplicadas con psql -f, scripts/ CLI,
  includes/ con un *_repo.php por dominio, y public/ con páginas planas + api/.

· Convenciones heredadas de conferenciapastores: Tailwind CDN (sin build),
  PDO singleton con EMULATE_PREPARES=false, sesión scopeada por app,
  CSRF + helper h(), y endpoints api/ que solo devuelven JSON.

· Los *_repo.php se van creando por fase: en la Fase 1 bastan asistentes_repo
  y catalogos_repo (para login, admin y catálogos); el resto entra conforme
  se construyen las pantallas que los usan.
