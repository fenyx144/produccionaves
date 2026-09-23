<?php

declare(strict_types=1);

/** Columnas camelCase de las etapas del despacho en san_fact_mortalidad_det. */
const MORT_FACT_ETAPA_COLS = [
    'procesoPreparar',
    'procesoAcorralar',
    'procesoSeleccionar',
    'procesoEnjabar',
    'procesoPesar',
    'procesoEstibar',
];

/**
 * Etapas de un detalle desde el mapa proceso_etapas (claves camelCase).
 *
 * @param array<string, mixed> $det
 * @return array<string, int> Valor 0 para etapas sin registrar.
 */
function mort_fact_normalizar_etapas(array $det): array
{
    $fuente = $det['proceso_etapas'] ?? [];
    if (!is_array($fuente)) {
        $fuente = [];
    }
    $out = [];
    foreach (MORT_FACT_ETAPA_COLS as $col) {
        $out[$col] = max(0, (int) ($fuente[$col] ?? 0));
    }

    return $out;
}

/** Las tablas auxiliares san_fact_mortalidad_cab/det existen. */
function mort_fact_tablas_disponibles(mysqli $conn): bool
{
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $resCab = @$conn->query("SHOW TABLES LIKE 'san_fact_mortalidad_cab'");
    $resDet = @$conn->query("SHOW TABLES LIKE 'san_fact_mortalidad_det'");
    $cache[$key] = $resCab && $resCab->num_rows > 0 && $resDet && $resDet->num_rows > 0;

    return $cache[$key];
}

/**
 * Columnas de etapa presentes en san_fact_mortalidad_det.
 * Vacío si aún no se aplicó la migración de columnas proceso_*.
 *
 * @return list<string>
 */
function mort_fact_det_columnas_etapas(mysqli $conn): array
{
    static $cache = [];
    $key = spl_object_hash($conn);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $cols = [];
    if (mort_fact_tablas_disponibles($conn)) {
        $res = @$conn->query('SHOW COLUMNS FROM san_fact_mortalidad_det');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $name = (string) ($row['Field'] ?? '');
                if (in_array($name, MORT_FACT_ETAPA_COLS, true)) {
                    $cols[] = $name;
                }
            }
        }
    }
    $cache[$key] = $cols;

    return $cache[$key];
}

/** La cabecera auxiliar ya existe por id (uuid de la app). */
function mort_fact_cab_existe(mysqli $conn, string $cabId): bool
{
    if (!mort_fact_tablas_disponibles($conn) || trim($cabId) === '') {
        return false;
    }
    $stmt = $conn->prepare('SELECT 1 FROM san_fact_mortalidad_cab WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $cabId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function mort_fact_fecha_ymd(string $fecha): string
{
    $fecha = trim($fecha);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $fecha, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $fecha, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    if (preg_match('/^(\d{4})\/(\d{2})\/(\d{2})/', $fecha, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }

    return date('Y-m-d');
}

function mort_fact_datetime(string $fechaHora): string
{
    $fh = trim($fechaHora);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}(?::\d{2})?)/', $fh, $m)) {
        $hora = strlen($m[2]) === 5 ? $m[2] . ':00' : $m[2];

        return $m[1] . ' ' . $hora;
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})$/', $fh, $m)) {
        return $m[1] . ' 00:00:00';
    }

    return date('Y-m-d H:i:s');
}

/**
 * Nombre del motivo desde regmotivo_mortalidadgrs (tcod_mort = código que viaja en la app).
 */
function mort_fact_nom_mortalidad(mysqli $conn, ?string $codMortalidad): string
{
    $cod = trim((string) $codMortalidad);
    if ($cod === '') {
        return '';
    }
    static $cache = [];
    if (array_key_exists($cod, $cache)) {
        return $cache[$cod];
    }
    $stmt = $conn->prepare('SELECT tnom_mort FROM regmotivo_mortalidadgrs WHERE tcod_mort = ? LIMIT 1');
    $nombre = '';
    if ($stmt) {
        $stmt->bind_param('s', $cod);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        $nombre = trim((string) ($row['tnom_mort'] ?? ''));
    }
    $cache[$cod] = $nombre;

    return $nombre;
}

/**
 * Upsert de cabecera en san_fact_mortalidad_cab.
 *
 * @param array<string, mixed> $cab Claves: id, doc, serie, numero, tipoMortalidad,
 *                                  subtipoTransporte, subtipoProduccion, granja, campania,
 *                                  granjaNombre, galpon, fechaRegistro, fechaLlegada,
 *                                  observaciones, usuarioRegistro, fechaHoraRegistro.
 */
