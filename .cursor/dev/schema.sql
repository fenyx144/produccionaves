-- Esquema de desarrollo mínimo para el entorno Cloud Agent.
--
-- La aplicación real se conecta a la base de datos ERP "Joya", que NO forma
-- parte de este repositorio. Aquí recreamos únicamente las tablas y columnas
-- que consultan los módulos activos (login + análisis de mortalidad en
-- despacho) para poder ejecutar y demostrar la aplicación de extremo a extremo
-- en un entorno de desarrollo aislado.
--
-- Este esquema es SOLO para desarrollo local; en producción las tablas viven
-- en el ERP compartido con muchas más columnas.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- Autenticación (core/lib/usuario_auth_lib.php)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS usuario;
CREATE TABLE usuario (
    codigo   VARCHAR(50)  NOT NULL,
    nombre   VARCHAR(120) NOT NULL DEFAULT '',
    password VARBINARY(64) NULL,
    estado   CHAR(1)      NOT NULL DEFAULT 'A',
    PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS conempre;
CREATE TABLE conempre (
    epre VARCHAR(4)   NOT NULL,
    enom VARCHAR(120) NOT NULL,
    PRIMARY KEY (epre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Auditoría de acciones (core/lib/historial_acciones.php)
DROP TABLE IF EXISTS san_dim_historial_acciones;
CREATE TABLE san_dim_historial_acciones (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    cod_usuario       VARCHAR(50)  NULL,
    nom_usuario       VARCHAR(120) NULL,
    accion            VARCHAR(50)  NULL,
    tabla_afectada    VARCHAR(120) NULL,
    registro_id       VARCHAR(120) NULL,
    datos_previos     LONGTEXT     NULL,
    datos_nuevos      LONGTEXT     NULL,
    descripcion       TEXT         NULL,
    fechaHora         DATETIME     NULL,
    ip                VARCHAR(64)  NULL,
    ubicacion_gps     VARCHAR(120) NULL,
    dispositivo       VARCHAR(60)  NULL,
    sistema_operativo VARCHAR(60)  NULL,
    navegador         VARCHAR(60)  NULL,
    user_agent        TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Movimientos de zonas: ventas (S700) y mortalidad (S808)
-- (modules/mortalidad/{ventas,despacho}/*)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS movi_zonas;
CREATE TABLE movi_zonas (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    tcodigo      VARCHAR(20)  NOT NULL,          -- P0001001 (macho) / P0001002 (hembra)
    tcodtra      VARCHAR(10)  NOT NULL,          -- S700 venta / S808 mortalidad
    tfectra      DATETIME     NOT NULL,
    tcencos      VARCHAR(12)  NOT NULL,          -- granja(3) + campania(3)
    tcodint      VARCHAR(10)  NOT NULL,          -- galpón
    tcantid      DECIMAL(12,2) NOT NULL DEFAULT 0,
    tcategoria   VARCHAR(30)  NULL,
    flujo        VARCHAR(30)  NULL,
    tcod_mortgrs VARCHAR(10)  NULL,              -- causa de mortalidad
    -- Claves de enlace con la cabecera del documento (cabe_zonas).
    mark         VARCHAR(10)  NULL,
    treg         VARCHAR(10)  NULL,
    tdoc         VARCHAR(10)  NULL,
    tserie       VARCHAR(12)  NULL,
    tnumfac      VARCHAR(12)  NULL,
    KEY idx_mz_busqueda (tcodtra, tcodigo, tcencos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cabecera de documentos de zonas (mortalidad_listado_lib.php: filtros de granja).
DROP TABLE IF EXISTS cabe_zonas;
CREATE TABLE cabe_zonas (
    mark    VARCHAR(10) NOT NULL,   -- JI1/JT2/JP3/JD4
    treg    VARCHAR(10) NOT NULL,
    tdoc    VARCHAR(10) NOT NULL,
    tserie  VARCHAR(12) NOT NULL,
    tnumfac VARCHAR(12) NOT NULL,
    KEY idx_cz (mark, treg, tdoc, tserie, tnumfac)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Centros de costo (nombres de granja) - mort_despacho_nombres_granja()
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS ccos;
CREATE TABLE ccos (
    codigo VARCHAR(12)  NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    swac   CHAR(1)      NOT NULL DEFAULT 'A',
    PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Tablas espejo de la app móvil para etapas del despacho
-- (core/lib/gri/mortalidad_fact_aux_lib.php)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS san_fact_mortalidad_det;
DROP TABLE IF EXISTS san_fact_mortalidad_cab;
CREATE TABLE san_fact_mortalidad_cab (
    id                CHAR(36)     NOT NULL,
    doc               VARCHAR(2)   NULL,
    serie             VARCHAR(12)  NULL,
    numero            VARCHAR(8)   NULL,
    tipoMortalidad    VARCHAR(20)  NOT NULL DEFAULT 'produccion',
    subtipoTransporte VARCHAR(20)  NULL,
    subtipoProduccion VARCHAR(30)  NULL,
    granja            VARCHAR(3)   NULL,
    campania          VARCHAR(3)   NULL,
    granjaNombre      VARCHAR(120) NULL,
    galpon            VARCHAR(20)  NULL,
    fechaRegistro     DATE         NULL,
    fechaLlegada      DATE         NULL,
    observaciones     TEXT         NULL,
    usuarioRegistro   VARCHAR(50)  NULL,
    fechaHoraRegistro DATETIME     NULL,
    PRIMARY KEY (id),
    KEY idx_cab_tipo (tipoMortalidad, fechaRegistro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE san_fact_mortalidad_det (
    id                 CHAR(36)     NOT NULL,
    cabId              CHAR(36)     NOT NULL,
    posicion           INT          NOT NULL DEFAULT 1,
    sexo               CHAR(1)      NOT NULL DEFAULT 'M',
    cantidad           INT          NOT NULL DEFAULT 0,
    codMortalidad      VARCHAR(20)  NULL,
    nomMortalidad      VARCHAR(200) NULL,
    observacion        TEXT         NULL,
    evidencia          TEXT         NULL,
    procesoPreparar    INT NOT NULL DEFAULT 0,
    procesoAcorralar   INT NOT NULL DEFAULT 0,
    procesoSeleccionar INT NOT NULL DEFAULT 0,
    procesoEnjabar     INT NOT NULL DEFAULT 0,
    procesoPesar       INT NOT NULL DEFAULT 0,
    procesoEstibar     INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_det_cab (cabId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Motivos de mortalidad (mort_fact_nom_mortalidad + API catálogos)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS regmotivo_mortalidadgrs;
CREATE TABLE regmotivo_mortalidadgrs (
    tcod_mort VARCHAR(10)  NOT NULL,
    tnom_mort VARCHAR(120) NOT NULL,
    PRIMARY KEY (tcod_mort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------------
-- Selector de granjas del dashboard de despacho
-- (core/lib/hc/hc_granjas_repository.php)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS regcencosgalpones;
CREATE TABLE regcencosgalpones (
    tcencos VARCHAR(12)  NOT NULL,
    tnomcen VARCHAR(120) NOT NULL,
    PRIMARY KEY (tcencos)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS pi_dim_caracteristicas;
CREATE TABLE pi_dim_caracteristicas (
    id     INT          NOT NULL,
    nombre VARCHAR(60)  NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS pi_dim_detalles;
CREATE TABLE pi_dim_detalles (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    id_granja        VARCHAR(12) NOT NULL,
    id_caracteristica INT        NULL,
    dato             VARCHAR(120) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
