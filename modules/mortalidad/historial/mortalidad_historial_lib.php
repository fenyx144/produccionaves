<?php

declare(strict_types=1);

/**
 * Acciones CRUD del listado de mortalidad registradas en san_dim_historial_acciones.
 *
 * @return list<string>
 */
function mort_hist_acciones_validas(): array
{
    return [
        'EDICION_MORTALIDAD',
        'ELIMINACION_MORTALIDAD_COMPLETA',
        'ELIMINACION_MORTALIDAD_DETALLE',
    ];
}

/**
 * @return array<string, array{label: string, icon: string, color: string, badge: string}>
 */
function mort_hist_acciones_meta(): array
{
    return [
        'EDICION_MORTALIDAD' => [
            'label' => 'Edición',
            'icon' => 'fas fa-pen',
            'color' => 'text-amber-600',
            'badge' => 'bg-amber-50 text-amber-700 border-amber-200',
        ],
        'ELIMINACION_MORTALIDAD_COMPLETA' => [
            'label' => 'Eliminación completa',
            'icon' => 'fas fa-trash',
            'color' => 'text-red-600',
            'badge' => 'bg-red-50 text-red-700 border-red-200',
        ],
        'ELIMINACION_MORTALIDAD_DETALLE' => [
            'label' => 'Eliminación de detalle',
            'icon' => 'fas fa-trash-alt',
            'color' => 'text-red-600',
            'badge' => 'bg-red-50 text-red-700 border-red-200',
        ],
    ];
}

function mort_hist_label_accion(string $accion): string
{
    $meta = mort_hist_acciones_meta();
    return $meta[$accion]['label'] ?? $accion;
}

/**
 * Etiqueta de operación para UI (incluye cantidad en eliminación de detalle).
 *
 * @param mixed $snap Snapshot array o JSON
 */
function mort_hist_label_accion_dinamica(string $accion, $snap = null): string
{
    if ($accion === 'ELIMINACION_MORTALIDAD_COMPLETA') {
        return 'Eliminación completa';
    }
    if ($accion === 'ELIMINACION_MORTALIDAD_DETALLE') {
        $n = mort_hist_contar_detalles_snapshot($snap);
        if ($n < 1) {
            $n = 1;
        }
        return $n === 1
            ? 'Eliminación de 1 detalle'
            : ('Eliminación de ' . $n . ' detalles');
    }
    if ($accion === 'EDICION_MORTALIDAD') {
        return 'Edición';
    }

    return mort_hist_label_accion($accion);
}

/**
 * @param mixed $snap
 */
function mort_hist_contar_detalles_snapshot($snap): int
{
    $decoded = mort_hist_decode_snapshot($snap);
    if ($decoded === null) {
        return 0;
    }
    $det = $decoded['san_fact_mortalidad_det'] ?? [];
    return is_array($det) ? count($det) : 0;
}

/**
 * Tipo capitalizado + subtipo, p. ej. "Producción - Sub Tipo Crianza".
 *
 * @param array<string, mixed> $cab
 */
function mort_hist_label_tipo_completo(array $cab): string
{
    require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

    $tipoKey = trim((string) ($cab['tipoMortalidad'] ?? ''));
    $tipoLabel = mort_listado_label_tipo($tipoKey);
    $subLabel = trim(mort_listado_label_subtipo(
        $tipoKey,
        isset($cab['subtipoTransporte']) ? (string) $cab['subtipoTransporte'] : null,
        isset($cab['subtipoProduccion']) ? (string) $cab['subtipoProduccion'] : null
    ));

    if ($tipoLabel === '') {
        return $subLabel !== '' ? ('Sub Tipo ' . $subLabel) : '';
    }
    if ($subLabel === '') {
        return $tipoLabel;
    }

    return $tipoLabel . ' - Sub Tipo ' . $subLabel;
}

/**
 * Texto corto tipo+subtipo para descripciones: "Producción crianza".
 *
 * @param array<string, mixed> $cab
 */
function mort_hist_label_tipo_corto(array $cab): string
{
    require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

    $tipoKey = trim((string) ($cab['tipoMortalidad'] ?? ''));
    $tipoLabel = mort_listado_label_tipo($tipoKey);
    $subLabel = trim(mort_listado_label_subtipo(
        $tipoKey,
        isset($cab['subtipoTransporte']) ? (string) $cab['subtipoTransporte'] : null,
        isset($cab['subtipoProduccion']) ? (string) $cab['subtipoProduccion'] : null
    ));

    if ($tipoLabel === '') {
        return $subLabel;
    }
    if ($subLabel === '') {
        return $tipoLabel;
    }

    $subLower = function_exists('mb_strtolower')
        ? mb_strtolower($subLabel, 'UTF-8')
        : strtolower($subLabel);

    return $tipoLabel . ' ' . $subLower;
}

