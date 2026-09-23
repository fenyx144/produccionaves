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

require_once __DIR__ . '/mortalidad_listado_lib.php';
require_once __DIR__ . '/../../../core/lib/gri/mortalidad_zonas_lib.php';
require_once __DIR__ . '/../../../core/lib/gri/mortalidad_doc_lib.php';
require_once __DIR__ . '/../../../core/lib/gri/mortalidad_fact_aux_lib.php';
require_once __DIR__ . '/../../../core/lib/historial_acciones.php';
require_once __DIR__ . '/../../../core/lib/uploads_config.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

/**
 * Semántica al editar en la web un registro con mortalidad hacia 0 aves.
 * 'A': conservar cabe_zonas con tcanttot=0 y sin movi_zonas (el documento no se libera).
 * 'B': eliminar cabe_zonas/movi_zonas y dejar solo el registro auxiliar sin documento.
 */
const MORT_EDITAR_A_CERO = 'A';

function mort_listado_uploads_mortalidad_dir(): string
{
    $dir = sanidad_uploads_fs_dir('mortalidad');
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

/**
 * @return list<string>
 */
function mort_listado_procesar_imagenes_edit(string $fileKey, string $granja, string $galpon, string $numero, string $fechaYmd, string $sexo): array
{
    $rutas = [];
    $prefix = 'img_' . $fileKey . '_';
    $carpeta = mort_listado_uploads_mortalidad_dir();

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
            '%s_%s_%s_%s_%s_%s_%s.%s',
            mort_normalizar_granja3($granja),
            preg_replace('/[^A-Za-z0-9_-]/', '', $galpon) ?: 'G',
            preg_replace('/\D/', '', $numero) ?: '0',
            $fechaYmd,
            $sexo,
            substr(preg_replace('/[^A-Za-z0-9]/', '', $fileKey) ?? 'x', 0, 12),
            $idx,
            $ext
        );
        $base = preg_replace('/[^A-Za-z0-9_.-]/', '_', $base) ?? $base;
        $destFs = $carpeta . $base;
        if (!@move_uploaded_file($tmp, $destFs)) {
            continue;
        }
        $rutas[] = sanidad_uploads_rel('mortalidad', $base);
    }

    return $rutas;
}

/**
 * @param list<string> $rutas
 */
function mort_listado_borrar_evidencias_fs(array $rutas): void
{
    foreach ($rutas as $rel) {
        $rel = mort_listado_normalize_evidencia_rel((string) $rel);
        if ($rel === '' || strpos($rel, 'uploads/mortalidad/') !== 0) {
            continue;
        }
        $fs = sanidad_uploads_fs_from_rel($rel);
        if ($fs !== '' && is_file($fs)) {
            @unlink($fs);
        }
    }
}

$conn = conectar_joya_mysqli();
if (!$conn) {
    mort_admin_json_error('Error de conexion', 500);
}

mort_listado_require_editar_eliminar($conn);

$payload = [];
if (isset($_POST['data']) && is_string($_POST['data']) && trim($_POST['data']) !== '') {
    $decoded = json_decode($_POST['data'], true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === []) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
}
if ($payload === []) {
    $payload = $_POST;
}

$id = trim((string) ($payload['id'] ?? ''));
if ($id === '') {
    mysqli_close($conn);
    mort_admin_json_error('ID no valido.');
}

$idEsc = mysqli_real_escape_string($conn, $id);
$cab = mort_listado_cabecera_desde_zonas($conn, $id);
if (!$cab) {
    mysqli_close($conn);
    mort_admin_json_error('Registro no encontrado.', 404);
}

$tipoOriginal = strtolower(trim((string) ($cab['tipoMortalidad'] ?? '')));
$tipoMortalidad = strtolower(trim((string) ($payload['tipoMortalidad'] ?? $tipoOriginal)));
$tiposValidos = ['incubacion', 'transporte', 'produccion', 'despacho'];
if (!in_array($tipoMortalidad, $tiposValidos, true)) {
    mysqli_close($conn);
    mort_admin_json_error('Tipo de mortalidad invalido.');
}
$fechaRegistro = trim((string) ($payload['fechaRegistro'] ?? $cab['fechaRegistro'] ?? ''));
$fechaLlegada = trim((string) ($payload['fechaLlegada'] ?? ''));
if ($fechaLlegada === '') {
    $fechaLlegada = null;
}
$granja = mort_normalizar_granja3((string) ($payload['granja'] ?? $cab['granja'] ?? ''));
$campaniaRaw = trim((string) ($payload['campania'] ?? $cab['campania'] ?? ''));
$campania = $campaniaRaw !== ''
    ? substr(str_pad(preg_replace('/\D/', '', $campaniaRaw) ?? '', 3, '0', STR_PAD_LEFT), 0, 3)
    : '';
