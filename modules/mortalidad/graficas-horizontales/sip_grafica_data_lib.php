<?php

declare(strict_types=1);

require_once __DIR__ . '/sip_grafica_mortalidad.php';
require_once __DIR__ . '/sip_grafica_consumo.php';
require_once __DIR__ . '/sip_grafica_peso_cloro.php';
require_once __DIR__ . '/sip_grafica_respiratorio_digestivo.php';
require_once __DIR__ . '/sip_grafica_cv_lib.php';

if (!function_exists('sip_grafica_tipos_soportados')) {
    /** @return list<string> */
    function sip_grafica_tipos_soportados(): array
    {
        return array_merge(
            ['mortalidad'],
            sip_grafica_consumo_tipos(),
            sip_grafica_peso_cloro_tipos(),
            sip_grafica_cv_tipos(),
            sip_grafica_respiratorio_digestivo_tipos()
        );
    }
}

if (!function_exists('sip_grafica_es_consumo')) {
    function sip_grafica_es_consumo(string $tipo): bool
    {
        return sip_grafica_consumo_cfg($tipo) !== null;
    }
}

if (!function_exists('sip_grafica_es_peso_cloro')) {
    function sip_grafica_es_peso_cloro(string $tipo): bool
    {
        return sip_grafica_peso_cloro_cfg($tipo) !== null;
    }
}

if (!function_exists('sip_grafica_build')) {
    /**
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     */
    function sip_grafica_build(mysqli $conn, string $tipo, array $opts): array
    {
        $tipoNorm = strtolower(trim($tipo));
        $seccion = strtolower(trim((string) ($opts['seccion'] ?? 'all')));
        $esFact = strtolower(trim((string) ($opts['origen'] ?? ''))) === 'fact';

        if (sip_grafica_es_consumo($tipoNorm)) {
            return sip_grafica_consumo_linea($conn, $opts, $tipoNorm);
        }

        if (sip_grafica_es_cv($tipoNorm)) {
            if ($esFact) {
                require_once __DIR__ . '/sip_grafica_pesaje_fact_lib.php';

                return sip_grafica_cv_fact_linea($conn, $opts, $tipoNorm);
            }

            return sip_grafica_cv_linea($conn, $opts, $tipoNorm);
        }

        if (sip_grafica_es_peso_cloro($tipoNorm)) {
            if ($tipoNorm === 'nivel_cloro') {
                return sip_grafica_cloro_linea($conn, $opts);
            }
            if ($esFact) {
                require_once __DIR__ . '/sip_grafica_pesaje_fact_lib.php';
                if ($tipoNorm === 'pesaje_pollo') {
                    return sip_grafica_pesaje_fact_linea($conn, $opts);
                }
                if ($tipoNorm === 'ganancia_peso') {
                    return sip_grafica_ganancia_fact_linea($conn, $opts);
                }
            }
            if ($tipoNorm === 'pesaje_pollo') {
                require_once __DIR__ . '/sip_grafica_pesaje_lib.php';

                return sip_grafica_pesaje_linea($conn, $opts);
            }
            if ($tipoNorm === 'ganancia_peso') {
                require_once __DIR__ . '/sip_grafica_ganancia_lib.php';

                return sip_grafica_ganancia_linea($conn, $opts);
            }

            return sip_grafica_peso_linea($conn, $opts, $tipoNorm);
        }

        if (sip_grafica_es_respiratorio_digestivo($tipoNorm)) {
            return $tipoNorm === 'respiratorio'
                ? sip_grafica_respiratorio_linea($conn, $opts)
                : sip_grafica_digestivo_linea($conn, $opts);
        }

        if ($tipoNorm !== 'mortalidad') {
            return ['success' => false, 'message' => 'Tipo no soportado: ' . $tipo];
        }

        if ($seccion === 'all') {
            $linea = sip_grafica_mortalidad_linea($conn, $opts);
            $causas = sip_grafica_mortalidad_causas($conn, $opts);
            $pareto = sip_grafica_mortalidad_pareto($conn, $opts, $causas['porFecha'] ?? []);

            return [
                'success' => ($linea['success'] ?? false) || ($causas['success'] ?? false),
                'linea' => $linea,
                'causas' => $causas,
                'pareto' => $pareto,
            ];
        }

        if ($seccion === 'linea') {
            return sip_grafica_mortalidad_linea($conn, $opts);
        }
        if ($seccion === 'causas') {
            return sip_grafica_mortalidad_causas($conn, $opts);
        }
        if ($seccion === 'pareto') {
            return sip_grafica_mortalidad_pareto($conn, $opts);
        }

        return ['success' => false, 'message' => 'Seccion no valida: ' . $seccion];
    }
}
