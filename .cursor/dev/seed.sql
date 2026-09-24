-- Datos de ejemplo para el entorno de desarrollo Cloud Agent.
-- Genera un escenario coherente de "mortalidad en despacho" para la granja 601,
-- campaña 001, galpón 1, en la fecha 2026-09-15.

SET NAMES utf8mb4;

-- Clave de empresa usada por AES_ENCRYPT en el login.
INSERT INTO conempre (epre, enom) VALUES ('RS', 'CLAVE_DESARROLLO_RS')
    ON DUPLICATE KEY UPDATE enom = VALUES(enom);

-- Usuario demo. La contraseña se guarda igual que en el ERP:
--   LEFT(AES_ENCRYPT('1234', enom), 8)
-- Credenciales de desarrollo -> usuario: ADMIN  contraseña: 1234
DELETE FROM usuario WHERE codigo = 'ADMIN';
INSERT INTO usuario (codigo, nombre, password, estado)
SELECT 'ADMIN', 'Administrador Demo', LEFT(AES_ENCRYPT('1234', enom), 8), 'A'
FROM conempre WHERE epre = 'RS' LIMIT 1;

-- Nombres de centro de costo: base de granja ("6xx000") y campaña ("601001").
INSERT INTO ccos (codigo, nombre, swac) VALUES
    ('601000', 'GRANJA RINCONADA 601', 'A'),
    ('601001', 'GRANJA RINCONADA 601 C=001', 'A')
    ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), swac = VALUES(swac);

-- Catálogo de motivos de mortalidad.
INSERT INTO regmotivo_mortalidadgrs (tcod_mort, tnom_mort) VALUES
    ('05', 'Asfixia'),
    ('14', 'Muerte subita'),
    ('15', 'Degollamiento'),
    ('17', 'Situacion especial'),
    ('18', 'Aplastamiento'),
    ('19', 'Situacion especial 2')
    ON DUPLICATE KEY UPDATE tnom_mort = VALUES(tnom_mort);

-- Selector de granjas del dashboard.
INSERT INTO regcencosgalpones (tcencos, tnomcen) VALUES ('601001', 'RINCONADA')
    ON DUPLICATE KEY UPDATE tnomcen = VALUES(tnomcen);
INSERT INTO pi_dim_caracteristicas (id, nombre) VALUES (1, 'ZONA'), (2, 'SUBZONA')
    ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);
DELETE FROM pi_dim_detalles WHERE id_granja = '601';
INSERT INTO pi_dim_detalles (id_granja, id_caracteristica, dato) VALUES
    ('601', 1, 'NORTE'),
    ('601', 2, 'SUBZONA-A');

-- Cabecera del documento de zonas (mark JD4 = despacho) para los filtros.
DELETE FROM cabe_zonas WHERE mark = 'JD4' AND tserie = 'S001' AND tnumfac = '00000001';
INSERT INTO cabe_zonas (mark, treg, tdoc, tserie, tnumfac) VALUES
    ('JD4', '001', 'FD', 'S001', '00000001');

-- Movimientos de zonas para el 2026-09-15, cenco 601001, galpón 1.
-- Todos enlazan con la cabecera cabe_zonas de arriba (mark/treg/tdoc/tserie/tnumfac).
DELETE FROM movi_zonas WHERE tcencos = '601001';
INSERT INTO movi_zonas (tcodigo, tcodtra, tfectra, tcencos, tcodint, tcantid, tcategoria, flujo, tcod_mortgrs, mark, treg, tdoc, tserie, tnumfac) VALUES
    -- Ventas del día (S700): hay salida de machos y hembras.
    ('P0001001', 'S700', '2026-09-15 08:00:00', '601001', '1', 1000, 'DESPACHO', 'DESPACHO', NULL, 'JD4', '001', 'FD', 'S001', '00000001'),
    ('P0001002', 'S700', '2026-09-15 08:10:00', '601001', '1',  800, 'DESPACHO', 'DESPACHO', NULL, 'JD4', '001', 'FD', 'S001', '00000001'),
    -- Mortalidad de despacho (S808) por causa.
    ('P0001001', 'S808', '2026-09-15 09:00:00', '601001', '1',   10, 'DESPACHO', 'DESPACHO', '05', 'JD4', '001', 'FD', 'S001', '00000001'),
    ('P0001001', 'S808', '2026-09-15 09:05:00', '601001', '1',    5, 'DESPACHO', 'DESPACHO', '14', 'JD4', '001', 'FD', 'S001', '00000001'),
    ('P0001002', 'S808', '2026-09-15 09:10:00', '601001', '1',    3, 'DESPACHO', 'DESPACHO', '15', 'JD4', '001', 'FD', 'S001', '00000001'),
    ('P0001001', 'S808', '2026-09-15 09:15:00', '601001', '1',    2, 'DESPACHO', 'DESPACHO', '18', 'JD4', '001', 'FD', 'S001', '00000001');

-- Espejo de etapas del proceso de despacho (san_fact_mortalidad_*).
DELETE d FROM san_fact_mortalidad_det d
    JOIN san_fact_mortalidad_cab c ON c.id = d.cabId
    WHERE c.id = 'cab-despacho-demo-0001';
DELETE FROM san_fact_mortalidad_cab WHERE id = 'cab-despacho-demo-0001';

INSERT INTO san_fact_mortalidad_cab
    (id, doc, serie, numero, tipoMortalidad, granja, campania, granjaNombre, galpon, fechaRegistro, usuarioRegistro, fechaHoraRegistro)
VALUES
    ('cab-despacho-demo-0001', '01', 'S001', '00000001', 'despacho', '601', '001', 'GRANJA RINCONADA 601', '1', '2026-09-15', 'ADMIN', '2026-09-15 09:30:00');

INSERT INTO san_fact_mortalidad_det
    (id, cabId, posicion, sexo, cantidad, codMortalidad, nomMortalidad,
     procesoPreparar, procesoAcorralar, procesoSeleccionar, procesoEnjabar, procesoPesar, procesoEstibar)
VALUES
    ('det-despacho-demo-M', 'cab-despacho-demo-0001', 1, 'M', 17, '05', 'Asfixia',
     3, 2, 1, 4, 5, 2),
    ('det-despacho-demo-H', 'cab-despacho-demo-0001', 2, 'H',  3, '15', 'Degollamiento',
     1, 0, 1, 1, 2, 0);
