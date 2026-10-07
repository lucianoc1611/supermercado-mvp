<?php
require 'lib.php';
$u=need('reponer','ver');
$sucursalId=sucursal_actual_id();
$puedeFijarPrecio=in_array($u['rol'],['jefe','supervisor'],true);

if($_SERVER['REQUEST_METHOD']==='POST'){
  chk();
  $productoId=(int)($_POST['producto_id']??0);
  $cantidad=filter_var($_POST['cantidad']??null,FILTER_VALIDATE_INT);
  $mensaje='No se pudo registrar el ingreso. Revisa producto, cantidad y costo.';

  if($productoId>0&&$cantidad!==false&&$cantidad>0&&$cantidad<=100000){
    try{
      db()->beginTransaction();
      $producto=q1('SELECT p.*,s.stock,s.entrante,s.minimo FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id=? WHERE p.id=? FOR UPDATE',[$sucursalId,$productoId]);
      if(!$producto)throw new DomainException('Producto inválido.');

      $costo=(float)$producto['costo_referencia'];
      $comision=(float)$producto['comision_pct'];
      $precioManual=null;
      if($puedeFijarPrecio){
        if(isset($_POST['costo_unitario'])&&$_POST['costo_unitario']!=='')$costo=(float)str_replace(',','.',(string)$_POST['costo_unitario']);
        if(isset($_POST['comision_pct'])&&$_POST['comision_pct']!=='')$comision=(float)str_replace(',','.',(string)$_POST['comision_pct']);
        if(isset($_POST['precio_venta'])&&$_POST['precio_venta']!=='')$precioManual=(float)str_replace(',','.',(string)$_POST['precio_venta']);
      }
      if(!is_finite($costo)||$costo<=0||$costo>1000000000||!is_finite($comision)||$comision<0||$comision>500
        ||($precioManual!==null&&(!is_finite($precioManual)||$precioManual<=0||$precioManual>1000000000))){
        throw new DomainException('Costo, comisión o precio fuera de rango.');
      }

      $precio=$precioManual??round($costo*(1+$comision/100),2);
      q('INSERT INTO lotes_stock(producto_id,cantidad_inicial,cantidad_restante,costo_unitario,comision_pct,precio_venta,usuario_id,sucursal_id)
        VALUES(?,?,?,?,?,?,?,?)',[$productoId,$cantidad,$cantidad,$costo,$comision,$precio,$u['id'],$sucursalId]);
      $loteId=db()->lastInsertId();
      q('UPDATE stock_sucursales SET stock=stock+?,entrante=GREATEST(entrante-?,0) WHERE sucursal_id=? AND producto_id=?',
        [$cantidad,$cantidad,$sucursalId,$productoId]);
      q('UPDATE productos SET precio=?,costo_referencia=?,comision_pct=? WHERE id=?',[$precio,$costo,$comision,$productoId]);
      auditar('ingreso_lote','lotes_stock',$loteId,[
        'producto'=>$producto['nombre'],'cantidad'=>$cantidad,'costo'=>$costo,'comision_pct'=>$comision,'precio_venta'=>$precio
      ]);
      db()->commit();
      $_SESSION['m']='Ingreso registrado. Lote #'.$loteId.'; precio de venta '.money($precio).'.';
    }catch(Throwable $error){
      if(db()->inTransaction())db()->rollBack();
      registrar_error_aplicacion('Ingreso de lote fallido: '.$error->getMessage());
      $_SESSION['m']=$error instanceof DomainException?$error->getMessage():$mensaje;
    }
  }else{
    $_SESSION['m']=$mensaje;
  }
  header('Location: reponer.php');
  exit;
}

$consultaProductos='SELECT p.id,p.codigo,p.nombre,s.stock,s.minimo,s.entrante,p.precio,p.costo_referencia,p.comision_pct FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id AND s.sucursal_id='.$sucursalId;
if($u['rol']==='repositor')$consultaProductos.=' WHERE stock<5';
$consultaProductos.=' ORDER BY s.stock,p.nombre';
$productos=q($consultaProductos)->fetchAll();
$lotes=q('SELECT l.*,p.nombre producto,u.nombre usuario FROM lotes_stock l JOIN productos p ON p.id=l.producto_id LEFT JOIN usuarios u ON u.id=l.usuario_id WHERE l.sucursal_id=? ORDER BY l.id DESC LIMIT 20',[$sucursalId])->fetchAll();
head('Reposición'); ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
 <div><h1 class="h3 mb-1">Reposición</h1></div>
</div>

<div class="row g-3">
 <section class="col-lg-5" aria-labelledby="ingreso-titulo">
  <div class="card shadow-sm"><div class="card-body p-4">
  <h2 id="ingreso-titulo" class="h5 mb-3">Ingresar unidades</h2>
  <?php if(!$productos): ?><div class="alert alert-info"><?=$u['rol']==='repositor'?'No hay productos con menos de 5 unidades para reponer.':'No hay productos cargados.'?></div><?php endif ?>
   <form method="post">
    <input type="hidden" name="c" value="<?=csrf()?>">
    <div class="mb-3">
     <label class="form-label" for="producto">Producto</label>
    <select id="producto" name="producto_id" class="form-select form-select-lg" required>
      <option value="">Seleccionar producto</option>
      <?php foreach($productos as $producto): ?>
        <option value="<?=$producto['id']?>"<?php if($puedeFijarPrecio): ?> data-costo="<?=$producto['costo_referencia']?>" data-comision="<?=$producto['comision_pct']?>"<?php endif ?>>
        <?=e($producto['nombre'])?> · <?=$producto['stock']?>
       </option>
      <?php endforeach ?>
     </select>
    </div>
    <div class="mb-3">
     <label class="form-label" for="cantidad">Unidades recibidas</label>
     <input id="cantidad" name="cantidad" type="number" min="1" max="100000" step="1" class="form-control form-control-lg" required>
    </div>
    <?php if($puedeFijarPrecio): ?>
     <div class="border-top pt-3">
      <div class="mb-3"><label class="form-label" for="costo">Costo unitario de compra</label><input id="costo" name="costo_unitario" type="number" min="0.01" step="0.01" class="form-control" placeholder="Usar costo de referencia"></div>
      <div class="mb-3"><label class="form-label" for="comision">Comisión / recargo (%)</label><input id="comision" name="comision_pct" type="number" min="0" max="500" step="0.01" class="form-control" placeholder="Usar comisión del producto"></div>
      <div class="mb-3"><label class="form-label" for="precio">Precio de venta manual (opcional)</label><input id="precio" name="precio_venta" type="number" min="0.01" step="0.01" class="form-control" placeholder="Costo más comisión"></div>
      <output id="estimado" class="d-block fw-semibold text-success mb-3" aria-live="polite"></output>
     </div>
    <?php else: ?>
    <?php endif ?>
    <button class="btn btn-success btn-lg w-100" <?=$productos?'':'disabled'?>>Registrar ingreso</button>
   </form>
  </div></div>
 </section>

 <section class="col-lg-7" aria-labelledby="inventario-titulo">
  <div class="card shadow-sm"><div class="card-body">
  <h2 id="inventario-titulo" class="h5"><?=$u['rol']==='repositor'?'Por reponer':'Inventario'?></h2>
  <?php if($u['rol']==='repositor'&&!$productos): ?><p class="text-muted mb-0">No hay productos por debajo de 5 unidades.</p><?php else: ?>
   <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Producto</th><th class="text-end">Stock</th><th class="text-end">Mínimo</th><th class="text-end">Por recibir</th></tr></thead>
    <tbody><?php foreach($productos as $producto): ?>
     <tr><td><?=e($producto['nombre'])?></td><td class="text-end"><?=$producto['stock']?></td><td class="text-end"><?=$producto['minimo']?></td><td class="text-end"><?=$producto['entrante']?></td></tr>
    <?php endforeach ?></tbody>
   </table></div>
   <?php endif ?>
  </div></div>

  <?php if($u['rol']!=='repositor'): ?><div class="card shadow-sm mt-3"><div class="card-body">
   <h2 class="h5">Últimos ingresos</h2>
   <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Fecha</th><th>Producto</th><th>Cantidad</th><?php if($puedeFijarPrecio): ?><th class="text-end">Costo</th><th class="text-end">Comisión</th><?php endif ?><th class="text-end">Venta</th></tr></thead>
    <tbody><?php foreach($lotes as $lote): ?>
     <tr><td><?=date('d/m H:i',strtotime($lote['fecha']))?></td><td><?=e($lote['producto'])?></td><td><?=$lote['cantidad_inicial']?></td><?php if($puedeFijarPrecio): ?><td class="text-end"><?=money($lote['costo_unitario'])?></td><td class="text-end"><?=number_format((float)$lote['comision_pct'],2,',','.')?>%</td><?php endif ?><td class="text-end"><?=money($lote['precio_venta'])?></td></tr>
    <?php endforeach ?></tbody>
   </table></div>
  </div></div><?php endif ?>
 </section>
</div>

<?php if($puedeFijarPrecio): ?><script>
const selector=document.getElementById('producto');
const costo=document.getElementById('costo');
const comision=document.getElementById('comision');
const precio=document.getElementById('precio');
const estimado=document.getElementById('estimado');
function actualizarEstimado(){
  const opcion=selector.selectedOptions[0];
  const costoBase=Number(costo.value||opcion?.dataset.costo||0);
  const porcentaje=Number(comision.value||opcion?.dataset.comision||0);
  const venta=Number(precio.value||0)||costoBase*(1+porcentaje/100);
  estimado.textContent=costoBase>0?'Precio estimado por unidad: '+new Intl.NumberFormat('es-AR',{style:'currency',currency:'ARS'}).format(venta):'';
}
selector.addEventListener('change',actualizarEstimado);
costo.addEventListener('input',actualizarEstimado);
comision.addEventListener('input',actualizarEstimado);
precio.addEventListener('input',actualizarEstimado);
</script><?php endif ?>
<?php foot();