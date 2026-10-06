-- =========================================================
-- Base de datos: super_simple_v3 (Supermercado con permisos granulares)
-- =========================================================
CREATE DATABASE IF NOT EXISTS super_simple_v3 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE super_simple_v3;

-- Datos del supermercado
CREATE TABLE IF NOT EXISTS sucursales(
  id INT PRIMARY KEY AUTO_INCREMENT,
  nombre VARCHAR(100) NOT NULL,
  direccion VARCHAR(200) NULL,
  localidad VARCHAR(100) NULL,
  provincia VARCHAR(100) NULL,
  codigo_postal VARCHAR(15) NULL,
  telefono VARCHAR(30) NULL,
  email VARCHAR(150) NULL,
  latitud DECIMAL(10,7) NULL,
  longitud DECIMAL(10,7) NULL,
  activa TINYINT(1) NOT NULL DEFAULT 1,
  fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT INTO sucursales(id,nombre) VALUES(1,'Sucursal Principal')
  ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);

CREATE TABLE IF NOT EXISTS config(
  id INT PRIMARY KEY AUTO_INCREMENT,
  razon_social VARCHAR(150),
  cuit CHAR(11),
  condicion ENUM('RI','MONO') DEFAULT 'RI',
  punto_venta INT DEFAULT 1,
  tienda_url VARCHAR(255) NULL,
  mercadopago_url VARCHAR(500) NULL,
  pago_virtual_nombre VARCHAR(60) NULL,
  pago_virtual_url VARCHAR(500) NULL,
  pago_cvu CHAR(22) NULL,
  sucursal_id INT NOT NULL DEFAULT 1,
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB;

-- Personal - CAMBIO: Sin email, solo usuario/contraseña
CREATE TABLE IF NOT EXISTS usuarios(
  id INT PRIMARY KEY AUTO_INCREMENT,
  usuario VARCHAR(100) UNIQUE NOT NULL,
  nombre VARCHAR(100),
  clave VARCHAR(255),
  rol ENUM('propietario','jefe','supervisor','encargado','repositor','cajero'),
  sucursal_id INT NULL,
  activo TINYINT DEFAULT 1,
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuario_sucursales(
  usuario_id INT NOT NULL,
  sucursal_id INT NOT NULL,
  PRIMARY KEY(usuario_id,sucursal_id),
  FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabla de permisos granulares por rol
CREATE TABLE IF NOT EXISTS permisos(
  id INT PRIMARY KEY AUTO_INCREMENT,
  rol VARCHAR(20),
  modulo VARCHAR(50),
  accion VARCHAR(50),
  permitido TINYINT DEFAULT 0,
  UNIQUE KEY rol_modulo_accion (rol, modulo, accion)
) ENGINE=InnoDB;

-- Bloqueo por intentos fallidos de login
CREATE TABLE IF NOT EXISTS intentos(
  id INT PRIMARY KEY AUTO_INCREMENT,
  usuario VARCHAR(100),
  ip VARCHAR(45),
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Horas trabajadas (entrada / salida)
CREATE TABLE IF NOT EXISTS fichajes(
  id INT PRIMARY KEY AUTO_INCREMENT,
  usuario_id INT,
  entrada DATETIME,
  salida DATETIME NULL,
  sucursal_id INT NOT NULL DEFAULT 1,
  FOREIGN KEY(usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB;

-- Categorías de productos
CREATE TABLE IF NOT EXISTS categorias(
  id INT PRIMARY KEY AUTO_INCREMENT,
  nombre VARCHAR(80),
  icono VARCHAR(30) DEFAULT 'tag'
) ENGINE=InnoDB;

-- Productos
CREATE TABLE IF NOT EXISTS productos(
  id INT PRIMARY KEY AUTO_INCREMENT,
  codigo VARCHAR(20) UNIQUE,
  nombre VARCHAR(150),
  categoria_id INT NULL,
  precio DECIMAL(12,2),
  iva DECIMAL(4,2) DEFAULT 21,
  stock INT DEFAULT 0,
  entrante INT DEFAULT 0,
  minimo INT DEFAULT 0,
  FOREIGN KEY(categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_sucursales(
  sucursal_id INT NOT NULL,
  producto_id INT NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  entrante INT NOT NULL DEFAULT 0,
  minimo INT NOT NULL DEFAULT 0,
  PRIMARY KEY(sucursal_id,producto_id),
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
  FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Clientes registrados en el programa de socios
CREATE TABLE IF NOT EXISTS socios(
  id INT PRIMARY KEY AUTO_INCREMENT,
  dni CHAR(8) NOT NULL UNIQUE,
  nombre VARCHAR(120) NOT NULL,
  telefono VARCHAR(30) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  fecha_alta TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Ventas
CREATE TABLE IF NOT EXISTS ventas(
  id INT PRIMARY KEY AUTO_INCREMENT,
  cajero_id INT,
  socio_id INT NULL,
  cliente VARCHAR(150),
  doc VARCHAR(13),
  neto DECIMAL(12,2),
  iva DECIMAL(12,2),
  total DECIMAL(12,2),
  pago VARCHAR(20) NULL,
  descuento_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  descuento_monto DECIMAL(12,2) NOT NULL DEFAULT 0,
  sucursal_id INT NOT NULL DEFAULT 1,
  tipo CHAR(1) NULL,
  numero INT NULL,
  estado VARCHAR(12) DEFAULT 'pagada',
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY(cajero_id) REFERENCES usuarios(id),
  FOREIGN KEY(socio_id) REFERENCES socios(id) ON DELETE SET NULL,
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS venta_items(
  id INT PRIMARY KEY AUTO_INCREMENT,
  venta_id INT,
  producto_id INT,
  nombre VARCHAR(150),
  cantidad INT,
  precio DECIMAL(12,2),
  iva DECIMAL(4,2),
  FOREIGN KEY(venta_id) REFERENCES ventas(id)
) ENGINE=InnoDB;

-- Pedidos de compra
CREATE TABLE IF NOT EXISTS pedidos_compra(
  id INT PRIMARY KEY AUTO_INCREMENT,
  encargado_id INT,
  proveedor VARCHAR(150),
  estado ENUM('pendiente','recibido','cancelado') DEFAULT 'pendiente',
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  fecha_recibido DATETIME NULL,
  sucursal_id INT NOT NULL DEFAULT 1,
  FOREIGN KEY(encargado_id) REFERENCES usuarios(id),
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pedido_items(
  id INT PRIMARY KEY AUTO_INCREMENT,
  pedido_id INT,
  producto_id INT,
  cantidad INT,
  precio DECIMAL(12,2),
  FOREIGN KEY(pedido_id) REFERENCES pedidos_compra(id),
  FOREIGN KEY(producto_id) REFERENCES productos(id)
) ENGINE=InnoDB;

-- Asignaciones de cajeros a cajas
CREATE TABLE IF NOT EXISTS asignaciones_caja(
  id INT PRIMARY KEY AUTO_INCREMENT,
  usuario_id INT,
  caja_numero INT,
  fecha_inicio DATETIME,
  fecha_fin DATETIME NULL,
  sucursal_id INT NOT NULL DEFAULT 1,
  FOREIGN KEY(usuario_id) REFERENCES usuarios(id),
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id)
) ENGINE=InnoDB;

-- Auditoría de cambios
CREATE TABLE IF NOT EXISTS auditoria(
  id INT PRIMARY KEY AUTO_INCREMENT,
  usuario_id INT,
  accion VARCHAR(100),
  tabla VARCHAR(50),
  registro_id INT,
  cambios JSON,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  sucursal_id INT NULL,
  FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comunicaciones_sucursal(
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  sucursal_id INT NOT NULL,
  autor_id INT NOT NULL,
  respuesta_a BIGINT NULL,
  tipo ENUM('mensaje','orden') NOT NULL DEFAULT 'mensaje',
  contenido VARCHAR(2000) NOT NULL,
  estado ENUM('enviado','leido','completado') NOT NULL DEFAULT 'enviado',
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX comunicaciones_sucursal_fecha (sucursal_id,fecha),
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id),
  FOREIGN KEY(autor_id) REFERENCES usuarios(id),
  FOREIGN KEY(respuesta_a) REFERENCES comunicaciones_sucursal(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Categorías iniciales
INSERT INTO categorias(nombre,icono) SELECT * FROM (
  SELECT 'Alimentos' n,'basket' i UNION ALL SELECT 'Bebidas','cup-straw' UNION ALL SELECT 'Lácteos','droplet'
  UNION ALL SELECT 'Carnes y fiambres','egg-fried' UNION ALL SELECT 'Bazar','house' UNION ALL SELECT 'Limpieza','stars'
  UNION ALL SELECT 'Perfumería','heart' UNION ALL SELECT 'Otros','tag') x
WHERE NOT EXISTS (SELECT 1 FROM categorias);

-- PERMISOS POR ROL
-- =========================================================

-- JEFE: Acceso total a todo
INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
('jefe','personal','ver',1),
('jefe','personal','crear',1),
('jefe','personal','editar',1),
('jefe','personal','eliminar',1),
('jefe','personal','cambiar_permisos',1),
('jefe','personal','expulsar',1),
('jefe','productos','ver',1),
('jefe','productos','crear',1),
('jefe','productos','editar',1),
('jefe','productos','eliminar',1),
('jefe','categorias','ver',1),
('jefe','categorias','crear',1),
('jefe','categorias','editar',1),
('jefe','categorias','eliminar',1),
('jefe','ventas','ver',1),
('jefe','ventas','registrar',1),
('jefe','socios','ver',1),
('jefe','socios','crear',1),
('jefe','socios','editar',1),
('jefe','pedidos','ver',1),
('jefe','pedidos','crear',1),
('jefe','pedidos','editar',1),
('jefe','pedidos','eliminar',1),
('jefe','cajas','asignar',1),
('jefe','reportes','ver',1),
('jefe','configuracion','ver',1),
('jefe','configuracion','editar',1);

INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
('propietario','personal','ver',1),('propietario','personal','crear',1),('propietario','personal','editar',1),('propietario','personal','eliminar',1),('propietario','personal','cambiar_permisos',1),('propietario','personal','expulsar',1),
('propietario','productos','ver',1),('propietario','productos','crear',1),('propietario','productos','editar',1),('propietario','productos','eliminar',1),
('propietario','categorias','ver',1),('propietario','categorias','crear',1),('propietario','categorias','editar',1),('propietario','categorias','eliminar',1),
('propietario','ventas','ver',1),('propietario','ventas','registrar',1),
('propietario','socios','ver',1),('propietario','socios','crear',1),('propietario','socios','editar',1),
('propietario','pedidos','ver',1),('propietario','pedidos','crear',1),('propietario','pedidos','editar',1),('propietario','pedidos','eliminar',1),
('propietario','cajas','asignar',1),('propietario','reportes','ver',1),('propietario','configuracion','ver',1),('propietario','configuracion','editar',1);

-- SUPERVISOR: Ver todo (excepto configuración), pero no modificar permisos
INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
('supervisor','personal','ver',1),
('supervisor','personal','crear',0),
('supervisor','personal','editar',0),
('supervisor','personal','eliminar',0),
('supervisor','personal','cambiar_permisos',0),
('supervisor','personal','expulsar',0),
('supervisor','productos','ver',1),
('supervisor','productos','crear',0),
('supervisor','productos','editar',0),
('supervisor','productos','eliminar',0),
('supervisor','categorias','ver',1),
('supervisor','categorias','crear',0),
('supervisor','categorias','editar',0),
('supervisor','categorias','eliminar',0),
('supervisor','ventas','ver',1),
('supervisor','ventas','registrar',0),
('supervisor','socios','ver',1),
('supervisor','socios','crear',1),
('supervisor','socios','editar',1),
('supervisor','pedidos','ver',1),
('supervisor','pedidos','crear',0),
('supervisor','pedidos','editar',0),
('supervisor','pedidos','eliminar',0),
('supervisor','cajas','asignar',0),
('supervisor','reportes','ver',1),
('supervisor','configuracion','ver',0),
('supervisor','configuracion','editar',0);

-- ENCARGADO: Cargar pedidos, asignar cajas, ver personal
INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
('encargado','personal','ver',1),
('encargado','personal','crear',0),
('encargado','personal','editar',0),
('encargado','personal','eliminar',0),
('encargado','personal','cambiar_permisos',0),
('encargado','personal','expulsar',0),
('encargado','productos','ver',1),
('encargado','productos','crear',0),
('encargado','productos','editar',0),
('encargado','productos','eliminar',0),
('encargado','categorias','ver',1),
('encargado','categorias','crear',0),
('encargado','categorias','editar',0),
('encargado','categorias','eliminar',0),
('encargado','ventas','ver',1),
('encargado','ventas','registrar',0),
('encargado','pedidos','ver',1),
('encargado','pedidos','crear',1),
('encargado','pedidos','editar',1),
('encargado','pedidos','eliminar',0),
('encargado','cajas','asignar',1),
('encargado','reportes','ver',1),
('encargado','configuracion','ver',0),
('encargado','configuracion','editar',0);

-- REPOSITOR: Solo ver productos, crear/editar stock
INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
('repositor','personal','ver',0),
('repositor','personal','crear',0),
('repositor','personal','editar',0),
('repositor','personal','eliminar',0),
('repositor','personal','cambiar_permisos',0),
('repositor','personal','expulsar',0),
('repositor','productos','ver',1),
('repositor','productos','crear',0),
('repositor','productos','editar',1),
('repositor','productos','eliminar',0),
('repositor','categorias','ver',1),
('repositor','categorias','crear',0),
('repositor','categorias','editar',0),
('repositor','categorias','eliminar',0),
('repositor','ventas','ver',0),
('repositor','ventas','registrar',0),
('repositor','pedidos','ver',0),
('repositor','pedidos','crear',0),
('repositor','pedidos','editar',0),
('repositor','pedidos','eliminar',0),
('repositor','cajas','asignar',0),
('repositor','reportes','ver',0),
('repositor','configuracion','ver',0),
('repositor','configuracion','editar',0);

-- CAJERO: Solo vender y facturar
INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
('cajero','personal','ver',0),
('cajero','personal','crear',0),
('cajero','personal','editar',0),
('cajero','personal','eliminar',0),
('cajero','personal','cambiar_permisos',0),
('cajero','personal','expulsar',0),
('cajero','productos','ver',1),
('cajero','productos','crear',0),
('cajero','productos','editar',0),
('cajero','productos','eliminar',0),
('cajero','categorias','ver',1),
('cajero','categorias','crear',0),
('cajero','categorias','editar',0),
('cajero','categorias','eliminar',0),
('cajero','ventas','ver',1),
('cajero','ventas','registrar',1),
('cajero','pedidos','ver',0),
('cajero','pedidos','crear',0),
('cajero','pedidos','editar',0),
('cajero','pedidos','eliminar',0),
('cajero','cajas','asignar',0),
('cajero','reportes','ver',0),
('cajero','configuracion','ver',0),
('cajero','configuracion','editar',0);
