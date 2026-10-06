<?php
require 'lib.php';
$u=need('reportes','ver');
$sucursalId=sucursal_actual_id();

$fechaValida=static function($valor){
  if(!is_string($valor)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$valor))return false;
  $fecha=DateTimeImmutable::createFromFormat('!Y-m-d',$valor);
  return $fecha&&$fecha->format('Y-m-d')===$valor;
};
$tipo=$_GET['tipo']??'diario';
if(!in_array($tipo,['diario','periodo','stock'],true))$tipo='diario';
$hoy=date('Y-m-d');
$desde=$fechaValida($_GET['desde']??null)?$_GET['desde']:$hoy;
$hasta=$fechaValida($_GET['hasta']??null)?$_GET['hasta']:$hoy;

// Reporte diario
if($tipo=='diario'){
  $ventas_por_hora=q('
    SELECT HOUR(fecha) hora,COUNT(*) cantidad,SUM(total) total 
    FROM ventas 
    WHERE sucursal_id=? AND estado="pagada" AND DATE(fecha)=? 
    GROUP BY HOUR(fecha) 
    ORDER BY hora
  ',[$sucursalId,$desde])->fetchAll();
  
  $top_productos=q('
    SELECT p.nombre,SUM(vi.cantidad) cantidad,SUM(vi.cantidad*vi.precio) total
    FROM venta_items vi
    JOIN productos p ON vi.producto_id=p.id
    JOIN ventas v ON vi.venta_id=v.id
    WHERE v.sucursal_id=? AND v.estado="pagada" AND DATE(v.fecha)=?
    GROUP BY vi.producto_id
    ORDER BY cantidad DESC LIMIT 10
  ',[$sucursalId,$desde])->fetchAll();
}

// Reporte por periodo
elseif($tipo=='periodo'){
  $ventas_por_dia=q('
    SELECT DATE(fecha) dia,COUNT(*) cantidad,SUM(total) total
    FROM ventas
    WHERE sucursal_id=? AND estado="pagada" AND DATE(fecha) BETWEEN ? AND ?
    GROUP BY DATE(fecha)
    ORDER BY dia
  ',[$sucursalId,$desde,$hasta])->fetchAll();
  
  $ventas_por_cajero=q('
    SELECT u.nombre,COUNT(*) cantidad,SUM(v.total) total
    FROM ventas v
    JOIN usuarios u ON v.cajero_id=u.id
    WHERE v.sucursal_id=? AND v.estado="pagada" AND DATE(v.fecha) BETWEEN ? AND ?
    GROUP BY v.cajero_id
    ORDER BY total DESC
  ',[$sucursalId,$desde,$hasta])->fetchAll();
}

// Reporte de stock
elseif($tipo=='stock'){
  $bajo_stock=q('
    SELECT p.nombre,s.stock,s.minimo,s.entrante
    FROM stock_sucursales s JOIN productos p ON p.id=s.producto_id
    WHERE s.sucursal_id=? AND s.stock<=s.minimo
    ORDER BY s.stock
  ',[$sucursalId])->fetchAll();
  
  $sin_stock=q('
    SELECT p.nombre,s.stock,s.minimo,s.entrante
    FROM stock_sucursales s JOIN productos p ON p.id=s.producto_id
    WHERE s.sucursal_id=? AND s.stock=0
    ORDER BY p.nombre
  ',[$sucursalId])->fetchAll();
}

head('Reportes'); ?>

<div class="card shadow-sm mb-3"><div class="card-body">
 <h5 class="mb-3">Tipos de reporte</h5>
 <div class="btn-group w-100" role="group">
  <a href="?tipo=diario&desde=<?=date('Y-m-d')?>" class="btn btn-<?=$tipo=='diario'?'primary':'outline-primary'?>">
   Diario
  </a>
  <a href="?tipo=periodo&desde=<?=date('Y-m-01')?>&hasta=<?=date('Y-m-d')?>" class="btn btn-<?=$tipo=='periodo'?'primary':'outline-primary'?>">
   Por período
  </a>
  <a href="?tipo=stock" class="btn btn-<?=$tipo=='stock'?'primary':'outline-primary'?>">
   Stock
  </a>
 </div>
</div></div>

<?php if($tipo=='diario'): ?>
 <div class="row g-3">
  <div class="col-md-6">
   <div class="card shadow-sm"><div class="card-body">
    <h5>Filtro</h5>
    <form method="get" class="row g-2">
     <input type="hidden" name="tipo" value="diario">
     <div class="col-12">
      <input type="date" name="desde" value="<?=e($desde)?>" class="form-control">
     </div>
     <div class="col-12">
      <button class="btn btn-primary w-100">Filtrar</button>
     </div>
    </form>
   </div></div>
  </div>
  
  <div class="col-md-6">
   <div class="card shadow-sm text-center"><div class="card-body">
    <h6 class="text-muted">Día</h6>
    <div class="display-6"><?=date('d/m/Y',strtotime($desde))?></div>
   </div></div>
  </div>
 </div>

 <div class="row g-3 mt-3">
  <div class="col-lg-6">
   <div class="card shadow-sm"><div class="card-body">
    <h5>Ventas por hora</h5>
    <div class="table-responsive"><table class="table table-sm">
     <thead><tr><th>Hora</th><th class="text-center">Cantidad</th><th class="text-end">Total</th></tr></thead>
     <tbody>
     <?php foreach($ventas_por_hora as $v): ?>
      <tr>
      <td><?=str_pad($v['hora'],2,'0',STR_PAD_LEFT)?>:00</td>
       <td class="text-center"><span class="badge bg-info"><?=$v['cantidad']?></span></td>
       <td class="text-end"><?=money($v['total'])?></td>
      </tr>
     <?php endforeach ?>
     </tbody>
    </table></div>
   </div></div>
  </div>

  <div class="col-lg-6">
   <div class="card shadow-sm"><div class="card-body">
    <h5>Top productos</h5>
    <div class="list-group">
     <?php foreach($top_productos as $p): ?>
      <div class="list-group-item">
       <div class="d-flex w-100 justify-content-between">
        <h6 class="mb-1"><?=e($p['nombre'])?></h6>
        <span class="badge bg-success"><?=$p['cantidad']?> unds</span>
       </div>
       <small class="text-muted"><?=money($p['total'])?></small>
      </div>
     <?php endforeach ?>
    </div>
   </div></div>
  </div>
 </div>

<?php elseif($tipo=='periodo'): ?>
 <div class="row g-3">
  <div class="col-md-3">
   <div class="card shadow-sm"><div class="card-body">
    <h5>Filtro</h5>
    <form method="get" class="row g-2">
     <input type="hidden" name="tipo" value="periodo">
     <div class="col-12">
      <label>Desde</label>
      <input type="date" name="desde" value="<?=e($desde)?>" class="form-control">
     </div>
     <div class="col-12">
      <label>Hasta</label>
      <input type="date" name="hasta" value="<?=e($hasta)?>" class="form-control">
     </div>
     <div class="col-12">
      <button class="btn btn-primary w-100">Filtrar</button>
     </div>
    </form>
   </div></div>
  </div>
  
  <div class="col-md-9">
   <div class="row g-3">
    <div class="col-md-6">
     <div class="card shadow-sm"><div class="card-body">
      <h5>Ventas por día</h5>
      <div class="table-responsive"><table class="table table-sm">
       <thead><tr><th>Fecha</th><th class="text-center">Cantidad</th><th class="text-end">Total</th></tr></thead>
       <tbody>
       <?php foreach($ventas_por_dia as $v): ?>
        <tr>
         <td><?=date('d/m',strtotime($v['dia']))?></td>
         <td class="text-center"><?=$v['cantidad']?></td>
         <td class="text-end"><?=money($v['total'])?></td>
        </tr>
       <?php endforeach ?>
       </tbody>
      </table></div>
     </div></div>
    </div>

    <div class="col-md-6">
     <div class="card shadow-sm"><div class="card-body">
      <h5>Ventas por cajero</h5>
      <div class="list-group">
       <?php foreach($ventas_por_cajero as $c): ?>
        <div class="list-group-item">
         <div class="d-flex w-100 justify-content-between">
          <h6 class="mb-1"><?=e($c['nombre'])?></h6>
          <span class="badge bg-success"><?=$c['cantidad']?></span>
         </div>
         <small class="text-muted"><?=money($c['total'])?></small>
        </div>
       <?php endforeach ?>
      </div>
     </div></div>
    </div>
   </div>
  </div>
 </div>

<?php elseif($tipo=='stock'): ?>
 <div class="row g-3">
  <div class="col-lg-6">
   <div class="card shadow-sm border-warning"><div class="card-header bg-warning">
    <h5 class="mb-0">Stock bajo</h5>
   </div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Producto</th><th class="text-center">Stock</th><th class="text-center">Mínimo</th><th class="text-center">Por llegar</th></tr></thead>
    <tbody>
    <?php foreach($bajo_stock as $b): ?>
     <tr class="table-warning">
      <td><?=e($b['nombre'])?></td>
      <td class="text-center"><span class="badge bg-danger"><?=$b['stock']?></span></td>
      <td class="text-center"><?=$b['minimo']?></td>
      <td class="text-center"><?=$b['entrante']?'<span class="badge bg-info">'.$b['entrante'].'</span>':'-'?></td>
     </tr>
    <?php endforeach ?>
    </tbody>
   </table></div></div>
  </div>

  <div class="col-lg-6">
   <div class="card shadow-sm border-danger"><div class="card-header bg-danger text-white">
    <h5 class="mb-0">Sin stock</h5>
   </div><div class="list-group list-group-flush">
    <?php foreach($sin_stock as $s): ?>
     <div class="list-group-item">
      <div class="d-flex w-100 justify-content-between">
       <h6 class="mb-1"><?=e($s['nombre'])?></h6>
       <span class="badge bg-danger">Agotado</span>
      </div>
      <small class="text-muted">Mínimo: <?=$s['minimo']?> | Por llegar: <?=$s['entrante']?:'-'?></small>
     </div>
    <?php endforeach ?>
   </div></div>
  </div>
 </div>

<?php endif ?>

<?php foot();