function mort_fact_cab_upsert(mysqli $conn, array $cab): void
{
    if (!mort_fact_tablas_disponibles($conn)) {
        return;
    }
    $cabId = trim((string) ($cab['id'] ?? ''));
    if ($cabId === '') {
        return;
    }

    $doc = substr(trim((string) ($cab['doc'] ?? '')), 0, 2);
    $serie = substr(trim((string) ($cab['serie'] ?? '')), 0, 12);
    $numero = substr(trim((string) ($cab['numero'] ?? '')), 0, 8);
    $tipo = strtolower(trim((string) ($cab['tipoMortalidad'] ?? '')));
    if (!in_array($tipo, ['incubacion', 'transporte', 'produccion', 'despacho'], true)) {
        $tipo = 'produccion';
    }
    $subTransporte = trim((string) ($cab['subtipoTransporte'] ?? ''));
    $subTransporte = $subTransporte !== '' ? substr($subTransporte, 0, 20) : null;
    $subProduccion = trim((string) ($cab['subtipoProduccion'] ?? ''));
    $subProduccion = $subProduccion !== '' ? substr($subProduccion, 0, 30) : null;
    $granja = substr(mort_normalizar_granja3((string) ($cab['granja'] ?? '')), 0, 3);
    $campania = substr(str_pad(preg_replace('/\D/', '', (string) ($cab['campania'] ?? '')) ?? '', 3, '0', STR_PAD_LEFT), 0, 3);
    $granjaNombre = trim((string) ($cab['granjaNombre'] ?? ''));
    $granjaNombre = $granjaNombre !== '' ? substr($granjaNombre, 0, 120) : null;
    $galpon = substr(trim((string) ($cab['galpon'] ?? '')), 0, 20);
    $fechaRegistro = mort_fact_fecha_ymd((string) ($cab['fechaRegistro'] ?? ''));
    $fechaLlegadaRaw = trim((string) ($cab['fechaLlegada'] ?? ''));
    $fechaLlegada = $fechaLlegadaRaw !== '' ? mort_fact_fecha_ymd($fechaLlegadaRaw) : null;
    $observaciones = trim((string) ($cab['observaciones'] ?? ''));
    $observaciones = $observaciones !== '' ? $observaciones : null;
    $usuario = trim((string) ($cab['usuarioRegistro'] ?? ''));
    $usuario = $usuario !== '' ? substr($usuario, 0, 50) : null;
    $fechaHora = mort_fact_datetime((string) ($cab['fechaHoraRegistro'] ?? ''));

    if (mort_fact_cab_existe($conn, $cabId)) {
        $sql = 'UPDATE san_fact_mortalidad_cab SET
                    doc = ?, serie = ?, numero = ?, tipoMortalidad = ?,
                    subtipoTransporte = ?, subtipoProduccion = ?, granja = ?, campania = ?,
                    granjaNombre = ?, galpon = ?, fechaRegistro = ?, fechaLlegada = ?,
                    observaciones = ?, usuarioRegistro = ?, fechaHoraRegistro = ?
                WHERE id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error prepare update san_fact_mortalidad_cab: ' . $conn->error);
        }
        $stmt->bind_param(
            'ssssssssssssssss',
            $doc,
            $serie,
            $numero,
            $tipo,
            $subTransporte,
            $subProduccion,
            $granja,
            $campania,
            $granjaNombre,
            $galpon,
            $fechaRegistro,
            $fechaLlegada,
            $observaciones,
            $usuario,
            $fechaHora,
            $cabId
        );
    } else {
        $sql = 'INSERT INTO san_fact_mortalidad_cab (
                    id, doc, serie, numero, tipoMortalidad, subtipoTransporte,
                    subtipoProduccion, granja, campania, granjaNombre, galpon,
                    fechaRegistro, fechaLlegada, observaciones, usuarioRegistro,
                    fechaHoraRegistro
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error prepare insert san_fact_mortalidad_cab: ' . $conn->error);
        }
        $stmt->bind_param(
            'ssssssssssssssss',
            $cabId,
            $doc,
            $serie,
            $numero,
            $tipo,
            $subTransporte,
            $subProduccion,
            $granja,
            $campania,
            $granjaNombre,
            $galpon,
            $fechaRegistro,
            $fechaLlegada,
            $observaciones,
            $usuario,
            $fechaHora
        );
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error upsert san_fact_mortalidad_cab: ' . $err);
    }
    $stmt->close();
}

/** Id determinístico del detalle auxiliar (32 hex, cabe en char(36)). */
function mort_fact_det_id(string $cabId, int $posicion, string $sexo): string
{
    return 'd' . md5($cabId . '|' . $posicion . '|' . strtoupper($sexo));
}

