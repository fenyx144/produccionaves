<?php

declare(strict_types=1);

require_once __DIR__ . '/mortalidad_zonas_lib.php';

if (!function_exists('evf_generar_uuid')) {
    function evf_generar_uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('evf_es_uuid')) {
    function evf_es_uuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($value)
        );
    }
}

function mort_aud_movi_tiene_internal_auditoria(mysqli $conn): bool
{
    return true;
}

/**
 * @return list<string>
 */
function mort_aud_marks(): array
{
    return array_values(mort_zonas_marks());
}

/**
 * mark de zonas (JI1/JT2/JP3/JD4) → tipoMortalidad del contrato fact.
 */
function mort_aud_tipo_desde_mark(string $mark): string
{
    switch (strtoupper(trim($mark))) {
        case 'JI1':
            return 'incubacion';
        case 'JT2':
            return 'transporte';
        case 'JP3':
            return 'produccion';
        case 'JD4':
            return 'despacho';
        default:
            return 'produccion';
    }
}

/**
 * @return array{transporte: ?string, produccion: ?string}
 */
function mort_aud_subtipos_desde_mark_flujo(string $mark, string $flujo): array
{
    $mark = strtoupper(trim($mark));
    $flujo = trim($flujo);
    if ($mark === 'JT2') {
        return [
            'transporte' => strtolower($flujo) === 'evento' ? 'evento' : 'transporte',
            'produccion' => null,
        ];
    }
    if ($mark === 'JP3') {
        return ['transporte' => null, 'produccion' => strtolower($flujo)];
    }

    return ['transporte' => null, 'produccion' => null];
}

function mort_aud_grupo_clave(string $tipoMortalidad, ?string $subtipoTransporte, ?string $subtipoProduccion): string
{
    $tipo = strtolower(trim($tipoMortalidad));
    if ($tipo === 'incubacion') {
        return 'incubacion';
    }
    if ($tipo === 'despacho') {
        return 'despacho';
    }
    if ($tipo === 'transporte') {
        $st = strtolower(trim((string) $subtipoTransporte));
        if ($st !== 'evento') {
            $st = 'transporte';
        }

        return 'transporte:' . $st;
    }
    if ($tipo === 'produccion') {
        static $valid = ['crianza' => true, 'necropsia' => true, 'laboratorio' => true, 'cuarentena' => true];
        $st = strtolower(trim((string) $subtipoProduccion));
        if ($st === '' || !isset($valid[$st])) {
            $st = 'crianza';
        }

        return 'produccion:' . $st;
    }

    return $tipo;
}

function mort_aud_grupo_label(string $clave): string
{
    static $map = [
        'incubacion' => 'Planta incubación',
        'despacho' => 'Despacho',
        'transporte:evento' => 'Transporte — Evento',
        'transporte:transporte' => 'Transporte — Traslado',
        'produccion:crianza' => 'Producción — Crianza',
        'produccion:necropsia' => 'Producción — Necropsia',
        'produccion:laboratorio' => 'Producción — Laboratorio',
        'produccion:cuarentena' => 'Producción — Cuarentena',
    ];

    return $map[$clave] ?? $clave;
}

/**
 * @return array{tipo_mortalidad: string, subtipo: ?string}
 */
function mort_aud_clave_partes(string $clave): array
{
    if (strpos($clave, ':') === false) {
        return ['tipo_mortalidad' => $clave, 'subtipo' => null];
    }
    [$tipo, $sub] = explode(':', $clave, 2);

    return ['tipo_mortalidad' => $tipo, 'subtipo' => $sub !== '' ? $sub : null];
}

/**
 * @param array{fecha_desde?: string, fecha_hasta?: string} $filtros
 * @return list<array<string, mixed>>
 */