/**
 * Normaliza snapshots legacy (cabecera/detalle/auditorias) al formato actual.
 *
 * @param array<string, mixed> $snap
 * @return array<string, mixed>
 */
function mort_hist_normalize_snapshot(array $snap): array
{
    if (!isset($snap['san_fact_mortalidad_cab']) && isset($snap['cabecera']) && is_array($snap['cabecera'])) {
        $snap['san_fact_mortalidad_cab'] = $snap['cabecera'];
    }
    if (!isset($snap['san_fact_mortalidad_det'])) {
        if (isset($snap['detalle']) && is_array($snap['detalle'])) {
            $snap['san_fact_mortalidad_det'] = $snap['detalle'];
        } elseif (isset($snap['detalles']) && is_array($snap['detalles'])) {
            $snap['san_fact_mortalidad_det'] = $snap['detalles'];
        }
    }
    if (!isset($snap['san_mortalidad_auditoria']) && isset($snap['auditorias']) && is_array($snap['auditorias'])) {
        $snap['san_mortalidad_auditoria'] = $snap['auditorias'];
    }

    return $snap;
}

/**
 * Extrae cabecera desde snapshot normalizado o legacy.
 *
 * @param array<string, mixed> $snap
 * @return array<string, mixed>
 */
function mort_hist_extraer_cab(array $snap): array
{
    $snap = mort_hist_normalize_snapshot($snap);
    $cab = $snap['san_fact_mortalidad_cab'] ?? null;

    return is_array($cab) ? $cab : [];
}

/**
 * Mismo criterio del listado: numFac de zonas, si no serie-numero (sin GI).
 *
 * @param array<string, mixed> $snap
 * @param array<string, mixed>|null $cab
 */
function mort_hist_documento_desde_snapshot(array $snap, ?array $cab = null): string
{
    $snap = mort_hist_normalize_snapshot($snap);

    foreach (['movi_zonas', 'cabe_zonas'] as $key) {
        $rows = $snap[$key] ?? [];
        if (!is_array($rows)) {
            continue;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $numFac = trim((string) ($row['tnumfac'] ?? ''));
            if ($numFac !== '' && $numFac !== '0') {
                return $numFac;
            }
        }
    }

    if ($cab === null) {
        $cab = mort_hist_extraer_cab($snap);
    }
    if ($cab === []) {
        return '';
    }

    $serie = trim((string) ($cab['serie'] ?? ''));
    $numero = trim((string) ($cab['numero'] ?? ''));
    if ($serie === '' && $numero === '') {
        return '';
    }

    return trim($serie . '-' . $numero, '-');
}

/**
 * Descripción corta amigable para UI / historial.
 * Ej.: "Se eliminó de forma completa el registro de Producción crianza"
 *
 * @param mixed $snap
 */
function mort_hist_descripcion_amigable(string $accion, $snap = null): string
{
    $decoded = mort_hist_decode_snapshot($snap) ?? [];
    $cab = mort_hist_extraer_cab($decoded);
    $tipoCorto = $cab !== [] ? mort_hist_label_tipo_corto($cab) : '';
    $deTipo = $tipoCorto !== '' ? (' de ' . $tipoCorto) : '';
    $nDet = mort_hist_contar_detalles_snapshot($decoded);

    if ($accion === 'EDICION_MORTALIDAD') {
        return 'Se editó el registro' . $deTipo;
    }
    if ($accion === 'ELIMINACION_MORTALIDAD_COMPLETA') {
        return 'Se eliminó de forma completa el registro' . $deTipo;
    }
    if ($accion === 'ELIMINACION_MORTALIDAD_DETALLE') {
        $n = $nDet > 0 ? $nDet : 1;
        if ($n === 1) {
            return 'Se eliminó 1 detalle del registro' . $deTipo;
        }

        return 'Se eliminaron ' . $n . ' detalles del registro' . $deTipo;
    }

    return mort_hist_label_accion_dinamica($accion, $decoded);
}

/**
 * @param mixed $json
 * @return array<string, mixed>|null
 */