/**
 * Upsert de una línea en san_fact_mortalidad_det.
 *
 * @param array<string, mixed> $det Claves: posicion, sexo, cantidad, codMortalidad,
 *                                  nomMortalidad, observacion, evidencia, proceso_etapas
 *                                  (mapa columna camelCase → int, solo valores >= 0).
 */
function mort_fact_det_upsert(mysqli $conn, string $cabId, array $det): void
{
    if (!mort_fact_tablas_disponibles($conn) || trim($cabId) === '') {
        return;
    }
    $posicion = max(1, (int) ($det['posicion'] ?? 1));
    $sexo = strtoupper(substr(trim((string) ($det['sexo'] ?? 'M')), 0, 1));
    if ($sexo !== 'M' && $sexo !== 'H') {
        $sexo = 'M';
    }
    $detId = mort_fact_det_id($cabId, $posicion, $sexo);

    $cantidad = max(0, (int) ($det['cantidad'] ?? 0));
    $codMortalidad = trim((string) ($det['codMortalidad'] ?? ''));
    $codMortalidad = $codMortalidad !== '' ? substr($codMortalidad, 0, 20) : null;
    $nomMortalidad = trim((string) ($det['nomMortalidad'] ?? ''));
    if ($nomMortalidad === '' && $codMortalidad !== null) {
        $nomMortalidad = mort_fact_nom_mortalidad($conn, $codMortalidad);
    }
    $nomMortalidad = $nomMortalidad !== '' ? substr($nomMortalidad, 0, 200) : null;
    $observacion = trim((string) ($det['observacion'] ?? ''));
    $observacion = $observacion !== '' ? $observacion : null;
    $evidencia = trim((string) ($det['evidencia'] ?? ''));
    $evidencia = $evidencia !== '' ? $evidencia : null;

    $etapasNorm = mort_fact_normalizar_etapas($det);
    $etapaCols = mort_fact_det_columnas_etapas($conn);
    $valoresEtapa = [];
    $sqlEtapaSet = '';
    $sqlEtapaCols = '';
    $sqlEtapaMarkers = '';
    $typesEtapa = '';
    foreach ($etapaCols as $col) {
        $valoresEtapa[] = $etapasNorm[$col] ?? 0;
        $typesEtapa .= 'i';
        $sqlEtapaSet .= ', ' . $col . ' = ?';
        $sqlEtapaCols .= ', ' . $col;
        $sqlEtapaMarkers .= ', ?';
    }

    $detIdEsc = mysqli_real_escape_string($conn, $detId);
    $qExiste = mysqli_query($conn, "SELECT 1 FROM san_fact_mortalidad_det WHERE id = '{$detIdEsc}' LIMIT 1");
    $existe = $qExiste && mysqli_num_rows($qExiste) > 0;

    if ($existe) {
        $sql = 'UPDATE san_fact_mortalidad_det SET
                    posicion = ?, sexo = ?, cantidad = ?, codMortalidad = ?,
                    nomMortalidad = ?, observacion = ?, evidencia = ?' . $sqlEtapaSet . '
                WHERE id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error prepare update san_fact_mortalidad_det: ' . $conn->error);
        }
        $types = 'isissss' . $typesEtapa . 's';
        $params = [$posicion, $sexo, $cantidad, $codMortalidad, $nomMortalidad, $observacion, $evidencia];
        foreach ($valoresEtapa as $v) {
            $params[] = $v;
        }
        $params[] = $detId;
        $stmt->bind_param($types, ...$params);
    } else {
        $sql = 'INSERT INTO san_fact_mortalidad_det (
                    id, cabId, posicion, sexo, cantidad, codMortalidad,
                    nomMortalidad, observacion, evidencia' . $sqlEtapaCols . '
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?' . $sqlEtapaMarkers . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Error prepare insert san_fact_mortalidad_det: ' . $conn->error);
        }
        $types = 'ssissssss' . $typesEtapa;
        $params = [$detId, $cabId, $posicion, $sexo, $cantidad, $codMortalidad, $nomMortalidad, $observacion, $evidencia];
        foreach ($valoresEtapa as $v) {
            $params[] = $v;
        }
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        throw new RuntimeException('Error upsert san_fact_mortalidad_det: ' . $err);
    }
    $stmt->close();
}

/**
 * Borra las líneas auxiliares de una cabecera que no estén en $mantener.
 *
 * @param list<string> $mantener Claves "posicion-sexo" que se conservan.
 */
