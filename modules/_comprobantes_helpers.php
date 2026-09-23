<?php
// ============================================================
// HELPERS DE COMPROBANTES DE ENTREGA
// Los archivos se guardan en uploads/comprobantes/{empresa}/{AAAA}/{MM}/
// (uploads/ tiene .htaccess que bloquea el acceso directo; se sirven vía portal/ver.php)
// ============================================================

const COMPROBANTE_MAX_BYTES = 15 * 1024 * 1024;

const COMPROBANTE_MIMES = [
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'image/webp'      => 'webp',
    'application/pdf' => 'pdf',
];

function comprobantes_dir(): string
{
    return dirname(__DIR__) . '/uploads/comprobantes';
}

// Comprobantes de un remito (ya validado que pertenece a la empresa)
function comprobantes_de_remito(PDO $db, int $eid, int $remito_id): array
{
    $st = $db->prepare("
        SELECT id, mime, origen, creado_en
        FROM remito_comprobantes
        WHERE empresa_id = ? AND remito_id = ?
        ORDER BY id
    ");
    $st->execute([$eid, $remito_id]);
    return $st->fetchAll();
}

// Cantidad de comprobantes por remito, para un conjunto de ids.
// Si la tabla todavía no existe (migración sin aplicar) devuelve [] en vez de romper la página.
function comprobantes_contar(PDO $db, int $eid, array $remito_ids): array
{
    $ids = array_filter(array_map('intval', $remito_ids));
    if (!$ids) return [];
    try {
        $st = $db->prepare("
            SELECT remito_id, COUNT(*) AS n
            FROM remito_comprobantes
            WHERE empresa_id = ? AND remito_id IN (" . implode(',', $ids) . ")
            GROUP BY remito_id
        ");
        $st->execute([$eid]);
        return array_map('intval', array_column($st->fetchAll(), 'n', 'remito_id'));
    } catch (PDOException $e) {
        return [];
    }
}
