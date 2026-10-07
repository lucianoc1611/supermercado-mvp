<?php
require 'lib.php';
$u=need('productos','ver');
if(!verificar_esquema_operativo([
  'stock_sucursales'=>['sucursal_id','producto_id','stock','entrante','minimo'],
  'lotes_stock'=>['sucursal_id','producto_id','cantidad_restante','precio_venta']
]))solicitar_migracion_operativa('Productos');
$sucursalId=sucursal_actual_id();

if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  $accion=$_POST['accion']??'';
  
  if($accion=='crear' && tiene_permiso('productos','crear')){
    $codigo=trim($_POST['codigo']??'');
    $nombre=trim($_POST['nombre']??'');
    $categoria_id=(int)($_POST['categoria_id']??0);
    $precio=(float)($_POST['precio']??0);
    $iva=(float)($_POST['iva']??21);
    $minimo=(int)($_POST['minimo']??0);
    $comision=(float)($_POST['comision_pct']??0);
    $costo=(float)($_POST['costo_referencia']??0);
    
    if(!$codigo || !$nombre || $precio<=0 || $iva<0 || $iva>100 || $minimo<0 || $comision<0 || $comision>500 || $costo<0){
      $_SESSION['m']='Datos inválidos.';
    }else{
      try{
        db()->beginTransaction();
        q('INSERT INTO productos(codigo,nombre,categoria_id,precio,iva,comision_pct,costo_referencia) VALUES(?,?,?,?,?,?,?)',
          [$codigo,$nombre,$categoria_id>0?$categoria_id:null,$precio,$iva,$comision,$costo]);
        $productoId=(int)db()->lastInsertId();
        q('INSERT INTO stock_sucursales(sucursal_id,producto_id,stock,entrante,minimo) SELECT id,?,0,0,? FROM sucursales WHERE activa=1',[$productoId,$minimo]);
        auditar('crear_producto','productos',$productoId,['nombre'=>$nombre]);
        db()->commit();
        $_SESSION['m']='Producto creado ✔';
      }catch(PDOException $x){
        if(db()->inTransaction())db()->rollBack();
        $_SESSION['m']='Ese código ya existe.';
      }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        throw $error;
      }
    }
  }
  
  elseif($accion=='editar' && tiene_permiso('productos','editar')){
    $id=(int)($_POST['id']??0);
    $producto=q1('SELECT * FROM productos WHERE id=?',[$id]);
    if(!$producto){ $_SESSION['m']='Producto no encontrado.'; }
    else{
      $campos=['nombre','categoria_id','precio','iva','minimo','comision_pct','costo_referencia'];
      $update_vals=[];
      foreach($campos as $c){
        if($c=='categoria_id'){
          $v=(int)($_POST[$c]??0);
          if($v===0)$v=null;
        }
        elseif(in_array($c,['iva','precio','comision_pct','costo_referencia'],true)) $v=(float)($_POST[$c]??$producto[$c]);
        else $v=trim($_POST[$c]??$producto[$c]);
        $update_vals[]=$v;
      }
      if($update_vals[2]<=0||$update_vals[3]<0||$update_vals[3]>100||$update_vals[4]<0||$update_vals[5]<0||$update_vals[5]>500||$update_vals[6]<0){
        $_SESSION['m']='Precio, impuesto, comisión o stock mínimo inválido.';
      }else{
        db()->beginTransaction();
        q('UPDATE productos SET nombre=?,categoria_id=?,precio=?,iva=?,comision_pct=?,costo_referencia=? WHERE id=?',
          [$update_vals[0],$update_vals[1],$update_vals[2],$update_vals[3],$update_vals[5],$update_vals[6],$id]);
        q('UPDATE stock_sucursales SET minimo=? WHERE sucursal_id=? AND producto_id=?',[$update_vals[4],$sucursalId,$id]);
        q('UPDATE lotes_stock SET precio_venta=? WHERE sucursal_id=? AND producto_id=? AND cantidad_restante>0',[$update_vals[2],$sucursalId,$id]);
        auditar('editar_producto','productos',$id,['nombre'=>$update_vals[0],'precio'=>$update_vals[2],'comision_pct'=>$update_vals[5]]);
        db()->commit();
        $_SESSION['m']='Producto actualizado; el precio nuevo se aplicó al stock restante.';
      }
    }
  }
  
  header('Location: productos.php');
  exit;
}

$editar=(isset($_GET['editar'])?(int)$_GET['editar']:null);
$editando=$editar?q1('SELECT p.*,s.minimo,s.stock,s.entrante FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=? WHERE p.id=?',[$sucursalId,$editar]):null;