function mort_aud_fetch_pendientes(mysqli $conn, array $filtros = []): array
{
    $marks = mort_aud_marks();
    if ($marks === []) {
        return [];
    }
    $marksEsc = implode(',', array_map(static function (string $m) use ($conn): string {
        return "'" . mysqli_real_escape_string($conn, $m) . "'";
    }, $marks));

    $whereFecha = '';
    $desde = trim((string) ($filtros['fecha_desde'] ?? ''));
    $hasta = trim((string) ($filtros['fecha_hasta'] ?? ''));
    if ($desde !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) {
        $whereFecha .= " AND cz.tfecrem >= '" . mysqli_real_escape_string($conn, $desde) . "' ";
    }
    if ($hasta !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
        $whereFecha .= " AND cz.tfecrem <= '" . mysqli_real_escape_string($conn, $hasta) . "' ";
    }

    $filtroAuditMz = mort_aud_movi_tiene_internal_auditoria($conn)
        ? ' AND (mz.internal_auditoria IS NULL OR TRIM(mz.internal_auditoria) = \'\') '
        : '';

    // Solo registros en cabe_zonas/movi_zonas pendientes de auditoría.
    $sql = "
        SELECT
            cz.external_id AS cab_uuid,
            CASE cz.mark
                WHEN 'JI1' THEN 'incubacion'
                WHEN 'JT2' THEN 'transporte'
                WHEN 'JP3' THEN 'produccion'
                WHEN 'JD4' THEN 'despacho'
                ELSE 'produccion'
            END AS tipoMortalidad,
            CASE
                WHEN cz.mark = 'JT2' AND MAX(mz.flujo) = 'Evento' THEN 'evento'
                WHEN cz.mark = 'JT2' THEN 'transporte'
                ELSE NULL
            END AS subtipoTransporte,
            CASE WHEN cz.mark = 'JP3' THEN LOWER(MAX(mz.flujo)) ELSE NULL END AS subtipoProduccion,
            COALESCE(SUM(mz.tcantid), 0) AS total_aves
        FROM cabe_zonas cz
        INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
            AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
        WHERE cz.mark IN ({$marksEsc})
          {$filtroAuditMz}
          {$whereFecha}
        GROUP BY cz.external_id, cz.mark
        HAVING total_aves > 0
        ORDER BY MAX(CONCAT(cz.tdate, ' ', cz.ttime)) DESC
    ";

    $rows = [];
    $res = mysqli_query($conn, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

/**
 * @param list<array<string, mixed>> $filas
 * @return array{grupos: list<array<string, mixed>>, resumen: array<string, int>}
 */
function mort_aud_agrupar_pendientes(array $filas): array
{
    $buckets = [];
    foreach ($filas as $row) {
        $clave = mort_aud_grupo_clave(
            (string) ($row['tipoMortalidad'] ?? ''),
            $row['subtipoTransporte'] ?? null,
            $row['subtipoProduccion'] ?? null
        );
        if (!isset($buckets[$clave])) {
            $partes = mort_aud_clave_partes($clave);
            $buckets[$clave] = [
                'clave' => $clave,
                'tipo_mortalidad' => $partes['tipo_mortalidad'],
                'subtipo' => $partes['subtipo'],
                'label' => mort_aud_grupo_label($clave),
                'total_registros' => 0,
                'total_aves' => 0,
                'registro_uuids' => [],
            ];
        }
        $uuid = trim((string) ($row['cab_uuid'] ?? ''));
        $aves = (int) ($row['total_aves'] ?? 0);
        if ($uuid === '' || $aves <= 0) {
            continue;
        }
        $buckets[$clave]['total_registros']++;
        $buckets[$clave]['total_aves'] += $aves;
        $buckets[$clave]['registro_uuids'][$uuid] = true;
    }

    $orden = [
        'incubacion',
        'transporte:evento',
        'transporte:transporte',
        'produccion:crianza',
        'produccion:necropsia',
        'produccion:laboratorio',
        'produccion:cuarentena',
        'despacho',
    ];

    $aprobables = [];
    foreach ($orden as $clave) {
        if (!isset($buckets[$clave]) || (int) ($buckets[$clave]['total_aves'] ?? 0) <= 0) {
            continue;
        }
        $item = $buckets[$clave];
        $uuidsMap = $item['registro_uuids'];
        $item['registro_uuids'] = is_array($uuidsMap) ? array_keys($uuidsMap) : [];
        $item['total_registros'] = count($item['registro_uuids']);
        $aprobables[] = $item;
    }

    $resumen = [
        'total_registros' => 0,
        'total_aves' => 0,
    ];
    foreach ($aprobables as $g) {
        $resumen['total_registros'] += (int) ($g['total_registros'] ?? 0);
        $resumen['total_aves'] += (int) ($g['total_aves'] ?? 0);
    }

    return [
        'aprobables' => $aprobables,
        'resumen' => $resumen,
    ];
}

/**
 * @return list<string>
 */
function mort_aud_procesar_fotos(string $clave, string $auditId, string $carpetaFs): array
{
    $rutas = [];
    $safeClave = preg_replace('/[^A-Za-z0-9_-]/', '_', $clave) ?? 'grupo';
    $prefix = 'aud_img_' . $safeClave . '_';

    foreach ($_FILES as $key => $fileInfo) {
        if (!is_string($key) || strpos($key, $prefix) !== 0) {
            continue;
        }
        if (!is_array($fileInfo) || ($fileInfo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        $tmp = (string) ($fileInfo['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            continue;
        }

        $ext = strtolower(pathinfo((string) ($fileInfo['name'] ?? ''), PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $idx = substr($key, strlen($prefix));
        $base = sprintf(
            'audit_%s_%s_%s.%s',
            substr(str_replace('-', '', $auditId), 0, 12),
            $safeClave,
            $idx,
            $ext
        );
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '_', $base) ?? $base;
        $destFs = $carpetaFs . $base;

        if (!move_uploaded_file($tmp, $destFs)) {
            continue;
        }
        $rutas[] = sanidad_uploads_rel('mortalidad', $base);
    }

    return $rutas;
}

/**
 * @param list<string> $uuids
 * @return list<string>
 */
function mort_aud_filtrar_uuids_por_clave(mysqli $conn, string $clave, array $uuids): array
{
    $valid = [];
    foreach ($uuids as $raw) {
        $id = trim((string) $raw);
        if ($id === '' || !evf_es_uuid($id)) {
            continue;
        }
        $idEsc = mysqli_real_escape_string($conn, $id);
        $res = mysqli_query($conn, "
            SELECT cz.mark, mz.flujo
            FROM cabe_zonas cz
            INNER JOIN movi_zonas mz ON mz.mark = cz.mark AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
            WHERE cz.external_id = '{$idEsc}'
            LIMIT 1
        ");
        if (!$res || !($row = mysqli_fetch_assoc($res))) {
            continue;
        }
        $tipoMortalidad = mort_aud_tipo_desde_mark((string) ($row['mark'] ?? ''));
        $subs = mort_aud_subtipos_desde_mark_flujo(
            (string) ($row['mark'] ?? ''),
            (string) ($row['flujo'] ?? '')
        );
        $expected = mort_aud_grupo_clave(
            $tipoMortalidad,
            $subs['transporte'],
            $subs['produccion']
        );
        if ($expected === $clave) {
            $valid[$id] = true;
        }
    }

    return array_keys($valid);
}

function mort_aud_item_aprobado(array $item): bool
{
    if (!array_key_exists('aprobado', $item)) {
        return false;
    }
    $v = $item['aprobado'];

    return $v === true || $v === 1 || $v === '1';
}

/**
 * @param list<string> $cabUuids
 */
function mort_aud_vincular_movi(mysqli $conn, string $auditId, array $cabUuids): int
{
    if ($cabUuids === []) {
        return 0;
    }

    if (!mort_aud_movi_tiene_internal_auditoria($conn)) {
        throw new RuntimeException('Falta columna internal_auditoria en movi_zonas. Ejecute la migración.');
    }

    $auditEsc = mysqli_real_escape_string($conn, $auditId);
    $totalAffected = 0;

    foreach ($cabUuids as $cabUuid) {
        $cabUuid = trim((string) $cabUuid);
        if ($cabUuid === '' || !evf_es_uuid($cabUuid)) {
            continue;
        }
        $cabEsc = mysqli_real_escape_string($conn, $cabUuid);
        $qMark = mysqli_query($conn, "
            SELECT cz.mark
            FROM cabe_zonas cz
            WHERE cz.external_id = '{$cabEsc}'
            LIMIT 1
        ");
        if (!$qMark || !($row = mysqli_fetch_assoc($qMark))) {
            continue;
        }
        $mark = strtoupper(trim((string) ($row['mark'] ?? '')));
        if (!in_array($mark, mort_aud_marks(), true)) {
            continue;
        }
        $markEsc = mysqli_real_escape_string($conn, $mark);

        $sql = "
            UPDATE movi_zonas mz
            INNER JOIN cabe_zonas cz ON mz.mark = cz.mark
                AND mz.treg = cz.treg
                AND mz.tdoc = cz.tdoc
                AND mz.tserie = cz.tserie
                AND mz.tnumfac = cz.tnumfac
            SET mz.internal_auditoria = '{$auditEsc}'
            WHERE cz.external_id = '{$cabEsc}'
              AND cz.mark = '{$markEsc}'
              AND (mz.internal_auditoria IS NULL OR TRIM(mz.internal_auditoria) = '')
        ";

        if (!mysqli_query($conn, $sql)) {
            throw new RuntimeException('Error al vincular movi_zonas: ' . mysqli_error($conn));
        }
        $totalAffected += (int) mysqli_affected_rows($conn);
    }

    return $totalAffected;
}

/**
 * @return list<string>
 */
function mort_aud_claves_validas(): array
{
    return [
        'incubacion',
        'despacho',
        'transporte:evento',
        'transporte:transporte',
        'produccion:crianza',
        'produccion:necropsia',
        'produccion:laboratorio',
        'produccion:cuarentena',
    ];
}

/**
 * Normaliza un grupo enviado desde la app hacia el ítem del JSON `registros`.
 *
 * @param array<string, mixed> $item
 * @return array{
 *   clave: string,
 *   tipo_mortalidad: string,
 *   subtipo: ?string,
 *   label: string,
 *   registro_uuids: list<string>,
 *   totalRegistros: int,
 *   totalAves: int,
 *   observaciones: ?string,
 *   evidencia: ?string
 * }
 */
function mort_aud_normalizar_registro_item(
    mysqli $conn,
    array $item,
    string $auditId,
    string $carpetaFs
): array {
    $clave = trim((string) ($item['clave'] ?? ''));
    if ($clave === '' || !in_array($clave, mort_aud_claves_validas(), true)) {
        throw new InvalidArgumentException('Clave de grupo inválida: ' . $clave);
    }

    $uuidsRaw = $item['registro_uuids'] ?? [];
    if (!is_array($uuidsRaw)) {
        throw new InvalidArgumentException('registro_uuids debe ser un arreglo en ' . $clave);
    }

    $uuids = mort_aud_filtrar_uuids_por_clave($conn, $clave, $uuidsRaw);
    if ($uuids === []) {
        throw new InvalidArgumentException('No hay registros válidos para auditar en ' . $clave);
    }

    $partes = mort_aud_clave_partes($clave);
    $observaciones = trim((string) ($item['observaciones'] ?? ''));
    $fotos = mort_aud_procesar_fotos($clave, $auditId, $carpetaFs);
    $evidencia = count($fotos) > 0 ? implode(',', $fotos) : null;

    $totalAves = 0;
    $in = implode(',', array_map(static function (string $id) use ($conn): string {
        return "'" . mysqli_real_escape_string($conn, $id) . "'";
    }, $uuids));
    $qAves = mysqli_query($conn, "
        SELECT COALESCE(SUM(mz.tcantid), 0) AS total
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON mz.mark = cz.mark AND mz.treg = cz.treg
            AND mz.tdoc = cz.tdoc AND mz.tserie = cz.tserie AND mz.tnumfac = cz.tnumfac
        WHERE cz.external_id IN ({$in})
    ");
    if ($qAves && ($r = mysqli_fetch_assoc($qAves))) {
        $totalAves = (int) ($r['total'] ?? 0);
    }

    return [
        'clave' => $clave,
        'tipo_mortalidad' => $partes['tipo_mortalidad'],
        'subtipo' => $partes['subtipo'],
        'label' => mort_aud_grupo_label($clave),
        'registro_uuids' => $uuids,
        'totalRegistros' => count($uuids),
        'totalAves' => $totalAves,
        'observaciones' => $observaciones !== '' ? $observaciones : null,
        'evidencia' => $evidencia,
    ];
}

/**
 * Busca auditoría existente por UUID.
 *
 * @return array<string, mixed>|null
 */
function mort_aud_existe_por_id(mysqli $conn, string $auditId): ?array
{
    $stmt = $conn->prepare(
        'SELECT id, usuarioRegistro, totalRegistros, totalAves, fecha, fechaHoraRegistro
         FROM san_mortalidad_auditoria WHERE id = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $auditId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

/**
 * Guarda una sesión de auditoría (puede incluir varios tipos/subtipos en `registros`).
 * El UUID lo define el cliente (mismo contrato que mortalidad).
 *
 * @param list<array<string, mixed>> $itemsRaw
 * @return array<string, mixed>
 */
function mort_aud_guardar_sesion(
    mysqli $conn,
    string $usuarioRegistro,
    array $itemsRaw,
    ?string $observacionesGenerales,
    string $carpetaFs,
    string $auditId
): array {
    $auditId = trim($auditId);
    if ($auditId === '' || !evf_es_uuid($auditId)) {
        throw new InvalidArgumentException('tuuid/id de auditoría inválido');
    }

    $existente = mort_aud_existe_por_id($conn, $auditId);
    if ($existente !== null) {
        return [
            'id' => (string) $existente['id'],
            'registros' => [],
            'totalRegistros' => (int) ($existente['totalRegistros'] ?? 0),
            'totalAves' => (int) ($existente['totalAves'] ?? 0),
            'fecha' => (string) ($existente['fecha'] ?? ''),
            'fechaHoraRegistro' => (string) ($existente['fechaHoraRegistro'] ?? ''),
            'movi_actualizados' => 0,
            'grupos' => 0,
            'duplicado' => true,
        ];
    }

    $registros = [];
    $totalReg = 0;
    $totalAves = 0;
    $moviActualizados = 0;
    $evidenciasGlobales = [];

    foreach ($itemsRaw as $idx => $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Grupo #' . $idx . ': formato inválido');
        }
        if (!mort_aud_item_aprobado($item)) {
            continue;
        }

        $norm = mort_aud_normalizar_registro_item($conn, $item, $auditId, $carpetaFs);
        $registros[] = $norm;
        $totalReg += (int) $norm['totalRegistros'];
        $totalAves += (int) $norm['totalAves'];
        $moviActualizados += mort_aud_vincular_movi($conn, $auditId, $norm['registro_uuids']);
        if (!empty($norm['evidencia'])) {
            $evidenciasGlobales[] = (string) $norm['evidencia'];
        }
    }

    if ($registros === []) {
        throw new InvalidArgumentException('No hay Tipos de registro para registrar');
    }

    $jsonRegistros = json_encode($registros, JSON_UNESCAPED_UNICODE);
    if ($jsonRegistros === false) {
        throw new RuntimeException('No se pudo serializar registros');
    }

    $obsGlobal = trim((string) ($observacionesGenerales ?? ''));
    $obsParam = $obsGlobal !== '' ? $obsGlobal : null;
    $evGlobal = $evidenciasGlobales !== [] ? implode(',', $evidenciasGlobales) : null;
    $fecha = date('Y-m-d');
    $fechaHoraRegistro = date('Y-m-d H:i:s');

    $stmt = $conn->prepare('
        INSERT INTO san_mortalidad_auditoria (
            id, usuarioRegistro, registros, totalRegistros, totalAves,
            observaciones, evidencia, fecha, fechaHoraRegistro
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');
    if (!$stmt) {
        throw new RuntimeException('Error prepare auditoría: ' . $conn->error);
    }

    $stmt->bind_param(
        'sssiissss',
        $auditId,
        $usuarioRegistro,
        $jsonRegistros,
        $totalReg,
        $totalAves,
        $obsParam,
        $evGlobal,
        $fecha,
        $fechaHoraRegistro
    );

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $errno = (int) $stmt->errno;
        $stmt->close();
        // Carrera: otro request insertó el mismo UUID
        if ($errno === 1062) {
            $again = mort_aud_existe_por_id($conn, $auditId);
            if ($again !== null) {
                return [
                    'id' => (string) $again['id'],
                    'registros' => [],
                    'totalRegistros' => (int) ($again['totalRegistros'] ?? 0),
                    'totalAves' => (int) ($again['totalAves'] ?? 0),
                    'fecha' => (string) ($again['fecha'] ?? ''),
                    'fechaHoraRegistro' => (string) ($again['fechaHoraRegistro'] ?? ''),
                    'movi_actualizados' => 0,
                    'grupos' => 0,
                    'duplicado' => true,
                ];
            }
        }
        throw new RuntimeException('Error insert auditoría: ' . $err);
    }
    $stmt->close();

    return [
        'id' => $auditId,
        'registros' => $registros,
        'totalRegistros' => $totalReg,
        'totalAves' => $totalAves,
        'fecha' => $fecha,
        'fechaHoraRegistro' => $fechaHoraRegistro,
        'movi_actualizados' => $moviActualizados,
        'grupos' => count($registros),
        'duplicado' => false,
    ];
}
