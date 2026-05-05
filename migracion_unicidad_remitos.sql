-- Evita cargar el mismo número de remito dos veces en la misma empresa
ALTER TABLE remitos
    ADD UNIQUE KEY uq_remito_nro_empresa (empresa_id, nro_remito_propio);
