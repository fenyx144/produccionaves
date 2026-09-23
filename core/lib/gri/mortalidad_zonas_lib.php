<?php

declare(strict_types=1);

require_once __DIR__ . '/mortalidad_doc_lib.php';

const MORT_ZONAS_IDCIA = 'RS';
const MORT_ZONAS_TPROCLI = '20419158462';
/** Transacción GRI mortalidad — valor fijo por defecto. */
const MORT_ZONAS_TCODTRA = 'S808';
const MORT_ZONAS_TMON = 'S/.';
const MORT_ZONAS_TLIB = 'AL';
const MORT_ZONAS_TALM = '010';
const MORT_ZONAS_TGRE = 'N';

function mort_zonas_tcodtra(?string $value = null): string
{
    $v = strtoupper(trim((string) $value));

    return $v !== '' ? $v : MORT_ZONAS_TCODTRA;
}

/**
 * @return array<string, string>
 */
function mort_zonas_marks(): array
{
    return [
        'incubacion' => 'JI1',
        'transporte' => 'JT2',
        'produccion' => 'JP3',
        'despacho' => 'JD4',
    ];
}

function mort_zonas_mark(string $tipoMortalidad): string
{
    $tipo = strtolower(trim($tipoMortalidad));
    $marks = mort_zonas_marks();
    if (!isset($marks[$tipo])) {
        throw new InvalidArgumentException('tipoMortalidad inválido para zonas: ' . $tipoMortalidad);
    }

    return $marks[$tipo];
}

function mort_zonas_glosa(string $tipoMortalidad): string
{
    static $labels = [
        'incubacion' => 'Mortalidad de Planta de Incubación',
        'transporte' => 'Mortalidad de Transporte',
        'produccion' => 'Mortalidad de Producción',
        'despacho' => 'Mortalidad de Despacho',
    ];
    $tipo = strtolower(trim($tipoMortalidad));

    return $labels[$tipo] ?? 'Mortalidad';
}

function mort_zonas_tcategoria_label(string $tipoMortalidad): string
{
    static $map = [
        'incubacion' => 'PlantaIncubacion',
        'transporte' => 'Transporte',
        'produccion' => 'Produccion',
        'despacho' => 'Despacho',
    ];
    $tipo = strtolower(trim($tipoMortalidad));

    return $map[$tipo] ?? 'Produccion';
}

/** Columna movi_zonas.tcategoria ahora almacena el label completo. */
function mort_zonas_tcategoria(string $tipoMortalidad): string
{
    return mort_zonas_tcategoria_label($tipoMortalidad);
}

function mort_zonas_flujo(string $tipoMortalidad, ?string $subtipoTransporte, ?string $subtipoProduccion): string
{
    $tipo = strtolower(trim($tipoMortalidad));
    if ($tipo === 'incubacion') {
        return 'PlantaIncubacion';
    }
    if ($tipo === 'despacho') {
        return 'Despacho';
    }
    if ($tipo === 'transporte') {
        $st = strtolower(trim((string) $subtipoTransporte));
        if ($st === 'evento') {
            return 'Evento';
        }

        return 'Transporte';
    }
    if ($tipo === 'produccion') {
        static $map = [
            'crianza' => 'Crianza',
            'necropsia' => 'Necropsia',
            'laboratorio' => 'Laboratorio',
            'cuarentena' => 'Cuarentena',
        ];
        $origen = strtolower(trim((string) $subtipoProduccion));

        return $map[$origen] ?? 'Crianza';
    }

    return 'Crianza';
}

/**
 * Serie GI (ej. 621-1) → tserie cabe/movi (4 chars, sin guión).
 */
function mort_zonas_tserie(string $serieGi): string
{
    $s = strtoupper(str_replace(['-', ' '], '', trim($serieGi)));

    return substr($s, 0, 4);
}

/**
 * tnumfac = tserie (4 chars) + correlativo (7 chars) = 11 caracteres en total.
 */
function mort_zonas_tnumfac(string $serie, string $numeroGi): float
{
    $tserie = mort_zonas_tserie($serie);
    $num = preg_replace('/\D/', '', $numeroGi) ?? '';
    $num = str_pad($num, 7, '0', STR_PAD_LEFT);

    return (float) ($tserie . $num);
}

/**
 * @return array{tfectra: string, tfecrem: string}
 */
