<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/mortalidad_auditoria_listado_lib.php';
require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión']);
    exit;
}

if (!mort_aud_listado_tabla_existe($conn)) {
    mysqli_close($conn);
    mort_admin_json_error('Tabla de auditoría no disponible.', 503);
}

$id = trim((string) ($_GET['id'] ?? $_POST['id'] ?? ''));
if ($id === '') {
    mysqli_close($conn);
    mort_admin_json_error('ID no válido.');
}

$idEsc = mysqli_real_escape_string($conn, $id);

$q = mysqli_query($conn, "
    SELECT
        a.id,
        a.usuarioRegistro,
        a.registros,
        a.totalRegistros,
        a.totalAves,
        a.observaciones,
        a.evidencia,
        a.fecha,
        a.fechaHoraRegistro,
        COALESCE(NULLIF(TRIM(a.granja), ''),
            (SELECT MAX(LEFT(TRIM(mz.tcencos), 3))
             FROM movi_zonas mz
             WHERE mz.internal_auditoria=a.id)
        ) AS codGranja,
        COALESCE(NULLIF(TRIM(a.campania), ''),
            (SELECT MAX(RIGHT(TRIM(mz.tcencos), 3))
             FROM movi_zonas mz
             WHERE mz.internal_auditoria=a.id)
        ) AS campania,
        COALESCE(NULLIF(TRIM(a.galpon), ''),
            (SELECT MAX(NULLIF(TRIM(mz.tcodint), ''))
             FROM movi_zonas mz
             WHERE mz.internal_auditoria=a.id)
        ) AS galpon
    FROM san_mortalidad_auditoria a
    WHERE a.id = '{$idEsc}'
    LIMIT 1
");
$row = $q ? mysqli_fetch_assoc($q) : null;

if (!$row) {
    mysqli_close($conn);
    mort_admin_json_error('Auditoría no encontrada.', 404);
}

$grupos = mort_aud_listado_parse_registros_json((string) ($row['registros'] ?? ''));
foreach ($grupos as &$g) {
    $g['evidenciaUrls'] = mort_aud_listado_evidencia_urls((string) ($g['evidencia'] ?? ''));
    if ($g['tipo_mortalidad'] !== '') {
        $g['tipoLabel'] = mort_listado_label_tipo((string) $g['tipo_mortalidad']);
    } else {
        $partes = mort_aud_clave_partes((string) ($g['clave'] ?? ''));
        $g['tipoLabel'] = mort_listado_label_tipo($partes['tipo_mortalidad']);
    }

    // Desglose macho/hembra para este grupo
    $g['machos'] = 0;
    $g['hembras'] = 0;
    $g['detalles'] = [];
    $uuids = $g['registro_uuids'] ?? [];
    if (!empty($uuids) && is_array($uuids)) {
        $escaped = array_map(static function (string $uuid) use ($conn): string {
            return "'" . mysqli_real_escape_string($conn, $uuid) . "'";
        }, $uuids);
        $inUuids = implode(',', $escaped);

        // Totales macho/hembra del grupo
        $qSexo = mysqli_query($conn, "
            SELECT
                COALESCE(SUM(CASE WHEN mz.tcodigo <> 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS machos,
                COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS hembras
            FROM movi_zonas mz
            INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
                AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
            WHERE cz.external_id IN ({$inUuids})
        ");
        if ($qSexo && ($rSexo = mysqli_fetch_assoc($qSexo))) {
            $g['machos'] = (int) ($rSexo['machos'] ?? 0);
            $g['hembras'] = (int) ($rSexo['hembras'] ?? 0);
        }

        // Detalle individual de cada registro auditado
        $qDet = mysqli_query($conn, "
            SELECT
                cz.external_id AS id,
                LEFT(MAX(mz.tcencos), 3) AS granja,
                RIGHT(MAX(mz.tcencos), 3) AS campania,
                MAX(mz.tcodint) AS galpon,
                MAX(cz.tfecrem) AS fechaRegistro,
                MAX(cc.nombre) AS granjaNombre,
                LEFT(MAX(mz.tcencos), 3) AS codGranja,
                COALESCE(SUM(mz.tcantid), 0) AS totalAves,
                COALESCE(SUM(CASE WHEN mz.tcodigo <> 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS machos,
                COALESCE(SUM(CASE WHEN mz.tcodigo = 'P0001002' THEN mz.tcantid ELSE 0 END), 0) AS hembras
            FROM cabe_zonas cz
            INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
            LEFT JOIN ccos cc ON cc.codigo = CONCAT(LEFT(mz.tcencos, 3), '000')
            WHERE cz.external_id IN ({$inUuids})
            GROUP BY cz.external_id
            ORDER BY MAX(cz.tfecrem) DESC, MAX(cz.tnumfac) ASC
        ");
        if ($qDet) {
            while ($rDet = mysqli_fetch_assoc($qDet)) {
                $g['detalles'][] = [
                    'id' => (string) ($rDet['id'] ?? ''),
                    'granja' => (string) ($rDet['granja'] ?? ''),
                    'codGranja' => (string) ($rDet['codGranja'] ?? ''),
                    'campania' => (string) ($rDet['campania'] ?? ''),
                    'galpon' => (string) ($rDet['galpon'] ?? ''),
                    'fechaRegistro' => (string) ($rDet['fechaRegistro'] ?? ''),
                    'granjaNombre' => (string) ($rDet['granjaNombre'] ?? ''),
                    'totalAves' => (int) ($rDet['totalAves'] ?? 0),
                    'machos' => (int) ($rDet['machos'] ?? 0),
                    'hembras' => (int) ($rDet['hembras'] ?? 0),
                ];
            }
        }
    }
}
unset($g);

$nombreAuditor = mort_aud_nombre_auditor($conn, (string) ($row['usuarioRegistro'] ?? ''));
$codGranja = (string) ($row['codGranja'] ?? '');
$nombreGranja = '';
if ($codGranja !== '') {
    $nombreGranja = mort_aud_nombre_granja($conn, $codGranja);
}

$sess = [
    'fecha' => (string) ($row['fecha'] ?? ''),
    'fechaHoraRegistro' => (string) ($row['fechaHoraRegistro'] ?? ''),
    'usuarioRegistro' => (string) ($row['usuarioRegistro'] ?? ''),
    'nombreAuditor' => $nombreAuditor,
    'totalRegistros' => (int) ($row['totalRegistros'] ?? 0),
    'totalAves' => (int) ($row['totalAves'] ?? 0),
    'observaciones' => $row['observaciones'] ?? null,
    'evidenciaUrls' => mort_aud_listado_evidencia_urls((string) ($row['evidencia'] ?? '')),
    'codGranja' => $codGranja,
    'nombreGranja' => $nombreGranja,
    'campania' => (string) ($row['campania'] ?? ''),
    'galpon' => (string) ($row['galpon'] ?? ''),
];

mysqli_close($conn);

mort_admin_json_ok([
    'sesion' => $sess,
    'grupos' => $grupos,
]);
