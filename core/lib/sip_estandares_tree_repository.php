<?php
declare(strict_types=1);

if (!function_exists('sip_est2_repo_table_exists')) {
    function sip_est2_repo_table_exists(mysqli $conn, string $table): bool
    {
        static $cache = [];
        $key = spl_object_hash($conn) . '|' . $table;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $safe = $conn->real_escape_string($table);
        $rs = @$conn->query("SHOW TABLES LIKE '{$safe}'");
        $cache[$key] = (bool) ($rs && $rs->num_rows > 0);

        return $cache[$key];
    }
}

if (!function_exists('sip_est2_repo_col_exists')) {
    function sip_est2_repo_col_exists(mysqli $conn, string $table, string $column): bool
    {
        static $cache = [];
        $key = spl_object_hash($conn) . '|' . $table . '|' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $safeTable = $conn->real_escape_string($table);
        $safeColumn = $conn->real_escape_string($column);
        $rs = @$conn->query("SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        $cache[$key] = (bool) ($rs && $rs->num_rows > 0);

        return $cache[$key];
    }
}

if (!function_exists('sip_est2_repo_path_codigo_from_tree_path')) {
    function sip_est2_repo_path_codigo_from_tree_path(string $treePath): string
    {
        $treePath = trim($treePath);
        if ($treePath === '') {
            return '';
        }
        $parts = array_values(array_filter(explode('.', $treePath), static function ($part): bool {
            return trim((string) $part) !== '';
        }));
        if ($parts === []) {
            return '';
        }
        $out = [];
        foreach ($parts as $part) {
            if (!preg_match('/^-?\d+$/', (string) $part)) {
                return '';
            }
            $out[] = (string) (((int) $part) + 1);
        }

        return implode('-', $out);
    }
}

if (!function_exists('sip_est2_repo_tree_path_from_path_codigo')) {
    function sip_est2_repo_tree_path_from_path_codigo(string $pathCodigo): string
    {
        $pathCodigo = trim($pathCodigo);
        if ($pathCodigo === '') {
            return '';
        }
        $parts = array_values(array_filter(explode('-', $pathCodigo), static function ($part): bool {
            return trim((string) $part) !== '';
        }));
        if ($parts === []) {
            return '';
        }
        $out = [];
        foreach ($parts as $part) {
            if (!preg_match('/^\d+$/', (string) $part)) {
                return '';
            }
            $out[] = (string) max(0, ((int) $part) - 1);
        }

        return implode('.', $out);
    }
}

if (!function_exists('sip_est2_repo_tree_path_primera_rama_raiz')) {
    /**
     * Primer índice de hijo bajo la raíz EST2 (segmentos de treePath "0", "1", …).
     *
     * @return int|null null si vacío o primer segmento no numérico
     */
    function sip_est2_repo_tree_path_primera_rama_raiz(string $treePath): ?int
    {
        $treePath = trim($treePath);
        if ($treePath === '') {
            return null;
        }
        $parts = explode('.', $treePath);
        $first = trim((string) ($parts[0] ?? ''));
        if ($first === '' || !preg_match('/^-?\d+$/', $first)) {
            return null;
        }

        return (int) $first;
    }
}

if (!function_exists('sip_est2_repo_canonical_root_names')) {
    function sip_est2_repo_canonical_root_names(): array
    {
        return [
            'Estándar de sanidad corporativo',
            'Estandar de Sanidad 1',
        ];
    }
}

if (!function_exists('sip_est2_repo_active_root_id')) {
    function sip_est2_repo_active_root_id(mysqli $conn): ?int
    {
        if (!sip_est2_repo_table_exists($conn, 'san_estandares_nodos')) {
            return null;
        }
        $roots = [];
        $rs = @$conn->query("SELECT id, TRIM(COALESCE(nombre, '')) AS nombre FROM san_estandares_nodos WHERE nodoPadreId IS NULL ORDER BY id ASC");
        while ($rs && ($row = $rs->fetch_assoc())) {
            $roots[] = [
                'id' => (int) ($row['id'] ?? 0),
                'nombre' => trim((string) ($row['nombre'] ?? '')),
            ];
        }
        if ($roots === []) {
            return null;
        }
        foreach (sip_est2_repo_canonical_root_names() as $canon) {
            foreach ($roots as $root) {
                if ($root['nombre'] === $canon && $root['id'] > 0) {
                    return $root['id'];
                }
            }
        }
        $last = end($roots);

        return !empty($last['id']) ? (int) $last['id'] : null;
    }
}

if (!function_exists('sip_est2_repo_build_tree')) {
    function sip_est2_repo_build_tree(
        int $nodeId,
        array $nodesById,
        array $childrenByParent,
        array &$treePathByNodeId,
        string $treePath = ''
    ): array {
        $node = $nodesById[$nodeId] ?? null;
        if (!is_array($node)) {
            return ['name' => 'Nodo', 'children' => []];
        }
        $treePathByNodeId[$nodeId] = $treePath;
        $children = $childrenByParent[$nodeId] ?? [];
        usort($children, static function (array $a, array $b): int {
            $pa = (int) ($a['posicion'] ?? 0);
            $pb = (int) ($b['posicion'] ?? 0);
            if ($pa === $pb) {
                return ((int) ($a['id'] ?? 0)) <=> ((int) ($b['id'] ?? 0));
            }

            return $pa <=> $pb;
        });
        $outChildren = [];
        foreach ($children as $idx => $child) {
            $childId = (int) ($child['id'] ?? 0);
            $childTreePath = $treePath === '' ? (string) $idx : ($treePath . '.' . $idx);
            $outChildren[] = sip_est2_repo_build_tree($childId, $nodesById, $childrenByParent, $treePathByNodeId, $childTreePath);
        }
        $actividadKey = trim((string) ($node['actividadKey'] ?? ''));
        $tareaNombre = trim((string) ($node['tareaNombre'] ?? ''));
        $esHoja = ((int) ($node['esHoja'] ?? 0) === 1) || $actividadKey !== '';
        $displayName = trim((string) ($node['nombre'] ?? ''));
        if ($esHoja && $tareaNombre !== '') {
            $displayName = $tareaNombre;
        }
        $ctpRaw = $node['codTipoPrograma'] ?? null;
        $codTipoPrograma = ($ctpRaw !== null && $ctpRaw !== '') ? (int) $ctpRaw : null;

        return [
            'name' => $displayName,
            'children' => $outChildren,
            'esHoja' => $esHoja ? 1 : 0,
            'actividadKey' => $actividadKey,
            'tareaKey' => $actividadKey,
            'codTipoPrograma' => $codTipoPrograma,
            'est2NodeKey' => 'db_' . $nodeId,
            'treePath' => $treePath,
            'pathCodigo' => sip_est2_repo_path_codigo_from_tree_path($treePath),
        ];
    }
}

if (!function_exists('sip_est2_repo_decode_info_origen')) {
    function sip_est2_repo_decode_info_origen($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw)) {
            return [];
        }
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $dec = json_decode($raw, true);

        return is_array($dec) ? $dec : [];
    }
}

