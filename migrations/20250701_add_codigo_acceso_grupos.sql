ALTER TABLE grupos_campamento
ADD COLUMN IF NOT EXISTS codigo_acceso VARCHAR(20) NULL UNIQUE AFTER estado;

-- Asegurar que el campo tenga un índice para búsquedas rápidas
CREATE INDEX IF NOT EXISTS idx_codigo_acceso ON grupos_campamento(codigo_acceso);
