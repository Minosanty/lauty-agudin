CREATE DATABASE IF NOT EXISTS farmacia_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE farmacia_db;

CREATE TABLE IF NOT EXISTS sucursales (
    id_sucursal INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    direccion VARCHAR(150) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuarios (
    id_usuario INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    usuario VARCHAR(50) UNIQUE NOT NULL,
    clave VARCHAR(255) NOT NULL,
    rol ENUM('Cajero', 'Jefe de Sucursal', 'Gerencia') NOT NULL,
    id_sucursal INT,
    FOREIGN KEY (id_sucursal) REFERENCES sucursales(id_sucursal) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS productos (
    id_producto INT AUTO_INCREMENT PRIMARY KEY,
    codigo_barra VARCHAR(50) UNIQUE NOT NULL,
    descripcion VARCHAR(150) NOT NULL,
    precio DECIMAL(10, 2) NOT NULL,
    stock INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ventas (
    id_venta INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATETIME DEFAULT CURRENT_TIMESTAMP,
    id_usuario INT NOT NULL,
    id_sucursal INT NOT NULL,
    total DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario),
    FOREIGN KEY (id_sucursal) REFERENCES sucursales(id_sucursal)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS detalle_ventas (
    id_detalle INT AUTO_INCREMENT PRIMARY KEY,
    id_venta INT NOT NULL,
    id_producto INT NOT NULL,
    cantidad INT NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (id_venta) REFERENCES ventas(id_venta) ON DELETE CASCADE,
    FOREIGN KEY (id_producto) REFERENCES productos(id_producto)
) ENGINE=InnoDB;

-- Datos iniciales de sucursales
INSERT IGNORE INTO sucursales (id_sucursal, nombre, direccion) VALUES
    (1, 'Casa Central', 'Av. 25 de Mayo 1000'),
    (2, 'Sucursal 1', 'Av. Kirchner 450');

-- Datos iniciales de usuarios (Clave por defecto: 1234)
INSERT IGNORE INTO usuarios (id_usuario, nombre, usuario, clave, rol, id_sucursal) VALUES
    (1, 'Carlos Gomez', 'cajero1', '1234', 'Cajero', 1),
    (2, 'Maria Lopez', 'jefe1', '1234', 'Jefe de Sucursal', 1),
    (3, 'Santiago Miño', 'admin', '1234', 'Gerencia', 1),
    (4, 'Laura Fernandez', 'cajero2', '1234', 'Cajero', 2),
    (5, 'Roberto Sanchez', 'jefe2', '1234', 'Jefe de Sucursal', 2);

-- Catálogo inicial de productos con stock variado
INSERT IGNORE INTO productos (id_producto, codigo_barra, descripcion, precio, stock) VALUES
    (1, '779123456789', 'Paracetamol 500mg (10 Comprimidos)', 1500.00, 50),
    (2, '779987654321', 'Ibuprofeno 600mg (20 Comprimidos)', 2800.00, 26),
    (3, '779334455667', 'Amoxicilina 500mg (16 Cápsulas)', 3450.00, 40),
    (4, '779445566778', 'Aspirina Prevent 100mg (30 Comprimidos)', 2100.00, 15),
    (5, '779556677889', 'Omeprazol 20mg (28 Cápsulas)', 4200.00, 25),
    (6, '779667788990', 'Loratadina 10mg (10 Comprimidos)', 1850.00, 4),
    (7, '779778899001', 'Alcohol en Gel 250ml', 1200.00, 60),
    (8, '779889900112', 'Termómetro Digital Clínico', 5500.00, 0);

-- Ventas de prueba para demostrar aislamiento por sucursales
INSERT IGNORE INTO ventas (id_venta, fecha, id_usuario, id_sucursal, total) VALUES
    (1, NOW() - INTERVAL 1 DAY, 1, 1, 11200.00),
    (2, NOW() - INTERVAL 5 HOUR, 4, 2, 6900.00);

INSERT IGNORE INTO detalle_ventas (id_detalle, id_venta, id_producto, cantidad, subtotal) VALUES
    (1, 1, 2, 4, 11200.00),
    (2, 2, 3, 2, 6900.00);
