<?php
/**
 * config.example.php
 *
 * Copiar a config.php y llenar los valores reales.
 * config.php DEBE estar en .gitignore — NUNCA commitearlo.
 *
 *   cp config/config.example.php config/config.php
 */

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 5432,
        'database' => 'asistentes',
        'user'     => 'asi_app',
        'password' => 'CAMBIAR_ESTA_CONTRASENA',
    ],

    'app' => [
        // Subpath de despliegue en el dominio. Debe coincidir con BASE_PATH
        // en includes/auth.php y con el front-controller @asifront de nginx.
        'base_url'  => 'https://montesion.cloud/asistentes',
        'fotos_dir' => __DIR__ . '/../public/fotos_asistentes',
        // Reportes de resultados de campaña: FUERA de public/ (no servidos por
        // nginx). La descarga siempre pasa por un endpoint PHP con guard de rol.
        // Si se omite, includes/reportes_campania.php usa este mismo default.
        'reportes_dir' => __DIR__ . '/../storage/reportes_campania',
        'debug'     => false,
    ],
];
