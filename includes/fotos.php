<?php
/**
 * includes/fotos.php
 *
 * Helper reutilizable para guardar la foto familiar de un asistente.
 *
 * Convención:
 *   {fotos_dir}/{id}.{ext}      ej. fotos_asistentes/42.jpg
 *
 * La actualización de `foto_familiar_url` en BD NO ocurre aquí — usar
 * actualizarFotoUrl() de asistentes_repo.php.
 *
 * Gotchas honrados (heredados de conferenciapastores):
 *   - Un solo move_uploaded_file.
 *   - Validación de mime real con finfo (no por extensión).
 *   - Nombrado por id (el reemplazo nunca colisiona).
 *   - HEIC→JPG si Imagick tiene soporte HEIF (desktop no renderiza HEIC).
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

const FOTOS_MIME_PERMITIDOS = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heic',
];

const FOTOS_TAMANIO_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB blandos

/**
 * Guarda el archivo subido del asistente en disco.
 *
 * Acepta una entrada de $_FILES (keys name/type/tmp_name/error/size).
 * Si error === UPLOAD_ERR_NO_FILE, devuelve éxito silencioso con
 * ruta_relativa = null (la foto es opcional).
 *
 * @return array{ok: bool, ruta_relativa: ?string, error: ?string}
 */
function guardarFotoAsistente(array $archivo, int $asistenteId): array
{
    $err = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'ruta_relativa' => null, 'error' => null];
    }

    if ($err !== UPLOAD_ERR_OK) {
        $mensaje = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La foto excede el tamaño máximo permitido por el servidor.',
            UPLOAD_ERR_PARTIAL    => 'La foto se subió parcialmente. Intenta de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Error de servidor: no hay directorio temporal.',
            UPLOAD_ERR_CANT_WRITE => 'Error de servidor: no se pudo escribir la foto.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión PHP bloqueó la subida.',
            default               => 'Error desconocido al subir la foto.',
        };
        return ['ok' => false, 'ruta_relativa' => null, 'error' => $mensaje];
    }

    $tmp = (string) ($archivo['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'ruta_relativa' => null, 'error' => 'Archivo inválido.'];
    }

    $tamanio = (int) ($archivo['size'] ?? 0);
    if ($tamanio > FOTOS_TAMANIO_MAX_BYTES) {
        return ['ok' => false, 'ruta_relativa' => null, 'error' => 'La foto excede 10 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) ($finfo->file($tmp) ?: '');

    if (!isset(FOTOS_MIME_PERMITIDOS[$mime])) {
        return ['ok' => false, 'ruta_relativa' => null, 'error' => 'Formato no soportado. Usa JPG, PNG, WebP o HEIC.'];
    }

    // getimagesize confirma estructura de imagen real. HEIC no siempre se
    // soporta nativamente — para ese tipo confiamos en el mime de finfo.
    if ($mime !== 'image/heic' && $mime !== 'image/heif') {
        if (@getimagesize($tmp) === false) {
            return ['ok' => false, 'ruta_relativa' => null, 'error' => 'El archivo no es una imagen válida.'];
        }
    }

    $ext     = FOTOS_MIME_PERMITIDOS[$mime];
    $config  = configAsistentes();
    $rootDir = rtrim((string) $config['app']['fotos_dir'], '/');

    if (!is_dir($rootDir) && !@mkdir($rootDir, 0775, true) && !is_dir($rootDir)) {
        return ['ok' => false, 'ruta_relativa' => null, 'error' => 'No se pudo crear el directorio de fotos.'];
    }

    // Limpia cualquier foto previa del asistente (otra extensión) para no
    // dejar huérfanos al cambiar de formato.
    foreach (FOTOS_MIME_PERMITIDOS as $extPrevia) {
        $previa = $rootDir . '/' . $asistenteId . '.' . $extPrevia;
        if (is_file($previa)) {
            @unlink($previa);
        }
    }

    $destino = $rootDir . '/' . $asistenteId . '.' . $ext;

    if (!move_uploaded_file($tmp, $destino)) {
        return ['ok' => false, 'ruta_relativa' => null, 'error' => 'No se pudo guardar la foto en disco.'];
    }

    // HEIC → JPG si Imagick tiene soporte HEIF.
    if ($ext === 'heic') {
        $convertido = false;
        if (class_exists('Imagick')) {
            try {
                $formatos = Imagick::queryFormats('HEI*');
                if (!empty($formatos)) {
                    $img = new Imagick($destino);
                    $img->setImageFormat('jpeg');
                    $jpgDestino = $rootDir . '/' . $asistenteId . '.jpg';
                    $img->writeImage($jpgDestino);
                    $img->clear();
                    @unlink($destino);
                    $destino    = $jpgDestino;
                    $ext        = 'jpg';
                    $convertido = true;
                }
            } catch (Throwable $e) {
                error_log("[fotos] Conversión HEIC->JPG falló, dejo el HEIC original: " . $e->getMessage());
            }
        }
        if (!$convertido) {
            error_log("[fotos] HEIC guardado tal cual (Imagick/HEIF no disponible); puede no renderizar en desktop: $destino");
        }
    }

    return [
        'ok'            => true,
        'ruta_relativa' => 'fotos_asistentes/' . $asistenteId . '.' . $ext,
        'error'         => null,
    ];
}
