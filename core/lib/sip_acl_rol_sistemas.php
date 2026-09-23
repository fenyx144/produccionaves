<?php

declare(strict_types=1);

/**
 * Rol Sistemas vía ACL (adm_usuario_rol + adm_rol, programa Sanidad).
 * Requiere funciones base de sip_acl_sanidad.php cargadas antes.
 * Misma lógica que sanidad/modules/sip/historia-clinica/hc_obs_listar_query.php.
 */

if (!function_exists('sip_acl_nombres_rol_sistemas')) {

    /**
     * @return list<string>
     */
    function sip_acl_nombres_rol_sistemas(): array
    {
        return ['Sistemas'];
    }

    function sip_acl_usuario_tiene_rol_sistemas(mysqli $conn, ?string $codigoUsuario = null): bool
    {
        if ($codigoUsuario === null) {
            $codigoUsuario = trim((string) ($_SESSION['usuario'] ?? ''));
        } else {
            $codigoUsuario = trim($codigoUsuario);
        }
        if ($codigoUsuario === '') {
            return false;
        }
        if (!sip_acl_table_exists($conn, 'adm_usuario_rol') || !sip_acl_table_exists($conn, 'adm_rol')) {
            return false;
        }

        foreach (sip_acl_nombres_rol_sistemas() as $nomRol) {
            $nomRol = trim($nomRol);
            if ($nomRol === '') {
                continue;
            }

            $sql = 'SELECT 1 FROM adm_usuario_rol aur
                    INNER JOIN adm_rol r ON UPPER(TRIM(r.cod_rol)) = UPPER(TRIM(aur.cod_rol))';
            $types = 'ss';
            $bindCodigo = $codigoUsuario;
            $bindNom = $nomRol;
            $bindProg = null;

            if (sip_acl_adm_rol_tiene_id_programa($conn)) {
                try {
                    $bindProg = sip_acl_id_programa_sanidad($conn);
                } catch (RuntimeException $e) {
                    return false;
                }
                $sql .= ' AND CAST(r.id_programa AS UNSIGNED) = ?';
                $types = 'iss';
            }

            $sql .= ' WHERE aur.codigo = ? AND UPPER(TRIM(r.nom_rol)) = UPPER(TRIM(?)) LIMIT 1';

            $st = $conn->prepare($sql);
            if (!$st) {
                continue;
            }

            if ($bindProg !== null) {
                $st->bind_param($types, $bindProg, $bindCodigo, $bindNom);
            } else {
                $st->bind_param($types, $bindCodigo, $bindNom);
            }

            $st->execute();
            $res = $st->get_result();
            $ok = ($res && $res->fetch_assoc());
            $st->close();

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    function sip_acl_sql_not_exists_usuario_rol_sistemas(mysqli $conn, string $columnaCodigo = 'h.cod_usuario'): string
    {
        if (!sip_acl_table_exists($conn, 'adm_usuario_rol') || !sip_acl_table_exists($conn, 'adm_rol')) {
            return '';
        }

        $orNom = [];
        foreach (sip_acl_nombres_rol_sistemas() as $nomRol) {
            $nomRol = trim($nomRol);
            if ($nomRol !== '') {
                $orNom[] = "UPPER(TRIM(r.nom_rol)) = UPPER('" . mysqli_real_escape_string($conn, $nomRol) . "')";
            }
        }
        if ($orNom === []) {
            return '';
        }

        $condProg = '';
        if (sip_acl_adm_rol_tiene_id_programa($conn)) {
            try {
                $idProg = (int) sip_acl_id_programa_sanidad($conn);
                if ($idProg > 0) {
                    $condProg = ' AND CAST(r.id_programa AS UNSIGNED) = ' . $idProg;
                }
            } catch (RuntimeException $e) {
                return '';
            }
        }

        $col = trim($columnaCodigo);
        if ($col === '') {
            $col = 'h.cod_usuario';
        }

        return 'NOT EXISTS (
            SELECT 1
            FROM adm_usuario_rol aur
            INNER JOIN adm_rol r ON UPPER(TRIM(r.cod_rol)) = UPPER(TRIM(aur.cod_rol))
            WHERE aur.codigo = ' . $col . '
              AND (' . implode(' OR ', $orNom) . ')' . $condProg . '
        )';
    }

    function sip_acl_require_rol_sistemas(mysqli $conn, string $mensaje = 'No tiene permiso. Se requiere rol Sistemas.'): void
    {
        if (sip_acl_usuario_tiene_rol_sistemas($conn)) {
            return;
        }
        mysqli_close($conn);
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $mensaje,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
