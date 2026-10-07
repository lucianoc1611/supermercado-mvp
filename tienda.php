<?php
require 'lib.php';
if(!user()&&isset($_GET['sucursal'])){
  $sucursalPublica=filter_var($_GET['sucursal'],FILTER_VALIDATE_INT);
  if($sucursalPublica===false||!q1('SELECT id FROM sucursales WHERE id=? AND activa=1',[(int)$sucursalPublica])){
    http_response_code(404);
    exit('La sucursal no está disponible.');
  }
  if((int)($_SESSION['sucursal_actual_id']??sucursal_actual_id())!==(int)$sucursalPublica){
    $sessionKeyAnterior=clave_sesion_tienda();
    q('DELETE FROM reservas_tablet WHERE session_key=? AND pedido_id IS NULL',[$sessionKeyAnterior]);
    unset($_SESSION['tablet_pedido_id'],$_SESSION['tablet_codigo_confirmacion']);
  }
  $_SESSION['sucursal_actual_id']=(int)$sucursalPublica;
}
limpiar_reservas_tienda();
$sucursalId=sucursal_actual_id();
asegurar_stock_sucursal($sucursalId);
$sessionKey=clave_sesion_tienda();
if(empty($_SESSION['tablet_cart_key']))$_SESSION['tablet_cart_key']=bin2hex(random_bytes(16));
$cartKey=(string)$_SESSION['tablet_cart_key'];
$configuracion=cfg();
$cvuPago=trim((string)($configuracion['pago_cvu']??''));
$cvuPagoValido=preg_match('/^\d{22}$/D',$cvuPago)===1;
$mercadoPagoUrl=(string)($configuracion['mercadopago_url']??'');
$pagoVirtualUrl=(string)($configuracion['pago_virtual_url']??'');
$mercadoPagoDisponible=url_https_valida($mercadoPagoUrl)||$cvuPagoValido;
$pagoVirtualDisponible=!empty($configuracion['pago_virtual_nombre'])
  &&(url_https_valida($pagoVirtualUrl)||$cvuPagoValido);
$urlTienda=$configuracion['tienda_url']??'';
$hostSolicitud=strtolower((string)(parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST)?:''));
$ipSolicitud=filter_var($hostSolicitud,FILTER_VALIDATE_IP);
$hostLocal=$hostSolicitud==='localhost'||$hostSolicitud==='127.0.0.1'||$hostSolicitud==='::1'
  ||($ipSolicitud!==false&&filter_var($hostSolicitud,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)===false)
  ||substr($hostSolicitud,-6)==='.local';
$tiendaPublicaValida=url_https_valida($urlTienda,true);
$urlRegreso=$hostLocal?'tienda.php':($tiendaPublicaValida?$urlTienda:'tienda.php');
if($hostLocal||$tiendaPublicaValida)$urlRegreso.='?sucursal='.$sucursalId;
if($tiendaPublicaValida&&!$hostLocal){
  $rutaActual=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
  $rutaPublica=parse_url($urlTienda,PHP_URL_PATH);
  if($rutaActual!==$rutaPublica){
    header('Location: '.$urlTienda,true,302);
    exit;
  }
}
$error='';