$galpon = trim((string) ($payload['galpon'] ?? $cab['galpon'] ?? ''));
$observaciones = trim((string) ($payload['observaciones'] ?? ''));

$subtipoTransporte = null;
$subtipoProduccion = null;
if ($tipoMortalidad === 'transporte') {
    $subtipoTransporte = array_key_exists('subtipoTransporte', $payload)
        ? trim((string) $payload['subtipoTransporte'])
        : (trim((string) ($cab['subtipoTransporte'] ?? '')) !== '' ? trim((string) $cab['subtipoTransporte']) : 'transporte');
    if ($subtipoTransporte === '') {
        $subtipoTransporte = 'transporte';
    }
}
if ($tipoMortalidad === 'produccion') {
    $subtipoProduccion = array_key_exists('subtipoProduccion', $payload)
        ? trim((string) $payload['subtipoProduccion'])
        : (trim((string) ($cab['subtipoProduccion'] ?? '')) !== '' ? trim((string) $cab['subtipoProduccion']) : 'crianza');
    if ($subtipoProduccion === '') {
        $subtipoProduccion = 'crianza';
    }
}
if ($tipoMortalidad !== 'incubacion') {
    $fechaLlegada = null;
}

if ($granja === '' || $campania === '' || $galpon === '' || $fechaRegistro === '') {
    mysqli_close($conn);
    mort_admin_json_error('Granja, campaña, galpon y fecha son obligatorios.');
}

$detalleIn = $payload['detalle'] ?? [];
if (!is_array($detalleIn)) {
    mysqli_close($conn);
    mort_admin_json_error('Detalle invalido.');
}

// Registro sin mortalidad (0 aves): detalle vacío o líneas con todas las cantidades en 0.
// Producción con origen Necropsia/Laboratorio siempre exige aves.
$esRegistroCero = true;
if ($tipoMortalidad === 'produccion') {
    $origenCero = strtolower(trim((string) ($subtipoProduccion ?? 'crianza')));
    if ($origenCero === 'necropsia' || $origenCero === 'laboratorio') {
        $esRegistroCero = false;
    }
}
if ($esRegistroCero) {
    $hayCantidad = false;
    foreach ($detalleIn as $det) {
        if (is_array($det) && (int) ($det['cantidad'] ?? 0) > 0) {
            $hayCantidad = true;
            break;
        }
    }
    $esRegistroCero = !$hayCantidad;
}
if ($detalleIn === [] && !$esRegistroCero) {
    mysqli_close($conn);
    mort_admin_json_error('Detalle invalido.');
}

$muestraCausa = mort_listado_muestra_causa($tipoMortalidad, $subtipoTransporte, $subtipoProduccion);

// El registro vive solo en el espejo auxiliar (registro 0) hasta que se le asigna mortalidad.
$existeEnZonas = mort_zonas_cabe_existe_por_external($conn, $id);
$pasaAMortalidad = !$existeEnZonas && !$esRegistroCero;

if ($pasaAMortalidad) {
    try {
        $docGuardado = 'GI';
        $serieGuardada = mort_generar_serie($granja, $tipoMortalidad);
        $numeroGuardado = mort_siguiente_numero($conn, $docGuardado, $serieGuardada);
    } catch (Throwable $e) {
        mysqli_close($conn);
        mort_admin_json_error($e->getMessage(), 500);
    }
} else {
    $docGuardado = trim((string) ($cab['doc'] ?? ''));
    $docGuardado = $docGuardado !== '' ? $docGuardado : 'GI';
    $serieGuardada = trim((string) ($cab['serie'] ?? ''));
    $numeroGuardado = trim((string) ($cab['numero'] ?? ''));
}
$numero = $numeroGuardado;
$fechaYmd = preg_replace('/[^0-9]/', '', $fechaRegistro) ?? '';
if (strlen($fechaYmd) > 8) {
    $fechaYmd = substr($fechaYmd, 0, 8);
}

