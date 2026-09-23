<?php
declare(strict_types=1);

/**
 * @return list<array{tcencos:string,tcodint:string,tcodigo:string,descri:string,cantidad:float,peso:float,valor:float}>
 */
function mort_cantidad_pollos_query(
    mysqli $conn,
    string $tcencos,
    string $fecha,
    ?string $galpon = null
): array {
    $tcencosEsc = mysqli_real_escape_string($conn, $tcencos);
    $fechaEsc = mysqli_real_escape_string($conn, $fecha);
    $yearEsc = substr($fechaEsc, 0, 4);

    $filtroGalpon = '';
    if ($galpon !== null && $galpon !== '') {
        $galponEsc = mysqli_real_escape_string($conn, $galpon);
        $filtroGalpon = " AND frm1.tcodint = '{$galponEsc}' ";
    }

    $sql = "
        SELECT
            frm1.tcencos,
            frm1.tcodint,
            frm1.tcodigo,
            MAX(frm1.descri) AS descri,
            ROUND(SUM(frm1.cantidad), 2) AS cantidad,
            ROUND(SUM(frm1.peso), 2) AS peso,
            ROUND(SUM(frm1.valor), 2) AS valor
        FROM (
            SELECT
                a.tcencos,
                a.tcodint,
                a.tcodigo,
                MAX(c.descri) AS descri,
                SUM(IF(LEFT(a.tcodtra, 1) = 'E', a.tcantid, (-1) * a.tcantid)) AS cantidad,
                SUM(IF(LEFT(a.tcodtra, 1) = 'E', a.tpeso, (-1) * a.tpeso)) AS peso,
                SUM(IF(LEFT(a.tcodtra, 1) = 'E', a.timport, (-1) * a.timport)) AS valor
            FROM movi_zonas AS a USE INDEX (tcencos, tcodint, tcodigo)
            INNER JOIN mitm AS c USE INDEX (PRIMARY) ON a.tcodigo = c.codigo
            INNER JOIN linea AS l ON c.lin = l.linea
            WHERE c.lin IN ('001')
              AND a.tcencos = '{$tcencosEsc}'
              AND a.tfectra <= '{$fechaEsc}'
              AND YEAR(a.tfectra) = '{$yearEsc}'
            GROUP BY a.tcencos, a.tcodint, a.tcodigo

            UNION

            SELECT
                d.tcencos,
                d.tcodint,
                d.tcodigo,
                MAX(c.descri) AS descri,
                SUM(d.qinicio) AS cantidad,
                SUM(d.pinicio) AS peso,
                SUM(d.vinicio) AS valor
            FROM maes_zonas2 AS d USE INDEX (tcencos, tcodint, tcodigo)
            INNER JOIN mitm AS c USE INDEX (PRIMARY) ON c.codigo = d.tcodigo
            INNER JOIN linea AS l ON c.lin = l.linea
            WHERE c.lin IN ('001')
              AND d.tcencos = '{$tcencosEsc}'
            GROUP BY d.tcencos, d.tcodint, d.tcodigo
        ) AS frm1
        WHERE 1=1 {$filtroGalpon}
        GROUP BY frm1.tcencos, frm1.tcodint, frm1.tcodigo
        ORDER BY frm1.tcodint ASC, frm1.tcodigo ASC
    ";

    $result = mysqli_query($conn, $sql);
    if (!$result) {
        throw new RuntimeException(mysqli_error($conn) ?: 'Error SQL en cantidad pollos');
    }

    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $cantidad = (float) ($row['cantidad'] ?? 0);
        $peso = (float) ($row['peso'] ?? 0);
        $items[] = [
            'tcencos' => (string) ($row['tcencos'] ?? ''),
            'tcodint' => (string) ($row['tcodint'] ?? ''),
            'tcodigo' => (string) ($row['tcodigo'] ?? ''),
            'descri' => mb_convert_encoding((string) ($row['descri'] ?? ''), 'UTF-8', 'ISO-8859-1'),
            // El stock (aves y peso) nunca puede ser negativo: un saldo negativo es
            // un artefacto del día de salida/vaciado (entradas - salidas en movi_zonas).
            'cantidad' => $cantidad > 0 ? $cantidad : 0.0,
            'peso' => $peso > 0 ? $peso : 0.0,
            'valor' => (float) ($row['valor'] ?? 0),
        ];
    }

    return $items;
}