$productos=q('
  SELECT p.*,s.stock,s.minimo,s.entrante,c.nombre categoria_nombre,
    COALESCE((SELECT l.precio_venta FROM lotes_stock l WHERE l.producto_id=p.id AND l.sucursal_id=? AND l.cantidad_restante>0 ORDER BY l.fecha,l.id LIMIT 1),p.precio) precio_venta
  FROM productos p
  JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=?
  LEFT JOIN categorias c ON p.categoria_id=c.id
  ORDER BY p.nombre
',[$sucursalId,$sucursalId])->fetchAll();

$categorias=q('SELECT * FROM categorias ORDER BY nombre')->fetchAll();

head('Productos'); ?>

<div class="row g-3">
 <div class="col-lg-8">
  <div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
   <thead class="table-light"><tr>
     <th>Código</th><th>Producto</th><th>Categoría</th>
     <th class="text-end">Precio</th><th class="text-center">Stock</th><th class="text-center">Entrante</th><th></th>
   </tr></thead>
   <tbody>
   <?php foreach($productos as $p): ?>
    <tr class="<?=$p['stock']<=$p['minimo']?'table-warning':''?>">
     <td><code><?=e($p['codigo'])?></code></td>
     <td><?=e($p['nombre'])?></td>
     <td><small class="text-muted"><?=e($p['categoria_nombre']??'-')?></small></td>
    <td class="text-end"><?=money($p['precio_venta'])?></td>
     <td class="text-center">
      <span class="badge bg-<?=$p['stock']<=$p['minimo']?'danger':'success'?>">
       <?=$p['stock']?>
      </span>
      <?php if($p['minimo']>0): ?>
       <small class="text-muted">(min: <?=$p['minimo']?>)</small>
      <?php endif ?>
     </td>
     <td class="text-center"><?=$p['entrante']?'<span class="badge bg-info">'.$p['entrante'].'</span>':'<small class="text-muted">—</small>'?></td>
     <td class="text-end">
      <?php if(tiene_permiso('productos','editar')): ?>
       <a href="?editar=<?=$p['id']?>" class="btn btn-sm btn-outline-primary">Editar</a>
      <a href="reponer.php" class="btn btn-sm btn-outline-warning">Ingresar lote</a>
      <?php else: ?>
       <small class="text-muted">Ver</small>
      <?php endif ?>
     </td>
    </tr>
   <?php endforeach ?>
   </tbody>
  </table></div></div>
 </div>

 <div class="col-lg-4">
  <?php if($editando && tiene_permiso('productos','editar')): ?>
   <div class="card shadow-sm border-primary"><div class="card-body">
    <h5>Editar producto</h5>
    <form method="post">
     <input type="hidden" name="c" value="<?=csrf()?>">
     <input type="hidden" name="accion" value="editar">
     <input type="hidden" name="id" value="<?=$editando['id']?>">
     <input type="text" name="nombre" value="<?=e($editando['nombre'])?>" class="form-control mb-2" required>
     <select name="categoria_id" class="form-select mb-2">
      <option value="0">Sin categoría</option>
      <?php foreach($categorias as $c): ?>
       <option value="<?=$c['id']?>" <?=$c['id']==$editando['categoria_id']?'selected':''?>>
        <?=e($c['nombre'])?>
       </option>
      <?php endforeach ?>
     </select>
     <input type="number" name="precio" step="0.01" value="<?=$editando['precio']?>" class="form-control mb-2" required>
     <input type="number" name="iva" step="0.01" value="<?=$editando['iva']?>" class="form-control mb-2" placeholder="IVA %">
    <input type="number" name="comision_pct" min="0" max="500" step="0.01" value="<?=$editando['comision_pct']?>" class="form-control mb-2" placeholder="Comisión % para nuevos lotes">
    <input type="number" name="costo_referencia" min="0" step="0.01" value="<?=$editando['costo_referencia']?>" class="form-control mb-2" placeholder="Costo de referencia para reposición">
     <input type="number" name="minimo" value="<?=$editando['minimo']?>" class="form-control mb-3" placeholder="Stock mínimo">
     <button class="btn btn-primary w-100 mb-2">Guardar cambios</button>
     <a href="productos.php" class="btn btn-secondary w-100">Cancelar</a>
    </form>
   </div></div>
  <?php elseif(tiene_permiso('productos','crear')): ?>
   <div class="card shadow-sm"><div class="card-body">
    <h5>Nuevo producto</h5>
    <form method="post">
     <input type="hidden" name="c" value="<?=csrf()?>">
     <input type="hidden" name="accion" value="crear">
     <input type="text" name="codigo" class="form-control mb-2" placeholder="Código" required>
     <input type="text" name="nombre" class="form-control mb-2" placeholder="Nombre" required>
     <select name="categoria_id" class="form-select mb-2">
      <option value="0">Selecciona categoría</option>
      <?php foreach($categorias as $c): ?>
       <option value="<?=$c['id']?>"><?=e($c['nombre'])?></option>
      <?php endforeach ?>
     </select>
     <input type="number" name="precio" step="0.01" class="form-control mb-2" placeholder="Precio" required>
     <input type="number" name="iva" step="0.01" value="21" class="form-control mb-2" placeholder="IVA %">
    <input type="number" name="comision_pct" min="0" max="500" step="0.01" value="0" class="form-control mb-2" placeholder="Comisión % para nuevos lotes">
    <input type="number" name="costo_referencia" min="0" step="0.01" value="0" class="form-control mb-3" placeholder="Costo de referencia para reposición">
    <input type="number" name="minimo" min="0" class="form-control mb-3" placeholder="Stock mínimo">
     <button class="btn btn-success w-100">Crear</button>
    </form>
   </div></div>
  <?php endif ?>

  <div class="card shadow-sm mt-3"><div class="card-body">
   <h5>Estadísticas</h5>
   <small class="d-block mb-2">
    <b>Total productos:</b> <?=count($productos)?>
   </small>
   <small class="d-block mb-2">
    <b>Stock bajo:</b> <?=count(array_filter($productos,fn($p)=>$p['stock']<=$p['minimo']))?>
   </small>
   <small class="d-block">
    <b>Por recibir:</b> <?=array_sum(array_column($productos,'entrante'))?>
   </small>
  </div></div>
 </div>
</div>

<?php foot();