function mort_zonas_fechas_cabe(string $tipoMortalidad, string $fechaRegistro, ?string $fechaLlegada): array
{
    $tipo = strtolower(trim($tipoMortalidad));
    $fecReg = mort_zonas_normalizar_fecha($fechaRegistro);
    if ($tipo === 'incubacion') {
        $fecLleg = mort_zonas_normalizar_fecha((string) ($fechaLlegada ?? ''));
        if ($fecLleg === '') {
            $fecLleg = $fecReg;
        }

        return ['tfectra' => $fecLleg, 'tfecrem' => $fecReg];
    }

    return ['tfectra' => $fecReg, 'tfecrem' => $fecReg];
}

function mort_zonas_normalizar_fecha(string $fecha): string
{
    $fecha = trim($fecha);
    if ($fecha === '') {
        return date('Y-m-d');
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $fecha, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $fecha, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }

    return date('Y-m-d');
}

/**
 * @return array{tdate: string, ttime: string}
 */
function mort_zonas_fecha_hora(string $fechaHoraRegistro, string $fechaRegistro): array
{
    $fh = trim($fechaHoraRegistro);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}:\d{2})/', $fh, $m)) {
        return ['tdate' => $m[1], 'ttime' => $m[2]];
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $fh, $m)) {
        return ['tdate' => $m[1], 'ttime' => date('H:i:s')];
    }

    return [
        'tdate' => mort_zonas_normalizar_fecha($fechaRegistro),
        'ttime' => date('H:i:s'),
    ];
}

function mort_zonas_cabe_existe_por_external(mysqli $conn, string $externalId): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM cabe_zonas WHERE external_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $externalId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ok = $res && $res->num_rows > 0;
    $stmt->close();

    return $ok;
}

function mort_zonas_siguiente_correlativo(mysqli $conn, string $mark, string $campo): int
{
    if (!in_array($campo, ['treg', 'tnumreg'], true)) {
        throw new InvalidArgumentException('Campo correlativo inválido');
    }

    $markEsc = mysqli_real_escape_string($conn, $mark);
    $maxVal = 0;

    foreach (['cabe_zonas', 'movi_zonas'] as $tabla) {
        $sql = "SELECT MAX(`{$campo}`) AS num FROM `{$tabla}` WHERE mark = '{$markEsc}'";
        $res = mysqli_query($conn, $sql);
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $maxVal = max($maxVal, (int) ($row['num'] ?? 0));
        }
    }

    return $maxVal + 1;
}

/**
 * @return array{tcod_mortgrs: string, tcod_mort: string}
 */
function mort_zonas_motivo_codigos(mysqli $conn, ?string $codMortalidad): array
{
    $def = ['tcod_mortgrs' => '', 'tcod_mort' => ''];
    $cod = trim((string) $codMortalidad);
    if ($cod === '') {
        return $def;
    }

    $stmt = $conn->prepare(
        'SELECT tcod_mort, tsanfer FROM regmotivo_mortalidadgrs WHERE tcod_mort = ? LIMIT 1'
    );
    if (!$stmt) {
        return $def;
    }
    $stmt->bind_param('s', $cod);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) {
        return $def;
    }

    return [
        'tcod_mortgrs' => trim((string) ($row['tcod_mort'] ?? '')),
        'tcod_mort' => trim((string) ($row['tsanfer'] ?? '')),
    ];
}

function mort_zonas_calcular_edad(
    mysqli $conn,
    string $tipoMortalidad,
    string $tcencos,
    string $galpon,
    string $fechaRef
): int
{
    $tipo = strtolower(trim($tipoMortalidad));
    if ($tipo === 'incubacion' || $tipo === 'transporte') {
        return 1;
    }

    $fechaBase = mort_zonas_normalizar_fecha($fechaRef);
    $galponNorm = trim($galpon);
    if ($galponNorm === '') {
        return 1;
    }

    $sql = "SELECT DATEDIFF(?, MIN(m.fec_ing)) + 1 AS edad
            FROM maes_zonas m
            WHERE m.tcodigo IN ('P0001001','P0001002')
              AND m.tcencos = ?
              AND CAST(m.tcodint AS CHAR) = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 1;
    }
    $stmt->bind_param('sss', $fechaBase, $tcencos, $galponNorm);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $edad = isset($row['edad']) ? (int) $row['edad'] : 0;

    return $edad > 0 ? $edad : 1;
}

