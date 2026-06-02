<?php
/**
 * includes/reportes_campania.php
 *
 * Helper de almacenamiento del reporte de resultados de una campaña.
 *
 * Decisión (Trabajo B): almacenamiento PROTEGIDO. A diferencia de la foto
 * familiar (que nginx sirve pública como <img>), un reporte puede traer
 * información más sensible, así que se guarda FUERA de public/ —en
 * storage/reportes_campania/, hermano de public/— donde ninguna URL lo
 * alcanza. La descarga siempre pasa por public/api/descargar_reporte_campania.php
 * con guard de rol/pertenencia.
 *
 * Convención de nombre en disco (no adivinable):
 *   {campania_id}_{token}.{ext}     ej. 42_9f3a…c1.pdf
 * En la columna periodos_campania.reporte_ruta se guarda SOLO ese nombre (no la
 * ruta absoluta del servidor, que cambia entre entornos); el directorio se
 * resuelve siempre con directorioReportes(). Leer con basename() defiende de
 * cualquier intento de path traversal aunque el valor en BD fuese manipulado.
 *
 * Gotchas honrados (igual que includes/fotos.php):
 *   - Un solo move_uploaded_file + is_uploaded_file.
 *   - Validación de mime REAL con finfo (no por extensión).
 *   - HEIC→JPG si Imagick tiene soporte HEIF (desktop no renderiza HEIC).
 */

declare(strict_types=1);

require_once __DIR__ . '/conexion.php';

/** Mimes aceptados para el reporte → extensión en disco. */
const REPORTE_MIME_PERMITIDOS = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/heic'      => 'heic',
    'image/heif'      => 'heic',
];

/** Tope de tamaño del reporte. Más alto que la foto: los PDF pesan más. */
const REPORTE_TAMANIO_MAX_BYTES = 10 * 1024 * 1024;   // 10 MB

/** Content-Type de descarga por extensión guardada. */
const REPORTE_CONTENT_TYPE = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'png'  => 'image/png',
    'heic' => 'image/heic',
];

/**
 * Directorio de almacenamiento de los reportes (absoluto, sin barra final).
 * Usa config['app']['reportes_dir'] si está definido; si no, el default
 * hermano de public/ (storage/reportes_campania bajo la raíz de la app).
 */
function directorioReportes(): string
{
    $config = configAsistentes();
    $dir = $config['app']['reportes_dir'] ?? (dirname(__DIR__) . '/storage/reportes_campania');
    return rtrim((string) $dir, '/');
}

/**
 * Saneamiento del nombre ORIGINAL para mostrar/descargar: quita ruta y
 * caracteres de control (corta header injection en Content-Disposition), y
 * acota a 255 (largo de la columna). Devuelve un nombre no vacío.
 */
function sanearNombreReporte(string $nombre): string
{
    $base = basename($nombre);
    // Fuera caracteres de control, comillas, separadores de ruta y saltos.
    $base = preg_replace('/[\x00-\x1F\x7F"\\\\\/\r\n]+/', '_', $base) ?? '';
    $base = trim($base);
    if ($base === '' || $base === '.' || $base === '..') {
        $base = 'reporte';
    }
    return mb_substr($base, 0, 255);
}

/**
 * Guarda en disco el archivo subido del reporte de una campaña.
 *
 * @param array $archivo Entrada de $_FILES (name/type/tmp_name/error/size).
 * @return array{ok: bool, archivo: ?string, error: ?string}
 *         En éxito, 'archivo' es el nombre en disco (a guardar en reporte_ruta).
 */