if (!function_exists('sip_est2_parametro_nombre_visible')) {
    /**
     * Nombre mostrado del parámetro.
     * Muestras: prioriza parametroNombre / etiqueta de tipo de muestra; analisisLabel solo si no hay otro nombre
     * (evita que HC arme "Mesofilos · Mesofilos" al concatenar análisis otra vez).
     *
     * @param array|string|null $infoOrigenRaw JSON infoOrigen o ya decodificado
     */
    function sip_est2_parametro_nombre_visible(
        string $parametroKey,
        string $tipoParametro,
        $infoOrigenRaw,
        string $parametroNombreDb = ''
    ): string {
        $infoOrigen = is_array($infoOrigenRaw) ? $infoOrigenRaw : sip_est2_repo_decode_info_origen($infoOrigenRaw);
        $pk = trim($parametroKey);
        $nomDb = trim($parametroNombreDb);
        $tp = strtolower(trim($tipoParametro));
        $lblAn = is_array($infoOrigen) ? trim((string) ($infoOrigen['analisisLabel'] ?? '')) : '';
        $muestraLbl = is_array($infoOrigen) ? trim((string) ($infoOrigen['muestraTipoLabel'] ?? '')) : '';

        $equiv = static function (string $a, string $b): bool {
            $na = mb_strtolower(preg_replace('/[^a-z0-9]+/iu', '', $a), 'UTF-8');
            $nb = mb_strtolower(preg_replace('/[^a-z0-9]+/iu', '', $b), 'UTF-8');

            return $na !== '' && $na === $nb;
        };

        if ($tp === 'muestras' || $tp === 'muestra') {
            /* Nombre de parámetro distinto del análisis (p. ej. "Hisopado de Linea Matriz").
             * Si parametroNombreDb coincide con el tipo de muestra (muestraTipoLabel),
             * descartarlo: es el nombre genérico del tipo de muestra, no del parámetro. */
            if ($nomDb !== '' && strcasecmp($nomDb, $pk) !== 0
                && ($lblAn === '' || !$equiv($nomDb, $lblAn))
                && ($muestraLbl === '' || !$equiv($nomDb, $muestraLbl))
            ) {
                return $nomDb;
            }
            if ($muestraLbl !== '' && ($lblAn === '' || !$equiv($muestraLbl, $lblAn))) {
                return $muestraLbl;
            }
            /* Si solo quedó el label del análisis guardado como nombre, preferir clave humanizada. */
            if ($nomDb !== '' && $lblAn !== '' && $equiv($nomDb, $lblAn) && $pk !== '') {
                $fromKey = sip_est2_parametro_key_a_etiqueta($pk);
                if ($fromKey !== '' && !$equiv($fromKey, $lblAn)) {
                    return $fromKey;
                }
            }
            if ($nomDb !== '' && strcasecmp($nomDb, $pk) !== 0) {
                return $nomDb;
            }
            if ($lblAn !== '') {
                return $lblAn;
            }
        } else {
            if ($nomDb !== '' && strcasecmp($nomDb, $pk) !== 0) {
                return $nomDb;
            }
        }

        return sip_est2_parametro_key_a_etiqueta($pk);
    }
}

