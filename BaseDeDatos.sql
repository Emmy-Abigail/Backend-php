-- =====================================================================
-- Sistema de GestiÃ³n de EnvÃ­os (RIVA) â€” Esquema de base de datos v3
-- MySQL 8 Â· InnoDB Â· utf8mb4
-- Orden de creaciÃ³n: primero catÃ¡logos, luego tablas que los referencian.
-- Todas las claves forÃ¡neas usan la acciÃ³n por defecto (RESTRICT):
-- no se permite borrar un registro que otro usa.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. CATÃLOGOS
-- ---------------------------------------------------------------------

-- Zonas de Lima y Callao 
CREATE TABLE zonas (
    id      TINYINT      PRIMARY KEY,
    codigo  VARCHAR(10)  NOT NULL,
    nombre  VARCHAR(30)  NOT NULL,
    UNIQUE KEY uq_zonas_codigo (codigo)
) ENGINE=InnoDB;

-- Departamentos, provincias y distritos: UBIGEO del INEI como clave
CREATE TABLE departamentos (
    ubigeo  CHAR(2)      PRIMARY KEY,
    nombre  VARCHAR(50)  NOT NULL
) ENGINE=InnoDB;

CREATE TABLE provincias (
    ubigeo               CHAR(4)      PRIMARY KEY,
    ubigeo_departamento  CHAR(2)      NOT NULL,
    nombre               VARCHAR(50)  NOT NULL,
    CONSTRAINT fk_provincias_departamento FOREIGN KEY (ubigeo_departamento) REFERENCES departamentos(ubigeo)
) ENGINE=InnoDB;

CREATE TABLE distritos (
    ubigeo            CHAR(6)       PRIMARY KEY,
    ubigeo_provincia  CHAR(4)       NOT NULL,
    id_zona           TINYINT       NOT NULL,
    nombre_oficial    VARCHAR(80)   NOT NULL,   -- como lo registra el INEI
    nombre_mostrado   VARCHAR(80)   NOT NULL,   -- como aparece en la rÃºbrica
    lat               DECIMAL(9,6)  NOT NULL,   -- punto representativo para ordenar paradas
    lng               DECIMAL(9,6)  NOT NULL,
    activo            BOOLEAN       NOT NULL DEFAULT TRUE,
    INDEX idx_distritos_zona (id_zona),
    CONSTRAINT fk_distritos_provincia FOREIGN KEY (ubigeo_provincia) REFERENCES provincias(ubigeo),
    CONSTRAINT fk_distritos_zona      FOREIGN KEY (id_zona)          REFERENCES zonas(id)
) ENGINE=InnoDB;

-- Una sede por zona; sus coordenadas son el punto de partida de las rutas
CREATE TABLE sedes (
    id               TINYINT       PRIMARY KEY,
    nombre           VARCHAR(60)   NOT NULL,
    id_zona          TINYINT       NOT NULL,
    ubigeo_distrito  CHAR(6)       NOT NULL,
    direccion        VARCHAR(255)  NOT NULL,
    lat              DECIMAL(9,6)  NOT NULL,     -- origen (P0) de las rutas de la sede
    lng              DECIMAL(9,6)  NOT NULL,
    activa           BOOLEAN       NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_sedes_zona (id_zona),
    CONSTRAINT fk_sedes_zona     FOREIGN KEY (id_zona)         REFERENCES zonas(id),
    CONSTRAINT fk_sedes_distrito FOREIGN KEY (ubigeo_distrito) REFERENCES distritos(ubigeo)
) ENGINE=InnoDB;

