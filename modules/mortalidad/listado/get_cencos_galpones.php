<?php
declare(strict_types=1);

set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['active'])) {
    http_response_code(401);
    echo json_encode(['status' => 401, 'success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!function_exists('mort_listado_cencos_send_json')) {
    function mort_listado_cencos_send_json(int $status, bool $success, string $message, $data = null, ?string $errorCode = null): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($status);
        $payload = [
            'status' => $status,
            'success' => $success,
            'message' => $message,
        ];
        if ($errorCode !== null) {
            $payload['errorCode'] = $errorCode;
        }
        if ($data !== null) {
            $payload['data'] = $data;
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        echo $json !== false ? $json : '{"status":500,"success":false,"message":"Error al generar JSON"}';
        exit;
    }
}

include_once __DIR__ . '/../../../../conexion_grs/conexion.php';

$conn = conectar_joya_mysqli();
if (!$conn) {
    mort_listado_cencos_send_json(500, false, 'Error de conexión a la base de datos');
}

mysqli_set_charset($conn, 'latin1');
mysqli_query($conn, "SET time_zone = 'America/Lima'");

$query = "
    SELECT c.codigo, c.nombre, g.tcodint,
        COALESCE(m1.fec_ing) AS fec_ing_min
    FROM ccos AS c
        INNER JOIN regcencosgalpones AS g ON LEFT(c.codigo, 3) = g.tcencos
        LEFT JOIN (
            SELECT tcencos, tcodint, MAX(fec_ing) AS fec_ing
            FROM maes_zonas USE INDEX (tcodigo)
            WHERE tcodigo IN ('P0001001', 'P0001002')
              AND fec_ing IS NOT NULL
            GROUP BY tcencos, tcodint
        ) AS m1 ON c.codigo = m1.tcencos AND g.tcodint = m1.tcodint
    WHERE LEFT(c.codigo, 1) = '6'
      AND RIGHT(c.codigo, 3) <> '000'
      AND c.swac = 'A'
      AND CHAR_LENGTH(c.codigo) = 6
      AND LEFT(c.codigo, 3) NOT IN ('668', '669', '650', '680')
    ORDER BY c.codigo ASC, g.tcodint ASC
";

$result = mysqli_query($conn, $query);
if (!$result) {
    mysqli_close($conn);
    mort_listado_cencos_send_json(500, false, 'Error al obtener granjas');
}

$cencosMap = [];

while ($row = mysqli_fetch_assoc($result)) {
    $codigo = (string) ($row['codigo'] ?? '');
    if ($codigo === '') {
        continue;
    }

    if (!isset($cencosMap[$codigo])) {
        $nombre = mb_convert_encoding((string) ($row['nombre'] ?? ''), 'UTF-8', 'ISO-8859-1');
        $cencosMap[$codigo] = [
            'codigo' => $codigo,
            'nombre' => $nombre,
            'edad' => '0',
            'galpones' => [],
        ];
    }

    if ($row['tcodint'] !== null && $row['tcodint'] !== '') {
        $fecIngMin = $row['fec_ing_min'] ?? null;
        if ($fecIngMin && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecIngMin)) {
            if ((int) substr($fecIngMin, 0, 4) <= 1900) {
                $fecIngMin = null;
            }
        }

        $galponEdad = '0';
        if ($fecIngMin) {
            $diff = date_diff(date_create($fecIngMin), date_create('now'));
            $galponEdad = (string) ($diff->days + 1);
        }

        $cencosMap[$codigo]['galpones'][] = [
            'tcodint' => (string) $row['tcodint'],
            'fec_ing_min' => $fecIngMin,
            'edad' => $galponEdad,
        ];
    }
}

foreach ($cencosMap as &$cenco) {
    $edades = array_values(array_filter(
        array_map(static function ($g) { return (int) ($g['edad'] ?? 0); }, $cenco['galpones']),
        static function ($e) { return $e > 0; }
    ));
    if ($edades !== []) {
        $promedio = array_sum($edades) / count($edades);
        $cenco['edad'] = (string) (int) round($promedio);
    }
}
unset($cenco);

$cencos = array_values($cencosMap);
usort($cencos, static function ($a, $b) {
    return strcmp($a['nombre'] ?? '', $b['nombre'] ?? '');
});

mysqli_close($conn);

mort_listado_cencos_send_json(200, true, count($cencos) > 0 ? 'Granjas y galpones cargados' : 'No se encontraron granjas', [
    'cencos' => $cencos,
    'total' => count($cencos),
]);
