<?php

declare(strict_types=1);

if (!function_exists('sip_grafica_norm_modo_evaluacion')) {
    function sip_grafica_norm_modo_evaluacion($raw): string
    {
        $m = strtolower(trim((string) $raw));
        static $ok = [
            'rango_cerrado' => true,
            'valor_unico' => true,
            'solo_max' => true,
            'solo_min' => true,
            'franjas' => true,
        ];

        return isset($ok[$m]) ? $m : 'rango_cerrado';
    }
}

if (!function_exists('sip_grafica_eval_range')) {
    function sip_grafica_eval_range(?float $valor, ?float $min, ?float $max, $modo = 'rango_cerrado'): ?bool
    {
        if ($valor === null) {
            return null;
        }
        $modo = sip_grafica_norm_modo_evaluacion($modo);
        if ($modo === 'valor_unico') {
            $ref = $min !== null ? $min : $max;
            if ($ref === null) {
                return null;
            }

            return abs($valor - $ref) < 1e-9;
        }
        if ($modo === 'solo_max') {
            if ($max === null) {
                return null;
            }

            return $valor <= $max;
        }
        if ($modo === 'solo_min') {
            if ($min === null) {
                return null;
            }

            return $valor >= $min;
        }
        if ($modo === 'franjas') {
            if ($min === null && $max === null) {
                return null;
            }
            if ($max !== null && $valor > $max) {
                return false;
            }

            return true;
        }
        if ($min !== null && $valor < $min) {
            return false;
        }
        if ($max !== null && $valor > $max) {
            return false;
        }
        if ($min === null && $max === null) {
            return null;
        }

        return true;
    }
}

if (!function_exists('sip_grafica_is_finite_num')) {
    function sip_grafica_is_finite_num($v): bool
    {
        return is_numeric($v) && is_finite((float) $v);
    }
}

if (!function_exists('sip_grafica_to_num')) {
    function sip_grafica_to_num($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_array($v) || is_object($v)) {
            return null;
        }
        $s = trim((string) $v);
        if ($s === '' || $s === '—' || strcasecmp($s, 'N/A') === 0) {
            return null;
        }
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) {
            return null;
        }
        $f = (float) $s;

        return is_finite($f) ? $f : null;
    }
}

if (!function_exists('sip_grafica_json_is_vector')) {
    function sip_grafica_json_is_vector(array $a): bool
    {
        if ($a === []) {
            return true;
        }

        return array_keys($a) === range(0, count($a) - 1);
    }
}

if (!function_exists('sip_grafica_parse_celda_raw')) {
    function sip_grafica_parse_celda_raw($raw, int $cellIndex = 0): ?float
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            if ($raw === []) {
                return null;
            }
            if (sip_grafica_json_is_vector($raw)) {
                return sip_grafica_to_num($raw[$cellIndex] ?? ($raw[0] ?? null));
            }

            return null;
        }

        return sip_grafica_to_num($raw);
    }
}

if (!function_exists('sip_grafica_parse_valor_mh')) {
    /**
     * Decodifica `valor` de san_fact_historia_clinica_det (formatos HC).
     *
     * @return array{m:?float,h:?float,unified:?float,esMh:bool}
     */
    function sip_grafica_parse_valor_mh($rawVal, int $cellIndex = 0): array
    {
        $empty = ['m' => null, 'h' => null, 'unified' => null, 'esMh' => false];
        if ($rawVal === null) {
            return $empty;
        }
        if (is_string($rawVal)) {
            $s = trim($rawVal);
            if ($s === '' || $s === '—') {
                return $empty;
            }
            if ($s !== '' && $s[0] !== '{' && $s[0] !== '[') {
                $n = sip_grafica_to_num($s);
                if ($n !== null) {
                    return ['m' => $n, 'h' => $n, 'unified' => $n, 'esMh' => false];
                }
            }
        }
        $dec = is_string($rawVal) ? json_decode($rawVal, true) : (is_array($rawVal) ? $rawVal : null);
        if (!is_array($dec)) {
            $n = sip_grafica_to_num($rawVal);

            return $n !== null
                ? ['m' => $n, 'h' => $n, 'unified' => $n, 'esMh' => false]
                : $empty;
        }
        if (isset($dec['current']) && is_array($dec['current'])) {
            $dec = $dec['current'];
        }
        $hasM = array_key_exists('M', $dec) || array_key_exists('m', $dec);
        $hasH = array_key_exists('H', $dec) || array_key_exists('h', $dec);
        if ($hasM || $hasH) {
            return [
                'm' => sip_grafica_parse_celda_raw($dec['M'] ?? $dec['m'] ?? null, $cellIndex),
                'h' => sip_grafica_parse_celda_raw($dec['H'] ?? $dec['h'] ?? null, $cellIndex),
                'unified' => null,
                'esMh' => true,
            ];
        }
        if (sip_grafica_json_is_vector($dec)) {
            $u = sip_grafica_parse_celda_raw($dec, $cellIndex);

            return ['m' => null, 'h' => null, 'unified' => $u, 'esMh' => false];
        }

        return $empty;
    }
}

if (!function_exists('sip_grafica_valor_fact_export')) {
    /**
     * Valor tal como está en san_fact_historia_clinica_det.valor (JSON decodificado o escalar).
     *
     * @return mixed
     */
    function sip_grafica_valor_fact_export($rawVal)
    {
        if ($rawVal === null) {
            return null;
        }
        if (is_array($rawVal)) {
            return $rawVal;
        }
        $s = trim((string) $rawVal);
        if ($s === '') {
            return null;
        }
        if ($s[0] === '{' || $s[0] === '[') {
            $dec = json_decode($s, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $dec;
            }
        }
        $n = sip_grafica_to_num($s);
        if ($n !== null) {
            return $n;
        }

        return $s;
    }
}

if (!function_exists('sip_grafica_gas_cumple')) {
    /** Cumplimiento consumo gas/agua: por debajo o igual al estándar → cumple. */
    function sip_grafica_gas_cumple(?float $v, ?float $min, ?float $max): ?bool
    {
        if ($v === null || !is_finite($v)) {
            return null;
        }
        $hasMin = ($min !== null && is_finite($min));
        $hasMax = ($max !== null && is_finite($max));
        if ($hasMin && $hasMax) {
            if (abs($max - $min) < 1e-9) {
                return $v <= $min;
            }

            return $v >= $min && $v <= $max;
        }
        if ($hasMax) {
            return $v <= $max;
        }
        if ($hasMin) {
            return $v >= $min;
        }

        return null;
    }
}

if (!function_exists('sip_grafica_is_nan')) {
    function sip_grafica_is_nan($v): bool
    {
        return is_float($v) && $v !== $v;
    }
}