$codUsuario = (string) ($_SESSION['usuario'] ?? 'unknown');
$nomUsuario = (string) ($_SESSION['nombre'] ?? $_SESSION['nombre_completo'] ?? $_SESSION['usuario'] ?? 'Usuario desconocido');
$datosPrevios = mort_listado_snapshot_desde_zonas($conn, $id);
$datosPreviosJson = json_encode($datosPrevios, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

mysqli_begin_transaction($conn);

try {
    mort_listado_validar_fecha_cierre($conn, $fechaRegistro);

    // No permitir editar registros cuya campaña ya no está activa.
    $gCab = trim((string) ($cab['granja'] ?? ''));
    $cCab = trim((string) ($cab['campania'] ?? ''));
    if ($gCab !== '' && $cCab !== '' && !mort_listado_cenco_activo($conn, $gCab, $cCab)) {
        throw new RuntimeException('No se puede editar: la campaña ' . $gCab . '-' . $cCab . ' ya no está activa.');
    }

    // Si cambió el tipo de mortalidad, actualizar también la marca (mark) de
    // cabe_zonas/movi_zonas para que el resto de la sincronización coincida.
    if ($tipoMortalidad !== $tipoOriginal) {
        $nuevoMark = mort_zonas_mark($tipoMortalidad);
        $nuevoMarkEsc = mysqli_real_escape_string($conn, $nuevoMark);
        mysqli_query($conn, "UPDATE cabe_zonas SET mark = '{$nuevoMarkEsc}' WHERE external_id = '{$idEsc}'");
        mysqli_query($conn, "
            UPDATE movi_zonas mz
            INNER JOIN cabe_zonas cz
                ON cz.mark = mz.mark AND cz.treg = mz.treg
                AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
            SET mz.mark = '{$nuevoMarkEsc}'
            WHERE cz.external_id = '{$idEsc}'
        ");
    }

    // Líneas actuales en movi_zonas (idmovi + tcodigo => detId reconstruido).
    $detIndex = [];
    $posUsadas = [];
    $maxPos = 0;
    $qPos = mysqli_query($conn, "
        SELECT mz.idmovi, mz.tcodigo, mz.evidencia, mz.tcod_mortgrs
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.external_id = '{$idEsc}'
    ");
    if ($qPos) {
        while ($r = mysqli_fetch_assoc($qPos)) {
            $pos = (int) ($r['idmovi'] ?? 0);
            $sexo = strtoupper(trim((string) ($r['tcodigo'] ?? ''))) === 'P0001002' ? 'H' : 'M';
            $detId = sprintf('%s-%02d-%s', $id, $pos, $sexo);
            $maxPos = max($maxPos, $pos);
            $posUsadas[$pos] = true;
            $detIndex[$detId] = [
                'posicion' => $pos,
                'sexo' => $sexo,
                'evidencia' => trim((string) ($r['evidencia'] ?? '')),
                'codMortalidad' => trim((string) ($r['tcod_mortgrs'] ?? '')),
            ];
        }
    }

    // Clave grupo+sexo: macho y hembra usan idmovi distintos (PK en movi_zonas).
    $grupoSexoKey = static function (string $grupo, string $sexo, string $fallback = ''): string {
        $g = $grupo !== '' ? $grupo : ($fallback !== '' ? $fallback : 'auto');
        $s = strtoupper(substr(trim($sexo), 0, 1));
        if ($s !== 'M' && $s !== 'H') {
            $s = 'M';
        }

        return $g . '_' . $s;
    };
    $reservarIdmovi = static function () use (&$maxPos, &$posUsadas): int {
        do {
            $maxPos++;
        } while (isset($posUsadas[$maxPos]));
        $posUsadas[$maxPos] = true;

        return $maxPos;
    };

    $grupoAPos = [];
    foreach ($detalleIn as $det) {
        if (!is_array($det)) {
            continue;
        }
        $cantPre = (int) ($det['cantidad'] ?? 0);
        if ($cantPre < 1) {
            continue;
        }
        $detId = trim((string) ($det['id'] ?? ''));
        $grupo = trim((string) ($det['grupo'] ?? ''));
        $sexoPre = strtoupper(substr(trim((string) ($det['sexo'] ?? 'M')), 0, 1));
        if ($sexoPre !== 'M' && $sexoPre !== 'H') {
            $sexoPre = 'M';
        }
        $keyGS = $grupoSexoKey($grupo, $sexoPre, $detId);
        if ($detId !== '' && isset($detIndex[$detId])) {
            $grupoAPos[$keyGS] = (int) ($detIndex[$detId]['posicion'] ?? 0);
            $posUsadas[(int) ($detIndex[$detId]['posicion'] ?? 0)] = true;
        } elseif (!isset($grupoAPos[$keyGS]) || (int) $grupoAPos[$keyGS] <= 0) {
            $grupoAPos[$keyGS] = $reservarIdmovi();
        }
    }

    $idsEnviados = [];
    $lineasZonas = [];

    foreach ($detalleIn as $det) {
        if (!is_array($det)) {
            continue;
        }
        $cantidad = (int) ($det['cantidad'] ?? 0);
        if ($cantidad < 1) {
            continue;
        }

        $detId = trim((string) ($det['id'] ?? ''));
        $sexo = strtoupper(substr(trim((string) ($det['sexo'] ?? 'M')), 0, 1));
        if ($sexo !== 'M' && $sexo !== 'H') {
            $sexo = 'M';
        }
        $grupo = trim((string) ($det['grupo'] ?? ''));
        $fileKey = trim((string) ($det['fileKey'] ?? $detId));
        if ($fileKey === '') {
            $fileKey = 'tmp_' . $grupo . '_' . $sexo;
        }

        $codMortalidad = trim((string) ($det['codMortalidad'] ?? ''));
        $observacion = trim((string) ($det['observacion'] ?? ''));
        if ($muestraCausa && $codMortalidad === '') {
            throw new RuntimeException('La causa es obligatoria en lineas con cantidad.');
        }
        if (!$muestraCausa) {
            // conservar si ya existe; si es nueva queda vacia
            if ($detId !== '' && isset($detIndex[$detId])) {
                $codMortalidad = $detIndex[$detId]['codMortalidad'];
            } else {
                $codMortalidad = '';
            }
        }

        $evidKeep = $det['evidenciaKeep'] ?? [];
        if (!is_array($evidKeep)) {
            $evidKeep = [];
        }
        $keepNorm = [];
        foreach ($evidKeep as $p) {
            $rel = mort_listado_normalize_evidencia_rel((string) $p);
            if ($rel !== '' && strpos($rel, 'uploads/mortalidad/') === 0) {
                $keepNorm[] = $rel;
            }
        }

        $esNuevo = ($detId === '' || !isset($detIndex[$detId]));
        $sexoAnterior = $sexo;
        $posicion = 0;

        if (!$esNuevo) {
            $sexoAnterior = $detIndex[$detId]['sexo'];
            $posicion = (int) $detIndex[$detId]['posicion'];

            $prevEvid = $detIndex[$detId]['evidencia'];
            $prevRutas = $prevEvid !== '' ? (preg_split('/\s*,\s*/', $prevEvid) ?: []) : [];
            $prevNorm = [];
            foreach ($prevRutas as $p) {
                $rel = mort_listado_normalize_evidencia_rel((string) $p);
                if ($rel !== '') {
                    $prevNorm[] = $rel;
                }
            }
            $eliminadas = array_values(array_diff($prevNorm, $keepNorm));
            mort_listado_borrar_evidencias_fs($eliminadas);

            $nuevas = mort_listado_procesar_imagenes_edit($fileKey, $granja, $galpon, $numero, $fechaYmd, $sexo);
            $todas = array_values(array_unique(array_filter(array_merge($keepNorm, $nuevas))));
            $evidenciaCsv = $todas !== [] ? implode(',', $todas) : null;
        } else {
            $keyG = $grupo !== '' ? $grupo : ('auto_' . uniqid('', true));
            $keyGS = $grupoSexoKey($grupo, $sexo, $keyG);
            $posicion = (int) ($grupoAPos[$keyGS] ?? 0);
            if ($posicion <= 0) {
                $posicion = $reservarIdmovi();
                $grupoAPos[$keyGS] = $posicion;
            } else {
                $posUsadas[$posicion] = true;
            }

            $detId = sprintf('%s-%02d-%s', $id, $posicion, $sexo);
            // evitar colision
            if (isset($detIndex[$detId])) {
                $detId = $id . '-' . $posicion . '-' . $sexo . '-' . substr(uniqid(), -4);
            }

            $nuevas = mort_listado_procesar_imagenes_edit($fileKey, $granja, $galpon, $numero, $fechaYmd, $sexo);
            $todas = array_values(array_unique(array_filter(array_merge($keepNorm, $nuevas))));
            $evidenciaCsv = $todas !== [] ? implode(',', $todas) : null;
        }

        // Etapas del proceso de despacho (mapa proceso_etapas con columnas camelCase).
        $etapasLinea = [];
        if ($tipoMortalidad === 'despacho') {
            $etRaw = is_array($det['proceso_etapas'] ?? null) ? $det['proceso_etapas'] : [];
            foreach (MORT_FACT_ETAPA_COLS as $col) {
                $etapasLinea[$col] = max(0, (int) ($etRaw[$col] ?? 0));
            }
            $sumaEtapas = array_sum($etapasLinea);
            if ($sumaEtapas !== $cantidad) {
                throw new RuntimeException(
                    'La suma de etapas (' . $sumaEtapas . ') no coincide con la cantidad (' . $cantidad
                    . ') en la línea posicion ' . $posicion . ' sexo ' . $sexo . '.'
                );
            }
        }

        $idsEnviados[$detId] = true;
        $lineasZonas[] = [
            'id' => $detId,
            'posicion' => $posicion,
            'sexo' => $sexo,
            'sexoAnterior' => $sexoAnterior,
            'cantidad' => $cantidad,
            'codMortalidad' => $codMortalidad !== '' ? $codMortalidad : null,
            'evidencia' => $evidenciaCsv ?? '',
            'observacion' => $observacion,
            'proceso_etapas' => $etapasLinea,
        ];
    }

    // Eliminar registros que existian y ya no vienen (cantidad 0 / quitadas).
    foreach ($detIndex as $detId => $info) {
        if ($detId === '' || isset($idsEnviados[$detId])) {
            continue;
        }
        $posDel = (int) $info['posicion'];
        $sexoDel = strtoupper(substr(trim((string) $info['sexo']), 0, 1));
        $tcod = $sexoDel === 'H' ? 'P0001002' : 'P0001001';
        $tcodEsc = mysqli_real_escape_string($conn, $tcod);

        $parts = preg_split('/\s*,\s*/', trim((string) $info['evidencia'])) ?: [];
        mort_listado_borrar_evidencias_fs($parts);

        mysqli_query($conn, "
            DELETE mz FROM movi_zonas mz
            INNER JOIN cabe_zonas cz
                ON cz.mark = mz.mark AND cz.treg = mz.treg
                AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
            WHERE cz.external_id = '{$idEsc}'
              AND mz.idmovi = {$posDel}
              AND mz.tcodigo = '{$tcodEsc}'
        ");
    }

    $usuarioEspejo = trim((string) ($cab['usuarioRegistro'] ?? ''));
    if ($usuarioEspejo === '') {
        $usuarioEspejo = $codUsuario;
    }
    $fechaHoraEspejo = trim((string) ($cab['fechaHoraRegistro'] ?? ''));
    if ($fechaHoraEspejo === '') {
        $fechaHoraEspejo = date('Y-m-d H:i:s');
    }

    if ($pasaAMortalidad) {
        mort_zonas_registrar(
            $conn,
            $id,
            $docGuardado,
            $serieGuardada,
            $numeroGuardado,
            $tipoMortalidad,
            $subtipoTransporte !== null ? (string) $subtipoTransporte : null,
            $subtipoProduccion !== null ? (string) $subtipoProduccion : null,
            $granja,
            $campania,
            $galpon,
            $fechaRegistro,
            $fechaLlegada,
            $usuarioEspejo,
            $fechaHoraEspejo,
            'web_edit',
            $lineasZonas,
            $observaciones
        );
    } elseif ($existeEnZonas && $lineasZonas !== []) {
        mort_zonas_actualizar_desde_fact(
            $conn,
            $id,
            $tipoMortalidad,
            $subtipoTransporte !== null ? (string) $subtipoTransporte : null,
            $subtipoProduccion !== null ? (string) $subtipoProduccion : null,
            $granja,
            $campania,
            $galpon,
            $fechaRegistro,
            $fechaLlegada,
            $docGuardado,
            $serieGuardada,
            $numeroGuardado,
            $usuarioEspejo,
            $fechaHoraEspejo,
            $lineasZonas,
            $observaciones,
            false,
            false
        );
    } elseif ($existeEnZonas && $esRegistroCero) {
        if (MORT_EDITAR_A_CERO === 'A') {
            // Conservar cabe_zonas con tcanttot=0 y sin filas en movi_zonas.
            $fechasCero = mort_zonas_fechas_cabe($tipoMortalidad, $fechaRegistro, $fechaLlegada);
            $tfectraCeroEsc = mysqli_real_escape_string($conn, $fechasCero['tfectra']);
            $tfecremCeroEsc = mysqli_real_escape_string($conn, $fechasCero['tfecrem']);
            $obsCeroEsc = mysqli_real_escape_string($conn, $observaciones);
            $markCeroEsc = mysqli_real_escape_string($conn, mort_zonas_mark($tipoMortalidad));
            $okCero = mysqli_query($conn, "
                UPDATE cabe_zonas
                SET tcanttot = 0,
                    tfectra = '{$tfectraCeroEsc}',
                    tfecrem = '{$tfecremCeroEsc}',
                    observaciones = '{$obsCeroEsc}'
                WHERE external_id = '{$idEsc}'
                  AND mark = '{$markCeroEsc}'
                LIMIT 1
            ");
            if (!$okCero) {
                throw new RuntimeException('Error al actualizar cabe_zonas: ' . mysqli_error($conn));
            }
        } else {
            // Opción B: eliminar cabe_zonas/movi_zonas y liberar el documento.
            $okDelMz = mysqli_query($conn, "
                DELETE mz FROM movi_zonas mz
                INNER JOIN cabe_zonas cz
                    ON cz.mark = mz.mark AND cz.treg = mz.treg
                    AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
                WHERE cz.external_id = '{$idEsc}'
            ");
            if (!$okDelMz) {
                throw new RuntimeException('Error al eliminar movi_zonas: ' . mysqli_error($conn));
            }
            $okDelCz = mysqli_query($conn, "DELETE FROM cabe_zonas WHERE external_id = '{$idEsc}'");
            if (!$okDelCz) {
                throw new RuntimeException('Error al eliminar cabe_zonas: ' . mysqli_error($conn));
            }
        }
    }

    // Espejo auxiliar san_fact_mortalidad_cab/det (siempre, incluido el caso 0).
    $docEspejo = $docGuardado;
    $serieEspejo = $serieGuardada;
    $numeroEspejo = $numeroGuardado;
    if ($existeEnZonas && $esRegistroCero && MORT_EDITAR_A_CERO === 'B') {
        $docEspejo = '';
        $serieEspejo = '';
        $numeroEspejo = '';
    }
    mort_fact_espejo_guardar($conn, [
        'id' => $id,
        'doc' => $docEspejo,
        'serie' => $serieEspejo,
        'numero' => $numeroEspejo,
        'tipoMortalidad' => $tipoMortalidad,
        'subtipoTransporte' => $subtipoTransporte,
        'subtipoProduccion' => $subtipoProduccion,
        'granja' => $granja,
        'campania' => $campania,
        'granjaNombre' => (string) ($cab['granjaNombre'] ?? ''),
        'galpon' => $galpon,
        'fechaRegistro' => $fechaRegistro,
        'fechaLlegada' => $fechaLlegada,
        'observaciones' => $observaciones,
        'usuarioRegistro' => $usuarioEspejo,
        'fechaHoraRegistro' => $fechaHoraEspejo,
    ], $lineasZonas);

    $datosNuevos = mort_listado_snapshot_desde_zonas($conn, $id);
    $datosNuevosJson = json_encode($datosNuevos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    mysqli_commit($conn);
    mysqli_close($conn);

    try {
        require_once __DIR__ . '/../historial/mortalidad_historial_lib.php';
        $desc = mort_hist_descripcion_amigable(
            'EDICION_MORTALIDAD',
            $datosNuevos ?: $datosPrevios
        );
        registrarAccionCRUD(
            'EDICION_MORTALIDAD',
            $codUsuario,
            $nomUsuario,
            'cabe_zonas',
            $id,
            $datosPreviosJson !== false ? $datosPreviosJson : null,
            $datosNuevosJson !== false ? $datosNuevosJson : null,
            $desc
        );
    } catch (Throwable $eHist) {
        error_log('Error al registrar historial de edición mortalidad: ' . $eHist->getMessage());
    }

    mort_admin_json_ok([
        'message' => 'Registro actualizado correctamente.',
        'historial_guardado' => true,
    ]);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    mysqli_close($conn);
    mort_admin_json_error($e->getMessage(), 500);
}
