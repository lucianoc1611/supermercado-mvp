<?php
require 'lib.php';
$u=need('ventas','registrar');
if(!verificar_esquema_operativo([
  'stock_sucursales'=>['sucursal_id','producto_id','stock'],
  'lotes_stock'=>['sucursal_id','producto_id','cantidad_restante','precio_venta'],
  'reservas_tablet'=>['sucursal_id','producto_id','session_key'],
  'pedidos_tablet'=>['sucursal_id','estado','expira_en'],
  'pedido_tablet_items'=>['pedido_id','producto_id'],
  'ventas'=>['sucursal_id']
]))solicitar_migracion_operativa('Caja');
limpiar_reservas_tienda();

// Validar que el cajero esté asignado a una caja
$sucursalId=sucursal_actual_id();
$asignacion=q1('SELECT * FROM asignaciones_caja WHERE usuario_id=? AND sucursal_id=? AND fecha_fin IS NULL ORDER BY fecha_inicio DESC LIMIT 1',[$u['id'],$sucursalId]);
if(!$asignacion && $u['rol']=='cajero'){
  head('Caja');
  echo '<div class="alert alert-danger">No estás asignado a ninguna caja. Contacta a un encargado.</div>';
  foot();
  exit;
}

if($_SERVER['REQUEST_METHOD']==='GET'&&isset($_GET['validar_socio'])){
  header('Content-Type: application/json; charset=utf-8');
  $dniSocio=normalizar_dni((string)($_GET['dni']??''));
  if($dniSocio===null){
    http_response_code(400);
    echo json_encode(['error'=>'Ingresa un DNI válido de 7 u 8 dígitos.']);
    exit;
  }
  $socio=q1('SELECT nombre FROM socios WHERE dni=? AND activo=1',[$dniSocio]);
  if(!$socio){
    http_response_code(404);
    echo json_encode(['error'=>'No encontramos un socio activo con ese DNI.']);
    exit;
  }
  echo json_encode(['nombre'=>$socio['nombre'],'descuento_pct'=>20]);
  exit;
}

$carrito=&$_SESSION['carrito'];
if(!isset($carrito)) $carrito=[];
foreach($carrito as &$item){
  $producto=q1('SELECT id,nombre,iva FROM productos WHERE id=?',[(int)($item['id']??0)]);
  if($producto)$item=array_merge($item,['id'=>$producto['id'],'nombre'=>$producto['nombre'],'iva'=>$producto['iva'],'cantidad'=>max(0,(int)($item['cantidad']??0))]);
}
unset($item);
$pedidoTabletId=(int)($_SESSION['pedido_tablet_id']??0);