/**
 * @param list<array{id: string, posicion: int, sexo: string, cantidad: int, codMortalidad: ?string}> $lineasDet
 */
function mort_zonas_registrar(
    mysqli $conn,
    string $cabExternalId,
    string $doc,
    string $serie,
    string $numero,
    string $tipoMortalidad,
    ?string $subtipoTransporte,
    ?string $subtipoProduccion,
    string $granja,
    string $campania,
    string $galpon,
    string $fechaRegistro,
    ?string $fechaLlegada,
    string $usuarioRegistro,
    string $fechaHoraRegistro,
    string $tidandroid,
    array $lineasDet,
    string $observaciones = ''
): void {
    if (mort_zonas_cabe_existe_por_external($conn, $cabExternalId)) {
        return;
    }

    $observaciones = trim($observaciones);

    $lineas = [];
    foreach ($lineasDet as $ln) {
        $cant = (int) ($ln['cantidad'] ?? 0);
        if ($cant <= 0) {
            continue;
        }
        $sexo = strtoupper(substr(trim((string) ($ln['sexo'] ?? 'M')), 0, 1));
        if ($sexo !== 'M' && $sexo !== 'H') {
            continue;
        }
        $lineas[] = $ln;
    }

    if ($lineas === []) {
        return;
    }

    $mark = mort_zonas_mark($tipoMortalidad);
    $treg = mort_zonas_siguiente_correlativo($conn, $mark, 'treg');
    $tnumreg = mort_zonas_siguiente_correlativo($conn, $mark, 'tnumreg');
    $fechas = mort_zonas_fechas_cabe($tipoMortalidad, $fechaRegistro, $fechaLlegada);
    $fh = mort_zonas_fecha_hora($fechaHoraRegistro, $fechaRegistro);
    $usuarioRegistro = substr(strtoupper(trim($usuarioRegistro)), 0, 8);
    $tidandroid = substr(trim($tidandroid), 0, 64);
    if ($tidandroid === '') {
        $tidandroid = 'app_movil';
    }
    $granja3 = mort_normalizar_granja3($granja);
    $camp3 = str_pad(preg_replace('/\D/', '', $campania) ?? '', 3, '0', STR_PAD_LEFT);
    $camp3 = substr($camp3, -3);
    $tcencos = $granja3 . $camp3;
    $tserie = mort_zonas_tserie($serie);
    $tnumfac = mort_zonas_tnumfac($serie, $numero);
    $tcanttot = 0;
    foreach ($lineas as $ln) {
        $tcanttot += (int) ($ln['cantidad'] ?? 0);
    }

    $sqlCabe = 'INSERT INTO cabe_zonas (
        idcia, treg, tnumreg, tprocli, tfectra, tfecrem, tdoc, tserie, tnumfac,
        tcodtra, tmon, tlib, talm, tuser, ttime, tdate, tcanttot, tglosa, tgre, mark, external_id, observaciones
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    $stmtCabe = $conn->prepare($sqlCabe);
    if (!$stmtCabe) {
        throw new RuntimeException('Error prepare cabe_zonas: ' . $conn->error);
    }

    $idcia = MORT_ZONAS_IDCIA;
    $tprocli = MORT_ZONAS_TPROCLI;
    $tcodtra = mort_zonas_tcodtra();
    $tmon = MORT_ZONAS_TMON;
    $tlib = MORT_ZONAS_TLIB;
    $talm = MORT_ZONAS_TALM;
    $tgre = MORT_ZONAS_TGRE;
    $tglosa = mort_zonas_glosa($tipoMortalidad);
    $tregF = (float) $treg;
    $tnumregF = (float) $tnumreg;

    $stmtCabe->bind_param(
        'sddssssssdssssssisssss',
        $idcia,
        $tregF,
        $tnumregF,
        $tprocli,
        $fechas['tfectra'],
        $fechas['tfecrem'],
        $doc,
        $tserie,
        $tnumfac,
        $tcodtra,
        $tmon,
        $tlib,
        $talm,
        $usuarioRegistro,
        $fh['ttime'],
        $fh['tdate'],
        $tcanttot,
        $tglosa,
        $tgre,
        $mark,
        $cabExternalId,
        $observaciones
    );

    if (!$stmtCabe->execute()) {
        $err = $stmtCabe->error;
        $stmtCabe->close();
        throw new RuntimeException('Error insert cabe_zonas: ' . $err);
    }
    $stmtCabe->close();

    $tcategoria = mort_zonas_tcategoria($tipoMortalidad);
    $flujo = mort_zonas_flujo($tipoMortalidad, $subtipoTransporte, $subtipoProduccion);
    $tedad = mort_zonas_calcular_edad($conn, $tipoMortalidad, $tcencos, $galpon, $fechas['tfectra']);

    $sqlMovi = 'INSERT INTO movi_zonas (
        idcia, tcencos, tcodint, tcodigo, tfectra, tfecrem, ttipo, tipox, tcodtra, tedad, tcantid,
        idmovi, treg, tprocli, tdoc, tserie, tnumfac, mark, tcategoria, flujo,
        tcod_mortgrs, tcod_mort, tuser, tdate, ttime, tglosa, tgre, tidandroid, tline, evidencia, observaciones
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

    $stmtMovi = $conn->prepare($sqlMovi);
    if (!$stmtMovi) {
        throw new RuntimeException('Error prepare movi_zonas: ' . $conn->error);
    }

    foreach ($lineas as $ln) {
        $sexo = strtoupper(substr(trim((string) ($ln['sexo'] ?? 'M')), 0, 1));
        $tcodigo = $sexo === 'H' ? 'P0001002' : 'P0001001';
        $ttipo = $sexo === 'H' ? '016' : '015';
        $cant = (int) ($ln['cantidad'] ?? 0);
        $pos = (int) ($ln['posicion'] ?? 1);
        $motivos = mort_zonas_motivo_codigos($conn, $ln['codMortalidad'] ?? null);
        $tipox = 'A';
        $tcantid = (float) $cant;
        $idmovi = (float) $pos;
        $tglosaMovi = $tglosa;
        $tline = '001';
        $evidencia = trim((string) ($ln['evidencia'] ?? ''));
        $observacionLinea = trim((string) ($ln['observacion'] ?? ''));

        $stmtMovi->bind_param(
            'sssssssssddddsssdssssssssssssss',
            $idcia,
            $tcencos,
            $galpon,
            $tcodigo,
            $fechas['tfectra'],
            $fechas['tfecrem'],
            $ttipo,
            $tipox,
            $tcodtra,
            $tedad,
            $tcantid,
            $idmovi,
            $tregF,
            $tprocli,
            $doc,
            $tserie,
            $tnumfac,
            $mark,
            $tcategoria,
            $flujo,
            $motivos['tcod_mortgrs'],
            $motivos['tcod_mort'],
            $usuarioRegistro,
            $fh['tdate'],
            $fh['ttime'],
            $tglosaMovi,
            $tgre,
            $tidandroid,
            $tline,
            $evidencia,
            $observacionLinea
        );

        if (!$stmtMovi->execute()) {
            $err = $stmtMovi->error;
            $stmtMovi->close();
            throw new RuntimeException('Error insert movi_zonas: ' . $err);
        }
    }

    $stmtMovi->close();
}

