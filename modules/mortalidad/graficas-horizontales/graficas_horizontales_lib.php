<?php

declare(strict_types=1);

/**
 * Filtros por defecto del dashboard de gráficas horizontales.
 *
 * @return array{fechaInicio:string,fechaFin:string,semana:int}
 */
if (!function_exists('graficas_horizontales_filtros_defecto')) {
    function graficas_horizontales_filtros_defecto(): array
    {
        $hoy = new DateTimeImmutable();

        return [
            'fechaInicio' => $hoy->modify('-3 months')->format('Y-m-d'),
            'fechaFin' => $hoy->format('Y-m-d'),
            'semana' => 1,
        ];
    }
}

/** Orden lógico del ciclo productivo en el selector de tipos. */
if (!function_exists('graficas_horizontales_tipos_orden')) {
    function graficas_horizontales_tipos_orden(): array
    {
        return ['mortalidad', 'pesaje_pollo', 'ganancia_peso', 'ica_2_4', 'iep_2_4'];
    }
}

/** Tipos visibles: mortalidad, pesaje, ganancia e indicadores de liquidación. */
if (!function_exists('graficas_horizontales_tipos_visibles')) {
    function graficas_horizontales_tipos_visibles(): array
    {
        $orden = graficas_horizontales_tipos_orden();
        $idxOrden = array_flip($orden);
        $porId = [];

        foreach (sip_grafica_catalog_tipos_grafica()['data'] ?? [] as $t) {
            if (!is_array($t)) {
                continue;
            }
            $id = strtolower(trim((string) ($t['id'] ?? $t['tipo'] ?? '')));
            if ($id !== '' && isset($idxOrden[$id])) {
                $porId[$id] = $t;
            }
        }

        $out = [];
        foreach ($orden as $id) {
            if (isset($porId[$id])) {
                $out[] = $porId[$id];
            }
        }

        return $out;
    }
}

/** Tipos que se consultan por día de pesaje/ganancia (multi-día). */
if (!function_exists('graficas_horizontales_tipos_pesaje')) {
    function graficas_horizontales_tipos_pesaje(): array
    {
        return ['pesaje_pollo', 'ganancia_peso'];
    }
}

/** Tipos de liquidación al cierre de campaña (fecha_fin). */
if (!function_exists('graficas_horizontales_tipos_liquidacion')) {
    function graficas_horizontales_tipos_liquidacion(): array
    {
        return ['iep_2_4', 'ica_2_4'];
    }
}