function mort_fact_det_borrar_por_cab(mysqli $conn, string $cabId, array $mantener = []): void
{
    if (!mort_fact_tablas_disponibles($conn) || trim($cabId) === '') {
        return;
    }
    $cabIdEsc = mysqli_real_escape_string($conn, $cabId);
    if ($mantener === []) {
        mysqli_query($conn, "DELETE FROM san_fact_mortalidad_det WHERE cabId = '{$cabIdEsc}'");
        return;
    }
    $keys = [];
    foreach ($mantener as $k) {
        if (preg_match('/^\d+-[MH]$/', trim((string) $k))) {
            $keys[] = "'" . mysqli_real_escape_string($conn, (string) $k) . "'";
        }
    }
    if ($keys !== []) {
        $in = implode(',', $keys);
        mysqli_query(
            $conn,
            "DELETE FROM san_fact_mortalidad_det
             WHERE cabId = '{$cabIdEsc}'
               AND CONCAT(CAST(posicion AS CHAR), '-', sexo) NOT IN ({$in})"
        );
    }
}

/**
 * Escribe cabecera y detalle auxiliar completos desde los datos de zonas.
 *
 * @param array<string, mixed> $cab Datos de cabecera (ver mort_fact_cab_upsert).
 * @param list<array<string, mixed>> $lineas Líneas con posicion, sexo, cantidad,
 *                                           codMortalidad, nomMortalidad, observacion,
 *                                           evidencia y proceso_etapas.
 */
function mort_fact_espejo_guardar(mysqli $conn, array $cab, array $lineas): void
{
    if (!mort_fact_tablas_disponibles($conn)) {
        return;
    }
    $cabId = trim((string) ($cab['id'] ?? ''));
    if ($cabId === '') {
        return;
    }
    mort_fact_cab_upsert($conn, $cab);

    $mantener = [];
    $lineasValidas = [];
    foreach ($lineas as $ln) {
        if (!is_array($ln)) {
            continue;
        }
        $sexo = strtoupper(substr(trim((string) ($ln['sexo'] ?? 'M')), 0, 1));
        if ($sexo !== 'M' && $sexo !== 'H') {
            continue;
        }
        $pos = max(1, (int) ($ln['posicion'] ?? 1));
        $mantener[] = $pos . '-' . $sexo;
        $lineasValidas[] = $ln;
    }
    mort_fact_det_borrar_por_cab($conn, $cabId, $mantener);

    foreach ($lineasValidas as $ln) {
        mort_fact_det_upsert($conn, $cabId, $ln);
    }
}

/**
 * Etapas por cabecera indexadas por "posicion-sexo".
 *
 * @return array<string, array<string, int>> Valor 0 para etapas sin registrar.
 */
function mort_fact_etapas_por_cab(mysqli $conn, string $cabId): array
{
    if (trim($cabId) === '') {
        return [];
    }
    $map = mort_fact_etapas_por_cabs($conn, [$cabId]);

    return $map[$cabId] ?? [];
}

/**
 * Etapas de varias cabeceras: [cabId => ["posicion-sexo" => etapas]].
 *
 * @param list<string> $cabIds
 * @return array<string, array<string, array<string, int>>>
 */
function mort_fact_etapas_por_cabs(mysqli $conn, array $cabIds): array
{
    $out = [];
    if (!mort_fact_tablas_disponibles($conn) || $cabIds === []) {
        return $out;
    }
    $cols = mort_fact_det_columnas_etapas($conn);
    if ($cols === []) {
        return $out;
    }
    $escaped = [];
    foreach ($cabIds as $id) {
        $t = trim((string) $id);
        if ($t !== '') {
            $escaped[] = "'" . mysqli_real_escape_string($conn, $t) . "'";
        }
    }
    if ($escaped === []) {
        return $out;
    }
    $in = implode(',', $escaped);
    $selectCols = implode(', ', $cols);
    $res = mysqli_query(
        $conn,
        "SELECT cabId, posicion, sexo, {$selectCols}
         FROM san_fact_mortalidad_det
         WHERE cabId IN ({$in})"
    );
    if (!$res) {
        return $out;
    }
    while ($row = $res->fetch_assoc()) {
        $cabId = (string) ($row['cabId'] ?? '');
        $pos = (int) ($row['posicion'] ?? 0);
        $sexo = strtoupper(substr(trim((string) ($row['sexo'] ?? '')), 0, 1));
        if ($cabId === '' || $pos <= 0 || ($sexo !== 'M' && $sexo !== 'H')) {
            continue;
        }
        $etapas = [];
        foreach ($cols as $col) {
            $etapas[$col] = (int) ($row[$col] ?? 0);
        }
        $out[$cabId][$pos . '-' . $sexo] = $etapas;
    }

    return $out;
}

/**
 * Cabecera auxiliar cruda por id.
 *
 * @return array<string, mixed>|null
 */
function mort_fact_cab_por_id(mysqli $conn, string $cabId): ?array
{
    if (!mort_fact_tablas_disponibles($conn) || trim($cabId) === '') {
        return null;
    }
    $stmt = $conn->prepare('SELECT * FROM san_fact_mortalidad_cab WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $cabId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}