/**
 * Actualiza cabe_zonas / movi_zonas a partir del fact ya persistido.
 * Empareja movi por idmovi (= posicion) + tcodigo (sexo). Si cambia el sexo, usar sexoAnterior.
 *
 * @param list<array{posicion: int, sexo: string, cantidad: int, codMortalidad: ?string, sexoAnterior?: string}> $lineasDet
 */
function mort_zonas_actualizar_desde_fact(
    mysqli $conn,
    string $cabExternalId,
    string $tipoMortalidad,
    ?string $subtipoTransporte,
    ?string $subtipoProduccion,
    string $granja,
    string $campania,
    string $galpon,
    string $fechaRegistro,
    ?string $fechaLlegada,
    string $doc,
    string $serie,
    string $numero,
    string $usuarioRegistro,
    string $fechaHoraRegistro,
    array $lineasDet,
    string $observaciones = '',
    bool $preservarEvidenciaSiVacia = false,
    bool $crearCabeSiFalta = true
): void {
    $observaciones = trim($observaciones);
    $lineas = [];
    $tcanttot = 0;
    foreach ($lineasDet as $ln) {
        $cant = (int) ($ln['cantidad'] ?? 0);
        if ($cant <= 0) {
            continue;
        }
        $sexo = strtoupper(substr(trim((string) ($ln['sexo'] ?? 'M')), 0, 1));
        if ($sexo !== 'M' && $sexo !== 'H') {
            continue;
        }
        $lineas[] = $ln;
        $tcanttot += $cant;
    }

    if ($lineas === []) {
        return;
    }

    if (!mort_zonas_cabe_existe_por_external($conn, $cabExternalId)) {
        if (!$crearCabeSiFalta) {
            throw new RuntimeException('Registro no encontrado en cabe_zonas.');
        }
        mort_zonas_registrar(
            $conn,
            $cabExternalId,
            $doc,
            $serie,
            $numero,
            $tipoMortalidad,
            $subtipoTransporte,
            $subtipoProduccion,
            $granja,
            $campania,
            $galpon,
            $fechaRegistro,
            $fechaLlegada,
            $usuarioRegistro,
            $fechaHoraRegistro,
            'web_edit',
            $lineas,
            $observaciones
        );

        return;
    }

    $mark = mort_zonas_mark($tipoMortalidad);
    $fechas = mort_zonas_fechas_cabe($tipoMortalidad, $fechaRegistro, $fechaLlegada);
    $granja3 = mort_normalizar_granja3($granja);
    $camp3 = str_pad(preg_replace('/\D/', '', $campania) ?? '', 3, '0', STR_PAD_LEFT);
    $camp3 = substr($camp3, -3);
    $tcencos = $granja3 . $camp3;
    $flujo = mort_zonas_flujo($tipoMortalidad, $subtipoTransporte, $subtipoProduccion);
    $tedad = mort_zonas_calcular_edad($conn, $tipoMortalidad, $tcencos, $galpon, $fechas['tfectra']);
    $extEsc = mysqli_real_escape_string($conn, $cabExternalId);
    $markEsc = mysqli_real_escape_string($conn, $mark);
    $galponEsc = mysqli_real_escape_string($conn, $galpon);
    $flujoEsc = mysqli_real_escape_string($conn, $flujo);
    $tfectraEsc = mysqli_real_escape_string($conn, $fechas['tfectra']);
    $tfecremEsc = mysqli_real_escape_string($conn, $fechas['tfecrem']);
    $tcencosEsc = mysqli_real_escape_string($conn, $tcencos);
    $obsEsc = mysqli_real_escape_string($conn, $observaciones);

    $okCabe = mysqli_query($conn, "
        UPDATE cabe_zonas
        SET tcanttot = {$tcanttot},
            tfectra = '{$tfectraEsc}',
            tfecrem = '{$tfecremEsc}',
            observaciones = '{$obsEsc}'
        WHERE external_id = '{$extEsc}'
          AND mark = '{$markEsc}'
        LIMIT 1
    ");
    if (!$okCabe) {
        throw new RuntimeException('Error update cabe_zonas: ' . mysqli_error($conn));
    }

    // Preservar el tidandroid (uuid del dispositivo) que registró la app.
    // Las líneas nuevas heredan el identificador original en lugar de 'web_edit'.
    $qTid = mysqli_query($conn, "
        SELECT mz.tidandroid
        FROM movi_zonas mz
        INNER JOIN cabe_zonas cz ON cz.mark = mz.mark AND cz.treg = mz.treg
            AND cz.tdoc = mz.tdoc AND cz.tserie = mz.tserie AND cz.tnumfac = mz.tnumfac
        WHERE cz.external_id = '{$extEsc}' AND cz.mark = '{$markEsc}'
          AND mz.tidandroid IS NOT NULL AND TRIM(mz.tidandroid) <> ''
        ORDER BY (mz.tidandroid = 'web_edit') ASC, mz.idmovi ASC
        LIMIT 1
    ");
    $tidandroid = 'app_movil';
    if ($qTid && ($rTid = mysqli_fetch_assoc($qTid))) {
        $tidandroid = substr(trim((string) ($rTid['tidandroid'] ?? '')), 0, 64);
        if ($tidandroid === '') {
            $tidandroid = 'app_movil';
        }
    }

    foreach ($lineas as $ln) {
        $sexo = strtoupper(substr(trim((string) ($ln['sexo'] ?? 'M')), 0, 1));
        $sexoAnt = strtoupper(substr(trim((string) ($ln['sexoAnterior'] ?? $sexo)), 0, 1));
        if ($sexoAnt !== 'M' && $sexoAnt !== 'H') {
            $sexoAnt = $sexo;
        }
        $tcodigoNuevo = $sexo === 'H' ? 'P0001002' : 'P0001001';
        $tcodigoMatch = $sexoAnt === 'H' ? 'P0001002' : 'P0001001';
        $ttipo = $sexo === 'H' ? '016' : '015';
        $cant = (int) ($ln['cantidad'] ?? 0);
        $pos = (int) ($ln['posicion'] ?? 1);
        $motivos = mort_zonas_motivo_codigos($conn, $ln['codMortalidad'] ?? null);
        $tcodNuevoEsc = mysqli_real_escape_string($conn, $tcodigoNuevo);
        $tcodMatchEsc = mysqli_real_escape_string($conn, $tcodigoMatch);
        $ttipoEsc = mysqli_real_escape_string($conn, $ttipo);
        $mortGrsEsc = mysqli_real_escape_string($conn, $motivos['tcod_mortgrs']);
        $mortEsc = mysqli_real_escape_string($conn, $motivos['tcod_mort']);
        $evidenciaEsc = mysqli_real_escape_string($conn, trim((string) ($ln['evidencia'] ?? '')));
        $observacionEsc = mysqli_real_escape_string($conn, trim((string) ($ln['observacion'] ?? '')));

        // PK en movi_zonas es por idmovi (treg+mark): buscar fila existente sin depender del sexo anterior.
        $qExiste = mysqli_query($conn, "
            SELECT mz.tcodigo, mz.evidencia
            FROM movi_zonas mz
            INNER JOIN cabe_zonas cz
                ON cz.mark = mz.mark
                AND cz.treg = mz.treg
                AND cz.tdoc = mz.tdoc
                AND cz.tserie = mz.tserie
                AND cz.tnumfac = mz.tnumfac
            WHERE cz.external_id = '{$extEsc}'
              AND cz.mark = '{$markEsc}'
              AND mz.idmovi = {$pos}
            LIMIT 1
        ");
        $existeMovi = $qExiste && mysqli_num_rows($qExiste) > 0;
        $evidenciaPrevia = '';
        if ($existeMovi && $qExiste) {
            $rowExist = mysqli_fetch_assoc($qExiste);
            $tcodExistente = trim((string) ($rowExist['tcodigo'] ?? ''));
            if ($tcodExistente !== '') {
                $tcodMatchEsc = mysqli_real_escape_string($conn, $tcodExistente);
            }
            $evidenciaPrevia = trim((string) ($rowExist['evidencia'] ?? ''));
        }
        // Reenvío desde la app sin archivos nuevos: conservar la evidencia ya grabada.
        if ($preservarEvidenciaSiVacia && $evidenciaEsc === '' && $evidenciaPrevia !== '') {
            $evidenciaEsc = mysqli_real_escape_string($conn, $evidenciaPrevia);
        }

        if ($existeMovi) {
            $sqlMovi = "
                UPDATE movi_zonas mz
                INNER JOIN cabe_zonas cz
                    ON cz.mark = mz.mark
                    AND cz.treg = mz.treg
                    AND cz.tdoc = mz.tdoc
                    AND cz.tserie = mz.tserie
                    AND cz.tnumfac = mz.tnumfac
                SET mz.tcantid = {$cant},
                    mz.tcodigo = '{$tcodNuevoEsc}',
                    mz.ttipo = '{$ttipoEsc}',
                    mz.tcodint = '{$galponEsc}',
                    mz.tedad = {$tedad},
                    mz.tfectra = '{$tfectraEsc}',
                    mz.tfecrem = '{$tfecremEsc}',
                    mz.flujo = '{$flujoEsc}',
                    mz.tcod_mortgrs = '{$mortGrsEsc}',
                    mz.tcod_mort = '{$mortEsc}',
                    mz.tcencos = '{$tcencosEsc}',
                    mz.evidencia = '{$evidenciaEsc}',
                    mz.observaciones = '{$observacionEsc}'
                WHERE cz.external_id = '{$extEsc}'
                  AND cz.mark = '{$markEsc}'
                  AND mz.idmovi = {$pos}
                  AND mz.tcodigo = '{$tcodMatchEsc}'
            ";
            $okMz = mysqli_query($conn, $sqlMovi);
            if (!$okMz) {
                throw new RuntimeException('Error update movi_zonas: ' . mysqli_error($conn));
            }
            continue;
        }

        // No existía la línea en movi → insertar
        $qCabeKeys = mysqli_query($conn, "
            SELECT idcia, treg, tprocli, tdoc, tserie, tnumfac, tcodtra, tuser, tgre
            FROM cabe_zonas
            WHERE external_id = '{$extEsc}' AND mark = '{$markEsc}'
            LIMIT 1
        ");
        $cabeKeys = $qCabeKeys ? mysqli_fetch_assoc($qCabeKeys) : null;
        if (!$cabeKeys) {
            throw new RuntimeException('No se encontro cabe_zonas para insertar movi.');
        }

        $tcategoria = mort_zonas_tcategoria($tipoMortalidad);
        $idciaEsc = mysqli_real_escape_string($conn, (string) ($cabeKeys['idcia'] ?? MORT_ZONAS_IDCIA));
        $tregF = (float) ($cabeKeys['treg'] ?? 0);
        $tprocliEsc = mysqli_real_escape_string($conn, (string) ($cabeKeys['tprocli'] ?? MORT_ZONAS_TPROCLI));
        $tdocEsc = mysqli_real_escape_string($conn, (string) ($cabeKeys['tdoc'] ?? 'GI'));
        $tserieEsc = mysqli_real_escape_string($conn, (string) ($cabeKeys['tserie'] ?? ''));
        $tnumfacF = (float) ($cabeKeys['tnumfac'] ?? 0);
        $tcodtraEsc = mysqli_real_escape_string($conn, (string) ($cabeKeys['tcodtra'] ?? mort_zonas_tcodtra()));
        $tuserEsc = mysqli_real_escape_string($conn, substr(strtoupper(trim((string) ($cabeKeys['tuser'] ?? $usuarioRegistro))), 0, 8));
        $tgreEsc = mysqli_real_escape_string($conn, (string) ($cabeKeys['tgre'] ?? MORT_ZONAS_TGRE));
        $tcategoriaEsc = mysqli_real_escape_string($conn, $tcategoria);
        $fh = mort_zonas_fecha_hora($fechaHoraRegistro, $fechaRegistro);
        $tdateEsc = mysqli_real_escape_string($conn, $fh['tdate']);
        $ttimeEsc = mysqli_real_escape_string($conn, $fh['ttime']);
        $tglosaEsc = mysqli_real_escape_string($conn, mort_zonas_glosa($tipoMortalidad));
        $tipox = 'A';
        $tline = '001';

        $sqlIns = "
            INSERT INTO movi_zonas (
                idcia, tcencos, tcodint, tcodigo, tfectra, tfecrem, ttipo, tipox, tcodtra, tedad, tcantid,
                idmovi, treg, tprocli, tdoc, tserie, tnumfac, mark, tcategoria, flujo,
                tcod_mortgrs, tcod_mort, tuser, tdate, ttime, tglosa, tgre, tidandroid, tline, evidencia, observaciones
            ) VALUES (
                '{$idciaEsc}', '{$tcencosEsc}', '{$galponEsc}', '{$tcodNuevoEsc}',
                '{$tfectraEsc}', '{$tfecremEsc}', '{$ttipoEsc}', '{$tipox}', '{$tcodtraEsc}',
                {$tedad}, {$cant}, {$pos}, {$tregF}, '{$tprocliEsc}', '{$tdocEsc}', '{$tserieEsc}',
                {$tnumfacF}, '{$markEsc}', '{$tcategoriaEsc}', '{$flujoEsc}',
                '{$mortGrsEsc}', '{$mortEsc}', '{$tuserEsc}', '{$tdateEsc}', '{$ttimeEsc}',
                '{$tglosaEsc}', '{$tgreEsc}', '{$tidandroid}', '{$tline}', '{$evidenciaEsc}', '{$observacionEsc}'
            )
        ";
        $okIns = mysqli_query($conn, $sqlIns);
        if (!$okIns) {
            throw new RuntimeException('Error insert movi_zonas: ' . mysqli_error($conn));
        }
    }
}
