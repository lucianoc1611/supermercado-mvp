<?php
if(PHP_SAPI!=='cli'){
  http_response_code(404);
  exit;
}

require __DIR__.'/lib.php';
if(APP_ENV==='production'){
  fwrite(STDERR,"El cargador demo está deshabilitado en producción.\n");
  exit(1);
}

$catalogo=[
  'Alimentos'=>[
    'Arroz largo fino 1 kg','Arroz integral 1 kg','Azúcar común 1 kg','Harina 000 1 kg','Harina leudante 1 kg',
    'Fideos spaghetti 500 g','Fideos mostacholes 500 g','Lentejas 400 g','Garbanzos 400 g','Avena 500 g',
    'Yerba mate 1 kg','Té negro 25 saquitos','Café molido 250 g','Galletitas dulces 300 g','Puré de tomate 520 g'
  ],
  'Bebidas'=>[
    'Agua mineral 500 ml','Agua mineral 1.5 l','Agua con gas 500 ml','Gaseosa cola 1.5 l','Gaseosa naranja 1.5 l',
    'Jugo de naranja 1 l','Jugo de manzana 1 l','Soda 2 l','Bebida energizante 473 ml','Bebida isotónica 500 ml',
    'Té frío 500 ml','Cerveza sin alcohol 473 ml'
  ],
  'Lácteos'=>[
    'Leche entera 1 l','Leche descremada 1 l','Yogur natural 190 g','Yogur de frutilla 190 g','Queso cremoso 1 kg',
    'Queso rallado 40 g','Manteca 200 g','Crema de leche 200 ml','Dulce de leche 400 g','Postre de vainilla 120 g',
    'Ricota 500 g','Leche vegetal 1 l'
  ],
  'Carnes y fiambres'=>[
    'Milanesas de carne 500 g','Milanesas de pollo 500 g','Hamburguesas 4 unidades','Salchichas 6 unidades','Jamón cocido 200 g',
    'Queso en fetas 200 g','Chorizo fresco 500 g','Carne picada 500 g','Pechuga de pollo 1 kg','Panceta ahumada 200 g',
    'Salame 200 g','Medallones de merluza 500 g'
  ],
  'Bazar'=>[
    'Esponja multiuso 2 unidades','Bolsas para residuos 10 unidades','Recipiente plástico 1 l','Vaso plástico 4 unidades','Plato playo 1 unidad',
    'Cubiertos descartables 12 unidades','Papel aluminio 10 m','Film adherente 30 m','Repasador de cocina 1 unidad','Percha plástica 3 unidades',
    'Frasco de vidrio 500 ml','Bandeja descartable 5 unidades'
  ],
  'Limpieza'=>[
    'Lavandina 1 l','Detergente limón 750 ml','Jabón para ropa 800 g','Suavizante para ropa 900 ml','Limpiador de pisos 900 ml',
    'Desinfectante aerosol 360 ml','Esponja de acero 2 unidades','Paño multiuso 3 unidades','Limpiavidrios 500 ml','Jabón en pan 200 g',
    'Bolsa consorcio 10 unidades','Guantes de limpieza 1 par'
  ],
  'Perfumería'=>[
    'Shampoo neutro 400 ml','Acondicionador 400 ml','Jabón de tocador 3 unidades','Pasta dental 90 g','Cepillo dental 1 unidad',
    'Desodorante aerosol 150 ml','Desodorante roll-on 50 ml','Crema corporal 200 ml','Pañuelos descartables 10 unidades','Algodón 100 g',
    'Protector solar 200 ml','Toallitas húmedas 50 unidades'
  ],
  'Otros'=>[
    'Alimento para perro 1 kg','Alimento para gato 1 kg','Pilas AA 2 unidades','Pilas AAA 2 unidades','Fósforos 200 unidades',
    'Velas blancas 4 unidades','Papel higiénico 4 rollos','Servilletas 100 unidades','Rollo de cocina 3 unidades','Paños húmedos 30 unidades',
    'Cuaderno tapa blanda 48 hojas','Lapicera azul 1 unidad','Cinta adhesiva 1 unidad'
  ]
];