// Procesar acciones
if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  $accion=$_POST['accion']??'';
  
  if($accion=='agregar'){
    $prod_id=(int)($_POST['producto_id']??0);
    $cantidad=filter_var($_POST['cantidad']??null,FILTER_VALIDATE_INT);
    $prod=q1('SELECT p.id,p.nombre,p.iva,s.stock FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=? WHERE p.id=?',[$sucursalId,$prod_id]);
    $enCarrito=0;
    foreach($carrito as $item)if((int)$item['id']===$prod_id)$enCarrito+=(int)$item['cantidad'];
    
    $stockLibre=$prod?(int)$prod['stock']-reservas_activas_producto($prod_id,$pedidoTabletId?:null):0;
    if($prod && $cantidad!==false && $cantidad>0 && $enCarrito+$cantidad<=$stockLibre){
      $existe=false;
      foreach($carrito as &$item){
        if($item['id']==$prod_id){
          $item['cantidad']+=$cantidad;
          $existe=true;
          break;
        }
      }
      if(!$existe){
        $carrito[]=['id'=>$prod['id'],'nombre'=>$prod['nombre'],'iva'=>$prod['iva'],'cantidad'=>$cantidad];
      }
    }else{
      $_SESSION['m']='Cantidad inválida o stock insuficiente.';
    }
  }
  
  elseif($accion=='quitar'){
    $idx=(int)($_POST['idx']??-1);
    if(isset($carrito[$idx])) array_splice($carrito,$idx,1);
  }
  
  elseif($accion=='vaciar'){
    if($pedidoTabletId){
      db()->beginTransaction();
      q('UPDATE pedidos_tablet SET estado="cancelado" WHERE id=? AND sucursal_id=? AND estado IN("pendiente","en_proceso")',[$pedidoTabletId,$sucursalId]);
      q('DELETE FROM reservas_tablet WHERE pedido_id=? AND sucursal_id=?',[$pedidoTabletId,$sucursalId]);
      db()->commit();
    }
    $carrito=[];
    unset($_SESSION['pedido_tablet_id']);
  }

  elseif($accion==='cargar_pedido_tablet'){
    $pedidoId=(int)($_POST['pedido_id']??0);
    if($carrito){
      $_SESSION['m']='Vacía el carrito actual antes de cargar un pedido de tablet.';
    }else{
      try{
        db()->beginTransaction();
        $pedido=q1('SELECT id FROM pedidos_tablet WHERE id=? AND sucursal_id=? AND estado="pendiente" AND (expira_en IS NULL OR expira_en>NOW()) FOR UPDATE',[$pedidoId,$sucursalId]);
        if(!$pedido)throw new DomainException('El pedido ya fue tomado o no existe.');
        $items=q('SELECT producto_id,nombre,cantidad,iva,precio_estimado,promocion_id,promocion_nombre FROM pedido_tablet_items WHERE pedido_id=? ORDER BY id',[$pedidoId])->fetchAll();
        if(!$items)throw new DomainException('El pedido no tiene artículos.');
        $tomado=q('UPDATE pedidos_tablet SET estado="en_proceso",cajero_id=? WHERE id=? AND sucursal_id=? AND estado="pendiente"',[$u['id'],$pedidoId,$sucursalId])->rowCount();
        if($tomado!==1)throw new DomainException('Otro cajero ya tomó este pedido.');
        db()->commit();
        foreach($items as $item)$carrito[]=['id'=>$item['producto_id'],'nombre'=>$item['nombre'],'iva'=>$item['iva'],'cantidad'=>(int)$item['cantidad'],'precio_fijado'=>$item['promocion_id']?(float)$item['precio_estimado']:null,'promocion_id'=>$item['promocion_id'],'promocion_nombre'=>$item['promocion_nombre']];
        $_SESSION['pedido_tablet_id']=$pedidoId;
      }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        $_SESSION['m']=$error instanceof DomainException?$error->getMessage():'No se pudo cargar el pedido de tablet.';
      }
    }

  }elseif($accion==='cancelar_pedido_tablet'){
      $pedidoId=(int)($_POST['pedido_id']??0);
      try{
        db()->beginTransaction();
        $pedido=q1('SELECT id FROM pedidos_tablet WHERE id=? AND sucursal_id=? AND estado="pendiente" FOR UPDATE',[$pedidoId,$sucursalId]);
        if(!$pedido)throw new DomainException('El pedido ya fue tomado o no existe.');
        q('UPDATE pedidos_tablet SET estado="cancelado" WHERE id=? AND sucursal_id=? AND estado="pendiente"',[$pedidoId,$sucursalId]);
        q('DELETE FROM reservas_tablet WHERE pedido_id=? AND sucursal_id=?',[$pedidoId,$sucursalId]);
        db()->commit();
        $_SESSION['m']='Pedido cancelado y reserva liberada.';
      }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        $_SESSION['m']=$error instanceof DomainException?$error->getMessage():'No se pudo cancelar el pedido.';
      }
  }elseif($accion=='finalizar'){
    if(empty($carrito)){
      $_SESSION['m']='El carrito está vacío.';
    }else{
      try{
        db()->beginTransaction();
        $pedidoActual=null;
        if($pedidoTabletId){
          $pedidoActual=q1('SELECT * FROM pedidos_tablet WHERE id=? AND sucursal_id=? AND (expira_en IS NULL OR expira_en>NOW()) FOR UPDATE',[$pedidoTabletId,$sucursalId]);
          if(!$pedidoActual||!in_array($pedidoActual['estado'],['pendiente','en_proceso'],true)
            ||($pedidoActual['estado']==='en_proceso'&&(int)$pedidoActual['cajero_id']!==(int)$u['id'])){
            throw new DomainException('El pedido de tablet venció, ya fue tomado por otra caja o ya fue procesado.');
          }
          if($pedidoActual['estado']==='pendiente')q('UPDATE pedidos_tablet SET estado="en_proceso",cajero_id=? WHERE id=? AND sucursal_id=? AND estado="pendiente"',[$u['id'],$pedidoTabletId,$sucursalId]);
        }

        $detalleVenta=[];
        $neto=$iva=$total=0;
        $cantidades=[];
        foreach($carrito as $item)$cantidades[(int)$item['id']]=($cantidades[(int)$item['id']]??0)+(int)$item['cantidad'];
        foreach($cantidades as $productoId=>$cantidad){
          $productoStock=q1('SELECT stock FROM stock_sucursales WHERE producto_id=? AND sucursal_id=? FOR UPDATE',[$productoId,$sucursalId]);
          if(!$productoStock||(int)$productoStock['stock']-reservas_activas_producto($productoId,$pedidoTabletId?:null)<$cantidad){
            throw new DomainException('Stock libre insuficiente para completar la venta.');
          }
        }
        foreach($carrito as $item){
          $producto=q1('SELECT p.id,p.nombre,p.iva,s.stock FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=? WHERE p.id=? FOR UPDATE',[$sucursalId,(int)$item['id']]);
          if(!$producto||(int)$producto['stock']<(int)$item['cantidad'])throw new DomainException('Stock insuficiente para '.($producto['nombre']??'un producto').'.');
          foreach(lotes_para_venta($producto['id'],(int)$item['cantidad'],true) as $lote){
            $precio=($item['precio_fijado']??null)!==null?(float)$item['precio_fijado']:(float)$lote['precio_venta'];
            $importe=$precio*(int)$lote['cantidad'];
            $netoLinea=$importe/(1+(float)$producto['iva']/100);
            $neto+=$netoLinea;
            $iva+=$importe-$netoLinea;
            $total+=$importe;
            $detalleVenta[]=['producto'=>$producto,'lote'=>$lote,'cantidad'=>(int)$lote['cantidad'],'precio'=>$precio];
            $detalleVenta[array_key_last($detalleVenta)]['promocion_id']=$item['promocion_id']??null;
            $detalleVenta[array_key_last($detalleVenta)]['promocion_nombre']=$item['promocion_nombre']??null;
          }
        }

        $cliente=trim((string)($_POST['cliente']??''));
        if(!$cliente)$cliente=$pedidoActual['cliente']??'Consumidor Final';
        $cliente=substr($cliente,0,150);
        $doc=substr(preg_replace('/\D/','',(string)($_POST['doc']??'')),0,13);
        $dniSocioIngresado=trim((string)($_POST['dni_socio']??''));
        $socioVenta=null;
        $descuentoPct=0;
        $descuentoMonto=0;
        if($dniSocioIngresado!==''){
          $dniSocio=normalizar_dni($dniSocioIngresado);
          if($dniSocio===null)throw new DomainException('El DNI del socio debe tener 7 u 8 dígitos.');
          $socioVenta=q1('SELECT id,nombre FROM socios WHERE dni=? AND activo=1 FOR UPDATE',[$dniSocio]);
          if(!$socioVenta)throw new DomainException('No encontramos un socio activo con ese DNI.');
          $descuentoPct=20;
          $total=round($total,2);
          $descuentoMonto=round($total*$descuentoPct/100,2);
          $total=round($total-$descuentoMonto,2);
          $neto=round($neto*(1-$descuentoPct/100),2);
          $iva=round($total-$neto,2);
          if($doc==='')$doc=$dniSocio;
        }
        $pagosPermitidos=['efectivo','tarjeta','transferencia','cheque'];
        $pago=$_POST['pago']??'efectivo';
        if(!in_array($pago,$pagosPermitidos,true))throw new DomainException('Forma de pago inválida.');

        q('INSERT INTO ventas(cajero_id,socio_id,cliente,doc,neto,iva,total,pago,descuento_pct,descuento_monto,sucursal_id,estado) VALUES(?,?,?,?,?,?,?,?,?,?,?,"pagada")',
          [$u['id'],$socioVenta['id']??null,$cliente,$doc,round($neto,2),round($iva,2),round($total,2),$pago,$descuentoPct,$descuentoMonto,$sucursalId]);
        $ventaId=db()->lastInsertId();
        foreach($detalleVenta as $linea){
          $lote=$linea['lote'];
          $descontado=q('UPDATE lotes_stock SET cantidad_restante=cantidad_restante-? WHERE id=? AND cantidad_restante>=?',
            [$linea['cantidad'],$lote['id'],$linea['cantidad']])->rowCount();
          if($descontado!==1)throw new DomainException('El stock cambió durante la venta. Actualiza la caja.');
          q('INSERT INTO venta_items(venta_id,producto_id,nombre,cantidad,precio,iva,lote_id,costo_unitario,promocion_id,promocion_nombre) VALUES(?,?,?,?,?,?,?,?,?,?)',[
            $ventaId,$linea['producto']['id'],$linea['producto']['nombre'],$linea['cantidad'],$linea['precio'],
            $linea['producto']['iva'],$lote['id'],$lote['costo_unitario'],$linea['promocion_id'],$linea['promocion_nombre']
          ]);
        }
        foreach($cantidades as $productoId=>$cantidad){
          $actualizado=q('UPDATE stock_sucursales SET stock=stock-? WHERE sucursal_id=? AND producto_id=? AND stock>=?',[$cantidad,$sucursalId,$productoId,$cantidad])->rowCount();
          if($actualizado!==1)throw new DomainException('El stock cambió durante la venta. Actualiza la caja.');
        }
        if($pedidoActual){
          q('UPDATE pedidos_tablet SET estado="completado",venta_id=? WHERE id=? AND sucursal_id=? AND estado="en_proceso" AND cajero_id=?',[$ventaId,$pedidoTabletId,$sucursalId,$u['id']]);
          q('DELETE FROM reservas_tablet WHERE pedido_id=? AND sucursal_id=?',[$pedidoTabletId,$sucursalId]);
        }
        auditar('venta_registrada','ventas',$ventaId,['total'=>$total,'descuento'=>$descuentoMonto,'socio_id'=>$socioVenta['id']??null,'pedido_tablet'=>$pedidoTabletId?:null]);
        db()->commit();
        $_SESSION['m']='Venta registrada | Comprobante #'.$ventaId;
        $carrito=[];
        unset($_SESSION['pedido_tablet_id']);
        header('Location: comprobante.php?id='.(int)$ventaId);
        exit;
      }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        registrar_error_aplicacion('Venta no completada: '.$error->getMessage());
        $_SESSION['m']=$error instanceof DomainException?$error->getMessage():'No se pudo confirmar la venta. El stock no fue modificado.';
      }
    }
    header('Location: caja.php');
    exit;
  }
  
  header('Location: caja.php');
  exit;
}

