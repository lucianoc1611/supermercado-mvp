<?php
require 'lib.php';
$u=need('pedidos','ver');
$sucursalId=sucursal_actual_id();

if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  $accion=$_POST['accion']??'';
  
  if($accion=='crear' && tiene_permiso('pedidos','crear')){
    $proveedor=trim($_POST['proveedor']??'');
    if(!$proveedor){
      $_SESSION['m']='Especifica un proveedor.';
    }else{
      q('INSERT INTO pedidos_compra(encargado_id,proveedor,sucursal_id) VALUES(?,?,?)',[$u['id'],$proveedor,$sucursalId]);
      $_SESSION['m']='Pedido creado ✔';
    }
  }
  
  elseif($accion=='agregar_item' && tiene_permiso('pedidos','editar')){
    $pedido_id=(int)($_POST['pedido_id']??0);
    $producto_id=(int)($_POST['producto_id']??0);
    $cantidad=(int)($_POST['cantidad']??0);
    $precio=(float)($_POST['precio']??0);
    
    $pedido=q1('SELECT * FROM pedidos_compra WHERE id=? AND sucursal_id=? AND estado="pendiente"',[$pedido_id,$sucursalId]);
    $producto=q1('SELECT * FROM productos WHERE id=?',[$producto_id]);
    
    if(!$pedido||!$producto||$cantidad<=0||$cantidad>100000||$precio<=0||$precio>1000000000){
      $_SESSION['m']='Datos inválidos.';
    }else{
      q('INSERT INTO pedido_items(pedido_id,producto_id,cantidad,precio) VALUES(?,?,?,?)',
        [$pedido_id,$producto_id,$cantidad,$precio]);
      auditar('agregar_item_pedido','pedidos_compra',$pedido_id,[]);
      $_SESSION['m']='Item agregado ✔';
    }
  }
  
  elseif($accion=='quitar_item' && tiene_permiso('pedidos','editar')){
    $item_id=(int)($_POST['item_id']??0);
    $item=q1('SELECT pi.* FROM pedido_items pi JOIN pedidos_compra p ON p.id=pi.pedido_id WHERE pi.id=? AND p.sucursal_id=?',[$item_id,$sucursalId]);
    if($item){
      q('DELETE FROM pedido_items WHERE id=?',[$item_id]);
      $_SESSION['m']='Item eliminado ✔';
    }
  }
  
  elseif($accion=='recibir' && tiene_permiso('pedidos','editar')){
    $pedido_id=(int)($_POST['pedido_id']??0);
    try{
      db()->beginTransaction();
      $pedido=q1('SELECT * FROM pedidos_compra WHERE id=? AND sucursal_id=? FOR UPDATE',[$pedido_id,$sucursalId]);
      $items=q('SELECT * FROM pedido_items WHERE pedido_id=? ORDER BY id',[$pedido_id])->fetchAll();
      if(!$pedido||$pedido['estado']!=='pendiente'||!$items)throw new DomainException('El pedido está vacío o ya fue procesado.');
      foreach($items as $item){
        $producto=q1('SELECT id,nombre,comision_pct FROM productos WHERE id=? FOR UPDATE',[$item['producto_id']]);
        if(!$producto||$item['cantidad']<1||$item['precio']<=0)throw new DomainException('El pedido contiene un item inválido.');
        $comision=(float)$producto['comision_pct'];
        $precioVenta=round((float)$item['precio']*(1+$comision/100),2);
        q('INSERT INTO lotes_stock(producto_id,cantidad_inicial,cantidad_restante,costo_unitario,comision_pct,precio_venta,usuario_id,sucursal_id)
          VALUES(?,?,?,?,?,?,?,?)',[$producto['id'],$item['cantidad'],$item['cantidad'],$item['precio'],$comision,$precioVenta,$u['id'],$sucursalId]);
        $loteId=db()->lastInsertId();
        q('UPDATE stock_sucursales SET stock=stock+?,entrante=GREATEST(entrante-?,0) WHERE sucursal_id=? AND producto_id=?',
          [$item['cantidad'],$item['cantidad'],$sucursalId,$producto['id']]);
        q('UPDATE productos SET precio=?,costo_referencia=? WHERE id=?',[$precioVenta,$item['precio'],$producto['id']]);
        auditar('ingreso_lote_pedido','lotes_stock',$loteId,['pedido_id'=>$pedido_id,'producto'=>$producto['nombre'],'cantidad'=>$item['cantidad'],'costo'=>$item['precio'],'comision_pct'=>$comision,'precio_venta'=>$precioVenta]);
      }
      q('UPDATE pedidos_compra SET estado="recibido",fecha_recibido=NOW() WHERE id=? AND estado="pendiente"',[$pedido_id]);
      auditar('recibir_pedido','pedidos_compra',$pedido_id,[]);
      db()->commit();
      $_SESSION['m']='Pedido recibido; stock y lotes actualizados.';
    }catch(Throwable $error){
      if(db()->inTransaction())db()->rollBack();
      registrar_error_aplicacion('Recepción de compra fallida: '.$error->getMessage());
      $_SESSION['m']=$error instanceof DomainException?$error->getMessage():'No se pudo recibir el pedido; el stock no fue modificado.';
    }
  }
  
  elseif($accion=='cancelar' && tiene_permiso('pedidos','eliminar')){
    $pedido_id=(int)($_POST['pedido_id']??0);
    $pedido=q1('SELECT * FROM pedidos_compra WHERE id=? AND sucursal_id=?',[$pedido_id,$sucursalId]);
    if($pedido && $pedido['estado']=='pendiente'){
      q('UPDATE pedidos_compra SET estado="cancelado" WHERE id=?',[$pedido_id]);
      auditar('cancelar_pedido','pedidos_compra',$pedido_id,[]);
      $_SESSION['m']='Pedido cancelado ✔';
    }
  }
  
  header('Location: pedidos.php');
  exit;
}

