<?php
session_start();
if (empty($_SESSION['active'])) {
    header('HTTP/1.1 401 Unauthorized');
    exit('No autorizado');
}

@ini_set('max_execution_time', '0');
@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/informe_contable_lib.php';
include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    exit('Error de conexión');
}

$filtros = inf_ctb_parse_filtros($_GET);
$where = inf_ctb_where_filtros($conn, $filtros);

$sql = "
    SELECT
        u.nombre AS usuario,
        b.tdate AS fecha,
        b.ttime AS hora,
        a.tfectra AS fechaCont,
        a.tcencos AS cencos,
        c.nombre AS nomCencos,
        TRIM(a.tcodint) AS galpon,
        a.tedad AS edad,
        a.tcodigo AS codigo,
        i.descri AS nomProd,
        a.tcodtra AS codTra,
        l.descri AS transaccion,
        IF(a.ttipo='015','Macho',IF(a.ttipo='016','Hembra','-')) AS sexo,
        CAST(a.flujo AS CHAR) AS flujo,
        CAST(a.tcategoria AS CHAR) AS tcategoria,
        a.tnumfac AS numFac,
        a.idmovi,
        IF(LEFT(a.tcodtra,1)='E',a.tcantid,-1*a.tcantid) AS cantidad,
        IF(a.tcodtra='S808',a.tcod_mortgrs,'') AS codMort,
        IF(a.tcodtra='S808',m.tnom_mort,'') AS nomMort,
        IF(a.tcodtra='S808',a.tcantid,'') AS cantidadMort
    FROM movi_zonas AS a
    LEFT JOIN cabe_zonas AS b
        ON a.mark = b.mark AND a.treg = b.treg AND a.tdoc = b.tdoc AND a.tserie = b.tserie AND a.tnumfac = b.tnumfac
    LEFT JOIN ccos AS c ON a.tcencos = c.codigo
    LEFT JOIN coal AS l ON a.tcodtra = l.codtra
    LEFT JOIN regmotivo_mortalidadgrs AS m ON a.tcod_mortgrs = m.tcod_mort
    LEFT JOIN usuario AS u ON b.tuser = u.codigo
    LEFT JOIN mitm AS i ON a.tcodigo = i.codigo
    {$where}
    ORDER BY a.tcencos, a.tcodint, a.tcodigo, a.tfectra, a.tcodtra, a.tnumfac
";

$qData = mysqli_query($conn, $sql);
$rows = [];
if ($qData) {
    while ($row = mysqli_fetch_assoc($qData)) {
        // Ajustar formato de etiquetas para display
        $catVal = trim((string) ($row['tcategoria'] ?? ''));
        $fluVal = trim((string) ($row['flujo'] ?? ''));
        if ($catVal === 'P' || $catVal === 'Produccion') {
            $row['tcategoria'] = 'Produccion';
            if ($fluVal === 'P') {
                $row['flujo'] = 'Crianza';
            }
        } elseif ($catVal === 'PlantaIncubacion') {
            $row['tcategoria'] = 'Planta Incubacion';
        }
        if ($fluVal === 'PlantaIncubacion') {
            $row['flujo'] = 'Planta Incubacion';
        }
        $rows[] = $row;
    }
}
mysqli_close($conn);

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="informe_contable_mortalidad_' . date('Ymd_His') . '.xls"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // BOM UTF-8
?>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
<style>
    table { border-collapse: collapse; font-size: 11pt; font-family: Arial, sans-serif; }
    th, td { border: 1px solid #999; padding: 4px 8px; }
    th { background-color: #2563eb; color: #fff; font-weight: bold; }
    .num { text-align: right; }
</style>
</head>
<body>
<table>
<thead>
<tr>
    <th>N°</th>
    <th>Usuario</th>
    <th>Fecha</th>
    <th>Hora</th>
    <th>Fec. cont.</th>
    <th>Cencos</th>
    <th>Nom. cencos</th>
    <th>Galpón</th>
    <th>Edad</th>
    <th>Código</th>
    <th>Nom. prod.</th>
    <th>Cod. tra.</th>
    <th>Transacción</th>
    <th>Sexo</th>
    <th>Categoria</th>
    <th>Flujo</th>
    <th>Num. fac</th>
    <th>Idmovi</th>
    <th>Cantidad</th>
    <th>Cod. mort.</th>
    <th>Causa mort.</th>
    <th>Cant. mort.</th>
</tr>
</thead>
<tbody>
<?php
$n = 0;
foreach ($rows as $r):
    $n++;
    $cantidad = number_format((float) ($r['cantidad'] ?? 0), 2, ',', '.');
    $cantMort = (float) ($r['cantidadMort'] ?? 0);
    $cantMortTxt = $cantMort > 0 ? number_format($cantMort, 2, ',', '.') : '';
?>
<tr>
    <td><?php echo $n; ?></td>
    <td><?php echo htmlspecialchars((string) ($r['usuario'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['fecha'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['hora'] ?? '')); ?></td>
    <td><?php
        $fc = trim((string) ($r['fechaCont'] ?? ''));
        if ($fc !== '' && preg_match('/^\d{4}-\d{2}-\d{2}/', $fc)) {
            $parts = explode(' ', $fc);
            $fc = implode('/', array_reverse(explode('-', $parts[0])));
        }
        echo htmlspecialchars($fc);
    ?></td>
    <td><?php echo htmlspecialchars((string) ($r['cencos'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['nomCencos'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['galpon'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['edad'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['codigo'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['nomProd'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['codTra'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['transaccion'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['sexo'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['tcategoria'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['flujo'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['numFac'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['idmovi'] ?? '')); ?></td>
    <td class="num"><?php echo $cantidad; ?></td>
    <td><?php echo htmlspecialchars((string) ($r['codMort'] ?? '')); ?></td>
    <td><?php echo htmlspecialchars((string) ($r['nomMort'] ?? '')); ?></td>
    <td class="num"><?php echo $cantMortTxt; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body>
</html>