function mort_hist_decode_snapshot($json): ?array
{
    if ($json === null || $json === '') {
        return null;
    }
    if (is_array($json)) {
        return mort_hist_normalize_snapshot($json);
    }
    if (!is_string($json)) {
        return null;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return null;
    }

    return mort_hist_normalize_snapshot($decoded);
}

function mort_hist_tabla_existe(mysqli $conn): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = false;
    $rs = @$conn->query("SHOW TABLES LIKE 'san_dim_historial_acciones'");
    if ($rs && $rs->num_rows > 0) {
        $cache = true;
    }

    return $cache;
}

/**
 * @return array<string, mixed>
 */
function mort_hist_filtros_defecto(): array
{
    return [
        'periodoTipo' => 'ENTRE_MESES',
        'fechaUnica' => '',
        'fechaInicio' => '',
        'fechaFin' => '',
        'mesUnico' => '',
        'mesInicio' => date('Y-01'),
        'mesFin' => date('Y-12'),
        'accion' => '',
        'search' => '',
    ];
}

/**
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function mort_hist_parse_filtros(array $input): array
{
    $f = mort_hist_filtros_defecto();
    foreach (array_keys($f) as $k) {
        if (!array_key_exists($k, $input)) {
            continue;
        }
        $val = $input[$k];
        if (is_array($val)) {
            if ($k === 'search' && isset($val['value'])) {
                $f[$k] = trim((string) $val['value']);
            }
            continue;
        }
        $f[$k] = trim((string) $val);
    }

    $accion = (string) $f['accion'];
    if ($accion !== '' && !in_array($accion, mort_hist_acciones_validas(), true)) {
        $f['accion'] = '';
    }

    return $f;
}

/**
 * Fragmento SQL (con alias h) que excluye acciones hechas por usuarios cuyo rol
 * actual es Sistemas (adm_rol/adm_usuario_rol, asignados en Roles y permisos).
 *
 * Devuelve '' si las tablas de roles no existen o el programa no se puede resolver
 * (en ese caso no se excluye nada, mismo criterio que el permiso de editar/eliminar).
 */
function mort_hist_sql_excluir_rol_sistemas(mysqli $conn): string
{
    require_once __DIR__ . '/../../../core/lib/sip_acl_sanidad.php';
    require_once __DIR__ . '/../../../core/lib/sip_acl_rol_sistemas.php';

    return sip_acl_sql_not_exists_usuario_rol_sistemas($conn, 'h.cod_usuario');
}

/**
 * @param array<string, mixed> $filtros
 */
function mort_hist_where_filtros(mysqli $conn, array $filtros): string
{
    $acciones = mort_hist_acciones_validas();
    $inParts = [];
    foreach ($acciones as $a) {
        $inParts[] = "'" . mysqli_real_escape_string($conn, $a) . "'";
    }
    $where = ' WHERE h.accion IN (' . implode(',', $inParts) . ') ';

    require_once __DIR__ . '/../../../core/lib/filtro_periodo_util.php';
    $rango = periodo_a_rango([
        'periodoTipo' => (string) ($filtros['periodoTipo'] ?? 'TODOS'),
        'fechaUnica' => (string) ($filtros['fechaUnica'] ?? ''),
        'fechaInicio' => (string) ($filtros['fechaInicio'] ?? ''),
        'fechaFin' => (string) ($filtros['fechaFin'] ?? ''),
        'mesUnico' => (string) ($filtros['mesUnico'] ?? ''),
        'mesInicio' => (string) ($filtros['mesInicio'] ?? ''),
        'mesFin' => (string) ($filtros['mesFin'] ?? ''),
    ]);
    if ($rango) {
        $desde = mysqli_real_escape_string($conn, $rango['desde']);
        $hasta = mysqli_real_escape_string($conn, $rango['hasta']);
        $where .= " AND DATE(h.fechaHora) BETWEEN '{$desde}' AND '{$hasta}' ";
    }

    $accion = trim((string) ($filtros['accion'] ?? ''));
    if ($accion !== '' && in_array($accion, $acciones, true)) {
        $aEsc = mysqli_real_escape_string($conn, $accion);
        $where .= " AND h.accion = '{$aEsc}' ";
    }

    $search = trim((string) ($filtros['search'] ?? ''));
    if ($search !== '') {
        $where .= ' AND (' . mort_hist_sql_busqueda($conn, $search) . ') ';
    }

    // En historial solo se listan acciones de usuarios que NO son de rol Sistemas.
    $excluirSistemas = mort_hist_sql_excluir_rol_sistemas($conn);
    if ($excluirSistemas !== '') {
        $where .= ' AND ' . $excluirSistemas . ' ';
    }

    return $where;
}