if (!function_exists('sip_est2_parametro_key_a_etiqueta')) {
    /**
     * Etiqueta legible cuando no hay parametroNombre en BD (evita mostrar CLAVE_TECNICA en UI).
     */
    function sip_est2_parametro_key_a_etiqueta(string $parametroKey): string
    {
        $pk = trim($parametroKey);
        if ($pk === '') {
            return '';
        }
        if (function_exists('hc_special_fact_display_label_for_parametro_key')) {
            return hc_special_fact_display_label_for_parametro_key($pk);
        }

        return trim(preg_replace('/\s+/u', ' ', str_replace('_', ' ', $pk)));
    }
}

if (!function_exists('sip_est2_repo_merge_dim_parametros_for_tarea')) {
    /**
     * Antes rellenaba desde `san_dim_parametro`. La fuente única es `san_estandares_parametros` (sin dim).
     *
     * @param list<array<string,mixed>> $rows Filas ya cargadas desde san_estandares_parametros por nodo
     * @return list<array<string,mixed>>
     */
    function sip_est2_repo_merge_dim_parametros_for_tarea(mysqli $conn, string $tareaKey, array $rows): array
    {
        unset($conn, $tareaKey);

        return $rows;
    }
}

if (!function_exists('sip_est2_repo_load_context')) {
    /**
     * @return array{
     *   rootId:?int,
     *   rootName:string,
     *   arbol:?array<string,mixed>,
     *   hojas:list<array<string,mixed>>,
     *   paramsMap:array<string, list<array<string,mixed>>>,
 *   treePathByNodeId:array<int,string>,
 *   hojaByTreePath:array<string,array<string,mixed>>
     * }
     */
    function sip_est2_repo_load_context(mysqli $conn, ?int $rootId = null): array
    {
        static $cache = [];
        $rootId = $rootId ?? sip_est2_repo_active_root_id($conn);
        $cacheKey = spl_object_hash($conn) . '|' . (string) ($rootId ?? 0);
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        $out = [
            'rootId' => null,
            'rootName' => '',
            'arbol' => null,
            'hojas' => [],
            'paramsMap' => [],
            'treePathByNodeId' => [],
            'hojaByTreePath' => [],
        ];
        if ($rootId === null || $rootId <= 0) {
            return $cache[$cacheKey] = $out;
        }
        if (!sip_est2_repo_table_exists($conn, 'san_estandares_nodos')) {
            return $cache[$cacheKey] = $out;
        }

        $colCodTp = sip_est2_repo_col_exists($conn, 'san_estandares_nodos', 'codTipoPrograma')
            ? ', n.codTipoPrograma'
            : '';
        /* actividadKeySqlRaw conserva NULL SQL (vs COALESCE a '') para HC/numeración por letras solo si la columna no es NULL. */
        $sqlNodes = "SELECT n.id, n.nodoPadreId, n.posicion, TRIM(COALESCE(n.nombre, '')) AS nombre,
                            n.esHoja, TRIM(COALESCE(n.actividadKey, '')) AS actividadKey,
                            n.actividadKey AS actividadKeySqlRaw,
                            TRIM(COALESCE(n.nombre, '')) AS tareaNombre{$colCodTp}
                     FROM san_estandares_nodos n
                     ORDER BY n.id ASC";
        $rsNodes = @$conn->query($sqlNodes);
        if (!$rsNodes) {
            return $cache[$cacheKey] = $out;
        }

        $nodesById = [];
        $childrenByParent = [];
        while ($row = $rsNodes->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            $parent = $row['nodoPadreId'] === null ? null : (int) $row['nodoPadreId'];
            $row['id'] = $id;
            $row['nodoPadreId'] = $parent;
            $nodesById[$id] = $row;
            if ($parent !== null) {
                if (!isset($childrenByParent[$parent])) {
                    $childrenByParent[$parent] = [];
                }
                $childrenByParent[$parent][] = $row;
            }
        }
        if (!isset($nodesById[$rootId])) {
            return $cache[$cacheKey] = $out;
        }

        $treePathByNodeId = [];
        $arbol = sip_est2_repo_build_tree($rootId, $nodesById, $childrenByParent, $treePathByNodeId, '');
        $out['rootId'] = $rootId;
        $out['rootName'] = trim((string) ($nodesById[$rootId]['nombre'] ?? ''));
        $out['arbol'] = $arbol;
        $out['treePathByNodeId'] = $treePathByNodeId;

        $leafNodeIds = [];
        foreach ($treePathByNodeId as $nodeId => $treePath) {
            $node = $nodesById[$nodeId] ?? null;
            if (!is_array($node)) {
                continue;
            }
            $hasChildren = !empty($childrenByParent[$nodeId]);
            $actividadKey = trim((string) ($node['actividadKey'] ?? ''));
            $esHoja = ((int) ($node['esHoja'] ?? 0) === 1) || $actividadKey !== '';
            if (!$hasChildren && $esHoja) {
                $leafNodeIds[] = $nodeId;
            }
        }
        $leafNodeIds = array_values(array_unique(array_filter(array_map('intval', $leafNodeIds))));
        if ($leafNodeIds === []) {
            return $cache[$cacheKey] = $out;
        }

        $rowsByNode = [];
        if (sip_est2_repo_table_exists($conn, 'san_estandares_parametros')) {
            $placeholders = implode(',', array_fill(0, count($leafNodeIds), '?'));
            $types = str_repeat('i', count($leafNodeIds));
            $colParamTk = sip_est2_repo_col_exists($conn, 'san_estandares_parametros', 'tareaKey');
            $colNom = sip_est2_repo_col_exists($conn, 'san_estandares_parametros', 'parametroNombre');
            $colTipo = sip_est2_repo_col_exists($conn, 'san_estandares_parametros', 'tipo');
            $colModo = sip_est2_repo_col_exists($conn, 'san_estandares_parametros', 'modoEvaluacion');
            $selParamTk = $colParamTk ? ', TRIM(COALESCE(p.`tareaKey`, \'\')) AS `parametroTareaKey`' : '';
            $selNom = $colNom ? ', TRIM(COALESCE(p.`parametroNombre`, \'\')) AS `parametroNombreDb`' : '';
            $selTipo = $colTipo ? ', TRIM(COALESCE(p.`tipo`, \'\')) AS `estandarTipoDb`' : '';
            $selModo = $colModo
                ? ', TRIM(COALESCE(p.`modoEvaluacion`, \'rango_cerrado\')) AS `modoEvaluacion`'
                : ', \'rango_cerrado\' AS `modoEvaluacion`';
            $sqlRows = "SELECT p.id, p.nodoId, TRIM(COALESCE(p.parametroKey, '')) AS parametroKey,
                               TRIM(COALESCE(p.tipoParametro, 'manual')) AS tipoParametro,
                               p.infoOrigen, TRIM(COALESCE(p.unidades, '')) AS unidades,
                               TRIM(COALESCE(p.stdMin, '')) AS stdMin, TRIM(COALESCE(p.stdMax, '')) AS stdMax,
                               TRIM(COALESCE(p.edadDiaExpr, '')) AS edadDiaExpr, TRIM(COALESCE(p.sexo, 'unificado')) AS sexo{$selParamTk}{$selNom}{$selTipo}{$selModo}
                        FROM san_estandares_parametros p
                        WHERE p.nodoId IN ($placeholders)
                        ORDER BY p.nodoId ASC, p.id ASC";
            $stRows = $conn->prepare($sqlRows);
            if ($stRows) {
                $bind = [];
                $bind[] = &$types;
                foreach ($leafNodeIds as $k => $value) {
                    $leafNodeIds[$k] = (int) $value;
                    $bind[] = &$leafNodeIds[$k];
                }
                call_user_func_array([$stRows, 'bind_param'], $bind);
                $stRows->execute();
                $rsRows = $stRows->get_result();
                while ($rsRows && ($row = $rsRows->fetch_assoc())) {
                    $nodeId = (int) ($row['nodoId'] ?? 0);
                    if (!isset($rowsByNode[$nodeId])) {
                        $rowsByNode[$nodeId] = [];
                    }
                    $infoOrigen = sip_est2_repo_decode_info_origen($row['infoOrigen'] ?? null);
                    $parametroKey = trim((string) ($row['parametroKey'] ?? ''));
                    $tipoParametro = strtolower(trim((string) ($row['tipoParametro'] ?? 'manual')));
                    $nomDb = $colNom ? trim((string) ($row['parametroNombreDb'] ?? '')) : '';
                    $parametroNombre = sip_est2_parametro_nombre_visible(
                        $parametroKey,
                        $tipoParametro !== '' ? $tipoParametro : 'manual',
                        $infoOrigen,
                        $nomDb
                    );
                    $codTipoMuestra = (int) ($infoOrigen['codTipoMuestra'] ?? 0);
                    $codEnfermedad = (int) ($infoOrigen['analisis'] ?? 0);
                    $rowOut = [
                        'id' => (int) ($row['id'] ?? 0),
                        'parametroKey' => $parametroKey,
                        'parametroNombre' => $parametroNombre,
                        'tipoParametro' => $tipoParametro !== '' ? $tipoParametro : 'manual',
                        'infoOrigen' => $infoOrigen,
                        'unidades' => trim((string) ($row['unidades'] ?? '')),
                        'stdMin' => trim((string) ($row['stdMin'] ?? '')),
                        'stdMax' => trim((string) ($row['stdMax'] ?? '')),
                        'modoEvaluacion' => (static function ($m) {
                            $m = strtolower(trim((string) $m));
                            $ok = ['rango_cerrado', 'valor_unico', 'solo_max', 'solo_min', 'franjas'];

                            return in_array($m, $ok, true) ? $m : 'rango_cerrado';
                        })($row['modoEvaluacion'] ?? 'rango_cerrado'),
                        'edadDiaExpr' => trim((string) ($row['edadDiaExpr'] ?? '')),
                        'sexo' => trim((string) ($row['sexo'] ?? 'unificado')),
                        'codTipoMuestra' => $codTipoMuestra,
                        'cod_enfermedad' => $codEnfermedad,
                        'estandarTipo' => $colTipo ? trim((string) ($row['estandarTipoDb'] ?? '')) : '',
                    ];
                    if ($colParamTk) {
                        $rowOut['parametroTareaKey'] = trim((string) ($row['parametroTareaKey'] ?? ''));
                    }
                    $rowsByNode[$nodeId][] = $rowOut;
                }
                $stRows->close();
            }
        }

        $paramsMap = [];
        $hojas = [];
        foreach ($leafNodeIds as $nodeId) {
            $treePath = $treePathByNodeId[$nodeId] ?? '';
            $pathCodigo = sip_est2_repo_path_codigo_from_tree_path($treePath);
            if ($pathCodigo === '') {
                continue;
            }
            $node = $nodesById[$nodeId] ?? [];
            $tareaKey = trim((string) ($node['actividadKey'] ?? ''));
            $tareaNombre = trim((string) ($node['tareaNombre'] ?? ''));
            $ctpNode = $node['codTipoPrograma'] ?? null;
            $codTipoProgramaLeaf = ($ctpNode !== null && $ctpNode !== '') ? (int) $ctpNode : null;
            $leafName = $tareaNombre !== '' ? $tareaNombre : trim((string) ($node['nombre'] ?? ''));
            $rows = $rowsByNode[$nodeId] ?? [];
            $rows = sip_est2_repo_merge_dim_parametros_for_tarea($conn, $tareaKey, $rows);
            $leafRows = [];
            $parametroKeys = [];
            foreach (array_values($rows) as $idx => $row) {
                $parametroKey = trim((string) ($row['parametroKey'] ?? ''));
                if ($parametroKey !== '') {
                    $parametroKeys[$parametroKey] = true;
                }
                $paramTkRow = trim((string) ($row['parametroTareaKey'] ?? ''));
                $tareaKeyLinea = $paramTkRow !== '' ? $paramTkRow : $tareaKey;
                $line = [
                    'nodoId' => $nodeId,
                    'path' => $pathCodigo . '-' . ($idx + 1),
                    'pathCodigo' => $pathCodigo,
                    'treePath' => $treePath,
                    'label' => $leafName,
                    'tipo' => $tareaNombre !== '' ? $tareaNombre : $tareaKey,
                    'estandarTipo' => trim((string) ($row['estandarTipo'] ?? '')),
                    'parametro' => (string) ($row['parametroNombre'] ?? $parametroKey),
                    'stdMin' => (string) ($row['stdMin'] ?? ''),
                    'stdMax' => (string) ($row['stdMax'] ?? ''),
                    'modoEvaluacion' => (string) ($row['modoEvaluacion'] ?? 'rango_cerrado'),
                    'unidades' => (string) ($row['unidades'] ?? ''),
                    'tipoParametro' => (string) ($row['tipoParametro'] ?? 'manual'),
                    'infoOrigen' => $row['infoOrigen'] ?? [],
                    'edadDiaExpr' => (string) ($row['edadDiaExpr'] ?? ''),
                    'sexo' => (string) ($row['sexo'] ?? 'unificado'),
                    'codTipoMuestra' => (int) ($row['codTipoMuestra'] ?? 0),
                    'cod_enfermedad' => (int) ($row['cod_enfermedad'] ?? 0),
                    'tareaKey' => $tareaKeyLinea,
                    'tareaNombre' => $tareaNombre,
                    'parametroKey' => $parametroKey,
                    'parametroNombre' => (string) ($row['parametroNombre'] ?? $parametroKey),
                    'nivelRaw' => $tareaNombre !== '' ? $tareaNombre : $tareaKey,
                    'parametroRaw' => (string) ($row['parametroNombre'] ?? $parametroKey),
                ];
                $leafRows[] = $line;
                $paramsMap[$pathCodigo][] = $line;
            }
            $parametroKeyList = array_values(array_keys($parametroKeys));
            /* HC/numeración: misma noción que para incluir la hoja en el mapa (columna esHoja y/o actividadKey). */
            $akNode = trim((string) ($node['actividadKey'] ?? ''));
            $esHojaDb = (int) ($node['esHoja'] ?? 0) === 1;
            $esHojaNumeracionHc = $esHojaDb || $akNode !== '';
            /* HC matriz:
               - Programas (primeraRama === 0): letras si actividadKey NOT NULL (comportamiento actual).
               - Otras secciones (primeraRama >= 1): numérico compuesto hasta nivel 4 (1.2.3.4),
                 letras desde nivel 5+ (profundidad >= 4 segmentos '.' en el treePath). */
            $primeraRama = sip_est2_repo_tree_path_primera_rama_raiz((string) $treePath);
            if ($primeraRama !== null && $primeraRama >= 1) {
                $deepCount = substr_count((string) $treePath, '.');
                $numeracionLetrasHc = $deepCount >= 4;
            } else {
                $numeracionLetrasHc = array_key_exists('actividadKeySqlRaw', $node) && $node['actividadKeySqlRaw'] !== null;
            }
            $meta = [
                'nodoId' => $nodeId,
                'path' => $pathCodigo,
                'treePath' => $treePath,
                'label' => $leafName,
                'tareaKey' => $tareaKey,
                'tareaNombre' => $tareaNombre,
                'esHoja' => $esHojaNumeracionHc ? 1 : 0,
                'codTipoPrograma' => $codTipoProgramaLeaf,
                'parametroKeys' => $parametroKeyList,
                'parametroKey' => $parametroKeyList !== [] ? $parametroKeyList[0] : '',
                'parametroCount' => count($parametroKeyList),
                'filas' => $leafRows,
                'numeracion_letras_hc' => $numeracionLetrasHc,
            ];
            $hojas[] = $meta;
        }

        $leafMetaByTreePath = [];
        foreach ($hojas as $hoja) {
            $treePath = trim((string) ($hoja['treePath'] ?? ''));
            if ($treePath !== '') {
                $leafMetaByTreePath[$treePath] = $hoja;
            }
        }
        $out['hojaByTreePath'] = $leafMetaByTreePath;
        if (is_array($out['arbol'])) {
            $walker = static function (array &$node) use (&$walker, &$leafMetaByTreePath): void {
                $treePath = trim((string) ($node['treePath'] ?? ''));
                if ($treePath !== '' && isset($leafMetaByTreePath[$treePath])) {
                    $meta = $leafMetaByTreePath[$treePath];
                    $node['nodoId'] = (int) ($meta['nodoId'] ?? 0);
                    $node['tareaKey'] = (string) ($meta['tareaKey'] ?? '');
                    $node['tareaNombre'] = (string) ($meta['tareaNombre'] ?? '');
                    $node['codTipoPrograma'] = $meta['codTipoPrograma'] ?? null;
                    $node['parametroKey'] = (string) ($meta['parametroKey'] ?? '');
                    $node['parametroKeys'] = $meta['parametroKeys'] ?? [];
                    $node['parametroCount'] = (int) ($meta['parametroCount'] ?? 0);
                }
                if (!empty($node['children']) && is_array($node['children'])) {
                    foreach ($node['children'] as &$child) {
                        if (is_array($child)) {
                            $walker($child);
                        }
                    }
                    unset($child);
                }
            };
            $walker($out['arbol']);
        }

        $out['hojas'] = $hojas;
        $out['paramsMap'] = $paramsMap;

        return $cache[$cacheKey] = $out;
    }
}