if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['descargar_comprobante'])){
  $pedidoId=(int)($_SESSION['tablet_pedido_id']??0);
  $pedidoComprobante=$pedidoId?q1('SELECT venta_id,codigo FROM pedidos_tablet WHERE id=? AND sucursal_id=? AND estado="completado" AND venta_id IS NOT NULL',[$pedidoId,$sucursalId]):null;
  $ventaComprobante=$pedidoComprobante?q1('SELECT v.id,v.cliente,v.doc,v.neto,v.iva,v.total,v.pago,v.fecha,v.descuento_pct,v.descuento_monto,s.nombre socio_nombre
    FROM ventas v LEFT JOIN socios s ON s.id=v.socio_id WHERE v.id=?',[$pedidoComprobante['venta_id']]):null;
  if(!$pedidoComprobante||!$ventaComprobante){
    http_response_code(404);
    exit('El comprobante estará disponible cuando caja confirme la compra.');
  }
  $itemsComprobante=q('SELECT nombre,cantidad,precio,iva FROM venta_items WHERE venta_id=? ORDER BY id',[$ventaComprobante['id']])->fetchAll();
  $configuracionComprobante=cfg();
  $_SESSION['tablet_comprobante_descargado_id']=$pedidoId;
  header('Content-Type: text/html; charset=utf-8');
  header('Content-Disposition: attachment; filename="comprobante-compra-'.$ventaComprobante['id'].'.html"');
  header('Cache-Control: no-store, private');
  ?>
<!doctype html>
<html lang="es">
<head>
 <meta charset="utf-8">
 <meta name="viewport" content="width=device-width,initial-scale=1">
 <title>Comprobante de compra #<?=e($ventaComprobante['id'])?></title>
 <style>
  body{font:15px/1.45 Arial,sans-serif;color:#17231f;max-width:820px;margin:28px auto;padding:0 20px}
  h1{margin-bottom:4px;color:#173b35}.muted{color:#58665f}.receipt-head{display:flex;justify-content:space-between;gap:16px;padding-bottom:18px;border-bottom:2px solid #173b35}
  .receipt-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:18px 0;padding:14px;background:#f5f8f5;border-radius:8px}
  table{width:100%;border-collapse:collapse;margin:20px 0}
  th,td{padding:10px 6px;border-bottom:1px solid #dce4dc;text-align:left}
  .number{text-align:right;white-space:nowrap}.total{font-size:1.25rem;font-weight:bold;color:#173b35}
  .notice{border:1px solid #d9e4dc;background:#f5faf6;padding:12px;border-radius:8px}
  @media(max-width:520px){body{margin:14px auto;padding:0 12px}.receipt-head{gap:8px}.receipt-meta{gap:8px;padding:10px}th,td{padding:8px 4px;font-size:13px}}
  @media print{body{margin:0 auto}.no-print{display:none}.receipt{max-width:none}}
 </style>
</head>
<body>
 <p class="muted">Codex</p>
 <h1>Comprobante de compra</h1>
 <p class="muted">Venta #<?=e($ventaComprobante['id'])?> · Pedido <?=e($pedidoComprobante['codigo'])?></p>
 <p><strong>Fecha:</strong> <?=e(date('d/m/Y H:i',strtotime($ventaComprobante['fecha'])))?><br>
 <strong>Cliente:</strong> <?=e($ventaComprobante['cliente']?:'Consumidor Final')?>
 <?php if(!empty($ventaComprobante['doc'])): ?><br><strong>Documento protegido:</strong> <?=e(enmascarar_documento($ventaComprobante['doc']))?><?php endif ?><br>
 <?php if($ventaComprobante['socio_nombre']): ?><strong>Socio:</strong> <?=e($ventaComprobante['socio_nombre'])?><br><?php endif ?>
 <strong>Pago:</strong> <?=e(ucfirst($ventaComprobante['pago']??'—'))?></p>
 <table>
  <thead><tr><th>Artículo</th><th class="number">Cantidad</th><th class="number">Precio</th><th class="number">Subtotal</th></tr></thead>
  <tbody>
  <?php foreach($itemsComprobante as $itemComprobante): ?>
   <tr><td><?=e($itemComprobante['nombre'])?></td><td class="number"><?=(int)$itemComprobante['cantidad']?></td><td class="number"><?=money($itemComprobante['precio'])?></td><td class="number"><?=money((float)$itemComprobante['precio']*(int)$itemComprobante['cantidad'])?></td></tr>
  <?php endforeach ?>
  </tbody>
 </table>
 <p class="number">Subtotal: <?=money((float)$ventaComprobante['total']+(float)$ventaComprobante['descuento_monto'])?><br>
 <?php if((float)$ventaComprobante['descuento_monto']>0): ?>Descuento socio (<?=e($ventaComprobante['descuento_pct'])?>%): −<?=money($ventaComprobante['descuento_monto'])?><br><?php endif ?>
 Neto: <?=money($ventaComprobante['neto'])?><br>IVA: <?=money($ventaComprobante['iva'])?></p>
 <p class="number total">Total: <?=money($ventaComprobante['total'])?></p>
 <p class="notice">Comprobante de compra no fiscal. No reemplaza una factura fiscal.</p>
 <button class="no-print" type="button" onclick="window.print()">Imprimir o guardar como PDF</button>
</body>
</html>
  <?php
  exit;
}

if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['estado_pedido'])){
  header('Content-Type: application/json; charset=utf-8');
  $pedidoId=(int)($_SESSION['tablet_pedido_id']??0);
  $pedidoEstado=$pedidoId?q1('SELECT estado FROM pedidos_tablet WHERE id=? AND sucursal_id=?',[$pedidoId,$sucursalId]):null;
  if(!$pedidoEstado){
    http_response_code(404);
    echo json_encode(['error'=>'No hay un pedido de seguimiento.']);
  }else{
    echo json_encode(['estado'=>$pedidoEstado['estado']]);
  }
  exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  chk();
  $accion=$_POST['accion']??'';
  if($accion==='agregar'){
    $id=(int)($_POST['producto_id']??0);
    $cantidad=filter_var($_POST['cantidad']??null,FILTER_VALIDATE_INT);
    try{
      if($id<1||$cantidad===false||$cantidad<1||$cantidad>50)throw new DomainException('Cantidad inválida.');
      db()->beginTransaction();
      $producto=q1('SELECT p.id,p.nombre,s.stock FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=? WHERE p.id=? FOR UPDATE',[$sucursalId,$id]);
      if(!$producto)throw new DomainException('Producto no disponible.');
      $reservaActual=q1('SELECT cantidad FROM reservas_tablet WHERE session_key=? AND cart_key=? AND producto_id=? AND sucursal_id=? AND pedido_id IS NULL AND vence_en>NOW() FOR UPDATE',[$sessionKey,$cartKey,$id,$sucursalId]);
      $cantidadActual=(int)($reservaActual['cantidad']??0);
      $reservado=reservas_activas_producto($id);
      $totalSolicitado=$cantidadActual+$cantidad;
      $disponibleParaCarrito=(int)$producto['stock']-$reservado+$cantidadActual;
      if($disponibleParaCarrito<$totalSolicitado)throw new DomainException('No quedan unidades suficientes.');
      $oferta=q1('SELECT pr.id,pr.nombre,pr.precio FROM promociones pr JOIN promocion_items pi ON pi.promocion_id=pr.id WHERE pr.tipo="producto" AND pr.activa=1 AND pi.producto_id=? AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio<=NOW()) AND (pr.fecha_fin IS NULL OR pr.fecha_fin>NOW()) ORDER BY pr.precio ASC LIMIT 1',[$id]);
      $precioOferta=$oferta?(float)$oferta['precio']:null;
      q('INSERT INTO reservas_tablet(session_key,cart_key,producto_id,cantidad,promocion_id,precio_unitario,vence_en,sucursal_id)
        VALUES(?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),?)
        ON DUPLICATE KEY UPDATE cantidad=VALUES(cantidad),promocion_id=VALUES(promocion_id),precio_unitario=VALUES(precio_unitario),vence_en=VALUES(vence_en)',
        [$sessionKey,$cartKey,$id,$cantidadActual+$cantidad,$oferta['id']??null,$precioOferta,$sucursalId]);
      db()->commit();
      $_SESSION['tienda_mensaje']=$producto['nombre'].' agregado.';
    }catch(Throwable $exception){
      if(db()->inTransaction())db()->rollBack();
      $error=$exception instanceof DomainException?$exception->getMessage():'No se pudo reservar el producto.';
    }
  }elseif($accion==='agregar_combo'){
    $promocionId=(int)($_POST['promocion_id']??0);
    $paquetes=filter_var($_POST['cantidad']??null,FILTER_VALIDATE_INT);
    try{
      if($promocionId<1||$paquetes===false||$paquetes<1||$paquetes>20)throw new DomainException('Cantidad inválida.');
      db()->beginTransaction();
      $promocion=q1('SELECT id,nombre,precio FROM promociones WHERE id=? AND tipo="combo" AND activa=1 AND (fecha_inicio IS NULL OR fecha_inicio<=NOW()) AND (fecha_fin IS NULL OR fecha_fin>NOW()) FOR UPDATE',[$promocionId]);
      if(!$promocion)throw new DomainException('La oferta ya no está disponible.');
      $componentes=q('SELECT producto_id,cantidad FROM promocion_items WHERE promocion_id=? ORDER BY producto_id',[$promocionId])->fetchAll();
      if(!$componentes)throw new DomainException('La oferta no tiene productos.');
      $cartKeyCombo=bin2hex(random_bytes(16));
      $cantidades=[];
      $preciosNormales=[];
      $totalNormal=0;
      foreach($componentes as $componente){
        $productoId=(int)$componente['producto_id'];
        $cantidadItem=(int)$componente['cantidad']*$paquetes;
        $producto=q1('SELECT s.stock FROM stock_sucursales s WHERE s.producto_id=? AND s.sucursal_id=? FOR UPDATE',[$productoId,$sucursalId]);
        if(!$producto)throw new DomainException('Un producto de la oferta ya no está disponible.');
        if((int)$producto['stock']-reservas_activas_producto($productoId)<$cantidadItem)throw new DomainException('No hay stock suficiente para completar la oferta.');
        $precioNormal=0;
        foreach(lotes_para_venta($productoId,$cantidadItem,true) as $lote)$precioNormal+=(float)$lote['precio_venta']*(int)$lote['cantidad'];
        $cantidades[$productoId]=$cantidadItem;
        $preciosNormales[$productoId]=$precioNormal;
        $totalNormal+=$precioNormal;
      }
      if($totalNormal<=0||(float)$promocion['precio']>1000000000)throw new DomainException('Precio de oferta inválido.');
      foreach($componentes as $componente){
        $productoId=(int)$componente['producto_id'];
        $cantidadItem=$cantidades[$productoId];
        $importeAsignado=(float)$promocion['precio']*$paquetes*$preciosNormales[$productoId]/$totalNormal;
        $precioUnitario=round($importeAsignado/$cantidadItem,2);
        q('INSERT INTO reservas_tablet(session_key,cart_key,producto_id,cantidad,promocion_id,precio_unitario,vence_en,sucursal_id)
          VALUES(?,?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),?)',
          [$sessionKey,$cartKeyCombo,$productoId,$cantidadItem,$promocionId,$precioUnitario,$sucursalId]);
      }
      db()->commit();
      $_SESSION['tienda_mensaje']=$promocion['nombre'].' agregado.';
    }catch(Throwable $exception){
      if(db()->inTransaction())db()->rollBack();
      $error=$exception instanceof DomainException?$exception->getMessage():'No se pudo reservar la oferta.';
    }
  }elseif($accion==='quitar'){
    $quitarKey=(string)($_POST['cart_key']??'');
    $productoQuitar=(int)($_POST['producto_id']??0);
    if($quitarKey===$cartKey&&$productoQuitar>0){
      q('DELETE FROM reservas_tablet WHERE session_key=? AND cart_key=? AND producto_id=? AND sucursal_id=? AND pedido_id IS NULL',[$sessionKey,$quitarKey,$productoQuitar,$sucursalId]);
    }elseif(preg_match('/^[a-f0-9]{32}$/',$quitarKey)&&$quitarKey!==$cartKey){
      q('DELETE FROM reservas_tablet WHERE session_key=? AND cart_key=? AND sucursal_id=? AND pedido_id IS NULL',[$sessionKey,$quitarKey,$sucursalId]);
    }
  }elseif($accion==='vaciar'){
    q('DELETE FROM reservas_tablet WHERE session_key=? AND sucursal_id=? AND pedido_id IS NULL',[$sessionKey,$sucursalId]);
    $_SESSION['tablet_cart_key']=bin2hex(random_bytes(16));
    $cartKey=$_SESSION['tablet_cart_key'];
  }elseif($accion==='enviar'){
    $ahora=time();
    $cliente=trim((string)($_POST['cliente']??''));
    $metodoPago=(string)($_POST['metodo_pago']??'');
    $urlPago=$metodoPago==='mercadopago'?$mercadoPagoUrl
      :($metodoPago==='virtual'?$pagoVirtualUrl:'');
    $nombrePago=$metodoPago==='mercadopago'?'Mercado Pago'
      :($metodoPago==='virtual'?trim((string)($configuracion['pago_virtual_nombre']??'')):'Efectivo');
    $urlPagoValida=url_https_valida($urlPago);
    $cvuDestino=$urlPagoValida?null:($cvuPagoValido?$cvuPago:null);
    if($cliente===''||mb_strlen($cliente)>100){$error='Escribe tu nombre para identificar el pedido.';}
    elseif(!in_array($metodoPago,['efectivo','mercadopago','virtual'],true)
      ||($metodoPago!=='efectivo'&&((!$urlPagoValida&&$cvuDestino===null)||$nombrePago===''))){$error='Ese método de pago no está configurado. Consulta en caja.';}
    elseif($ahora-(int)($_SESSION['tablet_ultimo_pedido']??0)<5){$error='Espera unos segundos antes de enviar otro pedido.';}
    else{
      try{
        db()->beginTransaction();
        $pedidoAnterior=(int)($_SESSION['tablet_pedido_id']??0);
        if($pedidoAnterior&&q1('SELECT id FROM pedidos_tablet WHERE id=? AND sucursal_id=? AND estado IN("pendiente","en_proceso") FOR UPDATE',[$pedidoAnterior,$sucursalId])){
          throw new DomainException('Tu pedido anterior sigue pendiente de confirmación en caja.');
        }
        $reservas=q('SELECT r.*,p.nombre,p.iva,pr.nombre promocion_nombre,pr.tipo promocion_tipo
          FROM reservas_tablet r JOIN productos p ON p.id=r.producto_id
          LEFT JOIN promociones pr ON pr.id=r.promocion_id
          WHERE r.session_key=? AND r.sucursal_id=? AND r.pedido_id IS NULL AND r.vence_en>NOW()
          ORDER BY r.cart_key,r.producto_id FOR UPDATE',[$sessionKey,$sucursalId])->fetchAll();
        if(!$reservas)throw new DomainException('El pedido está vacío o las reservas vencieron.');
        $lineas=[];
        foreach($reservas as $reserva){
          $productoStock=q1('SELECT stock FROM stock_sucursales WHERE producto_id=? AND sucursal_id=? FOR UPDATE',[$reserva['producto_id'],$sucursalId]);
          if(!$productoStock||(int)$reserva['cantidad']>(int)$productoStock['stock'])throw new DomainException('El stock de un producto ya no alcanza.');
          $precio=$reserva['precio_unitario']!==null?(float)$reserva['precio_unitario']:null;
          if($precio===null){
            $importe=0;
            foreach(lotes_para_venta($reserva['producto_id'],(int)$reserva['cantidad'],true) as $lote)$importe+=(float)$lote['precio_venta']*(int)$lote['cantidad'];
            $precio=round($importe/(int)$reserva['cantidad'],2);
          }
          $lineas[]=['producto_id'=>$reserva['producto_id'],'nombre'=>$reserva['nombre'],'iva'=>$reserva['iva'],'cantidad'=>(int)$reserva['cantidad'],'precio'=>$precio,'promocion_id'=>$reserva['promocion_id'],'promocion_nombre'=>$reserva['promocion_nombre']];
        }
        $codigo=strtoupper(bin2hex(random_bytes(8)));
        q('INSERT INTO pedidos_tablet(codigo,cliente,metodo_pago,pago_virtual_nombre,pago_url,pago_cvu,expira_en,sucursal_id) VALUES(?,?,?,?,?,?,NULL,?)',
          [$codigo,substr($cliente,0,100),$metodoPago,$metodoPago==='virtual'?$nombrePago:null,$urlPagoValida?$urlPago:null,$metodoPago==='efectivo'?null:$cvuDestino,$sucursalId]);
        $pedidoId=db()->lastInsertId();
        foreach($lineas as $linea){
          q('INSERT INTO pedido_tablet_items(pedido_id,producto_id,nombre,cantidad,precio_estimado,iva,promocion_id,promocion_nombre) VALUES(?,?,?,?,?,?,?,?)',[
            $pedidoId,$linea['producto_id'],$linea['nombre'],$linea['cantidad'],$linea['precio'],$linea['iva'],$linea['promocion_id'],$linea['promocion_nombre']
          ]);
        }
        q('UPDATE reservas_tablet SET pedido_id=?,vence_en=NULL WHERE session_key=? AND sucursal_id=? AND pedido_id IS NULL AND vence_en>NOW()',[$pedidoId,$sessionKey,$sucursalId]);
        db()->commit();
        $_SESSION['tablet_ultimo_pedido']=$ahora;
        $_SESSION['tablet_codigo_confirmacion']=$codigo;
        $_SESSION['tablet_pedido_id']=(int)$pedidoId;
        $_SESSION['tablet_cart_key']=bin2hex(random_bytes(16));
        if($metodoPago==='mercadopago'&&$urlPagoValida){
          header('Location: '.$urlPago,true,303);
          exit;
        }
        header('Location: '.$urlRegreso);
        exit;
      }catch(Throwable $exception){
        if(db()->inTransaction())db()->rollBack();
        registrar_error_aplicacion('Pedido de tablet fallido: '.$exception->getMessage());
        $error=$exception instanceof DomainException?$exception->getMessage():'No se pudo enviar el pedido. Vuelve a intentarlo.';
      }
    }
  }
  if(!$error){header('Location: '.$urlRegreso);exit;}
}

$productos=q('
  SELECT p.id,p.codigo,p.nombre,p.categoria_id,s.stock,p.iva,c.nombre categoria,
    GREATEST(s.stock-COALESCE((SELECT SUM(r.cantidad) FROM reservas_tablet r WHERE r.producto_id=p.id AND r.sucursal_id=s.sucursal_id AND (r.vence_en IS NULL OR r.vence_en>NOW())),0),0) disponible,
    COALESCE((SELECT pr.precio FROM promociones pr JOIN promocion_items pi ON pi.promocion_id=pr.id WHERE pr.tipo="producto" AND pr.activa=1 AND pi.producto_id=p.id AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio<=NOW()) AND (pr.fecha_fin IS NULL OR pr.fecha_fin>NOW()) ORDER BY pr.precio LIMIT 1),(SELECT l.precio_venta FROM lotes_stock l WHERE l.producto_id=p.id AND l.sucursal_id=s.sucursal_id AND l.cantidad_restante>0 ORDER BY l.fecha,l.id LIMIT 1),p.precio) precio,
    (SELECT pr.id FROM promociones pr JOIN promocion_items pi ON pi.promocion_id=pr.id WHERE pr.tipo="producto" AND pr.activa=1 AND pi.producto_id=p.id AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio<=NOW()) AND (pr.fecha_fin IS NULL OR pr.fecha_fin>NOW()) ORDER BY pr.precio LIMIT 1) promocion_id,
    (SELECT pr.nombre FROM promociones pr JOIN promocion_items pi ON pi.promocion_id=pr.id WHERE pr.tipo="producto" AND pr.activa=1 AND pi.producto_id=p.id AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio<=NOW()) AND (pr.fecha_fin IS NULL OR pr.fecha_fin>NOW()) ORDER BY pr.precio LIMIT 1) promocion_nombre,
    COALESCE((SELECT l.precio_venta FROM lotes_stock l WHERE l.producto_id=p.id AND l.sucursal_id=s.sucursal_id AND l.cantidad_restante>0 ORDER BY l.fecha,l.id LIMIT 1),p.precio) precio_base
  FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=? LEFT JOIN categorias c ON c.id=p.categoria_id
  WHERE s.stock>COALESCE((SELECT SUM(r.cantidad) FROM reservas_tablet r WHERE r.producto_id=p.id AND r.sucursal_id=s.sucursal_id AND (r.vence_en IS NULL OR r.vence_en>NOW())),0)
  ORDER BY c.nombre,p.nombre
',[$sucursalId])->fetchAll();
$categorias=q('SELECT id,nombre,icono FROM categorias ORDER BY nombre')->fetchAll();
$combos=q('SELECT pr.id,pr.nombre,pr.precio,GROUP_CONCAT(CONCAT(p.nombre," × ",pi.cantidad) ORDER BY p.nombre SEPARATOR ", ") componentes
  ,GROUP_CONCAT(DISTINCT CONCAT("cat-",p.categoria_id) ORDER BY p.categoria_id SEPARATOR " ") categorias
  FROM promociones pr JOIN promocion_items pi ON pi.promocion_id=pr.id JOIN productos p ON p.id=pi.producto_id
  WHERE pr.tipo="combo" AND pr.activa=1 AND (pr.fecha_inicio IS NULL OR pr.fecha_inicio<=NOW()) AND (pr.fecha_fin IS NULL OR pr.fecha_fin>NOW())
  GROUP BY pr.id ORDER BY pr.nombre')->fetchAll();
$reservas=q('SELECT r.cart_key,r.producto_id,r.cantidad,r.promocion_id,r.precio_unitario,p.nombre,p.iva,pr.nombre promocion_nombre,pr.tipo promocion_tipo
  FROM reservas_tablet r JOIN productos p ON p.id=r.producto_id
  LEFT JOIN promociones pr ON pr.id=r.promocion_id
  WHERE r.session_key=? AND r.sucursal_id=? AND r.pedido_id IS NULL AND r.vence_en>NOW()
  ORDER BY r.cart_key,p.nombre',[$sessionKey,$sucursalId])->fetchAll();
$resumen=[];
$total=0;
$cantidadEnCarrito=0;
foreach($reservas as $reserva){
  $cantidad=(int)$reserva['cantidad'];
  $precio=$reserva['precio_unitario']!==null?(float)$reserva['precio_unitario']:null;
  if($precio===null){
    try{
      $importe=0;
      foreach(lotes_para_venta($reserva['producto_id'],$cantidad) as $lote)$importe+=(float)$lote['precio_venta']*(int)$lote['cantidad'];
      $precio=$importe/$cantidad;
    }catch(DomainException $exception){$error='Una reserva venció. Vuelve a agregar ese producto.';continue;}
  }
  if(!isset($resumen[$reserva['cart_key']]))$resumen[$reserva['cart_key']]=['cart_key'=>$reserva['cart_key'],'promocion_nombre'=>$reserva['promocion_nombre'],'promocion_tipo'=>$reserva['promocion_tipo'],'lineas'=>[],'total'=>0];
  $importe=$precio*$cantidad;
  $resumen[$reserva['cart_key']]['lineas'][]=['producto_id'=>(int)$reserva['producto_id'],'nombre'=>$reserva['nombre'],'cantidad'=>$cantidad,'precio'=>$precio,'importe'=>$importe];
  $resumen[$reserva['cart_key']]['total']+=$importe;
  $total+=$importe;
  $cantidadEnCarrito+=$cantidad;
}
$resumen=array_values($resumen);
$confirmacion=$_SESSION['tablet_codigo_confirmacion']??null;
unset($_SESSION['tablet_codigo_confirmacion']);
$mensaje=$_SESSION['tienda_mensaje']??null;
unset($_SESSION['tienda_mensaje']);
$pedidoSeguimientoId=(int)($_SESSION['tablet_pedido_id']??0);
$pedidoSeguimiento=$pedidoSeguimientoId?q1('SELECT id,codigo,cliente,estado,metodo_pago,pago_virtual_nombre,pago_url,pago_cvu FROM pedidos_tablet WHERE id=? AND sucursal_id=?',[$pedidoSeguimientoId,$sucursalId]):null;
$comprobanteDescargado=$pedidoSeguimiento
  &&(int)($_SESSION['tablet_comprobante_descargado_id']??0)===$pedidoSeguimientoId;
$itemsSeguimiento=$pedidoSeguimiento?q('SELECT nombre,cantidad,precio_estimado FROM pedido_tablet_items WHERE pedido_id=? ORDER BY id',[$pedidoSeguimientoId])->fetchAll():[];
$urlPagoSeguimiento=(string)($pedidoSeguimiento['pago_url']??'');
$cvuPagoSeguimiento=(string)($pedidoSeguimiento['pago_cvu']??'');
if($pedidoSeguimiento&&$pedidoSeguimiento['metodo_pago']!=='efectivo'&&$urlPagoSeguimiento===''&&$cvuPagoSeguimiento===''){
  $urlPagoSeguimiento=$pedidoSeguimiento['metodo_pago']==='mercadopago'?$mercadoPagoUrl
    :($pedidoSeguimiento['metodo_pago']==='virtual'?$pagoVirtualUrl:'');
  $cvuPagoSeguimiento=$cvuPago;
}
$cvuPagoSeguimientoValido=preg_match('/^\d{22}$/D',$cvuPagoSeguimiento)===1;
$totalSeguimiento=0;
foreach($itemsSeguimiento as $itemSeguimiento)$totalSeguimiento+=(float)$itemSeguimiento['precio_estimado']*(int)$itemSeguimiento['cantidad'];
$estadosPedido=[
 'pendiente'=>['warning','Esperando confirmación en caja','Conserva este ID. El pedido y su stock quedan reservados hasta que caja lo procese.'],
 'en_proceso'=>['info','Pedido en caja','El personal está preparando y cobrando tu pedido. Espera la confirmación antes de retirarte.'],
 'completado'=>['success','Compra confirmada','Caja confirmó la compra. Ya puedes retirarte.'],
 'cancelado'=>['danger','Pedido cancelado','Acércate al personal de caja para recibir ayuda.']
];
[$estadoColor,$estadoTitulo,$estadoTexto]=$estadosPedido[$pedidoSeguimiento['estado']??'']??['secondary','Estado del pedido','Consulta en caja.'];
?>
<!doctype html>
<html lang="es">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
 <meta name="theme-color" content="#183c35"><title>Catálogo de compra · Codex</title>
 <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
 <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
 <link href="assets/codex.css?v=1" rel="stylesheet">
 <style>
 :root{--ink:#173b35;--leaf:#27735d;--lime:#e7f2c8;--paper:#f5f7f2;--line:#dce4dc;--warm:#f7b65b}
 body{background:var(--paper);color:#17231f}
 .shop-head{background:var(--ink);color:#fff;border-bottom:5px solid var(--warm)}
 .shop-head .brand{font-weight:750;font-size:1.25rem}
 .catalog-layout{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,360px);gap:1.25rem;align-items:start}
 .catalog-layout>*{min-width:0}
 .product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));gap:.8rem}
 .product-item{border:1px solid var(--line);border-radius:12px;background:#fff;min-height:190px;transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease;animation:card-enter .35s both}
 .product-item:hover,.promo-item:hover{transform:translateY(-3px);box-shadow:0 .7rem 1.5rem #173b3518;border-color:#aac8b5}
 .product-item .btn,.promo-item .btn{transition:transform .15s ease,filter .15s ease}
 .product-item .btn:active,.promo-item .btn:active{transform:scale(.97)}
 .scan-panel{overflow:hidden}
 .scan-panel>summary{display:flex;align-items:center;justify-content:space-between;gap:.75rem;cursor:pointer;list-style:none;font-weight:700}
 .scan-panel>summary::-webkit-details-marker{display:none}
 .scan-panel>summary:after{content:"+";display:grid;place-items:center;width:1.8rem;height:1.8rem;border-radius:50%;background:#e7f2ec;color:var(--ink);font-size:1.2rem}
 .scan-panel[open]>summary:after{content:"−"}
 .cart-mobile-toggle{display:none}
 @keyframes card-enter{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
 .catalog-item[hidden]{display:none!important}
 .promo-item{border:1px solid #e7c861;border-top:4px solid #e7c861;border-radius:8px;background:#fff}
 .product-price{color:var(--leaf);font-size:1.2rem;font-weight:750}
 .touch{min-height:48px;font-weight:650}
 .scan-panel{border:1px solid var(--line);border-left:5px solid var(--leaf);border-radius:8px;background:#fff}
 .scan-panel input,.scan-panel button{min-height:48px}
 .camera-preview{width:100%;max-height:42vh;object-fit:cover;border-radius:6px;background:#111}
 .cart-panel{position:sticky;top:1rem;border-top:4px solid var(--leaf)}
 .category-strip{display:flex;gap:.5rem;overflow:auto;padding:.25rem 0 .75rem}
 .category-strip .btn{white-space:nowrap}
 @media(max-width:850px){.catalog-layout{grid-template-columns:minmax(0,1fr) minmax(280px,320px);gap:.75rem}.product-grid{grid-template-columns:repeat(auto-fill,minmax(min(100%,160px),1fr))}}
 @media(max-width:700px){
  body{padding-bottom:5.4rem}
  .catalog-layout{display:block}
  .cart-panel{position:fixed;z-index:1030;left:.5rem;right:.5rem;bottom:.5rem;top:auto;max-height:4.6rem;overflow:hidden;margin:0;border-radius:14px;box-shadow:0 8px 30px #173b3540;transition:max-height .25s ease}
  .cart-panel.cart-open{max-height:min(76vh,680px);overflow-y:auto}
  .cart-mobile-toggle{position:sticky;top:0;display:flex;justify-content:space-between;align-items:center;gap:.75rem;width:100%;min-height:4.5rem;padding:.7rem 1rem;border:0;border-radius:13px 13px 0 0;background:var(--ink);color:#fff;text-align:left}
  .cart-mobile-toggle .cart-summary-total{font-size:1.05rem;font-weight:800}
  .cart-mobile-toggle .cart-summary-hint{display:block;color:#c6ddd0;font-size:.78rem}
  .cart-panel .card-body{display:none;padding:.75rem 1rem 1.25rem}
  .cart-panel.cart-open .card-body{display:block}
  .cart-panel.cart-open .cart-mobile-toggle{border-radius:13px 13px 0 0}
  .cart-panel .list-group{max-height:22vh;overflow-y:auto}
  .product-grid{grid-template-columns:repeat(auto-fill,minmax(min(100%,150px),1fr))}
 }
 @media(prefers-reduced-motion:reduce){.product-item,.product-item .btn,.promo-item .btn,.cart-panel{animation:none;transition:none}}
 </style>
</head>
<body>
<header class="shop-head py-3"><div class="container d-flex align-items-center justify-content-between gap-3">
 <div class="brand"><i class="bi bi-basket2-fill me-2"></i>Codex</div>
 <span class="small text-white-50">Catálogo de compra</span>
</div></header>
<main class="container py-4">
 <?php if($confirmacion): ?>
  <div class="alert alert-success d-flex gap-3 align-items-start" role="status">
   <i class="bi bi-check-circle-fill fs-3"></i><div><h1 class="h5 mb-1">Pedido enviado</h1><p class="mb-0">ID para seguirlo: <strong class="font-monospace"><?=e($confirmacion)?></strong>.</p></div>
  </div>
 <?php endif ?>
 <?php if($error): ?><div class="alert alert-warning" role="alert"><?=e($error)?></div><?php endif ?>
 <?php if($mensaje): ?><div class="alert alert-success py-2" role="status"><?=e($mensaje)?></div><?php endif ?>
 <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
  <div><h1 class="h3 mb-1">Elegí tus productos</h1><p class="text-muted mb-0">El pago se confirma en caja.</p></div>
  <div class="input-group" style="max-width:360px"><span class="input-group-text"><i class="bi bi-search"></i></span><input id="buscar" class="form-control" placeholder="Buscar producto" aria-label="Buscar producto"></div>
 </div>
 <details class="scan-panel mb-3">
  <summary class="p-3" aria-label="Expandir lector de códigos"><span><i class="bi bi-upc-scan me-2"></i>Escanear o cargar un código</span><small class="text-muted fw-normal">Toca para abrir</small></summary>
  <div class="px-3 pb-3" aria-label="Lectura de código">
  <div class="row g-2 align-items-end">
   <div class="col-md">
    <label class="form-label fw-semibold" for="codigoEscaneado"><i class="bi bi-upc-scan me-1"></i>Escanear código de barras o QR</label>
    <input id="codigoEscaneado" class="form-control form-control-lg" inputmode="none" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Esperando lector...">
   </div>
   <div class="col-5 col-md-2">
    <label class="form-label" for="cantidadEscaneada">Unidades</label>
    <input id="cantidadEscaneada" class="form-control form-control-lg" type="number" min="1" max="50" value="1" step="1">
   </div>
   <div class="col-7 col-md-auto">
    <button id="abrirCamara" class="btn btn-outline-success touch w-100" type="button"><i class="bi bi-qr-code-scan me-1"></i>Leer QR con cámara</button>
   </div>
  </div>
  <div id="estadoEscaner" class="small text-muted mt-2" role="status" aria-live="polite"></div>
  <div id="panelCamara" class="mt-2" hidden>
   <video id="videoCamara" class="camera-preview" playsinline muted></video>
   <button id="cerrarCamara" class="btn btn-outline-secondary touch mt-2" type="button">Cerrar cámara</button>
  </div>
  </div>
 </details>
 <form id="formEscaner" method="post" class="visually-hidden" aria-hidden="true">
  <input type="hidden" name="c" value="<?=csrf()?>">
  <input type="hidden" name="accion" value="agregar">
  <input id="productoEscaneado" type="hidden" name="producto_id">
  <input id="unidadesEscaneadas" type="hidden" name="cantidad">
 </form>
 <div class="catalog-layout">
  <section aria-label="Catálogo">
   <?php if($combos): ?>
    <section class="mb-4" aria-label="Ofertas y combos">
     <h2 class="h5 mb-2"><i class="bi bi-tags-fill me-2"></i>Ofertas</h2>
     <div class="product-grid">
      <?php foreach($combos as $combo): ?>
       <article class="catalog-item promo-item p-3 d-flex flex-column" data-categorias="<?=e($combo['categorias']??'')?>" data-texto="<?=e(mb_strtolower($combo['nombre'].' '.$combo['componentes']))?>">
        <span class="badge text-bg-warning align-self-start mb-2">COMBO</span>
        <h3 class="h6 flex-grow-1"><?=e($combo['nombre'])?></h3>
        <p class="small text-muted mb-2"><?=e($combo['componentes'])?></p>
        <div class="product-price mb-2"><?=money($combo['precio'])?></div>
        <form method="post" class="d-flex gap-2 form-reserva">
         <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="agregar_combo"><input type="hidden" name="promocion_id" value="<?=$combo['id']?>">
         <div class="cx-qty"><button type="button" data-qty="-1" aria-label="Menos">−</button><input name="cantidad" type="number" min="1" max="20" value="1" aria-label="Cantidad de combos <?=e($combo['nombre'])?>"><button type="button" data-qty="1" aria-label="Más">+</button></div>
         <button class="btn cx-add touch flex-grow-1">Agregar combo</button>
        </form>
       </article>
      <?php endforeach ?>
     </div>
    </section>
   <?php endif ?>
   <nav class="category-strip" aria-label="Categorías">
    <button class="btn btn-success touch" data-filtro="todas">Todos</button>
    <?php foreach($categorias as $categoria): ?><button class="btn btn-outline-success touch" data-filtro="cat-<?=$categoria['id']?>"><i class="bi bi-<?=e($categoria['icono'])?> me-1"></i><?=e($categoria['nombre'])?></button><?php endforeach ?>
   </nav>
   <?php if(!$productos): ?><div class="alert alert-info">No hay productos disponibles para comprar en este momento.</div><?php endif ?>
   <div class="product-grid" id="catalogo">
    <?php foreach($productos as $producto): ?>
    <article class="catalog-item product-item p-3 d-flex flex-column" data-pid="<?=$producto['id']?>" data-categorias="cat-<?=$producto['categoria_id']?>" data-codigo="<?=e($producto['codigo'])?>" data-texto="<?=e(mb_strtolower($producto['nombre'].' '.$producto['codigo']))?>">
      <div class="small text-muted mb-2"><?=e($producto['categoria']??'Otros')?></div>
      <h2 class="h6 flex-grow-1"><?=e($producto['nombre'])?></h2>
      <?php if($producto['promocion_id']): ?><span class="badge text-bg-warning align-self-start mb-1">OFERTA</span><?php endif ?>
      <div class="product-price mb-1"><?=money($producto['precio'])?></div>
      <?php if($producto['promocion_id']): ?><del class="small text-muted mb-1"><?=money($producto['precio_base'])?></del><?php endif ?>
      <div class="small text-muted mb-3"><span data-disp><?=$producto['disponible']?></span> disponibles</div>
      <form method="post" class="d-flex gap-2 form-reserva">
       <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="agregar"><input type="hidden" name="producto_id" value="<?=$producto['id']?>">
       <div class="cx-qty"><button type="button" data-qty="-1" aria-label="Menos">−</button><input type="number" name="cantidad" value="1" min="1" max="<?=max(1,min(50,(int)$producto['disponible']))?>" aria-label="Cantidad de <?=e($producto['nombre'])?>"><button type="button" data-qty="1" aria-label="Más">+</button></div>
       <button class="btn cx-add touch flex-grow-1"><i class="bi bi-plus-lg me-1"></i>Agregar</button>
      </form>
     </article>
    <?php endforeach ?>
   </div>
  </section>

  <aside id="cartPanel" class="card cart-panel shadow-sm" aria-label="Carrito de compra">
   <button id="cartMobileToggle" class="cart-mobile-toggle" type="button" aria-controls="cartContent" aria-expanded="false">
    <span><i class="bi bi-bag-check me-2"></i>Mi pedido <span class="badge text-bg-success"><?=$cantidadEnCarrito?></span><span class="cart-summary-hint">Toca para expandir o encoger</span></span>
    <span class="cart-summary-total"><?=money($total)?></span>
   </button>
   <div id="cartContent" class="card-body">
    <?php if($pedidoSeguimiento): ?>
     <section id="seguimientoPedido" class="<?=$pedidoSeguimiento['estado']==='completado'?'border rounded p-3 bg-white':'alert alert-'.$estadoColor?> mb-3" data-estado="<?=$pedidoSeguimiento['estado']?>" data-pedido-id="<?=$pedidoSeguimiento['id']?>" data-comprobante-descargado="<?=$comprobanteDescargado?'1':'0'?>" role="status" aria-live="polite">
      <h2 class="h5 mb-1"><?=e($estadoTitulo)?> <span class="font-monospace">#<?=e($pedidoSeguimiento['codigo'])?></span></h2>
      <p class="mb-2"><?=e($pedidoSeguimiento['cliente'])?> — <?=e($estadoTexto)?></p>
      <?php if($pedidoSeguimiento['estado']==='completado'): ?><a class="btn btn-outline-success" href="?descargar_comprobante=1" download>Descargar comprobante de compra</a><?php endif ?>
      <?php if($pedidoSeguimiento['metodo_pago']==='efectivo'&&$pedidoSeguimiento['estado']==='pendiente'): ?>
       <p class="mb-2"><strong>Pago en efectivo:</strong> acércate a la caja e indica el ID de tu pedido.</p>
      <?php elseif($pedidoSeguimiento['metodo_pago']!=='efectivo'&&$pedidoSeguimiento['estado']==='pendiente'): ?>
       <p class="mb-2">Método elegido: <strong><?=e($pedidoSeguimiento['metodo_pago']==='mercadopago'?'Mercado Pago':$pedidoSeguimiento['pago_virtual_nombre'])?></strong>. El pago debe verificarse en caja.</p>
       <?php if(url_https_valida($urlPagoSeguimiento)): ?><a class="btn btn-primary" href="<?=e($urlPagoSeguimiento)?>" target="_blank" rel="noopener noreferrer">Ir a pagar</a>
       <?php elseif($cvuPagoSeguimientoValido): ?>
        <p class="mb-2">Transferí el total estimado y mostrá el comprobante en caja; allí se confirmará el monto final.</p>
        <div class="input-group mb-2"><input class="form-control font-monospace" value="<?=e($cvuPagoSeguimiento)?>" aria-label="CVU para transferir" readonly><button class="btn btn-outline-secondary" type="button" data-copiar-cvu="<?=e($cvuPagoSeguimiento)?>" data-mensaje-cvu="estadoCvuSeguimiento">Copiar CVU</button></div>
        <small id="estadoCvuSeguimiento" class="d-block" role="status" aria-live="polite">Abrí tu banco o billetera y pegá el CVU para realizar la transferencia.</small>
       <?php else: ?><p class="mb-0">No hay un enlace ni un CVU de pago configurado. Acércate a caja.</p><?php endif ?>
      <?php endif ?>
      <ul class="mb-0 mt-2"><?php foreach($itemsSeguimiento as $itemSeguimiento): ?><li><?=e($itemSeguimiento['nombre'])?> × <?=$itemSeguimiento['cantidad']?> — <?=money((float)$itemSeguimiento['precio_estimado']*(int)$itemSeguimiento['cantidad'])?></li><?php endforeach ?></ul>
      <div class="d-flex justify-content-between border-top mt-2 pt-2"><span>Total estimado</span><strong><?=money($totalSeguimiento)?></strong></div>
      <?php if(in_array($pedidoSeguimiento['estado'],['pendiente','en_proceso'],true)): ?><small id="estadoSeguimiento" class="d-block mt-2">El estado se actualiza automáticamente.</small><?php endif ?>
     </section>
    <?php endif ?>
    <h2 class="h5"><i class="bi bi-bag-check me-2"></i>Tu pedido <span class="badge text-bg-success"><?=$cantidadEnCarrito?></span></h2>
    <?php if(!$resumen): ?><p class="text-muted py-3 mb-0">Agregá productos para empezar.</p>
    <?php else: ?>
    <div class="list-group list-group-flush mb-3">
     <?php foreach($resumen as $grupo): ?><div class="list-group-item px-0">
      <?php if($grupo['promocion_nombre']): ?><div class="fw-semibold mb-1"><?=e($grupo['promocion_nombre'])?></div><?php endif ?>
      <?php foreach($grupo['lineas'] as $linea): ?>
       <div class="d-flex justify-content-between gap-2"><div><div><?=e($linea['nombre'])?></div><small class="text-muted"><?=$linea['cantidad']?> × <?=money($linea['precio'])?></small></div><strong><?=money($linea['importe'])?></strong></div>
      <?php endforeach ?>
      <form method="post" class="text-end mt-1"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="quitar"><input type="hidden" name="cart_key" value="<?=e($grupo['cart_key'])?>"><?php if($grupo['cart_key']===$cartKey): ?><input type="hidden" name="producto_id" value="<?=$grupo['lineas'][0]['producto_id']?>"><?php endif ?><button class="btn btn-sm btn-link text-danger p-0">Quitar</button></form>
     </div><?php endforeach ?>
     </div>
     <div class="d-flex justify-content-between border-top pt-3 mb-3"><span>Total estimado</span><strong class="fs-5"><?=money($total)?></strong></div>
     <form method="post">
      <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="enviar">
      <label for="cliente" class="form-label fw-semibold">Tu nombre (obligatorio)</label>
      <input id="cliente" name="cliente" maxlength="100" class="form-control mb-3" autocomplete="name" required>
      <label for="metodoPago" class="form-label fw-semibold">¿Cómo vas a pagar?</label>
      <select id="metodoPago" name="metodo_pago" class="form-select mb-2" required>
       <option value="efectivo">Efectivo — pagar en caja</option>
       <?php if($mercadoPagoDisponible): ?><option value="mercadopago" data-cvu="<?=url_https_valida($mercadoPagoUrl)?'0':'1'?>">Mercado Pago</option><?php endif ?>
       <?php if($pagoVirtualDisponible): ?><option value="virtual" data-cvu="<?=url_https_valida($pagoVirtualUrl)?'0':'1'?>"><?=e($configuracion['pago_virtual_nombre'])?></option><?php endif ?>
      </select>
      <div class="form-text mb-3">El pago digital se verifica en caja; seleccionar un enlace no confirma el pago.</div>
      <?php if($cvuPagoValido): ?>
       <div id="instruccionesCvu" class="alert alert-info py-2" hidden>
        <div class="fw-semibold mb-1">Transferí desde tu banco o billetera</div>
        <div class="input-group"><input class="form-control font-monospace" value="<?=e($cvuPago)?>" aria-label="CVU para transferir" readonly><button class="btn btn-outline-secondary" type="button" data-copiar-cvu="<?=e($cvuPago)?>" data-mensaje-cvu="estadoCvuSeleccion">Copiar CVU</button></div>
        <small id="estadoCvuSeleccion" class="d-block mt-1" role="status" aria-live="polite">Creá el pedido y luego transferí el total estimado. Conservá el ID y mostrá el comprobante en caja.</small>
       </div>
      <?php endif ?>
      <button id="enviarPedido" class="btn btn-success btn-lg touch w-100"><i class="bi bi-send me-2"></i>Crear pedido</button>
     </form>
     <form method="post" class="mt-2"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="vaciar"><button class="btn btn-outline-secondary touch w-100">Vaciar pedido</button></form>
    <?php endif ?>
   </div>
  </aside>
 </div>
</main>
<script>
const buscar=document.getElementById('buscar');
const codigoEscaneado=document.getElementById('codigoEscaneado');
const cantidadEscaneada=document.getElementById('cantidadEscaneada');
const estadoEscaner=document.getElementById('estadoEscaner');
const videoCamara=document.getElementById('videoCamara');
const panelCamara=document.getElementById('panelCamara');
document.querySelectorAll('.form-reserva').forEach(form=>form.addEventListener('submit',()=>{
  const boton=form.querySelector('button[type="submit"],button:not([type])');
  if(boton)boton.disabled=true;
}));
const metodoPago=document.getElementById('metodoPago');
const enviarPedido=document.getElementById('enviarPedido');
const instruccionesCvu=document.getElementById('instruccionesCvu');
const cartPanel=document.getElementById('cartPanel');
const cartMobileToggle=document.getElementById('cartMobileToggle');
if(cartPanel&&cartMobileToggle){
  cartMobileToggle.addEventListener('click',()=>{
    const abierto=cartPanel.classList.toggle('cart-open');
    cartMobileToggle.setAttribute('aria-expanded',String(abierto));
    cartMobileToggle.querySelector('.cart-summary-hint').textContent=abierto?'Toca para encoger el pedido':'Toca para expandir el pedido';
  });
}
function actualizarMetodoPago(){
  if(enviarPedido)enviarPedido.innerHTML='<i class="bi bi-send me-2"></i>Crear pedido';
  if(metodoPago&&instruccionesCvu){
    const opcionSeleccionada=metodoPago.options[metodoPago.selectedIndex];
    instruccionesCvu.hidden=!opcionSeleccionada||opcionSeleccionada.dataset.cvu!=='1';
  }
}
if(metodoPago)metodoPago.addEventListener('change',actualizarMetodoPago);
actualizarMetodoPago();
const seguimiento=document.getElementById('seguimientoPedido');
let descargaComprobanteIniciada=false;
async function descargarComprobante(){
  if(descargaComprobanteIniciada)return;
  descargaComprobanteIniciada=true;
  const url=window.location.pathname+'?descargar_comprobante=1';
  try{
    const respuesta=await fetch(url,{cache:'no-store',headers:{Accept:'text/html'}});
    if(!respuesta.ok)throw new Error('Respuesta '+respuesta.status);
    const archivo=URL.createObjectURL(await respuesta.blob());
    const enlace=document.createElement('a');
    enlace.href=archivo;
    enlace.download='comprobante-compra-'+seguimiento.dataset.pedidoId+'.html';
    document.body.appendChild(enlace);
    enlace.click();
    enlace.remove();
    window.setTimeout(()=>URL.revokeObjectURL(archivo),1000);
    seguimiento.dataset.comprobanteDescargado='1';
  }catch(error){
    descargaComprobanteIniciada=false;
    const enlaceDescarga=seguimiento.querySelector('a[download]');
    if(enlaceDescarga)enlaceDescarga.focus();
  }
}
if(seguimiento?.dataset.estado==='completado'&&seguimiento.dataset.comprobanteDescargado!=='1'){
  descargarComprobante();
}
document.querySelectorAll('[data-copiar-cvu]').forEach(boton=>boton.addEventListener('click',async()=>{
  const mensaje=document.getElementById(boton.dataset.mensajeCvu);
  try{
    await navigator.clipboard.writeText(boton.dataset.copiarCvu);
    if(mensaje)mensaje.textContent='CVU copiado.';
  }catch{
    if(mensaje)mensaje.textContent='No se pudo copiar automáticamente. Seleccioná el CVU y copialo manualmente.';
  }
}));
if(seguimiento&&['pendiente','en_proceso'].includes(seguimiento.dataset.estado)){
  const estadoSeguimiento=document.getElementById('estadoSeguimiento');
  const revisarEstado=async()=>{
    try{
      const respuesta=await fetch(window.location.pathname+'?estado_pedido=1',{cache:'no-store',headers:{Accept:'application/json'}});
      if(!respuesta.ok)throw new Error('Respuesta '+respuesta.status);
      const datos=await respuesta.json();
      if(datos.estado!==seguimiento.dataset.estado){
        if(datos.estado==='completado'){
          seguimiento.dataset.estado='completado';
          descargarComprobante();
        }else window.location.reload();
        return;
      }
    }catch(error){
      if(estadoSeguimiento)estadoSeguimiento.textContent='No se pudo actualizar el estado. Conserva el ID y consulta en caja.';
    }
  };
  setInterval(revisarEstado,5000);
}
let categoria='todas';
function filtrar(){
  const texto=buscar.value.trim().toLocaleLowerCase('es');
  document.querySelectorAll('.catalog-item').forEach(item=>{
    const categoriasItem=(item.dataset.categorias||'').split(' ');
    item.hidden=!(categoria==='todas'||categoriasItem.includes(categoria))||!item.dataset.texto.includes(texto);
  });
}
buscar.addEventListener('input',filtrar);
document.querySelectorAll('[data-filtro]').forEach(boton=>boton.addEventListener('click',()=>{
  categoria=boton.dataset.filtro;
  document.querySelectorAll('[data-filtro]').forEach(elemento=>elemento.classList.toggle('btn-success',elemento===boton));
  document.querySelectorAll('[data-filtro]').forEach(elemento=>elemento.classList.toggle('btn-outline-success',elemento!==boton));
  filtrar();
}));

function extraerCodigo(valor){
  let codigo=valor.trim();
  try{
    const datos=JSON.parse(codigo);
    if(datos&&typeof datos==='object')codigo=datos.codigo||datos.sku||datos.code||codigo;
  }catch{}
  try{
    if(/^https?:\/\//i.test(codigo)){
      const url=new URL(codigo);
      codigo=url.searchParams.get('codigo')||url.searchParams.get('sku')||url.searchParams.get('code')||url.pathname.split('/').filter(Boolean).pop()||codigo;
    }
  }catch{}
  return String(codigo).trim();
}

function procesarEscaneo(){
  const codigo=extraerCodigo(codigoEscaneado.value);
  if(!codigo)return;
  const producto=[...document.querySelectorAll('.product-item')].find(item=>item.dataset.codigo.toLocaleLowerCase('es')===codigo.toLocaleLowerCase('es'));
  if(!producto){
    estadoEscaner.textContent='No se encontró ese código entre los productos disponibles.';
    codigoEscaneado.select();
    return;
  }
  const cantidad=Number(cantidadEscaneada.value);
  if(!Number.isInteger(cantidad)||cantidad<1||cantidad>50){
    estadoEscaner.textContent='La cantidad debe estar entre 1 y 50 unidades.';
    cantidadEscaneada.focus();
    return;
  }
  estadoEscaner.textContent='Agregando '+cantidad+' × '+producto.querySelector('h2').textContent.trim()+'...';
  document.getElementById('productoEscaneado').value=producto.querySelector('[name="producto_id"]').value;
  document.getElementById('unidadesEscaneadas').value=String(cantidad);
  document.getElementById('formEscaner').requestSubmit();
}

codigoEscaneado.addEventListener('keydown',evento=>{
  if(evento.key==='Enter'){
    evento.preventDefault();
    procesarEscaneo();
  }
});
window.addEventListener('load',()=>codigoEscaneado.focus());

let flujoCamara=null;
let cicloCamara=0;
function cerrarCamara(){
  cicloCamara++;
  if(flujoCamara){flujoCamara.getTracks().forEach(track=>track.stop());flujoCamara=null;}
  videoCamara.srcObject=null;
  panelCamara.hidden=true;
}

async function abrirCamara(){
  if(!window.isSecureContext||!navigator.mediaDevices?.getUserMedia||!('BarcodeDetector' in window)){
    estadoEscaner.textContent='La cámara QR requiere HTTPS y un navegador compatible. El lector físico funciona en esta URL LAN.';
    return;
  }
  try{
    const formatos=await BarcodeDetector.getSupportedFormats();
    const admitidos=['qr_code','ean_13','ean_8','upc_a','upc_e','code_128','code_39','itf'].filter(formato=>formatos.includes(formato));
    if(!admitidos.length)throw new Error('No hay formatos compatibles.');
    const detector=new BarcodeDetector({formats:admitidos});
    flujoCamara=await navigator.mediaDevices.getUserMedia({video:{facingMode:{ideal:'environment'}},audio:false});
    const idCiclo=++cicloCamara;
    videoCamara.srcObject=flujoCamara;
    panelCamara.hidden=false;
    await videoCamara.play();
    estadoEscaner.textContent='Apunta la cámara al código del producto.';
    const buscarEnVideo=async()=>{
      if(!flujoCamara||idCiclo!==cicloCamara)return;
      try{
        const codigos=await detector.detect(videoCamara);
        if(codigos.length){
          codigoEscaneado.value=codigos[0].rawValue;
          cerrarCamara();
          procesarEscaneo();
          return;
        }
      }catch{}
      requestAnimationFrame(buscarEnVideo);
    };
    buscarEnVideo();
  }catch{
    cerrarCamara();
    estadoEscaner.textContent='No se pudo abrir la cámara. Revisa permisos o usa el lector físico.';
  }
}

document.getElementById('abrirCamara').addEventListener('click',()=>codexEscaner.abrir({panel:panelCamara,onCode:c=>{codigoEscaneado.value=c;procesarEscaneo();},estado:m=>{estadoEscaner.textContent=m;}}));

</script>
<script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script>
function actualizarMetodoPago(){
  const mp=document.getElementById('metodoPago'),ic=document.getElementById('instruccionesCvu'),ep=document.getElementById('enviarPedido');
  if(ep)ep.innerHTML='<i class="bi bi-send me-2"></i>Crear pedido';
  if(mp&&ic){const o=mp.options[mp.selectedIndex];ic.hidden=!o||o.dataset.cvu!=='1';}
}
document.addEventListener('change',e=>{if(e.target.id==='metodoPago')actualizarMetodoPago();});
document.addEventListener('click',async e=>{
  const b=e.target.closest('[data-copiar-cvu]');if(!b)return;
  const m=document.getElementById(b.dataset.mensajeCvu);
  try{await navigator.clipboard.writeText(b.dataset.copiarCvu);if(m)m.textContent='CVU copiado.';}
  catch{if(m)m.textContent='No se pudo copiar automáticamente. Seleccioná el CVU y copialo manualmente.';}
});
window.CODEX_AJAX={
  acciones:['agregar','agregar_combo','quitar','vaciar'],
  swap:['#cartContent','#cartMobileToggle'],
  mensaje:doc=>{const a=doc.querySelector('main > .alert-success,main > .alert-warning');return a?a.textContent.trim():''},
  onDone:(doc,form)=>{
    actualizarMetodoPago();
    doc.querySelectorAll('.product-item[data-pid]').forEach(n=>{
      const v=document.querySelector('.product-item[data-pid="'+n.dataset.pid+'"]');if(!v)return;
      v.querySelector('[data-disp]').textContent=n.querySelector('[data-disp]').textContent;
      const i=v.querySelector('input[name="cantidad"]'),ni=n.querySelector('input[name="cantidad"]');
      i.max=ni.max;if(+i.value>+i.max)i.value=i.max;
    });
    if(form.id==='formEscaner'){codigoEscaneado.value='';codigoEscaneado.focus();estadoEscaner.textContent='Producto agregado.';}
  }
};
</script>
<script src="assets/codex.js?v=1"></script>
</body></html>