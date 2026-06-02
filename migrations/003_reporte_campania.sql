-- =====================================================================
-- 003_reporte_campania.sql
-- Sistema de Gestión Ministerial — reporte de resultados de campaña
--
-- Aplicar (después de 001 y 002):
--   psql -U asi_app -d asistentes -f migrations/003_reporte_campania.sql
--
-- Aditivo e idempotente (ADD COLUMN IF NOT EXISTS). Solo toca
-- periodos_campania: tres columnas para el archivo de reporte que cada
-- asistente entrega tras una campaña. El archivo vive FUERA de public/
-- (almacenamiento protegido); aquí solo se guarda el puntero y metadatos.
--
-- "Informa, no juzga": el estado del reporte (subido / pendiente) es un
-- dato NEUTRAL para el Pastor; NO es una validación ni una bandera del
-- sistema. La obligación de entregarlo es ministerial, no un candado.
-- =====================================================================

BEGIN;

ALTER TABLE periodos_campania
    ADD COLUMN IF NOT EXISTS reporte_ruta      TEXT,          -- nombre/token del archivo en disco (dentro de storage/); NULL = pendiente
    ADD COLUMN IF NOT EXISTS reporte_nombre    VARCHAR(255),  -- nombre original saneado (para mostrar y descargar)
    ADD COLUMN IF NOT EXISTS reporte_subido_en TIMESTAMPTZ;   -- cuándo se subió (para "subido el X" y orden)

COMMIT;