function mort_hist_sql_busqueda(mysqli $conn, string $search): string
{
    require_once __DIR__ . '/../listado/mortalidad_listado_lib.php';

    $sEsc = mysqli_real_escape_string($conn, $search);
    $ors = [
        "h.nom_usuario LIKE '%{$sEsc}%'",
        "h.cod_usuario LIKE '%{$sEsc}%'",
        "h.accion LIKE '%{$sEsc}%'",
        "h.descripcion LIKE '%{$sEsc}%'",
        "h.registro_id LIKE '%{$sEsc}%'",
        "h.tabla_afectada LIKE '%{$sEsc}%'",
        "CAST(h.id AS CHAR) LIKE '%{$sEsc}%'",
        "h.fechaHora LIKE '%{$sEsc}%'",
    ];

    $fechaYmd = mort_listado_search_fecha_ymd($search);
    if ($fechaYmd !== '') {
        $fEsc = mysqli_real_escape_string($conn, $fechaYmd);
        $ors[] = "DATE(h.fechaHora) = '{$fEsc}'";
    }

    $sLower = function_exists('mb_strtolower')
        ? mb_strtolower($search, 'UTF-8')
        : strtolower($search);

    foreach (mort_hist_acciones_meta() as $codigo => $meta) {
        $labelLower = function_exists('mb_strtolower')
            ? mb_strtolower($meta['label'], 'UTF-8')
            : strtolower($meta['label']);
        if ($sLower !== '' && (strpos($labelLower, $sLower) !== false || strpos($sLower, $labelLower) !== false)) {
            $cEsc = mysqli_real_escape_string($conn, $codigo);
            $ors[] = "h.accion = '{$cEsc}'";
        }
    }

    return implode(' OR ', $ors);
}

/**
 * Extrae un resumen legible del snapshot JSON de mortalidad.
 *
 * @param mixed $json
 * @return array{granja: string, campania: string, galpon: string, tipo: string, tipoLabel: string, documento: string, totalAves: int, lineas: int}
 */
function mort_hist_resumen_snapshot($json): array
{
    $out = [
        'granja' => '',
        'campania' => '',
        'galpon' => '',
        'tipo' => '',
        'tipoLabel' => '',
        'documento' => '',
        'totalAves' => 0,
        'lineas' => 0,
    ];

    $decoded = mort_hist_decode_snapshot($json);
    if ($decoded === null) {
        return $out;
    }

    $cab = mort_hist_extraer_cab($decoded);
    if ($cab !== []) {
        $out['granja'] = trim((string) ($cab['granjaNombre'] ?? $cab['granja'] ?? ''));
        $out['campania'] = trim((string) ($cab['campania'] ?? ''));
        $out['galpon'] = trim((string) ($cab['galpon'] ?? ''));
        $out['tipo'] = trim((string) ($cab['tipoMortalidad'] ?? ''));
        $out['tipoLabel'] = mort_hist_label_tipo_completo($cab);
        $out['documento'] = mort_hist_documento_desde_snapshot($decoded, $cab);
    } else {
        $out['documento'] = mort_hist_documento_desde_snapshot($decoded, null);
    }

    $det = $decoded['san_fact_mortalidad_det'] ?? [];
    if (is_array($det)) {
        $out['lineas'] = count($det);
        $total = 0;
        foreach ($det as $line) {
            if (is_array($line)) {
                $total += (int) ($line['cantidad'] ?? 0);
            }
        }
        $out['totalAves'] = $total;
    } elseif (isset($decoded['totalAves'])) {
        $out['totalAves'] = (int) $decoded['totalAves'];
    }

    return $out;
}

/**
 * Enriquece cabecera del snapshot con etiquetas de UI (tipo/documento).
 *
 * @param array<string, mixed> $snap
 * @return array<string, mixed>
 */
function mort_hist_enrich_snapshot_for_ui(array $snap): array
{
    $snap = mort_hist_normalize_snapshot($snap);
    $cab = mort_hist_extraer_cab($snap);
    if ($cab === []) {
        return $snap;
    }
    $cab['tipoLabelCompleto'] = mort_hist_label_tipo_completo($cab);
    $cab['numFac'] = mort_hist_documento_desde_snapshot($snap, $cab);
    $cab['documento'] = $cab['numFac'];
    $snap['san_fact_mortalidad_cab'] = $cab;

    return $snap;
}
