<?php
require 'lib.php';
$u=need('ventas','ver');
$sucursalId=sucursal_actual_id();

$fechaValida=static function($valor){
  if(!is_string($valor)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$valor))return false;
  $fecha=DateTimeImmutable::createFromFormat('!Y-m-d',$valor);
  return $fecha&&$fecha->format('Y-m-d')===$valor;
};
$desde=$fechaValida($_GET['desde']??null)?$_GET['desde']:date('Y-m-01');
$hasta=$fechaValida($_GET['hasta']??null)?$_GET['hasta']:date('Y-m-d');
if($desde>$hasta){$temporal=$desde;$desde=$hasta;$hasta=$temporal;}
$cajero_id=filter_var($_GET['cajero']??null,FILTER_VALIDATE_INT);
if($cajero_id===false||$cajero_id<=0)$cajero_id=null;

$condicion='v.sucursal_id=? AND DATE(v.fecha) BETWEEN ? AND ?';
$parametros=[$sucursalId,$desde,$hasta];

if($cajero_id && in_array($u['rol'],['propietario','jefe'],true)){
  $condicion.=' AND v.cajero_id=?';
  $parametros[]=$cajero_id;
}elseif($u['rol']=='cajero'){
  $condicion.=' AND v.cajero_id=?';
  $parametros[]=$u['id'];
}

$sql='SELECT v.*,u.nombre cajero,pt.codigo pedido_tablet_codigo,s.nombre socio_nombre FROM ventas v LEFT JOIN usuarios u ON v.cajero_id=u.id LEFT JOIN pedidos_tablet pt ON pt.venta_id=v.id LEFT JOIN socios s ON s.id=v.socio_id WHERE '.$condicion.' ORDER BY v.fecha DESC';
$ventas=q($sql,$parametros)->fetchAll();

$totales=q1('SELECT COUNT(*) n,COALESCE(SUM(v.neto),0) neto,COALESCE(SUM(v.iva),0) iva,COALESCE(SUM(v.total),0) total FROM ventas v WHERE '.$condicion,$parametros);

$cajeros=q('SELECT id,nombre FROM usuarios WHERE rol="cajero" AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=usuarios.id AND us.sucursal_id=?) ORDER BY nombre',[$sucursalId])->fetchAll();

head('Ventas'); ?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
 <div><h1 class="h3 mb-1">Ventas y comprobantes</h1></div>
 <?php if(in_array($u['rol'],['propietario','jefe','cajero'],true)): ?><a href="caja.php" class="btn btn-success"><i class="bi bi-bag-check me-1"></i>Ir a caja y registrar venta</a><?php endif ?>
</div>

<details class="card shadow-sm mb-3" open>
 <summary class="card-header fw-semibold" role="button"><i class="bi bi-funnel me-2"></i>Filtros de ventas</summary>
 <div class="card-body">
 <form method="get" class="row g-2">
  <div class="col-md-3">
   <label class="form-label">Desde</label>
   <input type="date" name="desde" value="<?=e($desde)?>" class="form-control">
  </div>
  <div class="col-md-3">
   <label class="form-label">Hasta</label>
   <input type="date" name="hasta" value="<?=e($hasta)?>" class="form-control">
  </div>
  <?php if(in_array($u['rol'],['propietario','jefe'],true)): ?>
   <div class="col-md-4">
    <label class="form-label">Cajero</label>
    <select name="cajero" class="form-select">
     <option value="">Todos</option>
     <?php foreach($cajeros as $c): ?>
      <option value="<?=$c['id']?>" <?=$c['id']==$cajero_id?'selected':''?>>
       <?=e($c['nombre'])?>
      </option>
     <?php endforeach ?>
    </select>
   </div>
  <?php endif ?>
  <div class="col-md-2 d-flex align-items-end">
   <button class="btn btn-primary w-100">Filtrar</button>
  </div>
 </form>
</div></details>

<div class="row g-3 mb-3">
 <div class="col-md-3"><div class="card shadow-sm text-center"><div class="card-body">
  <h6 class="text-muted">Cantidad</h6>
  <div class="display-6"><?=$totales['n']?></div>
 </div></div></div>
 
 <div class="col-md-3"><div class="card shadow-sm text-center"><div class="card-body">
  <h6 class="text-muted">Neto</h6>
  <div class="display-6"><?=money($totales['neto'])?></div>
 </div></div></div>
 
 <div class="col-md-3"><div class="card shadow-sm text-center"><div class="card-body">
  <h6 class="text-muted">IVA</h6>
  <div class="display-6"><?=money($totales['iva'])?></div>
 </div></div></div>
 
 <div class="col-md-3"><div class="card shadow-sm bg-success text-white text-center"><div class="card-body">
  <h6>Total</h6>
  <div class="display-6"><?=money($totales['total'])?></div>
 </div></div></div>
</div>

<div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
 <thead class="table-light"><tr>
  <th>Número</th><th>Cliente</th><th>Cajero</th><th>Origen</th><th class="text-center">Hora</th>
  <th class="text-end">Neto</th><th class="text-end">IVA</th><th>Socio / descuento</th><th class="text-end">Total</th><th>Pago</th><th></th>
 </tr></thead>
 <tbody>
 <?php foreach($ventas as $v): ?>
  <tr>
   <td><strong>#<?=$v['id']?></strong></td>
   <td><small><?=e($v['cliente']?:'Consumidor Final')?></small><?php if(!empty($v['doc'])): ?><small class="d-block text-muted">Doc. <?=e(enmascarar_documento($v['doc']))?></small><?php endif ?></td>
   <td><small><?=e($v['cajero']??'-')?></small></td>
  <td><small><?=$v['pedido_tablet_codigo']?'Tablet #'.e($v['pedido_tablet_codigo']):'Caja'?></small></td>
   <td class="text-center"><small><?=date('H:i',strtotime($v['fecha']))?></small></td>
   <td class="text-end"><small><?=money($v['neto'])?></small></td>
   <td class="text-end"><small><?=money($v['iva'])?></small></td>
   <td><small><?=e($v['socio_nombre']??'—')?><?php if((float)$v['descuento_monto']>0): ?><span class="d-block text-success">−<?=money($v['descuento_monto'])?> (<?=e($v['descuento_pct'])?>%)</span><?php endif ?></small></td>
   <td class="text-end"><strong><?=money($v['total'])?></strong></td>
   <td><small><?=ucfirst($v['pago']??'-')?></small></td>
   <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="comprobante.php?id=<?=(int)$v['id']?>" target="_blank" rel="noopener noreferrer" aria-label="Abrir comprobante de venta <?=$v['id']?>"><i class="bi bi-receipt me-1"></i>Comprobante</a></td>
  </tr>
 <?php endforeach ?>
 <?php if(!$ventas): ?><tr><td colspan="11" class="text-center text-muted py-4">No hay ventas en este período y sucursal.</td></tr><?php endif ?>
 </tbody>
</table></div></div>

<?php foot();
