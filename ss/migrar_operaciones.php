<?php
if(PHP_SAPI!=='cli'){
  http_response_code(404);
  exit;
}

require __DIR__.'/lib.php';
$pdo=db();

$pdo->exec('CREATE TABLE IF NOT EXISTS sucursales(
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
) ENGINE=InnoDB');
$columnasSucursal=array_column($pdo->query('SHOW COLUMNS FROM sucursales')->fetchAll(),'Field');
foreach([
  'localidad'=>'VARCHAR(100) NULL',
  'provincia'=>'VARCHAR(100) NULL',
  'codigo_postal'=>'VARCHAR(15) NULL',
  'telefono'=>'VARCHAR(30) NULL',
  'email'=>'VARCHAR(150) NULL',
  'latitud'=>'DECIMAL(10,7) NULL',
  'longitud'=>'DECIMAL(10,7) NULL'
] as $campo=>$definicion){
  if(!in_array($campo,$columnasSucursal,true))$pdo->exec('ALTER TABLE sucursales ADD COLUMN `'.$campo.'` '.$definicion);
}
$pdo->exec("INSERT INTO sucursales(nombre) SELECT 'Sucursal Principal'
  WHERE NOT EXISTS(SELECT 1 FROM sucursales)");
$sucursalPrincipal=(int)$pdo->query('SELECT id FROM sucursales ORDER BY id LIMIT 1')->fetchColumn();
$pdo->exec("ALTER TABLE usuarios MODIFY rol ENUM('propietario','jefe','supervisor','encargado','repositor','cajero') NULL");
$promoverPropietario=null;
foreach(array_slice($argv??[],1) as $argumento){
  if(strncmp($argumento,'--propietario=',14)===0){
    $valor=substr($argumento,14);
    if(!preg_match('/^[1-9]\d*$/D',$valor))throw new RuntimeException('Usa --propietario=ID con el ID numérico de una cuenta activa.');
    $promoverPropietario=(int)$valor;
  }
}
if($promoverPropietario){
  $cuentaPropietario=q1('SELECT id,rol FROM usuarios WHERE id=? AND activo=1',[$promoverPropietario]);
  if(!$cuentaPropietario)throw new RuntimeException('La cuenta indicada no existe o está desactivada.');
  $otroPropietario=q1('SELECT id FROM usuarios WHERE rol="propietario" AND id<>? LIMIT 1',[$promoverPropietario]);
  if($otroPropietario)throw new RuntimeException('Ya existe otro propietario. No se cambió ninguna cuenta.');
  q('UPDATE usuarios SET rol="propietario" WHERE id=?',[$promoverPropietario]);
}

$pdo->exec('
  CREATE TABLE IF NOT EXISTS socios(
    id INT PRIMARY KEY AUTO_INCREMENT,
    dni CHAR(8) NOT NULL UNIQUE,
    nombre VARCHAR(120) NOT NULL,
    telefono VARCHAR(30) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    fecha_alta TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB
');

$columnas=[
  'config'=>[
    'tienda_url'=>'VARCHAR(255) NULL',
    'mercadopago_url'=>'VARCHAR(500) NULL',
    'pago_virtual_nombre'=>'VARCHAR(60) NULL',
    'pago_virtual_url'=>'VARCHAR(500) NULL',
    'pago_cvu'=>'CHAR(22) NULL',
    'sucursal_id'=>'INT NULL'
  ],
  'usuarios'=>[
    'sucursal_id'=>'INT NULL',
    'otp_secret'=>'VARCHAR(64) NULL',
    'otp_enabled'=>'TINYINT(1) NOT NULL DEFAULT 0',
    'otp_last_counter'=>'BIGINT NOT NULL DEFAULT -1',
    'otp_recovery_codes'=>'TEXT NULL',
    'ultimo_acceso'=>'DATETIME NULL',
    'ultima_actividad'=>'DATETIME NULL'
  ],
  'fichajes'=>['sucursal_id'=>'INT NULL'],
  'productos'=>[
    'comision_pct'=>'DECIMAL(5,2) NOT NULL DEFAULT 0',
    'costo_referencia'=>'DECIMAL(12,2) NOT NULL DEFAULT 0'
  ],
  'venta_items'=>[
    'lote_id'=>'BIGINT NULL',
    'costo_unitario'=>'DECIMAL(12,2) NULL',
    'promocion_id'=>'BIGINT NULL',
    'promocion_nombre'=>'VARCHAR(100) NULL'
  ],
  'ventas'=>[
    'socio_id'=>'INT NULL',
    'descuento_pct'=>'DECIMAL(5,2) NOT NULL DEFAULT 0',
    'descuento_monto'=>'DECIMAL(12,2) NOT NULL DEFAULT 0',
    'sucursal_id'=>'INT NULL'
  ],
  'pedidos_compra'=>['sucursal_id'=>'INT NULL'],
  'asignaciones_caja'=>['sucursal_id'=>'INT NULL'],
  'auditoria'=>['sucursal_id'=>'INT NULL']
];

foreach($columnas as $tabla=>$campos){
  $existentes=array_column($pdo->query('SHOW COLUMNS FROM `'.$tabla.'`')->fetchAll(),'Field');
  foreach($campos as $campo=>$definicion){
    if(!in_array($campo,$existentes,true)){
      $pdo->exec('ALTER TABLE `'.$tabla.'` ADD COLUMN `'.$campo.'` '.$definicion);
      echo "Columna agregada: $tabla.$campo\n";
    }
  }
}

$pdo->exec('UPDATE usuarios SET sucursal_id='.$sucursalPrincipal.' WHERE sucursal_id IS NULL AND rol<>"propietario"');
$pdo->exec('UPDATE usuarios SET sucursal_id=NULL WHERE rol="propietario"');
$pdo->exec('UPDATE config SET sucursal_id='.$sucursalPrincipal.' WHERE sucursal_id IS NULL');
foreach(['fichajes','ventas','pedidos_compra','asignaciones_caja','auditoria'] as $tabla){
  $pdo->exec('UPDATE `'.$tabla.'` SET sucursal_id='.$sucursalPrincipal.' WHERE sucursal_id IS NULL');
}

$fkSocioVenta=$pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ventas' AND COLUMN_NAME='socio_id' AND REFERENCED_TABLE_NAME='socios'")->fetchColumn();
if(!$fkSocioVenta){
  $pdo->exec('ALTER TABLE ventas ADD CONSTRAINT ventas_socio_fk FOREIGN KEY(socio_id) REFERENCES socios(id) ON DELETE SET NULL');
}

$pdo->exec('
  CREATE TABLE IF NOT EXISTS lotes_stock(
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    producto_id INT NOT NULL,
    cantidad_inicial INT NOT NULL,
    cantidad_restante INT NOT NULL,
    costo_unitario DECIMAL(12,2) NOT NULL,
    comision_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
    precio_venta DECIMAL(12,2) NOT NULL,
    sucursal_id INT NOT NULL DEFAULT 1,
    usuario_id INT NULL,
    producto_legacy_id INT NULL UNIQUE,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX lotes_fifo (producto_id,cantidad_restante,fecha,id),
    FOREIGN KEY(producto_id) REFERENCES productos(id),
    FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
  ) ENGINE=InnoDB
');
$columnasLotes=array_column($pdo->query('SHOW COLUMNS FROM lotes_stock')->fetchAll(),'Field');
if(!in_array('sucursal_id',$columnasLotes,true))$pdo->exec('ALTER TABLE lotes_stock ADD COLUMN sucursal_id INT NULL');
$pdo->exec('UPDATE lotes_stock SET sucursal_id='.$sucursalPrincipal.' WHERE sucursal_id IS NULL');

$pdo->exec('CREATE TABLE IF NOT EXISTS stock_sucursales(
  sucursal_id INT NOT NULL,
  producto_id INT NOT NULL,
  stock INT NOT NULL DEFAULT 0,
  entrante INT NOT NULL DEFAULT 0,
  minimo INT NOT NULL DEFAULT 0,
  PRIMARY KEY(sucursal_id,producto_id),
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE,
  FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB');
$pdo->exec('INSERT IGNORE INTO stock_sucursales(sucursal_id,producto_id,stock,entrante,minimo)
  SELECT '.$sucursalPrincipal.',p.id,p.stock,p.entrante,p.minimo FROM productos p
');
$pdo->exec('CREATE TABLE IF NOT EXISTS usuario_sucursales(
  usuario_id INT NOT NULL,
  sucursal_id INT NOT NULL,
  PRIMARY KEY(usuario_id,sucursal_id),
  FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id) ON DELETE CASCADE
) ENGINE=InnoDB');
$pdo->exec('INSERT IGNORE INTO usuario_sucursales(usuario_id,sucursal_id)
  SELECT id,sucursal_id FROM usuarios WHERE sucursal_id IS NOT NULL AND rol<>"propietario"');

$pdo->exec('
  CREATE TABLE IF NOT EXISTS pedidos_tablet(
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    codigo CHAR(10) NOT NULL UNIQUE,
    cliente VARCHAR(100) NULL,
    estado ENUM("pendiente","en_proceso","completado","cancelado") NOT NULL DEFAULT "pendiente",
    cajero_id INT NULL,
    venta_id INT NULL,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expira_en DATETIME NULL,
    INDEX cola_tablet (estado,fecha_creacion),
    FOREIGN KEY(cajero_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    FOREIGN KEY(venta_id) REFERENCES ventas(id) ON DELETE SET NULL
  ) ENGINE=InnoDB
');
$columnasPedidoTablet=array_column($pdo->query('SHOW COLUMNS FROM pedidos_tablet')->fetchAll(),'Field');
foreach([
  'metodo_pago'=>'ENUM("efectivo","mercadopago","virtual") NOT NULL DEFAULT "efectivo"',
  'pago_virtual_nombre'=>'VARCHAR(60) NULL',
  'pago_url'=>'VARCHAR(500) NULL',
  'pago_cvu'=>'CHAR(22) NULL',
  'sucursal_id'=>'INT NULL'
] as $campo=>$definicion){
  if(!in_array($campo,$columnasPedidoTablet,true))$pdo->exec('ALTER TABLE pedidos_tablet ADD COLUMN `'.$campo.'` '.$definicion);
}
$pdo->exec('UPDATE pedidos_tablet SET sucursal_id='.$sucursalPrincipal.' WHERE sucursal_id IS NULL');
if(!in_array('expira_en',array_column($pdo->query('SHOW COLUMNS FROM pedidos_tablet')->fetchAll(),'Field'),true)){
  $pdo->exec('ALTER TABLE pedidos_tablet ADD COLUMN expira_en DATETIME NULL');
}
$pdo->exec('ALTER TABLE pedidos_tablet MODIFY codigo CHAR(16) NOT NULL');
$pdo->exec('UPDATE pedidos_tablet SET expira_en=NULL WHERE estado IN("pendiente","en_proceso")');

$pdo->exec('
  CREATE TABLE IF NOT EXISTS pedido_tablet_items(
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    pedido_id BIGINT NOT NULL,
    producto_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    cantidad INT NOT NULL,
    precio_estimado DECIMAL(12,2) NOT NULL,
    iva DECIMAL(4,2) NOT NULL,
    promocion_id BIGINT NULL,
    promocion_nombre VARCHAR(100) NULL,
    INDEX pedido_tablet (pedido_id),
    FOREIGN KEY(pedido_id) REFERENCES pedidos_tablet(id) ON DELETE CASCADE,
    FOREIGN KEY(producto_id) REFERENCES productos(id)
  ) ENGINE=InnoDB
');
$columnasPedidoItems=array_column($pdo->query('SHOW COLUMNS FROM pedido_tablet_items')->fetchAll(),'Field');
foreach(['promocion_id'=>'BIGINT NULL','promocion_nombre'=>'VARCHAR(100) NULL'] as $campo=>$definicion){
  if(!in_array($campo,$columnasPedidoItems,true))$pdo->exec('ALTER TABLE pedido_tablet_items ADD COLUMN `'.$campo.'` '.$definicion);
}

$pdo->exec('
  CREATE TABLE IF NOT EXISTS promociones(
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL,
    tipo ENUM("producto","combo") NOT NULL,
    precio DECIMAL(12,2) NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    fecha_inicio DATETIME NULL,
    fecha_fin DATETIME NULL,
    usuario_id INT NULL,
    sucursal_id INT NOT NULL DEFAULT 1,
    fecha_creacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX promociones_vigencia (activa,fecha_inicio,fecha_fin),
    FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
  ) ENGINE=InnoDB
');
$columnasPromociones=array_column($pdo->query('SHOW COLUMNS FROM promociones')->fetchAll(),'Field');
if(!in_array('sucursal_id',$columnasPromociones,true))$pdo->exec('ALTER TABLE promociones ADD COLUMN sucursal_id INT NULL');
$pdo->exec('UPDATE promociones SET sucursal_id='.$sucursalPrincipal.' WHERE sucursal_id IS NULL');

$pdo->exec('
  CREATE TABLE IF NOT EXISTS reservas_tablet(
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    session_key CHAR(64) NOT NULL,
    cart_key CHAR(32) NOT NULL,
    producto_id INT NOT NULL,
    sucursal_id INT NOT NULL DEFAULT 1,
    cantidad INT NOT NULL,
    promocion_id BIGINT NULL,
    precio_unitario DECIMAL(12,2) NULL,
    pedido_id BIGINT NULL,
    vence_en DATETIME NOT NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY reserva_linea (session_key,cart_key,producto_id),
    INDEX reservas_producto (producto_id,vence_en),
    INDEX reservas_pedido (pedido_id,vence_en),
    FOREIGN KEY(producto_id) REFERENCES productos(id),
    FOREIGN KEY(promocion_id) REFERENCES promociones(id) ON DELETE SET NULL,
    FOREIGN KEY(pedido_id) REFERENCES pedidos_tablet(id) ON DELETE CASCADE
  ) ENGINE=InnoDB
');
$columnasReservas=array_column($pdo->query('SHOW COLUMNS FROM reservas_tablet')->fetchAll(),'Field');
if(!in_array('sucursal_id',$columnasReservas,true))$pdo->exec('ALTER TABLE reservas_tablet ADD COLUMN sucursal_id INT NULL');
$pdo->exec('UPDATE reservas_tablet SET sucursal_id='.$sucursalPrincipal.' WHERE sucursal_id IS NULL');
$pdo->exec('ALTER TABLE reservas_tablet MODIFY vence_en DATETIME NULL');
$pdo->exec('UPDATE reservas_tablet r JOIN pedidos_tablet p ON p.id=r.pedido_id
  SET r.vence_en=NULL WHERE p.estado IN("pendiente","en_proceso")');

$pdo->exec('
  CREATE TABLE IF NOT EXISTS promocion_items(
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    promocion_id BIGINT NOT NULL,
    producto_id INT NOT NULL,
    cantidad INT NOT NULL,
    UNIQUE KEY promo_producto (promocion_id,producto_id),
    INDEX promo_items_producto (producto_id),
    FOREIGN KEY(promocion_id) REFERENCES promociones(id) ON DELETE CASCADE,
    FOREIGN KEY(producto_id) REFERENCES productos(id)
  ) ENGINE=InnoDB
');

$pdo->exec('
  CREATE TABLE IF NOT EXISTS registro_accesos(
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    usuario_id INT NULL,
    usuario VARCHAR(100) NOT NULL,
    evento ENUM("login_ok","login_fallido","mfa_ok","mfa_fallido","logout") NOT NULL,
    ip VARCHAR(45) NULL,
    agente VARCHAR(255) NULL,
    fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX acceso_fecha (fecha),
    INDEX acceso_usuario (usuario_id,fecha),
    FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
  ) ENGINE=InnoDB
');

$pdo->exec('CREATE TABLE IF NOT EXISTS comunicaciones_sucursal(
  id BIGINT PRIMARY KEY AUTO_INCREMENT,
  sucursal_id INT NOT NULL,
  autor_id INT NOT NULL,
  respuesta_a BIGINT NULL,
  tipo ENUM("mensaje","orden") NOT NULL DEFAULT "mensaje",
  contenido VARCHAR(2000) NOT NULL,
  estado ENUM("enviado","leido","completado") NOT NULL DEFAULT "enviado",
  fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX comunicaciones_sucursal_fecha (sucursal_id,fecha),
  FOREIGN KEY(sucursal_id) REFERENCES sucursales(id),
  FOREIGN KEY(autor_id) REFERENCES usuarios(id),
  FOREIGN KEY(respuesta_a) REFERENCES comunicaciones_sucursal(id) ON DELETE SET NULL
) ENGINE=InnoDB');

$pdo->exec('INSERT INTO permisos(rol,modulo,accion,permitido)
  VALUES("supervisor","personal","crear",1)
  ON DUPLICATE KEY UPDATE permitido=VALUES(permitido)');
$pdo->exec('INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
  ("supervisor","productos","editar",1),
  ("supervisor","promociones","ver",1),
  ("supervisor","promociones","crear",1),
  ("supervisor","promociones","editar",1),
  ("supervisor","reponer","ver",1),
  ("supervisor","cajas","asignar",1),
  ("encargado","reponer","ver",1),
  ("repositor","reponer","ver",1)
  ON DUPLICATE KEY UPDATE permitido=VALUES(permitido)');
$pdo->exec('INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
  ("supervisor","socios","ver",1),
  ("supervisor","socios","crear",1),
  ("supervisor","socios","editar",1)
  ON DUPLICATE KEY UPDATE permitido=VALUES(permitido)');
$pdo->exec('INSERT INTO permisos(rol,modulo,accion,permitido) VALUES
  ("propietario","personal","ver",1),("propietario","personal","crear",1),("propietario","personal","editar",1),("propietario","personal","eliminar",1),("propietario","personal","cambiar_permisos",1),("propietario","personal","expulsar",1),
  ("propietario","productos","ver",1),("propietario","productos","crear",1),("propietario","productos","editar",1),("propietario","productos","eliminar",1),
  ("propietario","categorias","ver",1),("propietario","categorias","crear",1),("propietario","categorias","editar",1),("propietario","categorias","eliminar",1),
  ("propietario","ventas","ver",1),("propietario","ventas","registrar",1),
  ("propietario","socios","ver",1),("propietario","socios","crear",1),("propietario","socios","editar",1),
  ("propietario","pedidos","ver",1),("propietario","pedidos","crear",1),("propietario","pedidos","editar",1),("propietario","pedidos","eliminar",1),
  ("propietario","cajas","asignar",1),("propietario","reportes","ver",1),("propietario","configuracion","ver",1),("propietario","configuracion","editar",1)
  ON DUPLICATE KEY UPDATE permitido=VALUES(permitido)');
$pdo->exec('UPDATE permisos SET permitido=0 WHERE rol="repositor" AND modulo IN("productos","categorias")');

$pdo->exec('
  INSERT INTO lotes_stock(producto_id,cantidad_inicial,cantidad_restante,costo_unitario,comision_pct,precio_venta,sucursal_id,producto_legacy_id)
  SELECT p.id,p.stock,p.stock,p.precio,0,p.precio,'.$sucursalPrincipal.',p.id
  FROM productos p
  WHERE p.stock>0 AND NOT EXISTS(
    SELECT 1 FROM lotes_stock l WHERE l.producto_id=p.id
  )
');

echo "Migración completada. Los productos y ventas existentes se conservaron.\n";