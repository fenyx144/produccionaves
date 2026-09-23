<?php
function periodo_a_rango(array $opts) {
    $tipo = trim((string)($opts['periodoTipo'] ?? ''));
    if ($tipo === '' || $tipo === 'TODOS') {
        return null;
    }

    $fechaUnica = trim((string)($opts['fechaUnica'] ?? ''));
    $fechaInicio = trim((string)($opts['fechaInicio'] ?? ''));
    $fechaFin = trim((string)($opts['fechaFin'] ?? ''));
    $mesUnico = trim((string)($opts['mesUnico'] ?? ''));
    $mesInicio = trim((string)($opts['mesInicio'] ?? ''));
    $mesFin = trim((string)($opts['mesFin'] ?? ''));

    $hoy = date('Y-m-d');

    switch ($tipo) {
        case 'POR_FECHA':
            if ($fechaUnica === '') return null;
            return ['desde' => $fechaUnica, 'hasta' => $fechaUnica];

        case 'ENTRE_FECHAS':
            if ($fechaInicio === '' || $fechaFin === '') return null;
            return ['desde' => $fechaInicio, 'hasta' => $fechaFin];

        case 'POR_MES':
            if ($mesUnico === '' || !preg_match('/^\d{4}-\d{2}$/', $mesUnico)) return null;
            return [
                'desde' => $mesUnico . '-01',
                'hasta' => date('Y-m-t', strtotime($mesUnico . '-01'))
            ];

        case 'ENTRE_MESES':
            if ($mesInicio === '' || $mesFin === '' || !preg_match('/^\d{4}-\d{2}$/', $mesInicio) || !preg_match('/^\d{4}-\d{2}$/', $mesFin)) return null;
            return [
                'desde' => $mesInicio . '-01',
                'hasta' => date('Y-m-t', strtotime($mesFin . '-01'))
            ];

        case 'ULTIMA_SEMANA':
            $desde = date('Y-m-d', strtotime('-6 days'));
            return ['desde' => $desde, 'hasta' => $hoy];

        default:
            return null;
    }
}
