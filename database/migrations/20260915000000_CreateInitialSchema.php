<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateInitialSchema extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<'SQL'
CREATE TABLE usuarios (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    nombres VARCHAR(150) NOT NULL,
    correo VARCHAR(150) NOT NULL,
    telefono VARCHAR(20),
    rol ENUM('Admin', 'Conductor') NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    debe_cambiar_password BOOLEAN NOT NULL DEFAULT TRUE,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE clientes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    dni VARCHAR(15) NOT NULL,
    nombres VARCHAR(150) NOT NULL,
    telefono VARCHAR(20),
    correo VARCHAR(150) NULL,
    password_hash VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clientes_dni (dni),
    UNIQUE KEY uq_clientes_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE distritos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    zona ENUM('Norte', 'Centro', 'Sur', 'Este') NOT NULL,
    activo BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_distritos_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE estados_paquete (
    id TINYINT PRIMARY KEY,
    codigo VARCHAR(30) NOT NULL,
    nombre VARCHAR(50) NOT NULL,
    UNIQUE KEY uq_estados_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
INSERT INTO estados_paquete (id, codigo, nombre) VALUES
    (1, 'EN_ALMACEN', 'En Almacén'),
    (2, 'ASIGNADO', 'Asignado'),
    (3, 'EN_RUTA', 'En Ruta'),
    (4, 'ENTREGADO', 'Entregado'),
    (5, 'FALLIDO', 'Fallido')
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE lotes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_conductor BIGINT NOT NULL,
    creado_por BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    INDEX idx_lotes_conductor (id_conductor, created_at),
    CONSTRAINT fk_lotes_conductor FOREIGN KEY (id_conductor) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_lotes_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE paquetes (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    tracking_id VARCHAR(20) NOT NULL,
    id_remitente BIGINT NOT NULL,
    destinatario_nombre VARCHAR(150) NOT NULL,
    destinatario_telefono VARCHAR(20),
    destinatario_direccion VARCHAR(255) NOT NULL,
    id_distrito_origen INT NOT NULL,
    direccion_origen VARCHAR(255) NOT NULL,
    id_distrito_destino INT NOT NULL,
    direccion_destino VARCHAR(255) NOT NULL,
    peso_kg DECIMAL(6,2) NOT NULL,
    alto_cm DECIMAL(6,2) NOT NULL,
    ancho_cm DECIMAL(6,2) NOT NULL,
    largo_cm DECIMAL(6,2) NOT NULL,
    costo_estimado DECIMAL(8,2) NOT NULL,
    estado_actual_id TINYINT NOT NULL,
    id_lote BIGINT NULL,
    asignado_en DATETIME NULL,
    creado_por BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_paquetes_tracking (tracking_id),
    INDEX idx_paquetes_estado (estado_actual_id, created_at),
    INDEX idx_paquetes_lote (id_lote),
    INDEX idx_paquetes_remitente (id_remitente),
    CONSTRAINT fk_paquetes_remitente FOREIGN KEY (id_remitente) REFERENCES clientes(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_paquetes_distrito_origen FOREIGN KEY (id_distrito_origen) REFERENCES distritos(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_paquetes_distrito_destino FOREIGN KEY (id_distrito_destino) REFERENCES distritos(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_paquetes_estado FOREIGN KEY (estado_actual_id) REFERENCES estados_paquete(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_paquetes_lote FOREIGN KEY (id_lote) REFERENCES lotes(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_paquetes_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->execute(<<<'SQL'
CREATE TABLE historial_estados (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_paquete BIGINT NOT NULL,
    estado_anterior_id TINYINT NULL,
    estado_nuevo_id TINYINT NOT NULL,
    id_usuario_ejecutor BIGINT NOT NULL,
    motivo VARCHAR(255) NULL,
    comentario TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_historial_paquete_fecha (id_paquete, created_at),
    CONSTRAINT fk_historial_paquete FOREIGN KEY (id_paquete) REFERENCES paquetes(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_historial_estado_anterior FOREIGN KEY (estado_anterior_id) REFERENCES estados_paquete(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_historial_estado_nuevo FOREIGN KEY (estado_nuevo_id) REFERENCES estados_paquete(id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_historial_usuario FOREIGN KEY (id_usuario_ejecutor) REFERENCES usuarios(id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS historial_estados');
        $this->execute('DROP TABLE IF EXISTS paquetes');
        $this->execute('DROP TABLE IF EXISTS lotes');
        $this->execute('DROP TABLE IF EXISTS estados_paquete');
        $this->execute('DROP TABLE IF EXISTS distritos');
        $this->execute('DROP TABLE IF EXISTS clientes');
        $this->execute('DROP TABLE IF EXISTS usuarios');
    }
}