function guardarReporteCampania(array $archivo, int $campaniaId): array
{
    $err = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($err === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'archivo' => null, 'error' => 'No se recibió ningún archivo.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        $mensaje = match ($err) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El reporte excede el tamaño máximo permitido por el servidor.',
            UPLOAD_ERR_PARTIAL    => 'El reporte se subió parcialmente. Intenta de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'Error de servidor: no hay directorio temporal.',
            UPLOAD_ERR_CANT_WRITE => 'Error de servidor: no se pudo escribir el reporte.',
            UPLOAD_ERR_EXTENSION  => 'Una extensión PHP bloqueó la subida.',
            default               => 'Error desconocido al subir el reporte.',
        };
        return ['ok' => false, 'archivo' => null, 'error' => $mensaje];
    }

    $tmp = (string) ($archivo['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'archivo' => null, 'error' => 'Archivo inválido.'];
    }

    $tamanio = (int) ($archivo['size'] ?? 0);
    if ($tamanio > REPORTE_TAMANIO_MAX_BYTES) {
        return ['ok' => false, 'archivo' => null, 'error' => 'El reporte excede 10 MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) ($finfo->file($tmp) ?: '');

    if (!isset(REPORTE_MIME_PERMITIDOS[$mime])) {
        return ['ok' => false, 'archivo' => null, 'error' => 'Formato no soportado. Sube PDF, JPG, PNG o HEIC.'];
    }

    // Para imágenes (no PDF, no HEIC) confirmamos estructura real con getimagesize.
    // El PDF no es imagen y HEIC no siempre se soporta nativamente: para esos
    // confiamos en el mime de finfo.
    if ($mime !== 'application/pdf' && $mime !== 'image/heic' && $mime !== 'image/heif') {
        if (@getimagesize($tmp) === false) {
            return ['ok' => false, 'archivo' => null, 'error' => 'El archivo no es una imagen válida.'];
        }
    }

    $ext     = REPORTE_MIME_PERMITIDOS[$mime];
    $rootDir = directorioReportes();

    if (!is_dir($rootDir) && !@mkdir($rootDir, 0775, true) && !is_dir($rootDir)) {
        return ['ok' => false, 'archivo' => null, 'error' => 'No se pudo crear el directorio de reportes.'];
    }

    // Nombre no adivinable: {campania_id}_{token}.{ext}. El token evita enumerar
    // y permite tener varias campañas sin colisión.
    $token       = bin2hex(random_bytes(16));
    $nombreDisco = $campaniaId . '_' . $token . '.' . $ext;
    $destino     = $rootDir . '/' . $nombreDisco;

    if (!move_uploaded_file($tmp, $destino)) {
        return ['ok' => false, 'archivo' => null, 'error' => 'No se pudo guardar el reporte en disco.'];
    }

    // HEIC → JPG si Imagick tiene soporte HEIF (igual que fotos.php).
    if ($ext === 'heic') {
        $convertido = false;
        if (class_exists('Imagick')) {
            try {
                $formatos = Imagick::queryFormats('HEI*');
                if (!empty($formatos)) {
                    $img = new Imagick($destino);
                    $img->setImageFormat('jpeg');
                    $jpgNombre  = $campaniaId . '_' . $token . '.jpg';
                    $jpgDestino = $rootDir . '/' . $jpgNombre;
                    $img->writeImage($jpgDestino);
                    $img->clear();
                    @unlink($destino);
                    $nombreDisco = $jpgNombre;
                    $convertido  = true;
                }
            } catch (Throwable $e) {
                error_log('[reportes_campania] Conversión HEIC->JPG falló, dejo el HEIC original: ' . $e->getMessage());
            }
        }
        if (!$convertido) {
            error_log('[reportes_campania] HEIC guardado tal cual (Imagick/HEIF no disponible); puede no renderizar en desktop: ' . $destino);
        }
    }

    return ['ok' => true, 'archivo' => $nombreDisco, 'error' => null];
}

/**
 * Borra un archivo de reporte previo del almacenamiento. Tolera null/''.
 * Usa basename() para no salir nunca del directorio de reportes.
 */
function eliminarArchivoReporte(?string $nombreDisco): void
{
    if ($nombreDisco === null || $nombreDisco === '') {
        return;
    }
    $ruta = directorioReportes() . '/' . basename($nombreDisco);
    if (is_file($ruta)) {
        @unlink($ruta);
    }
}