-- Capacidad de cada tipo de vehÃ­culo
CREATE TABLE tipos_vehiculo (
    id                 TINYINT       PRIMARY KEY,
    codigo             VARCHAR(12)   NOT NULL,
    nombre             VARCHAR(30)   NOT NULL,
    nivel              TINYINT       NOT NULL,   -- 1 = el mÃ¡s pequeÃ±o
    peso_max_total_kg  DECIMAL(7,2)  NOT NULL,
    lado_max_cm        DECIMAL(5,1)  NOT NULL,
    max_paquetes       TINYINT       NOT NULL,
    UNIQUE KEY uq_tipos_vehiculo_codigo (codigo),
    UNIQUE KEY uq_tipos_vehiculo_nivel (nivel),
    CONSTRAINT ck_tipos_vehiculo_max CHECK (max_paquetes BETWEEN 1 AND 20)
) ENGINE=InnoDB;

-- Estados del paquete (igual que en la v1)
CREATE TABLE estados_paquete (
    id      TINYINT      PRIMARY KEY,
    codigo  VARCHAR(30)  NOT NULL,
    nombre  VARCHAR(50)  NOT NULL,
    UNIQUE KEY uq_estados_codigo (codigo)
) ENGINE=InnoDB;

-- Motivos de fallo (los 3 del PDF)
CREATE TABLE motivos_fallo (
    id      TINYINT      PRIMARY KEY,
    codigo  VARCHAR(30)  NOT NULL,
    nombre  VARCHAR(60)  NOT NULL,
    UNIQUE KEY uq_motivos_codigo (codigo)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. PERSONAL Y TARIFAS
-- ---------------------------------------------------------------------

CREATE TABLE usuarios (
    id                     BIGINT        AUTO_INCREMENT PRIMARY KEY,
    nombres                VARCHAR(150)  NOT NULL,
    dni                    CHAR(8)       NOT NULL,
    correo                 VARCHAR(150)  NOT NULL,
    telefono               VARCHAR(20)   NULL,
    rol                    ENUM('ADMINISTRADOR', 'OPERADOR', 'CONDUCTOR') NOT NULL,
    id_sede                TINYINT       NULL,   -- solo Operador
    id_tipo_vehiculo       TINYINT       NULL,   -- solo Conductor
    password_hash          VARCHAR(255)  NOT NULL,
    debe_cambiar_password  BOOLEAN       NOT NULL DEFAULT TRUE,
    password_changed_at    DATETIME      NULL,
    activo                 BOOLEAN       NOT NULL DEFAULT TRUE,
    created_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_correo (correo),
    UNIQUE KEY uq_usuarios_dni (dni),
    CONSTRAINT fk_usuarios_sede     FOREIGN KEY (id_sede)          REFERENCES sedes(id),
    CONSTRAINT fk_usuarios_vehiculo FOREIGN KEY (id_tipo_vehiculo) REFERENCES tipos_vehiculo(id),
    -- Cada rol tiene exactamente los campos que le corresponden
    CONSTRAINT ck_usuarios_rol CHECK (
           (rol = 'ADMINISTRADOR' AND id_sede IS NULL     AND id_tipo_vehiculo IS NULL)
        OR (rol = 'OPERADOR'      AND id_sede IS NOT NULL AND id_tipo_vehiculo IS NULL)
        OR (rol = 'CONDUCTOR'     AND id_sede IS NULL     AND id_tipo_vehiculo IS NOT NULL))
) ENGINE=InnoDB;

-- RecuperaciÃ³n de contraseÃ±a por correo (se guarda el hash, nunca el token)
CREATE TABLE tokens_recuperacion (
    id          BIGINT    AUTO_INCREMENT PRIMARY KEY,
    id_usuario  BIGINT    NOT NULL,
    token_hash  CHAR(64)  NOT NULL,
    expira_en   DATETIME  NOT NULL,
    usado_en    DATETIME  NULL,
    created_at  DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tokens_hash (token_hash),
    CONSTRAINT fk_tokens_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Tarifa base: cada cambio es una fila nueva; la vigente es la mÃ¡s reciente
CREATE TABLE tarifas (
    id              INT           AUTO_INCREMENT PRIMARY KEY,
    tarifa_base_kg  DECIMAL(8,2)  NOT NULL,
    vigente_desde   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    creado_por      BIGINT        NULL,          -- NULL solo en la tarifa inicial
    INDEX idx_tarifas_vigencia (vigente_desde),
    CONSTRAINT fk_tarifas_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id),
    CONSTRAINT ck_tarifas_positiva CHECK (tarifa_base_kg > 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. OPERACIÃ“N
-- ---------------------------------------------------------------------

-- Remitentes (sin cuenta ni contraseÃ±a)
CREATE TABLE clientes (
    id                    BIGINT        AUTO_INCREMENT PRIMARY KEY,
    tipo_documento        ENUM('DNI', 'RUC') NOT NULL,
    numero_documento      VARCHAR(11)   NOT NULL,
    nombres_razon_social  VARCHAR(150)  NOT NULL,
    celular               VARCHAR(20)   NOT NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clientes_documento (tipo_documento, numero_documento)
) ENGINE=InnoDB;

CREATE TABLE lotes (
    id               BIGINT       AUTO_INCREMENT PRIMARY KEY,
    codigo           VARCHAR(16)  NOT NULL,
    id_sede          TINYINT      NOT NULL,
    id_zona_destino  TINYINT      NOT NULL,
    id_conductor     BIGINT       NOT NULL,
    creado_por       BIGINT       NOT NULL,
    estado           ENUM('ASIGNADO', 'EN_RUTA', 'CERRADO') NOT NULL DEFAULT 'ASIGNADO',
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at       DATETIME     NULL,
    closed_at        DATETIME     NULL,
    UNIQUE KEY uq_lotes_codigo (codigo),
    INDEX idx_lotes_conductor (id_conductor, estado),
    INDEX idx_lotes_sede (id_sede, estado),
    CONSTRAINT fk_lotes_sede       FOREIGN KEY (id_sede)         REFERENCES sedes(id),
    CONSTRAINT fk_lotes_zona       FOREIGN KEY (id_zona_destino) REFERENCES zonas(id),
    CONSTRAINT fk_lotes_conductor  FOREIGN KEY (id_conductor)    REFERENCES usuarios(id),
    CONSTRAINT fk_lotes_creado_por FOREIGN KEY (creado_por)      REFERENCES usuarios(id)
) ENGINE=InnoDB;

CREATE TABLE paquetes (
    id                    BIGINT        AUTO_INCREMENT PRIMARY KEY,
    tracking_id           VARCHAR(16)   NOT NULL,
    id_paquete_origen     BIGINT        NULL,          -- solo en reintentos
    id_sede               TINYINT       NOT NULL,      -- sede que lo recibiÃ³ (zona de origen)

    -- Remitente
    id_remitente          BIGINT        NOT NULL,

    -- Destinatario
    destinatario_nombres  VARCHAR(150)  NOT NULL,
    destinatario_dni      CHAR(8)       NOT NULL,
    destinatario_celular  VARCHAR(20)   NOT NULL,

    -- DirecciÃ³n de destino estructurada
    ubigeo_destino        CHAR(6)       NOT NULL,
    tipo_via              VARCHAR(20)   NOT NULL,
    nombre_via            VARCHAR(120)  NOT NULL,
    numero                VARCHAR(10)   NULL,
    manzana               VARCHAR(10)   NULL,
    lote_predio           VARCHAR(10)   NULL,
    referencia            VARCHAR(255)  NULL,

    -- Medidas y precio
    peso_kg               DECIMAL(5,2)  NOT NULL,
    largo_cm              DECIMAL(5,1)  NOT NULL,
    ancho_cm              DECIMAL(5,1)  NOT NULL,
    alto_cm               DECIMAL(5,1)  NOT NULL,
    costo_estimado        DECIMAL(8,2)  NOT NULL,
    id_tarifa             INT           NOT NULL,

    -- Estado y lote
    estado_actual_id      TINYINT       NOT NULL,
    id_lote               BIGINT        NULL,
    orden_parada          TINYINT       NULL,
    fecha_ingreso         DATETIME      NOT NULL,      -- orden de la cola FIFO

    creado_por            BIGINT        NOT NULL,
    created_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_paquetes_tracking (tracking_id),
    UNIQUE KEY uq_paquetes_reintento (id_paquete_origen),   -- un solo reintento por paquete fallido
    INDEX idx_paquetes_cola (id_sede, estado_actual_id, fecha_ingreso),
    INDEX idx_paquetes_lote (id_lote, orden_parada),
    INDEX idx_paquetes_remitente (id_remitente),
    CONSTRAINT fk_paquetes_origen     FOREIGN KEY (id_paquete_origen) REFERENCES paquetes(id),
    CONSTRAINT fk_paquetes_sede       FOREIGN KEY (id_sede)           REFERENCES sedes(id),
    CONSTRAINT fk_paquetes_remitente  FOREIGN KEY (id_remitente)      REFERENCES clientes(id),
    CONSTRAINT fk_paquetes_destino    FOREIGN KEY (ubigeo_destino)    REFERENCES distritos(ubigeo),
    CONSTRAINT fk_paquetes_tarifa     FOREIGN KEY (id_tarifa)         REFERENCES tarifas(id),
    CONSTRAINT fk_paquetes_estado     FOREIGN KEY (estado_actual_id)  REFERENCES estados_paquete(id),
    CONSTRAINT fk_paquetes_lote       FOREIGN KEY (id_lote)           REFERENCES lotes(id),
    CONSTRAINT fk_paquetes_creado_por FOREIGN KEY (creado_por)        REFERENCES usuarios(id),
    CONSTRAINT ck_paquetes_peso  CHECK (peso_kg BETWEEN 0.1 AND 50),
    CONSTRAINT ck_paquetes_lados CHECK (largo_cm BETWEEN 10 AND 150
                                    AND ancho_cm BETWEEN 10 AND 150
                                    AND alto_cm  BETWEEN 10 AND 150)
) ENGINE=InnoDB;

-- AuditorÃ­a inmutable: la aplicaciÃ³n solo tiene INSERT y SELECT aquÃ­
CREATE TABLE historial_estados (
    id                   BIGINT        AUTO_INCREMENT PRIMARY KEY,
    id_paquete           BIGINT        NOT NULL,
    estado_anterior_id   TINYINT       NULL,         -- NULL solo en el primer evento
    estado_nuevo_id      TINYINT       NOT NULL,
    id_usuario_ejecutor  BIGINT        NOT NULL,
    id_motivo_fallo      TINYINT       NULL,         -- solo en Fallido
    comentario           TEXT          NULL,         -- observaciÃ³n del conductor
    evidencia_key        VARCHAR(255)  NULL,         -- ruta de la foto en el volumen (Entregado)
    created_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_historial_paquete_fecha (id_paquete, created_at),
    INDEX idx_historial_fecha_estado (created_at, estado_nuevo_id),
    CONSTRAINT fk_historial_paquete         FOREIGN KEY (id_paquete)          REFERENCES paquetes(id),
    CONSTRAINT fk_historial_estado_anterior FOREIGN KEY (estado_anterior_id)  REFERENCES estados_paquete(id),
    CONSTRAINT fk_historial_estado_nuevo    FOREIGN KEY (estado_nuevo_id)     REFERENCES estados_paquete(id),
    CONSTRAINT fk_historial_usuario         FOREIGN KEY (id_usuario_ejecutor) REFERENCES usuarios(id),
    CONSTRAINT fk_historial_motivo          FOREIGN KEY (id_motivo_fallo)     REFERENCES motivos_fallo(id)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. DATOS SEMILLA (catÃ¡logos)
-- =====================================================================

INSERT INTO zonas (id, codigo, nombre) VALUES
    (1, 'NORTE',  'Norte'),
    (2, 'CENTRO', 'Centro'),
    (3, 'SUR',    'Sur'),
    (4, 'ESTE',   'Este'),
    (5, 'OESTE',  'Oeste');

INSERT INTO estados_paquete (id, codigo, nombre) VALUES
    (1, 'EN_ALMACEN', 'En AlmacÃ©n'),
    (2, 'ASIGNADO',   'Asignado'),
    (3, 'EN_RUTA',    'En Ruta'),
    (4, 'ENTREGADO',  'Entregado'),
    (5, 'FALLIDO',    'Fallido');

INSERT INTO motivos_fallo (id, codigo, nombre) VALUES
    (1, 'CLIENTE_AUSENTE',   'Cliente ausente'),
    (2, 'DIRECCION_ERRONEA', 'DirecciÃ³n errÃ³nea'),
    (3, 'RECHAZADO',         'Rechazado por destinatario');

INSERT INTO tipos_vehiculo (id, codigo, nombre, nivel, peso_max_total_kg, lado_max_cm, max_paquetes) VALUES
    (1, 'MOTORIZADO', 'Motorizado', 1,   20.00,  40.0, 20),
    (2, 'AUTO',       'Auto',       2,  200.00, 125.0, 20),
    (3, 'CAMION',     'CamiÃ³n',     3, 1000.00, 150.0, 20);

INSERT INTO departamentos (ubigeo, nombre) VALUES
    ('07', 'Callao'),
    ('15', 'Lima');

INSERT INTO provincias (ubigeo, ubigeo_departamento, nombre) VALUES
    ('0701', '07', 'Callao'),
    ('1501', '15', 'Lima');

-- 33 distritos de la rÃºbrica (secciÃ³n 6 del documento).
-- Fuente: dataset abierto ubigeo-peru-aumentado (licencia MIT),
--   https://github.com/jmcastagnetto/ubigeo-peru-aumentado (archivo ubigeo_distrito.csv),
--   descargado el 27/09/2026. FUENTE SECUNDARIA: recopilaciÃ³n no oficial.
-- UBIGEO: cÃ³digo INEI. Coordenadas: ubicaciÃ³n de la capital de cada distrito,
--   usada como punto representativo para ordenar paradas. Es una aproximaciÃ³n:
--   en distritos extensos (Carabayllo, San Juan de Lurigancho, LurÃ­n) la capital
--   queda lejos de su centro geogrÃ¡fico. La tarifa NO usa coordenadas, solo la zona.
INSERT INTO distritos (ubigeo, ubigeo_provincia, id_zona, nombre_oficial, nombre_mostrado, lat, lng) VALUES
    -- Norte
    ('150117', '1501', 1, 'Los Olivos',                 'Los Olivos', -11.991389, -77.070833),
    ('150110', '1501', 1, 'Comas',                      'Comas', -11.957222, -77.049444),
    ('150112', '1501', 1, 'Independencia',              'Independencia', -11.997222, -77.054722),
    ('150135', '1501', 1, 'San MartÃ­n de Porres',       'San MartÃ­n de Porres', -12.030000, -77.057500),
    ('150106', '1501', 1, 'Carabayllo',                 'Carabayllo', -11.890278, -77.026944),
    ('150125', '1501', 1, 'Puente Piedra',              'Puente Piedra', -11.866667, -77.076944),
    -- Centro
    ('150101', '1501', 2, 'Lima',                       'Cercado de Lima', -12.045278, -77.030833),
    ('150105', '1501', 2, 'BreÃ±a',                      'BreÃ±a', -12.058889, -77.046111),
    ('150115', '1501', 2, 'La Victoria',                'La Victoria', -12.065000, -77.030833),
    ('150128', '1501', 2, 'RÃ­mac',                      'RÃ­mac', -12.042222, -77.026944),
    ('150113', '1501', 2, 'JesÃºs MarÃ­a',                'JesÃºs MarÃ­a', -12.075556, -77.043333),
    ('150116', '1501', 2, 'Lince',                      'Lince', -12.084444, -77.030278),
    ('150121', '1501', 2, 'Pueblo Libre',               'Pueblo Libre', -12.078056, -77.062500),
    ('150136', '1501', 2, 'San Miguel',                 'San Miguel', -12.092222, -77.079444),
    -- Sur
    ('150122', '1501', 3, 'Miraflores',                 'Miraflores', -12.121667, -77.029167),
    ('150131', '1501', 3, 'San Isidro',                 'San Isidro', -12.097778, -77.027222),
    ('150104', '1501', 3, 'Barranco',                   'Barranco', -12.149167, -77.021667),
    ('150140', '1501', 3, 'Santiago de Surco',          'Santiago de Surco', -12.145000, -77.005000),
    ('150130', '1501', 3, 'San Borja',                  'San Borja', -12.107222, -76.998889),
    ('150108', '1501', 3, 'Chorrillos',                 'Chorrillos', -12.176944, -77.016389),
    ('150142', '1501', 3, 'Villa El Salvador',          'Villa El Salvador', -12.213333, -76.937222),
    ('150119', '1501', 3, 'LurÃ­n',                      'LurÃ­n', -12.274722, -76.870278),
    -- Este
    ('150132', '1501', 4, 'San Juan de Lurigancho',     'San Juan de Lurigancho', -12.029722, -77.010000),
    ('150103', '1501', 4, 'Ate',                        'Ate', -12.026389, -76.921389),
    ('150137', '1501', 4, 'Santa Anita',                'Santa Anita', -12.043889, -76.971389),
    ('150111', '1501', 4, 'El Agustino',                'El Agustino', -12.048333, -77.000556),
    ('150114', '1501', 4, 'La Molina',                  'La Molina', -12.078056, -76.916667),
    ('150107', '1501', 4, 'Chaclacayo',                 'Chaclacayo', -11.975278, -76.768889),
    -- Oeste
    ('070101', '0701', 5, 'Callao',                     'Callao', -12.063056, -77.146944),
    ('070102', '0701', 5, 'Bellavista',                 'Bellavista', -12.062500, -77.129167),
    ('070104', '0701', 5, 'La Perla',                   'La Perla', -12.065833, -77.108056),
    ('070103', '0701', 5, 'Carmen de la Legua Reynoso', 'Carmen de la Legua', -12.039444, -77.090278),
    ('070106', '0701', 5, 'Ventanilla',                 'Ventanilla', -11.877222, -77.127778);

-- Sedes: una por zona, en el distrito mÃ¡s cÃ©ntrico de su zona (menor distancia
--   total a los demÃ¡s distritos de la zona)
-- Coordenadas = las del distrito en la tabla distritos.
-- Direcciones REFERENCIALES (empresa ficticia): avenida principal, sin nÃºmero.
INSERT INTO sedes (id, nombre, id_zona, ubigeo_distrito, direccion, lat, lng) VALUES
    (1, 'Sede Norte',  1, '150117', 'Av. Carlos Izaguirre, Los Olivos (referencial)',          -11.991389, -77.070833),
    (2, 'Sede Centro', 2, '150113', 'Av. Brasil, JesÃºs MarÃ­a (referencial)',                   -12.075556, -77.043333),
    (3, 'Sede Sur',    3, '150140', 'Av. Caminos del Inca, Santiago de Surco (referencial)',   -12.145000, -77.005000),
    (4, 'Sede Este',   4, '150137', 'Av. NicolÃ¡s AyllÃ³n, Santa Anita (referencial)',           -12.043889, -76.971389),
    (5, 'Sede Oeste',  5, '070102', 'Av. Elmer Faucett, Bellavista (referencial)',             -12.062500, -77.129167);

-- Tarifa base inicial (S/ por kg facturable). La cambia el Administrador desde su pantalla.
INSERT INTO tarifas (tarifa_base_kg, creado_por) VALUES (0.05, NULL);

-- El Administrador inicial NO se inserta aquÃ­: lo crea un seeder de PHP
-- leyendo ADMIN_INITIAL_EMAIL y ADMIN_INITIAL_PASSWORD de las variables
-- de entorno, para que la contraseÃ±a nunca quede en Git.