<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MortalidadModel;

/**
 * Guardado/reparación de registros de mortalidad móvil.
 * Port fiel de guardar_mortalidad_movil.php y reparar_registro_movil.php.
 */
final class MortalidadGuardadoService
{
    /**
     * Procesa las imágenes de un detalle y devuelve rutas relativas creadas
     * y el número de archivos nuevos.
     *
     * @return array{rutas: list<string>, nuevas: int}
     */
    public static function procesarImagenesDetalle(
        string $detId,
        string $granja,
        string $galpon,
        string $numero,
        string $fechaYmd,
        string $sexo,
        string $carpetaFs
    ): array {
        $rutas = [];
        $nuevas = 0;
        $prefix = 'img_' . $detId . '_';

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
                \mort_normalizar_granja3($granja),
                preg_replace('/[^A-Za-z0-9_-]/', '', $galpon),
                $numero,
                $fechaYmd,
                $sexo,
                substr(str_replace('-', '', $detId), 0, 12),
                $idx,
                $ext
            );
            $base = preg_replace('/[^A-Za-z0-9_.-]/', '_', $base) ?? $base;
            $destFs = $carpetaFs . $base;

            $yaExistia = is_file($destFs);
            if (!move_uploaded_file($tmp, $destFs)) {
                continue;
            }
            if (!$yaExistia) {
                $nuevas++;
            }
            $rutas[] = sanidad_uploads_rel('mortalidad', $base);
        }

        return ['rutas' => $rutas, 'nuevas' => $nuevas];
    }

    /** Guardado/reenvío de un lote de registros (guardar_mortalidad_movil.php). */
    public static function guardarLote(\mysqli $conn, array $records, string $carpetaFs): array
    {
        $guardados = [];
        $duplicados = 0;
        $errores = [];
        $errorCodeCierre = null;
        $uuidsEnLote = [];

        foreach ($records as $idx => $record) {
            if (!is_array($record)) {
                $errores[] = "Registro #{$idx}: formato inválido";
                continue;
            }

            $cabId = trim((string) ($record['tuuid'] ?? $record['id'] ?? ''));
            if ($cabId === '' || strlen($cabId) > 36) {
                $errores[] = "Registro #{$idx}: falta tuuid/id válido";
                continue;
            }

            if (isset($uuidsEnLote[$cabId])) {
                $duplicados++;
                $errores[] = "Registro {$cabId}: UUID repetido en el mismo envío";
                continue;
            }
            $uuidsEnLote[$cabId] = true;

            $tipoMortalidad = strtolower(trim((string) ($record['tipo_mortalidad'] ?? '')));
            $granja = \mort_normalizar_granja3((string) ($record['granja'] ?? ''));
            $campania = trim((string) ($record['campania'] ?? ''));
            $galpon = trim((string) ($record['galpon'] ?? ''));
            $fechaRegistro = trim((string) ($record['fecha'] ?? ''));
            $observaciones = trim((string) ($record['observaciones'] ?? ''));
            $usuarioRegistro = strtoupper(trim((string) ($record['usuario'] ?? '')));
            $tidandroid = trim((string) ($record['tidandroid'] ?? $record['android'] ?? ''));

            if ($tipoMortalidad === '' || $galpon === '' || $fechaRegistro === '') {
                $errores[] = "Registro {$cabId}: faltan tipo_mortalidad, galpon o fecha";
                continue;
            }

            if (strlen($campania) < 3) {
                $campania = str_pad(preg_replace('/\D/', '', $campania) ?? '', 3, '0', STR_PAD_LEFT);
            } else {
                $campania = substr($campania, 0, 3);
            }

            $subtipoTransporte = null;
            $subtipoProduccion = null;
            $fechaLlegada = null;

            if ($tipoMortalidad === 'transporte') {
                $subtipoTransporte = trim((string) ($record['subtipo_transporte'] ?? 'transporte'));
                if ($subtipoTransporte === '') {
                    $subtipoTransporte = 'transporte';
                }
            } elseif ($tipoMortalidad === 'produccion') {
                $subtipoProduccion = trim((string) ($record['origen_analisis'] ?? ''));
                if ($subtipoProduccion === '') {
                    $subtipoProduccion = 'Crianza';
                }
            } elseif ($tipoMortalidad === 'incubacion') {
                $fl = trim((string) ($record['fecha_llegada'] ?? ''));
                if ($fl !== '') {
                    $fechaLlegada = $fl;
                }
            }

            $granjaNombre = trim((string) ($record['granja_nombre'] ?? ''));
            $fechaHoraRegistro = \mort_fecha_hora_desde_record($record);

            $fechaYmd = preg_replace('/[^0-9]/', '', $fechaRegistro);
            if (strlen($fechaYmd) > 8) {
                $fechaYmd = substr($fechaYmd, 0, 8);
            }

            $detalles = $record['detalles'] ?? [];
            if (!is_array($detalles)) {
                $detalles = [];
            }

            // Registro sin mortalidad (0 aves): vive solo en el espejo san_fact_mortalidad_cab/det.
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
                foreach ($detalles as $det) {
                    if (is_array($det) && (int) ($det['cantidad'] ?? 0) > 0) {
                        $hayCantidad = true;
                        break;
                    }
                }
                $esRegistroCero = !$hayCantidad;
            }
            if ($esRegistroCero && !\mort_fact_tablas_disponibles($conn)) {
                $errores[] = "Registro {$cabId}: registro sin mortalidad (0 aves) requiere las tablas "
                    . 'san_fact_mortalidad_cab/san_fact_mortalidad_det en BD (migración pendiente)';
                continue;
            }

            $existente = MortalidadModel::cabExiste($conn, $cabId);

            if ($existente === null) {
                // ---- Registro nuevo ----
                try {
                    MortalidadModel::validarFechaCierre($conn, $fechaRegistro);
                } catch (\Throwable $e) {
                    $msg = $e->getMessage();
                    if (in_array($msg, ['CIERRE_DIA', 'CIERRE_MES', 'CIERRE_ANIO'], true)) {
                        $errorCodeCierre = $msg;
                        $errores[] = "Registro {$cabId}: {$msg}";
                    } else {
                        $errores[] = "Registro {$cabId}: " . $msg;
                    }
                    continue;
                }

                try {
                    if ($esRegistroCero) {
                        $doc = '';
                        $serie = '';
                        $numero = '';
                    } else {
                        $doc = 'GI';
                        $serie = \mort_generar_serie($granja, $tipoMortalidad);
                        $numero = \mort_siguiente_numero($conn, $doc, $serie);
                    }
                } catch (\Throwable $e) {
                    $errores[] = "Registro {$cabId}: " . $e->getMessage();
                    continue;
                }

                $lineasZonas = [];
                $posicionAuto = 1;
                $gruposVistos = [];
                foreach ($detalles as $det) {
                    if (!is_array($det)) {
                        continue;
                    }

                    $grupoRaw = trim((string) ($det['grupo_id'] ?? $det['grupoId'] ?? $det['posicion'] ?? ''));
                    if ($grupoRaw !== '' && ctype_digit($grupoRaw)) {
                        $posInt = max(1, (int) $grupoRaw);
                    } else {
                        $posInt = $posicionAuto;
                        $posicionAuto++;
                    }
                    if (!isset($gruposVistos[$posInt])) {
                        $gruposVistos[$posInt] = true;
                        if ($posInt >= $posicionAuto) {
                            $posicionAuto = $posInt + 1;
                        }
                    }

                    $detId = trim((string) ($det['id'] ?? ''));
                    $sexo = strtoupper(substr(trim((string) ($det['sexo'] ?? 'M')), 0, 1));
                    if ($sexo !== 'M' && $sexo !== 'H') {
                        $sexo = 'M';
                    }
                    if ($detId === '') {
                        $detId = sprintf('%s-%02d-%s', $cabId, $posInt, $sexo);
                    }

                    $cantidad = (int) ($det['cantidad'] ?? 0);
                    $codMortalidad = trim((string) ($det['causa_codigo'] ?? ''));
                    $observacion = trim((string) ($det['observacion'] ?? ''));
                    $nomMortalidad = trim((string) ($det['causa_nombre'] ?? ''));

                    // Etapas del proceso de despacho por detalle (mapa columna camelCase → cantidad).
                    $etapas = $tipoMortalidad === 'despacho' ? \mort_fact_normalizar_etapas($det) : [];

                    $img = self::procesarImagenesDetalle(
                        $detId,
                        $granja,
                        $galpon,
                        $numero,
                        $fechaYmd,
                        $sexo,
                        $carpetaFs
                    );
                    $evidencia = count($img['rutas']) > 0 ? implode(',', $img['rutas']) : null;

                    $lineasZonas[] = [
                        'id' => $detId,
                        'posicion' => $posInt,
                        'sexo' => $sexo,
                        'cantidad' => $cantidad,
                        'codMortalidad' => $codMortalidad !== '' ? $codMortalidad : null,
                        'nomMortalidad' => $nomMortalidad,
                        'evidencia' => $evidencia ?? '',
                        'observacion' => $observacion,
                        'proceso_etapas' => $etapas,
                    ];
                }

                if ($lineasZonas === [] && !$esRegistroCero) {
                    $errores[] = "Registro {$cabId}: sin detalles válidos";
                    continue;
                }

                $cabAux = self::cabAux(
                    $cabId,
                    $doc,
                    $serie,
                    $numero,
                    $tipoMortalidad,
                    $subtipoTransporte,
                    $subtipoProduccion,
                    $granja,
                    $campania,
                    $granjaNombre,
                    $galpon,
                    $fechaRegistro,
                    $fechaLlegada,
                    $observaciones,
                    $usuarioRegistro,
                    $fechaHoraRegistro
                );

                mysqli_begin_transaction($conn);
                try {
                    if (!$esRegistroCero) {
                        \mort_zonas_registrar(
                            $conn,
                            $cabId,
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
                            $tidandroid,
                            $lineasZonas,
                            $observaciones
                        );
                    }
                    \mort_fact_espejo_guardar($conn, $cabAux, $lineasZonas);
                    mysqli_commit($conn);
                } catch (\Throwable $e) {
                    mysqli_rollback($conn);
                    $errores[] = "Registro {$cabId}: " . $e->getMessage();
                    continue;
                }

                $guardados[] = [
                    'id' => $cabId,
                    'doc' => $doc,
                    'serie' => $serie,
                    'numero' => $numero,
                    'codigo' => $doc !== '' && $serie !== ''
                        ? \mort_codigo_display($doc, $serie, $numero)
                        : '',
                    'duplicado' => false,
                ];

                continue;
            }

            // ---- Reenvío: la cabecera ya existe. Reconciliar detalles/imágenes ----
            $duplicados++;

            $doc = (string) $existente['doc'];
            $serie = (string) $existente['serie'];
            $numero = (string) $existente['numero'];

            $lineasZonas = [];
            $lineasAgregadas = 0;
            $imagenesAgregadas = 0;
            $posicionAuto = 1;
            $gruposVistos = [];

            foreach ($detalles as $det) {
                if (!is_array($det)) {
                    continue;
                }

                $grupoRaw = trim((string) ($det['grupo_id'] ?? $det['grupoId'] ?? $det['posicion'] ?? ''));
                if ($grupoRaw !== '' && ctype_digit($grupoRaw)) {
                    $posInt = max(1, (int) $grupoRaw);
                } else {
                    $posInt = $posicionAuto;
                    $posicionAuto++;
                }
                if (!isset($gruposVistos[$posInt])) {
                    $gruposVistos[$posInt] = true;
                    if ($posInt >= $posicionAuto) {
                        $posicionAuto = $posInt + 1;
                    }
                }

                $detId = trim((string) ($det['id'] ?? ''));
                $sexo = strtoupper(substr(trim((string) ($det['sexo'] ?? 'M')), 0, 1));
                if ($sexo !== 'M' && $sexo !== 'H') {
                    $sexo = 'M';
                }
                if ($detId === '') {
                    $detId = sprintf('%s-%02d-%s', $cabId, $posInt, $sexo);
                }

                $cantidad = (int) ($det['cantidad'] ?? 0);
                $codMortalidad = trim((string) ($det['causa_codigo'] ?? ''));
                $observacion = trim((string) ($det['observacion'] ?? ''));
                $nomMortalidad = trim((string) ($det['causa_nombre'] ?? ''));

                $etapas = $tipoMortalidad === 'despacho' ? \mort_fact_normalizar_etapas($det) : [];

                if (!MortalidadModel::moviLineaExiste($conn, $cabId, $posInt, $sexo)) {
                    $lineasAgregadas++;
                }

                $img = self::procesarImagenesDetalle(
                    $detId,
                    $granja,
                    $galpon,
                    $numero,
                    $fechaYmd,
                    $sexo,
                    $carpetaFs
                );
                $imagenesAgregadas += $img['nuevas'];
                $evidencia = count($img['rutas']) > 0 ? implode(',', $img['rutas']) : null;

                $lineasZonas[] = [
                    'id' => $detId,
                    'posicion' => $posInt,
                    'sexo' => $sexo,
                    'sexoAnterior' => $sexo,
                    'cantidad' => $cantidad,
                    'codMortalidad' => $codMortalidad !== '' ? $codMortalidad : null,
                    'nomMortalidad' => $nomMortalidad,
                    'evidencia' => $evidencia ?? '',
                    'observacion' => $observacion,
                    'proceso_etapas' => $etapas,
                ];
            }

            if ($lineasZonas === []) {
                // Reenvío sin líneas: si el registro quedó en 0, reconciliar solo el espejo auxiliar.
                if ($esRegistroCero) {
                    $cabAux = self::cabAux(
                        $cabId,
                        $doc,
                        $serie,
                        $numero,
                        $tipoMortalidad,
                        $subtipoTransporte,
                        $subtipoProduccion,
                        $granja,
                        $campania,
                        $granjaNombre,
                        $galpon,
                        $fechaRegistro,
                        $fechaLlegada,
                        $observaciones,
                        $usuarioRegistro,
                        $fechaHoraRegistro
                    );
                    mysqli_begin_transaction($conn);
                    try {
                        \mort_fact_espejo_guardar($conn, $cabAux, []);
                        mysqli_commit($conn);
                    } catch (\Throwable $e) {
                        mysqli_rollback($conn);
                        $errores[] = "Registro {$cabId}: " . $e->getMessage();
                        continue;
                    }
                }

                $guardados[] = [
                    'id' => $cabId,
                    'doc' => $doc,
                    'serie' => $serie,
                    'numero' => $numero,
                    'codigo' => \mort_codigo_display($doc, $serie, $numero),
                    'duplicado' => true,
                    'reparado' => false,
                    'lineas_agregadas' => 0,
                    'imagenes_agregadas' => 0,
                ];
                continue;
            }

            $cabAux = self::cabAux(
                $cabId,
                $doc,
                $serie,
                $numero,
                $tipoMortalidad,
                $subtipoTransporte,
                $subtipoProduccion,
                $granja,
                $campania,
                $granjaNombre,
                $galpon,
                $fechaRegistro,
                $fechaLlegada,
                $observaciones,
                $usuarioRegistro,
                $fechaHoraRegistro
            );

            mysqli_begin_transaction($conn);
            try {
                \mort_zonas_actualizar_desde_fact(
                    $conn,
                    $cabId,
                    $tipoMortalidad,
                    $subtipoTransporte,
                    $subtipoProduccion,
                    $granja,
                    $campania,
                    $galpon,
                    $fechaRegistro,
                    $fechaLlegada,
                    $doc,
                    $serie,
                    $numero,
                    $usuarioRegistro,
                    $fechaHoraRegistro,
                    $lineasZonas,
                    $observaciones,
                    true
                );
                \mort_fact_espejo_guardar($conn, $cabAux, $lineasZonas);
                mysqli_commit($conn);
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $errores[] = "Registro {$cabId}: " . $e->getMessage();
                continue;
            }

            $guardados[] = [
                'id' => $cabId,
                'doc' => $doc,
                'serie' => $serie,
                'numero' => $numero,
                'codigo' => \mort_codigo_display($doc, $serie, $numero),
                'duplicado' => true,
                'reparado' => true,
                'lineas_agregadas' => $lineasAgregadas,
                'imagenes_agregadas' => $imagenesAgregadas,
            ];
        }

        return [
            'guardados' => $guardados,
            'duplicados' => $duplicados,
            'errores' => $errores,
            'errorCodeCierre' => $errorCodeCierre,
        ];
    }

    /** Reparación de registros existentes (reparar_registro_movil.php). */
    public static function repararLote(\mysqli $conn, array $records, string $carpetaFs): array
    {
        $reparados = [];
        $omitidos = 0;
        $errores = [];

        foreach ($records as $idx => $record) {
            if (!is_array($record)) {
                $errores[] = "Registro #{$idx}: formato inválido";
                continue;
            }

            $cabId = trim((string) ($record['tuuid'] ?? $record['id'] ?? ''));
            if ($cabId === '' || strlen($cabId) > 36) {
                $errores[] = "Registro #{$idx}: falta tuuid/id válido";
                continue;
            }

            $existente = MortalidadModel::cabParaReparar($conn, $cabId);
            if ($existente === null) {
                $errores[] = "Registro {$cabId}: cabecera no existe en cabe_zonas. Use guardar_mortalidad_movil.php para crearla.";
                continue;
            }

            $granja = (string) $existente['granja'];
            $galpon = (string) $existente['galpon'];
            $numero = (string) $existente['numero'];
            $fechaRegistro = (string) $existente['fechaRegistro'];
            $fechaYmd = preg_replace('/[^0-9]/', '', $fechaRegistro);
            if (strlen($fechaYmd) > 8) {
                $fechaYmd = substr($fechaYmd, 0, 8);
            }

            $detalles = $record['detalles'] ?? [];
            if (!is_array($detalles)) {
                $detalles = [];
            }

            $lineasZonas = [];
            $lineasAgregadas = 0;
            $imagenesAgregadas = 0;
            $posicionAuto = 1;
            $gruposVistos = [];

            foreach ($detalles as $det) {
                if (!is_array($det)) {
                    continue;
                }

                $grupoRaw = trim((string) ($det['grupo_id'] ?? $det['grupoId'] ?? $det['posicion'] ?? ''));
                if ($grupoRaw !== '' && ctype_digit($grupoRaw)) {
                    $posInt = max(1, (int) $grupoRaw);
                } else {
                    $posInt = $posicionAuto;
                    $posicionAuto++;
                }
                if (!isset($gruposVistos[$posInt])) {
                    $gruposVistos[$posInt] = true;
                    if ($posInt >= $posicionAuto) {
                        $posicionAuto = $posInt + 1;
                    }
                }

                $detId = trim((string) ($det['id'] ?? ''));
                $sexo = strtoupper(substr(trim((string) ($det['sexo'] ?? 'M')), 0, 1));
                if ($sexo !== 'M' && $sexo !== 'H') {
                    $sexo = 'M';
                }
                if ($detId === '') {
                    $detId = sprintf('%s-%02d-%s', $cabId, $posInt, $sexo);
                }

                $cantidad = (int) ($det['cantidad'] ?? 0);
                $codMortalidad = trim((string) ($det['causa_codigo'] ?? ''));
                $observacion = trim((string) ($det['observacion'] ?? ''));

                $existeLinea = MortalidadModel::moviLineaExiste($conn, $cabId, $posInt, $sexo);
                if (!$existeLinea) {
                    $lineasAgregadas++;
                }

                $img = self::procesarImagenesDetalle($detId, $granja, $galpon, $numero, $fechaYmd, $sexo, $carpetaFs);
                $imagenesAgregadas += $img['nuevas'];
                $evidencia = count($img['rutas']) > 0 ? implode(',', $img['rutas']) : null;

                $lineasZonas[] = [
                    'id' => $detId,
                    'posicion' => $posInt,
                    'sexo' => $sexo,
                    'sexoAnterior' => $sexo,
                    'cantidad' => $cantidad,
                    'codMortalidad' => $codMortalidad !== '' ? $codMortalidad : null,
                    'evidencia' => $evidencia ?? '',
                    'observacion' => $observacion,
                ];
            }

            if ($lineasZonas === []) {
                $omitidos++;
                continue;
            }

            mysqli_begin_transaction($conn);
            try {
                \mort_zonas_actualizar_desde_fact(
                    $conn,
                    $cabId,
                    (string) $existente['tipoMortalidad'],
                    $existente['subtipoTransporte'],
                    $existente['subtipoProduccion'],
                    (string) $existente['granja'],
                    (string) $existente['campania'],
                    (string) $existente['galpon'],
                    (string) $existente['fechaRegistro'],
                    $existente['fechaLlegada'],
                    (string) $existente['doc'],
                    (string) $existente['serie'],
                    (string) $existente['numero'],
                    (string) $existente['usuarioRegistro'],
                    (string) $existente['fechaHoraRegistro'],
                    $lineasZonas,
                    (string) $existente['observaciones']
                );
                mysqli_commit($conn);
            } catch (\Throwable $e) {
                mysqli_rollback($conn);
                $errores[] = "Registro {$cabId}: " . $e->getMessage();
                continue;
            }

            $reparados[] = [
                'id' => $cabId,
                'doc' => $existente['doc'],
                'serie' => $existente['serie'],
                'numero' => $existente['numero'],
                'lineas_agregadas' => $lineasAgregadas,
                'imagenes_agregadas' => $imagenesAgregadas,
                'lineas_actualizadas' => count($lineasZonas),
            ];
        }

        return [
            'reparados' => $reparados,
            'omitidos' => $omitidos,
            'errores' => $errores,
        ];
    }

    /** @return array<string, mixed> */
    private static function cabAux(
        string $cabId,
        string $doc,
        string $serie,
        string $numero,
        string $tipoMortalidad,
        ?string $subtipoTransporte,
        ?string $subtipoProduccion,
        string $granja,
        string $campania,
        string $granjaNombre,
        string $galpon,
        string $fechaRegistro,
        ?string $fechaLlegada,
        string $observaciones,
        string $usuarioRegistro,
        string $fechaHoraRegistro
    ): array {
        return [
            'id' => $cabId,
            'doc' => $doc,
            'serie' => $serie,
            'numero' => $numero,
            'tipoMortalidad' => $tipoMortalidad,
            'subtipoTransporte' => $subtipoTransporte,
            'subtipoProduccion' => $subtipoProduccion,
            'granja' => $granja,
            'campania' => $campania,
            'granjaNombre' => $granjaNombre,
            'galpon' => $galpon,
            'fechaRegistro' => $fechaRegistro,
            'fechaLlegada' => $fechaLlegada,
            'observaciones' => $observaciones,
            'usuarioRegistro' => $usuarioRegistro,
            'fechaHoraRegistro' => $fechaHoraRegistro,
        ];
    }
}