$ver_id=(isset($_GET['ver'])?(int)$_GET['ver']:null);
$pedido_ver=$ver_id?q1('SELECT * FROM pedidos_compra WHERE id=? AND sucursal_id=?',[$ver_id,$sucursalId]):null;

$pedidos=q('
  SELECT p.*,COUNT(pi.id) items,u.nombre encargado
  FROM pedidos_compra p
  LEFT JOIN pedido_items pi ON p.id=pi.pedido_id
  LEFT JOIN usuarios u ON p.encargado_id=u.id
  WHERE p.sucursal_id=?
  GROUP BY p.id
  ORDER BY p.fecha_creacion DESC
',[$sucursalId])->fetchAll();

$productos=q('SELECT * FROM productos ORDER BY nombre')->fetchAll();

head('Pedidos'); ?>

<div class="row g-3">
 <div class="col-lg-8">
  <div class="card shadow-sm"><div class="card-header bg-primary text-white">
   <h5 class="mb-0">Mis pedidos</h5>
  </div><div class="list-group list-group-flush">
  <?php foreach($pedidos as $p): ?>
   <a href="?ver=<?=$p['id']?>" class="list-group-item list-group-item-action <?=$p['estado']=='pendiente'?'list-group-item-warning':''?>">
    <div class="d-flex w-100 justify-content-between">
     <h6 class="mb-1">Pedido #<?=$p['id']?> - <?=e($p['proveedor'])?></h6>
     <span class="badge bg-<?=$p['estado']=='pendiente'?'warning':($p['estado']=='recibido'?'success':'danger')?>">
      <?=ucfirst($p['estado'])?>
     </span>
    </div>
    <small class="text-muted">
     <?=$p['items']?> items | <?=e($p['encargado']??'-')?> | 
     <?=date('d/m H:i',strtotime($p['fecha_creacion']))?>
    </small>
   </a>
  <?php endforeach ?>
  </div></div>
 </div>

 <div class="col-lg-4">
  <?php if($pedido_ver): ?>
   <div class="card shadow-sm border-primary"><div class="card-body">
    <h5>Pedido #<?=$pedido_ver['id']?></h5>
    <p class="text-muted mb-2"><b><?=e($pedido_ver['proveedor'])?></b></p>
    <p class="text-muted"><small><?=date('d/m/Y H:i',strtotime($pedido_ver['fecha_creacion']))?></small></p>
    
    <?php $items=q('SELECT pi.*,p.nombre FROM pedido_items pi LEFT JOIN productos p ON pi.producto_id=p.id WHERE pi.pedido_id=?',[$pedido_ver['id']])->fetchAll(); ?>
    
    <h6 class="mt-3">Items (<?=count($items)?>)</h6>
    <div class="table-responsive"><table class="table table-sm mb-3">
     <thead><tr><th>Producto</th><th class="text-end">Cant.</th><th class="text-end">Precio</th><th class="text-end">Total</th><th></th></tr></thead>
     <tbody>
     <?php $total=0; foreach($items as $i): $subtotal=$i['cantidad']*$i['precio']; $total+=$subtotal; ?>
      <tr>
       <td><small><?=e($i['nombre']??'Producto eliminado')?></small></td>
       <td class="text-end"><small><?=$i['cantidad']?></small></td>
       <td class="text-end"><small><?=money($i['precio'])?></small></td>
       <td class="text-end"><small><?=money($subtotal)?></small></td>
       <td>
        <?php if($pedido_ver['estado']=='pendiente' && tiene_permiso('pedidos','editar')): ?>
         <form method="post" class="d-inline">
          <input type="hidden" name="c" value="<?=csrf()?>">
          <input type="hidden" name="accion" value="quitar_item">
          <input type="hidden" name="item_id" value="<?=$i['id']?>">
          <button class="btn btn-sm btn-danger">×</button>
         </form>
        <?php endif ?>
       </td>
      </tr>
     <?php endforeach ?>
     </tbody>
    </table></div>
    <div class="border-top pt-2 mb-3">
     <small class="d-flex justify-content-between"><b>Total:</b><b><?=money($total)?></b></small>
    </div>
    
    <?php if($pedido_ver['estado']=='pendiente'): ?>
     <?php if(tiene_permiso('pedidos','editar')): ?>
      <button class="btn btn-sm btn-success w-100 mb-2" data-bs-toggle="modal" data-bs-target="#agregarItemModal">Agregar item</button>
     <?php endif ?>
     <?php if(tiene_permiso('pedidos','editar')): ?>
      <form method="post" class="d-inline w-100">
       <input type="hidden" name="c" value="<?=csrf()?>">
       <input type="hidden" name="accion" value="recibir">
       <input type="hidden" name="pedido_id" value="<?=$pedido_ver['id']?>">
       <button class="btn btn-primary w-100 mb-2">Marcar como recibido</button>
      </form>
     <?php endif ?>
     <?php if(tiene_permiso('pedidos','eliminar')): ?>
      <form method="post" onsubmit="return confirm('¿Cancelar pedido?')" class="d-inline w-100">
       <input type="hidden" name="c" value="<?=csrf()?>">
       <input type="hidden" name="accion" value="cancelar">
       <input type="hidden" name="pedido_id" value="<?=$pedido_ver['id']?>">
       <button class="btn btn-outline-danger w-100">Cancelar</button>
      </form>
     <?php endif ?>
    <?php endif ?>
    
    <a href="pedidos.php" class="btn btn-secondary btn-sm w-100 mt-2">Volver</a>
   </div></div>
  <?php elseif(tiene_permiso('pedidos','crear')): ?>
   <div class="card shadow-sm"><div class="card-body">
    <h5>Nuevo pedido</h5>
    <form method="post">
     <input type="hidden" name="c" value="<?=csrf()?>">
     <input type="hidden" name="accion" value="crear">
     <input type="text" name="proveedor" class="form-control" placeholder="Proveedor" required autofocus>
     <button class="btn btn-success w-100 mt-3">Crear</button>
    </form>
   </div></div>
  <?php endif ?>
 </div>
</div>

<!-- Modal agregar item -->
<?php if($pedido_ver): ?>
<div class="modal fade" id="agregarItemModal"><div class="modal-dialog"><form method="post" class="modal-content">
 <div class="modal-header"><h5>Agregar item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <div class="modal-body">
  <input type="hidden" name="c" value="<?=csrf()?>">
  <input type="hidden" name="accion" value="agregar_item">
  <input type="hidden" name="pedido_id" value="<?=$pedido_ver['id']?>">
  <select name="producto_id" class="form-select mb-3" required>
   <option value="">Selecciona producto</option>
   <?php foreach($productos as $p): ?>
    <option value="<?=$p['id']?>"><?=e($p['nombre'])?> (<?=money($p['precio'])?>)</option>
   <?php endforeach ?>
  </select>
  <input type="number" name="cantidad" class="form-control mb-2" placeholder="Cantidad" required min="1">
  <input type="number" name="precio" step="0.01" class="form-control mb-2" placeholder="Costo unitario de compra" required min="0.01">
 </div>
 <div class="modal-footer">
  <button class="btn btn-success">Agregar</button>
 </div>
</form></div></div>
<?php endif ?>

<?php foot();