// Calcular totales
$neto=$iva=$total=0;
$carritoDetalle=[];
$carritoError='';
foreach($carrito as $idx=>$item){
  try{
    $producto=q1('SELECT id,nombre,iva FROM productos WHERE id=?',[(int)$item['id']]);
    if(!$producto)throw new DomainException('Un producto del carrito ya no existe.');
    $importe=0;
    foreach(lotes_para_venta($producto['id'],(int)$item['cantidad']) as $lote)$importe+=(float)$lote['precio_venta']*(int)$lote['cantidad'];
    $netoLinea=$importe/(1+(float)$producto['iva']/100);
    $neto+=$netoLinea;
    $iva+=$importe-$netoLinea;
    $total+=$importe;
    $carritoDetalle[$idx]=['producto'=>$producto,'importe'=>$importe];
  }catch(DomainException $error){
    $carritoError=$error->getMessage();
  }
}

$productos=q('SELECT p.*,s.stock,GREATEST(s.stock-COALESCE((SELECT SUM(r.cantidad) FROM reservas_tablet r WHERE r.producto_id=p.id AND r.sucursal_id=? AND (r.vence_en IS NULL OR r.vence_en>NOW())),0),0) stock_libre,COALESCE((SELECT l.precio_venta FROM lotes_stock l WHERE l.producto_id=p.id AND l.sucursal_id=? AND l.cantidad_restante>0 ORDER BY l.fecha,l.id LIMIT 1),p.precio) precio_actual FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=? WHERE s.stock>COALESCE((SELECT SUM(r.cantidad) FROM reservas_tablet r WHERE r.producto_id=p.id AND r.sucursal_id=? AND (r.vence_en IS NULL OR r.vence_en>NOW())),0) ORDER BY p.nombre',[$sucursalId,$sucursalId,$sucursalId,$sucursalId])->fetchAll();
$categorias=q('SELECT * FROM categorias ORDER BY nombre')->fetchAll();
$pedidosTablet=q('SELECT o.id,o.codigo,o.cliente,o.metodo_pago,o.pago_virtual_nombre,o.fecha_creacion,COUNT(i.id) items,COALESCE(SUM(i.precio_estimado*i.cantidad),0) total_estimado
  FROM pedidos_tablet o JOIN pedido_tablet_items i ON i.pedido_id=o.id
  WHERE o.sucursal_id=? AND o.estado="pendiente" AND (o.expira_en IS NULL OR o.expira_en>NOW()) GROUP BY o.id ORDER BY o.fecha_creacion LIMIT 30',[$sucursalId])->fetchAll();
$pedidoActivo=$pedidoTabletId?q1('SELECT codigo,cliente,metodo_pago FROM pedidos_tablet WHERE id=? AND sucursal_id=? AND estado IN("pendiente","en_proceso") AND (estado="pendiente" OR cajero_id=?) AND (expira_en IS NULL OR expira_en>NOW())',[$pedidoTabletId,$sucursalId,$u['id']]):null;
$directorioCaja=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/caja.php'));
if($directorioCaja==='/'||$directorioCaja==='.')$directorioCaja='';
$rutaTiendaTablet=rtrim($directorioCaja,'/').'/tienda.php?sucursal='.$sucursalId;

head('Caja'); ?>

<details class="card shadow-sm mb-3">
 <summary class="card-header fw-semibold" role="button"><i class="bi bi-tablet me-2"></i>¿Cómo conectar la tablet?</summary>
 <div class="card-body">
  <ol class="mb-2">
   <li>Conecta la tablet y la computadora de caja a la misma red Wi-Fi.</li>
   <li>En Windows, abre el Símbolo del sistema, escribe <code>ipconfig</code> y busca la dirección IPv4 de esa computadora.</li>
   <li>En el navegador de la tablet abre <code>http://IP-DE-LA-PC<?=e($rutaTiendaTablet)?></code>, reemplazando <code>IP-DE-LA-PC</code> por esa dirección. No uses <code>localhost</code> en la tablet.</li>
   <li>La tablet elige productos y envía el pedido; aparecerá aquí para que caja lo cargue y confirme el cobro.</li>
  </ol>
  <p class="small text-muted mb-0">Si no conecta, revisa que Apache esté iniciado y permite Apache en el Firewall de Windows para redes privadas. No abras el router a Internet.</p>
 </div>
</details>

<?php if($pedidosTablet): ?>
 <section class="card shadow-sm mb-3"><div class="card-body">
  <h2 class="h5"><i class="bi bi-tablet me-2"></i>Pedidos esperando en caja <span class="badge text-bg-warning"><?=count($pedidosTablet)?></span></h2>
  <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>ID</th><th>Cliente</th><th>Pago elegido</th><th>Artículos</th><th class="text-end">Estimado</th><th></th></tr></thead><tbody>
   <?php foreach($pedidosTablet as $pedido): ?><tr>
    <td class="font-monospace"><?=e($pedido['codigo'])?></td><td><?=e($pedido['cliente']?:'Sin nombre')?></td><td><?=e($pedido['metodo_pago']==='efectivo'?'Efectivo':($pedido['metodo_pago']==='mercadopago'?'Mercado Pago':($pedido['pago_virtual_nombre']?:'Pago virtual')))?></td><td><?=$pedido['items']?></td><td class="text-end"><?=money($pedido['total_estimado'])?></td>
    <td class="d-flex gap-1">
     <form method="post"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="cargar_pedido_tablet"><input type="hidden" name="pedido_id" value="<?=$pedido['id']?>"><button class="btn btn-sm btn-outline-success" <?=$carrito?'disabled':''?>>Cargar</button></form>
     <form method="post" onsubmit="return confirm('¿Cancelar este pedido y liberar el stock?')"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="cancelar_pedido_tablet"><input type="hidden" name="pedido_id" value="<?=$pedido['id']?>"><button class="btn btn-sm btn-outline-danger">Cancelar</button></form>
    </td>
   </tr><?php endforeach ?>
  </tbody></table></div>
  <small class="text-muted">Los pedidos permanecen reservados hasta que caja los confirme o cancele.</small>
 </div></section>
<?php endif ?>
<?php if($pedidoActivo): ?><div class="alert alert-info">Pedido de tablet <strong class="font-monospace"><?=e($pedidoActivo['codigo'])?></strong>. El importe final se calcula al confirmar el cobro.</div><?php endif ?>

<div class="row g-3">
 <div class="col-lg-8">
  <div class="card shadow-sm mb-3">
   <div class="card-header bg-primary text-white">
    <h5 class="mb-0">Búsqueda de productos</h5>
   </div>
   <div class="card-body">
    <div class="mb-3">
     <input type="text" id="buscar" class="form-control form-control-lg" placeholder="Busca un producto..." autofocus>
    </div>
    <div id="resultados" style="max-height:300px;overflow-y:auto;">
     <?php foreach($categorias as $cat): 
      $prods=array_filter($productos,fn($p)=>$p['categoria_id']==$cat['id']);
      if(!$prods) continue; ?>
      <div class="mb-3">
       <h6 class="text-muted">🏷️ <?=e($cat['nombre'])?></h6>
       <div class="btn-group-vertical w-100" role="group">
        <?php foreach($prods as $p): ?>
         <form method="post" class="d-inline-block" style="width:48%;margin:2px;">
          <input type="hidden" name="c" value="<?=csrf()?>">
          <input type="hidden" name="accion" value="agregar">
          <input type="hidden" name="producto_id" value="<?=$p['id']?>">
          <input type="hidden" name="cantidad" value="1">
          <button class="btn btn-outline-primary w-100 text-start small" style="font-size:.9em">
           <b><?=e(substr($p['nombre'],0,30))?></b><br>
           <small class="text-muted"><?=money($p['precio_actual'])?> (<?=$p['stock_libre']?> disponibles)</small>
          </button>
         </form>
        <?php endforeach ?>
       </div>
      </div>
     <?php endforeach ?>
    </div>
   </div>
  </div>
 </div>

 <div class="col-lg-4">
  <div class="card shadow-sm mb-3">
   <div class="card-header bg-success text-white">
    <h5 class="mb-0">Carrito</h5>
   </div>
   <div class="card-body" style="max-height:400px;overflow-y:auto;">
    <?php if($carritoError): ?><div class="alert alert-warning"><?=e($carritoError)?></div><?php endif ?>
    <?php if(empty($carrito)): ?>
     <div class="text-center text-muted py-5">Carrito vacío</div>
    <?php else: ?>
     <table class="table table-sm table-borderless">
      <thead><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Total</th><th></th></tr></thead>
      <tbody>
      <?php foreach($carrito as $idx=>$item): $detalle=$carritoDetalle[$idx]??null; ?>
       <tr>
        <td><small><?=e(substr($detalle['producto']['nombre']??$item['nombre'],0,20))?></small></td>
        <td class="text-end"><small><?=$item['cantidad']?></small></td>
        <td class="text-end"><small><?=$detalle?money($detalle['importe']):'—'?></small></td>
        <td>
         <form method="post" class="d-inline">
          <input type="hidden" name="c" value="<?=csrf()?>">
          <input type="hidden" name="accion" value="quitar">
          <input type="hidden" name="idx" value="<?=$idx?>">
          <button class="btn btn-sm btn-danger" type="submit">×</button>
         </form>
        </td>
       </tr>
      <?php endforeach ?>
      </tbody>
     </table>
    <?php endif ?>
   </div>
   <div class="card-footer">
    <div class="mb-2"><small class="d-flex justify-content-between"><span>Neto (antes de descuento):</span><strong><?=money($neto)?></strong></small></div>
    <div class="mb-2"><small class="d-flex justify-content-between"><span>IVA (antes de descuento):</span><strong><?=money($iva)?></strong></small></div>
    <div id="descuentoSocioCarrito" class="mb-2" hidden><small class="d-flex justify-content-between text-success"><span>Descuento socio (20%):</span><strong id="montoDescuentoCarrito"></strong></small></div>
    <div class="mb-3 border-top pt-2"><small class="d-flex justify-content-between"><span><b>Total:</b></span><strong id="totalCarritoCaja" data-subtotal="<?=e(number_format($total,2,'.',''))?>"><?=money($total)?></strong></small></div>
    
    <?php if(!empty($carrito)): ?>
    <button class="btn btn-warning w-100 mb-2" data-bs-toggle="modal" data-bs-target="#formalizarModal" <?=$carritoError?'disabled':''?>>Confirmar y cobrar</button>
     <form method="post" onsubmit="return confirm('¿Vaciar carrito?')">
      <input type="hidden" name="c" value="<?=csrf()?>">
      <input type="hidden" name="accion" value="vaciar">
      <button class="btn btn-outline-danger w-100" type="submit">Vaciar</button>
     </form>
    <?php endif ?>
   </div>
  </div>
 </div>
</div>

<!-- Modal para finalizar venta -->
<div class="modal fade" id="formalizarModal"><div class="modal-dialog"><form method="post" class="modal-content">
 <div class="modal-header"><h5>Finalizar venta</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <div class="modal-body">
  <input type="hidden" name="c" value="<?=csrf()?>">
  <input type="hidden" name="accion" value="finalizar">
  <div class="mb-3">
   <label class="form-label">Cliente (opcional)</label>
  <input type="text" name="cliente" class="form-control" placeholder="Consumidor final" value="<?=e($pedidoActivo['cliente']??'')?>">
  </div>
  <div class="mb-3">
   <label for="dniSocioCaja" class="form-label">DNI del socio (opcional)</label>
   <input id="dniSocioCaja" name="dni_socio" class="form-control" inputmode="numeric" autocomplete="off" maxlength="12">
   <div id="estadoSocioCaja" class="form-text" aria-live="polite">20% de descuento con socio activo.</div>
  </div>
  <div class="mb-3">
   <label class="form-label">Forma de pago</label>
   <select name="pago" class="form-select" required>
    <option value="efectivo" <?=($pedidoActivo['metodo_pago']??'')==='efectivo'?'selected':''?>>Efectivo</option>
    <option value="tarjeta" <?=in_array($pedidoActivo['metodo_pago']??'', ['mercadopago','virtual'],true)?'selected':''?>>Tarjeta / billetera virtual (verificar pago)</option>
    <option value="transferencia">Transferencia</option>
    <option value="cheque">Cheque</option>
   </select>
  </div>
  <div class="alert alert-info mb-0">
   <div id="resumenDescuentoModal" class="d-flex justify-content-between text-success mb-1" hidden><span>Descuento socio (20%)</span><strong id="montoDescuentoModal"></strong></div>
   <div class="d-flex justify-content-between"><b>Total a cobrar:</b><strong id="totalCobrarModal" data-subtotal="<?=e(number_format($total,2,'.',''))?>"><?=money($total)?></strong></div>
  </div>
 </div>
 <div class="modal-footer">
  <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
  <button id="confirmarCobro" type="submit" class="btn btn-success btn-lg">Cobrar</button>
 </div>
</form></div></div>

<script>
const campoDniSocio=document.getElementById('dniSocioCaja');
const estadoSocioCaja=document.getElementById('estadoSocioCaja');
const totalCarritoCaja=document.getElementById('totalCarritoCaja');
const totalCobrarModal=document.getElementById('totalCobrarModal');
const descuentoSocioCarrito=document.getElementById('descuentoSocioCarrito');
const resumenDescuentoModal=document.getElementById('resumenDescuentoModal');
const confirmarCobro=document.getElementById('confirmarCobro');
let temporizadorSocio;
let consultaSocio;
function formatoPesos(valor){
  return new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS'}).format(valor);
}
function limpiarDescuentoSocio(mensaje){
  descuentoSocioCarrito.hidden=true;
  resumenDescuentoModal.hidden=true;
  totalCarritoCaja.textContent=formatoPesos(Number(totalCarritoCaja.dataset.subtotal));
  totalCobrarModal.textContent=formatoPesos(Number(totalCobrarModal.dataset.subtotal));
  confirmarCobro.disabled=Boolean(campoDniSocio.value.trim());
  if(mensaje)estadoSocioCaja.textContent=mensaje;
}
campoDniSocio.addEventListener('input',()=>{
  clearTimeout(temporizadorSocio);
  if(consultaSocio)consultaSocio.abort();
  const dni=campoDniSocio.value.trim();
  if(!dni){limpiarDescuentoSocio('');return;}
  limpiarDescuentoSocio('Verificando socio...');
  temporizadorSocio=setTimeout(async()=>{
    consultaSocio=new AbortController();
    try{
      const parametros=new URLSearchParams({validar_socio:'1',dni});
      const respuesta=await fetch('caja.php?'+parametros.toString(),{cache:'no-store',headers:{Accept:'application/json'},signal:consultaSocio.signal});
      const datos=await respuesta.json();
      if(!respuesta.ok)throw new Error(datos.error||'No se pudo validar el DNI.');
      const subtotal=Number(totalCobrarModal.dataset.subtotal);
      const descuento=Math.round(subtotal*datos.descuento_pct)/100;
      const total=Math.round((subtotal-descuento)*100)/100;
      document.getElementById('montoDescuentoCarrito').textContent='−'+formatoPesos(descuento);
      document.getElementById('montoDescuentoModal').textContent='−'+formatoPesos(descuento);
      totalCarritoCaja.textContent=formatoPesos(total);
      totalCobrarModal.textContent=formatoPesos(total);
      descuentoSocioCarrito.hidden=false;
      resumenDescuentoModal.hidden=false;
      confirmarCobro.disabled=false;
      estadoSocioCaja.textContent=datos.nombre+' · Socio activo: 20% de descuento aplicado.';
    }catch(error){
      if(error.name!=='AbortError')limpiarDescuentoSocio(error.message);
    }
  },350);
});
document.getElementById('buscar').addEventListener('keyup',function(e){
  const q=this.value.toLowerCase();
  document.querySelectorAll('#resultados h6').forEach(h=>{
    const prods=h.nextElementSibling.querySelectorAll('button');
    let visible=false;
    prods.forEach(b=>{
      const m=b.textContent.toLowerCase().includes(q);
      b.style.display=m?'':'none';
      if(m)visible=true;
    });
    h.parentElement.style.display=visible?'':'none';
  });
});
</script>

<?php foot();