$cuentas=[];
foreach(['cajero'=>'Cajero','repositor'=>'Repositor','encargado'=>'Encargado'] as $rol=>$nombre){
  for($numero=1;$numero<=5;$numero++)$cuentas[]=['usuario'=>sprintf('demo_%s_%02d',$rol,$numero),'nombre'=>sprintf('DEMO %s %02d',$nombre,$numero),'rol'=>$rol];
}
$cuentas[]=['usuario'=>'demo_gerente_01','nombre'=>'DEMO Gerente 01','rol'=>'supervisor'];

$pdo=db();
$sucursalId=(int)q1('SELECT id FROM sucursales WHERE activa=1 ORDER BY id LIMIT 1')['id'];
$pdo->beginTransaction();
$insertadosProductos=0;
$omitidosProductos=0;
$insertadosUsuarios=0;
$omitidosUsuarios=0;
$credenciales=[];

try{
  $categorias=[];
  foreach(q('SELECT id,nombre FROM categorias')->fetchAll() as $categoria)$categorias[$categoria['nombre']]=(int)$categoria['id'];
  $numero=0;
  foreach($catalogo as $nombreCategoria=>$nombres){
    if(!isset($categorias[$nombreCategoria]))throw new RuntimeException('Falta la categoría '.$nombreCategoria.'.');
    foreach($nombres as $nombre){
      $numero++;
      $codigo=sprintf('DEMO-%04d',$numero);
      if(q1('SELECT id FROM productos WHERE codigo=?',[$codigo])){$omitidosProductos++;continue;}

      $costo=$numero===1?1000:round(600+$numero*173,2);
      $comisiones=[15,20,25,30,40];
      $comision=$numero===1?50:$comisiones[$numero%count($comisiones)];
      $precio=round($costo*(1+$comision/100),2);
      q('INSERT INTO productos(codigo,nombre,categoria_id,precio,iva,stock,entrante,minimo,comision_pct,costo_referencia)
        VALUES(?,?,?,?,?,20,0,5,?,?)',[
          $codigo,'DEMO | '.$nombre,$categorias[$nombreCategoria],$precio,21,$comision,$costo
        ]);
      $productoId=$pdo->lastInsertId();
      q('INSERT INTO stock_sucursales(sucursal_id,producto_id,stock,entrante,minimo) VALUES(?,?,20,0,5)',[$sucursalId,$productoId]);
      q('INSERT INTO lotes_stock(producto_id,cantidad_inicial,cantidad_restante,costo_unitario,comision_pct,precio_venta,sucursal_id)
        VALUES(?,20,20,?,?,?,?)',[$productoId,$costo,$comision,$precio,$sucursalId]);
      $insertadosProductos++;
    }
  }
  if($numero!==100)throw new RuntimeException('El catálogo de prueba debe contener exactamente 100 productos.');

  foreach($cuentas as $cuenta){
    if(q1('SELECT id FROM usuarios WHERE usuario=?',[$cuenta['usuario']])){$omitidosUsuarios++;continue;}
    $clave='Db!'.bin2hex(random_bytes(16)).'A9';
    q('INSERT INTO usuarios(usuario,nombre,clave,rol,sucursal_id) VALUES(?,?,?,?,?)',[
      $cuenta['usuario'],$cuenta['nombre'],password_hash($clave,PASSWORD_DEFAULT),$cuenta['rol'],$sucursalId
    ]);
    q('INSERT INTO usuario_sucursales(usuario_id,sucursal_id) VALUES(?,?)',[$pdo->lastInsertId(),$sucursalId]);
    $credenciales[]=['usuario'=>$cuenta['usuario'],'clave'=>$clave,'rol'=>$cuenta['rol']];
    $insertadosUsuarios++;
  }

  $pdo->commit();
}catch(Throwable $error){
  if($pdo->inTransaction())$pdo->rollBack();
  fwrite(STDERR,'No se cargó el demo: '.$error->getMessage().PHP_EOL);
  exit(1);
}

echo 'Productos creados: '.$insertadosProductos.'; ya existentes: '.$omitidosProductos.PHP_EOL;
echo 'Usuarios creados: '.$insertadosUsuarios.'; ya existentes: '.$omitidosUsuarios.PHP_EOL;
echo 'Claves iniciales únicas (guardar y entregar de forma segura):'.PHP_EOL;
foreach($credenciales as $credencial){
  echo $credencial['usuario'],' [',$credencial['rol'],'] ',$credencial['clave'],PHP_EOL;
}
echo 'Administrador existente conservado. Productos de prueba: código DEMO-; nombre DEMO |; 20 unidades por lote.'.PHP_EOL;