if (!function_exists('sip_est2_repo_leaf_meta_by_tree_path')) {
    /**
     * @return array<string,mixed>|null
     */
    function sip_est2_repo_leaf_meta_by_tree_path(mysqli $conn, string $treePath, ?int $rootId = null): ?array
    {
        $treePath = trim($treePath);
        if ($treePath === '') {
            return null;
        }
        $ctx = sip_est2_repo_load_context($conn, $rootId);
        $map = $ctx['hojaByTreePath'] ?? [];
        if (!is_array($map) || !isset($map[$treePath]) || !is_array($map[$treePath])) {
            return null;
        }

        return $map[$treePath];
    }
}

if (!function_exists('sip_est2_repo_nodos_parent_map_store')) {
    /**
     * @return array<int, int|null>
     */
    function &sip_est2_repo_nodos_parent_map_store(mysqli $conn, bool $reset = false): array
    {
        static $byConn = [];
        $ck = spl_object_hash($conn);
        if ($reset) {
            unset($byConn[$ck]);
        }
        if (!isset($byConn[$ck])) {
            $byConn[$ck] = [];
            if (sip_est2_repo_table_exists($conn, 'san_estandares_nodos')) {
                $rs = @$conn->query('SELECT `id`, `nodoPadreId` FROM `san_estandares_nodos`');
                while ($rs && ($row = $rs->fetch_assoc())) {
                    $id = (int) ($row['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }
                    $pid = $row['nodoPadreId'] ?? null;
                    $byConn[$ck][$id] = ($pid === null || $pid === '') ? null : (int) $pid;
                }
                if ($rs) {
                    $rs->free();
                }
            }
        }

        return $byConn[$ck];
    }
}

if (!function_exists('sip_est2_repo_root_first_child_id')) {
    function sip_est2_repo_root_first_child_id(mysqli $conn, int $rootId): int
    {
        static $cache = [];
        $ck = spl_object_hash($conn) . "\x1e" . $rootId;
        if (array_key_exists($ck, $cache)) {
            return $cache[$ck];
        }
        $first = 0;
        $st = @$conn->prepare('SELECT `id` FROM `san_estandares_nodos` WHERE `nodoPadreId` = ? ORDER BY `posicion` ASC, `id` ASC LIMIT 1');
        if ($st) {
            $st->bind_param('i', $rootId);
            $st->execute();
            $rs = $st->get_result();
            $row = $rs ? $rs->fetch_assoc() : null;
            $st->close();
            if (is_array($row)) {
                $first = (int) ($row['id'] ?? 0);
            }
        }

        return $cache[$ck] = $first;
    }
}

if (!function_exists('sip_est2_repo_leaf_in_first_root_branch')) {
    /**
     * Verdadero si el nodo hoja pertenece al subárbol del primer hijo de la raíz del estándar
     * (misma semántica que la antigua "familia treePath que empieza en 0", sin depender de strings de path).
     */
    function sip_est2_repo_leaf_in_first_root_branch(mysqli $conn, int $nodoLeafId, ?int $rootId = null): bool
    {
        static $resultCache = [];
        if (!sip_est2_repo_table_exists($conn, 'san_estandares_nodos') || $nodoLeafId <= 0) {
            return false;
        }
        $rootId = $rootId ?? sip_est2_repo_active_root_id($conn);
        if ($rootId === null || $rootId <= 0) {
            return false;
        }
        $cacheKey = spl_object_hash($conn) . "\x1e" . $rootId . "\x1e" . $nodoLeafId;
        if (array_key_exists($cacheKey, $resultCache)) {
            return $resultCache[$cacheKey];
        }
        $firstChildId = sip_est2_repo_root_first_child_id($conn, (int) $rootId);
        if ($firstChildId <= 0) {
            return $resultCache[$cacheKey] = false;
        }
        $parentMap = &sip_est2_repo_nodos_parent_map_store($conn);
        $cur = $nodoLeafId;
        for ($guard = 0; $guard < 5000; $guard++) {
            if ($cur <= 0) {
                return $resultCache[$cacheKey] = false;
            }
            if ($cur === $rootId) {
                return $resultCache[$cacheKey] = false;
            }
            if (!array_key_exists($cur, $parentMap)) {
                return $resultCache[$cacheKey] = false;
            }
            $pid = $parentMap[$cur];
            if ($pid === null) {
                return $resultCache[$cacheKey] = false;
            }
            $pid = (int) $pid;
            if ($pid === (int) $rootId) {
                return $resultCache[$cacheKey] = ($cur === $firstChildId);
            }
            $cur = $pid;
        }

        return $resultCache[$cacheKey] = false;
    }
}

if (!function_exists('sip_est2_repo_leaf_meta_by_tarea_key')) {
    /**
     * Misma forma de meta que una entrada en `sip_est2_repo_load_context()['hojas']` (filas, tareaKey, treePath, codTipoPrograma, …).
     * Se asume `actividadKey` única por hoja en el árbol activo.
     *
     * @return array<string,mixed>|null
     */
    function sip_est2_repo_leaf_meta_by_tarea_key(mysqli $conn, string $tareaKey, ?int $rootId = null): ?array
    {
        $tareaKey = trim($tareaKey);
        if ($tareaKey === '') {
            return null;
        }
        $ctx = sip_est2_repo_load_context($conn, $rootId);
        $hojas = $ctx['hojas'] ?? [];
        if (!is_array($hojas)) {
            return null;
        }
        foreach ($hojas as $hoja) {
            if (!is_array($hoja)) {
                continue;
            }
            if (trim((string) ($hoja['tareaKey'] ?? '')) === $tareaKey) {
                return $hoja;
            }
        }

        return null;
    }
}
