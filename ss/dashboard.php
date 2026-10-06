<?php
require 'lib.php'; 
$u=need_login();
if(!$u){header('Location: login.php');exit;}
if($u['rol']==='propietario'){header('Location: dueno.php');exit;}
if($u['rol']==='repositor'){header('Location: reponer.php');exit;}
if($u['rol']==='cajero'){header('Location: caja.php');exit;}
$sucursalId=sucursal_actual_id();

head('Dashboard - '.etiqueta_rol($u['rol']));

// Cargar datos generales
$en_servicio=q('SELECT COUNT(*) c FROM fichajes WHERE sucursal_id=? AND salida IS NULL',[$sucursalId])->fetch()['c'];
$productos_bajo_stock=q('SELECT COUNT(*) c FROM stock_sucursales WHERE sucursal_id=? AND stock<=minimo',[$sucursalId])->fetch()['c'];
$ventas_hoy=q1('SELECT COUNT(*) n,COALESCE(SUM(total),0) t FROM ventas WHERE sucursal_id=? AND estado="pagada" AND DATE(fecha)=CURDATE()',[$sucursalId]);
?>

<style>
 .dashboard-page{--dashboard-ink:#173b35;--dashboard-leaf:#27735d;--dashboard-line:#dce4dc}
 .dashboard-page .dashboard-heading{display:flex;justify-content:space-between;align-items:flex-end;gap:1rem;padding:1.5rem 1.75rem;margin-bottom:1.5rem;border-radius:14px;background:linear-gradient(120deg,var(--dashboard-ink),var(--dashboard-leaf));color:#fff}
 .dashboard-page .dashboard-heading h1{font-size:clamp(1.5rem,3vw,2rem);font-weight:750;margin:0}
 .dashboard-page .dashboard-heading p{margin:.45rem 0 0;color:#d5e5de}
 .dashboard-page .dashboard-heading .dashboard-date{color:#e7f2c8;font-size:.9rem;white-space:nowrap}
 .dashboard-page .row{--bs-gutter-x:1.25rem;--bs-gutter-y:1.25rem}
 .dashboard-page .row>[class*="col-"]{display:flex;min-width:0}
 .dashboard-page .card{width:100%;height:100%;border:1px solid var(--dashboard-line);border-radius:12px;overflow:hidden}
 .dashboard-page .dashboard-stat{border:0;min-height:145px;transition:transform .18s ease,box-shadow .18s ease}
 .dashboard-page .dashboard-stat:hover{transform:translateY(-2px);box-shadow:0 .75rem 1.5rem #173b351f!important}
 .dashboard-page .dashboard-stat .card-body{display:flex;flex-direction:column;justify-content:center;align-items:flex-start;padding:1.25rem 1.4rem;text-align:left!important}
 .dashboard-page .dashboard-stat .display-6{font-size:1.65rem;margin-bottom:.4rem}
 .dashboard-page .dashboard-stat h6{font-size:.85rem;font-weight:650;opacity:.9}
 .dashboard-page .dashboard-stat .display-5{font-size:clamp(1.65rem,3vw,2.15rem);font-weight:750;line-height:1.2}
 .dashboard-page .dashboard-stat.bg-primary{background:linear-gradient(135deg,#245c78,#3481a4)!important}
 .dashboard-page .dashboard-stat.bg-warning{background:linear-gradient(135deg,#f3c462,#f7d985)!important}
 .dashboard-page .dashboard-stat.bg-success{background:linear-gradient(135deg,#27735d,#419678)!important}
 .dashboard-page .dashboard-stat.bg-info{background:linear-gradient(135deg,#457d8d,#65a9b0)!important}
 .dashboard-page .dashboard-stat.bg-danger{background:linear-gradient(135deg,#a54545,#d56a5b)!important}
 .dashboard-page .card-body>h5{font-size:1rem;font-weight:700;color:var(--dashboard-ink);margin-bottom:1rem}
 .dashboard-page .table{margin-bottom:0}
 .dashboard-page .table>:not(caption)>*>*{padding:.75rem .65rem}
 .dashboard-page .list-group-item{padding:.9rem 1rem}
 .dashboard-page .dashboard-actions{display:grid;gap:.65rem}
 .dashboard-page .dashboard-actions .btn{margin:0!important}
 @media(max-width:575.98px){
  .dashboard-page .dashboard-heading{align-items:flex-start;flex-direction:column;padding:1.25rem;margin-bottom:1rem}
  .dashboard-page .dashboard-heading .dashboard-date{white-space:normal}
  .dashboard-page .row{--bs-gutter-x:.85rem;--bs-gutter-y:.85rem}
  .dashboard-page .dashboard-stat{min-height:128px}
  .dashboard-page .dashboard-stat .card-body{padding:1rem}
 }
 @media(prefers-reduced-motion:reduce){.dashboard-page .dashboard-stat{transition:none}.dashboard-page .dashboard-stat:hover{transform:none}}
</style>
<main class="dashboard-page">
<header class="dashboard-heading">
 <div><h1>Buen día, <?=e($u['nombre'])?></h1></div>
 <div class="dashboard-date"><i class="bi bi-calendar3 me-1"></i><?=e(date('d/m/Y'))?></div>
</header>

<?php if($u['rol']=='jefe'): ?>
<!-- PANEL JEFE -->
<div class="row g-3 mb-4">
 <div class="col-12 col-sm-6 col-xl-3"><div class="card dashboard-stat shadow-sm bg-primary text-white"><div class="card-body text-center">
  <div class="display-6">👥</div>
  <h6>Personal en servicio</h6>
  <div class="display-5"><?=$en_servicio?></div>
 </div></div></div>
 
 <div class="col-12 col-sm-6 col-xl-3"><div class="card dashboard-stat shadow-sm bg-warning text-dark"><div class="card-body text-center">
  <div class="display-6">📊</div>
  <h6>Stock bajo</h6>
  <div class="display-5"><?=$productos_bajo_stock?></div>
 </div></div></div>
 
 <div class="col-12 col-sm-6 col-xl-3"><div class="card dashboard-stat shadow-sm bg-success text-white"><div class="card-body text-center">
  <div class="display-6">💰</div>
  <h6>Ventas hoy</h6>
  <div class="display-5"><?=money($ventas_hoy['t'])?></div>
  <small><?=$ventas_hoy['n']?> compras</small>
 </div></div></div>

 <div class="col-12 col-sm-6 col-xl-3"><div class="card dashboard-stat shadow-sm bg-info text-white"><div class="card-body text-center">
  <div class="display-6">⚙️</div>
  <h6>Acciones</h6>
  <a href="personal.php" class="btn btn-sm btn-light mt-2">Gestionar personal</a>
    <a href="promociones.php" class="btn btn-sm btn-light mt-2">Ofertas</a>
 </div></div></div>
</div>

<div class="row g-3">
 <div class="col-md-6"><div class="card shadow-sm"><div class="card-body">
  <h5>Personal Activo</h5>
  <?php $pers=q('SELECT id,nombre,rol,(SELECT COUNT(*) FROM fichajes WHERE usuario_id=usuarios.id AND sucursal_id=? AND salida IS NULL) en_servicio FROM usuarios WHERE activo=1 AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=usuarios.id AND us.sucursal_id=?) ORDER BY nombre',[$sucursalId,$sucursalId])->fetchAll(); ?>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
   <thead><tr><th>Nombre</th><th>Rol</th><th>Estado</th></tr></thead>
   <tbody>
   <?php foreach($pers as $p): ?>
    <tr>
     <td><?=e($p['nombre'])?></td>
    <td><span class="badge bg-secondary"><?=e(etiqueta_rol($p['rol']))?></span></td>
     <td><?=$p['en_servicio']?'<span class="badge bg-success">🟢 En servicio</span>':'<span class="badge bg-secondary">⚪ Fuera</span>'?></td>
    </tr>
   <?php endforeach ?>
   </tbody>
  </table></div>
 </div></div></div>

 <div class="col-md-6"><div class="card shadow-sm"><div class="card-body">
  <h5>Productos con stock bajo</h5>
  <?php $bajos=q('SELECT p.nombre,s.stock,s.minimo FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id WHERE s.sucursal_id=? AND s.stock<=s.minimo ORDER BY s.stock LIMIT 8',[$sucursalId])->fetchAll(); ?>
  <div class="list-group">
   <?php foreach($bajos as $b): ?>
    <div class="list-group-item">
     <div class="d-flex w-100 justify-content-between">
      <h6 class="mb-1"><?=e($b['nombre'])?></h6>
      <span class="badge bg-danger"><?=$b['stock']?> unidades</span>
     </div>
     <small class="text-muted">Mínimo: <?=$b['minimo']?></small>
    </div>
   <?php endforeach ?>
  </div>
 </div></div></div>
</div>

<?php elseif($u['rol']=='supervisor'): ?>
<!-- PANEL SUPERVISOR -->
<div class="row g-3 mb-4">
 <div class="col-12 col-sm-6 col-lg-4"><div class="card dashboard-stat shadow-sm bg-primary text-white"><div class="card-body text-center">
  <div class="display-6">👥</div>
  <h6>En servicio ahora</h6>
  <div class="display-5"><?=$en_servicio?></div>
 </div></div></div>
 
 <div class="col-12 col-sm-6 col-lg-4"><div class="card dashboard-stat shadow-sm bg-warning text-dark"><div class="card-body text-center">
  <div class="display-6">📊</div>
  <h6>Stock bajo</h6>
  <div class="display-5"><?=$productos_bajo_stock?></div>
 </div></div></div>
 
 <div class="col-12 col-sm-6 col-lg-4"><div class="card dashboard-stat shadow-sm bg-success text-white"><div class="card-body text-center">
  <div class="display-6">💰</div>
  <h6>Ventas hoy</h6>
  <div class="display-5"><?=money($ventas_hoy['t'])?></div>
 </div></div></div>
</div>

<div class="row g-3">
 <div class="col-lg-8"><div class="card shadow-sm"><div class="card-body">
  <h5>Control de Personal - Horarios</h5>
  <?php $personal=q('
   SELECT u.id,u.nombre,u.rol,f.entrada,f.salida,
    TIMESTAMPDIFF(MINUTE,f.entrada,COALESCE(f.salida,NOW())) minutos
   FROM usuarios u
   LEFT JOIN fichajes f ON u.id=f.usuario_id AND f.sucursal_id=? AND DATE(f.entrada)=CURDATE()
   WHERE u.activo=1 AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=u.id AND us.sucursal_id=?)
   ORDER BY u.nombre
  ',[$sucursalId,$sucursalId])->fetchAll(); ?>
  <div class="table-responsive">
   <table class="table table-sm align-middle">
    <thead><tr><th>Empleado</th><th>Entrada</th><th>Salida</th><th>Duración</th><th>Estado</th></tr></thead>
    <tbody>
    <?php foreach($personal as $p): ?>
     <tr>
      <td><?=e($p['nombre'])?> <small class="text-muted"><?=e($p['rol'])?></small></td>
      <td><?=$p['entrada']?date('H:i',strtotime($p['entrada'])):'-'?></td>
      <td><?=$p['salida']?date('H:i',strtotime($p['salida'])):'-'?></td>
      <td><?=$p['entrada']?fh($p['minutos']):'-'?></td>
      <td><?=$p['salida']?'<span class="badge bg-secondary">Terminó</span>':'<span class="badge bg-success">🟢 Trabajando</span>'?></td>
     </tr>
    <?php endforeach ?>
    </tbody>
   </table>
  </div>
 </div></div></div>

 <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
  <h5>Información rápida</h5>
  <ul class="list-unstyled">
    <li class="mb-3"><b>Productos con stock bajo:</b> <?=$productos_bajo_stock?></li>
   <li class="mb-3"><b>Personal en servicio:</b> <?=$en_servicio?></li>
   <li class="mb-3"><b>Ventas del día:</b> <?=money($ventas_hoy['t'])?></li>
   <li><a href="reportes.php" class="btn btn-sm btn-outline-primary">Ver reportes</a></li>
    <li class="mt-2"><a href="promociones.php" class="btn btn-sm btn-outline-success">Ofertas</a></li>
  </ul>
 </div></div></div>
</div>

<?php elseif($u['rol']=='encargado'): ?>
<!-- PANEL ENCARGADO -->
<div class="row g-3 mb-4">
 <div class="col-12 col-sm-6 col-lg-4"><div class="card dashboard-stat shadow-sm bg-primary text-white"><div class="card-body text-center">
  <div class="display-6">📦</div>
  <h6>Pedidos pendientes</h6>
  <div class="display-5"><?=q('SELECT COUNT(*) c FROM pedidos_compra WHERE sucursal_id=? AND estado="pendiente"',[$sucursalId])->fetch()['c']?></div>
 </div></div></div>
 
 <div class="col-12 col-sm-6 col-lg-4"><div class="card dashboard-stat shadow-sm bg-warning text-dark"><div class="card-body text-center">
  <div class="display-6">👥</div>
  <h6>Personal en servicio</h6>
  <div class="display-5"><?=$en_servicio?></div>
 </div></div></div>
 
 <div class="col-12 col-sm-6 col-lg-4"><div class="card dashboard-stat shadow-sm bg-success text-white"><div class="card-body text-center">
  <div class="display-6">💰</div>
  <h6>Ventas hoy</h6>
  <div class="display-5"><?=money($ventas_hoy['t'])?></div>
 </div></div></div>
</div>

<div class="row g-3">
 <div class="col-lg-8"><div class="card shadow-sm"><div class="card-body">
  <h5>Mis Pedidos</h5>
  <?php $pedidos=q('
   SELECT p.*,COUNT(pi.id) items
   FROM pedidos_compra p
   LEFT JOIN pedido_items pi ON p.id=pi.pedido_id
   WHERE p.encargado_id=? AND p.sucursal_id=?
   GROUP BY p.id
   ORDER BY p.fecha_creacion DESC
   LIMIT 10
  ',[$u['id'],$sucursalId])->fetchAll(); ?>
  <div class="list-group">
   <?php if($pedidos): foreach($pedidos as $p): ?>
    <a href="pedidos.php?id=<?=$p['id']?>" class="list-group-item list-group-item-action">
     <div class="d-flex w-100 justify-content-between">
      <h6 class="mb-1">Pedido #<?=$p['id']?> - <?=e($p['proveedor'])?></h6>
      <span class="badge bg-<?=$p['estado']=='pendiente'?'warning':'success'?>"><?=$p['estado']?></span>
     </div>
     <small class="text-muted"><?=$p['items']?> productos | <?=date('d/m H:i',strtotime($p['fecha_creacion']))?></small>
    </a>
   <?php endforeach; else: ?>
    <div class="text-muted text-center p-4">Sin pedidos</div>
   <?php endif ?>
  </div>
 </div></div></div>

 <div class="col-lg-4"><div class="card shadow-sm"><div class="card-body">
  <h5>Acciones rápidas</h5>
  <div class="dashboard-actions">
   <a href="pedidos.php" class="btn btn-primary w-100">Nuevo pedido</a>
   <a href="cajas.php" class="btn btn-secondary w-100">Asignar cajas</a>
   <a href="personal.php" class="btn btn-info w-100">Ver personal</a>
  </div>
 </div></div></div>
</div>

<?php elseif($u['rol']=='repositor'): ?>
<!-- PANEL REPOSITOR -->
<div class="row g-3 mb-4">
 <div class="col-12 col-sm-6"><div class="card dashboard-stat shadow-sm bg-danger text-white"><div class="card-body text-center">
  <div class="display-6">⚠️</div>
  <h6>Productos por acabar</h6>
  <div class="display-5"><?=$productos_bajo_stock?></div>
 </div></div></div>
 
 <div class="col-12 col-sm-6"><div class="card dashboard-stat shadow-sm bg-info text-white"><div class="card-body text-center">
  <div class="display-6">📦</div>
  <h6>Productos para recibir</h6>
  <div class="display-5"><?=q('SELECT COALESCE(SUM(entrante),0) t FROM stock_sucursales WHERE sucursal_id=?',[$sucursalId])->fetch()['t']?></div>
 </div></div></div>
</div>

<div class="card shadow-sm"><div class="card-body">
 <h5>Productos con Stock Bajo</h5>
 <?php $bajos=q('SELECT p.id,p.nombre,s.stock,s.minimo,s.entrante FROM productos p JOIN stock_sucursales s ON s.producto_id=p.id WHERE s.sucursal_id=? AND s.stock<=s.minimo ORDER BY s.stock',[$sucursalId])->fetchAll(); ?>
 <div class="table-responsive">
  <table class="table table-sm">
   <thead><tr><th>Producto</th><th>Stock</th><th>Mínimo</th><th>Por llegar</th><th>Acción</th></tr></thead>
   <tbody>
   <?php foreach($bajos as $b): ?>
    <tr class="table-danger">
     <td><?=e($b['nombre'])?></td>
     <td><b><?=$b['stock']?></b></td>
     <td><?=$b['minimo']?></td>
     <td><?=$b['entrante']?:'<small class="text-muted">—</small>'?></td>
     <td><a href="productos.php?editar=<?=$b['id']?>" class="btn btn-sm btn-outline-primary">Reponer</a></td>
    </tr>
   <?php endforeach ?>
   </tbody>
  </table>
 </div>
</div></div>

<?php elseif($u['rol']=='cajero'): ?>
<!-- PANEL CAJERO -->
<div class="row g-3 mb-4">
 <div class="col-12 col-sm-6"><div class="card dashboard-stat shadow-sm bg-success text-white"><div class="card-body text-center">
  <div class="display-6">💰</div>
  <h6>Ventas hoy</h6>
  <div class="display-5"><?=money($ventas_hoy['t'])?></div>
  <small><?=$ventas_hoy['n']?> compras</small>
 </div></div></div>
 
 <div class="col-12 col-sm-6"><div class="card dashboard-stat shadow-sm bg-primary text-white"><div class="card-body text-center">
  <div class="display-6">🧾</div>
  <h6>Mi jornada</h6>
  <?php $fichaje=q1('SELECT entrada FROM fichajes WHERE usuario_id=? AND sucursal_id=? AND salida IS NULL ORDER BY entrada DESC LIMIT 1',[$u['id'],$sucursalId]); ?>
  <?php if($fichaje): ?>
   <div class="display-5"><?=fh(intval((strtotime('now')-strtotime($fichaje['entrada']))/60))?></div>
   <small>Trabajando desde <?=date('H:i',strtotime($fichaje['entrada']))?></small>
  <?php else: ?>
   <div class="display-5">—</div>
   <small class="text-muted">No has iniciado tu jornada</small>
  <?php endif ?>
 </div></div></div>
</div>

<div class="card shadow-sm"><div class="card-body">
 <h5>Acciones</h5>
 <a href="caja.php" class="btn btn-lg btn-success w-100 mb-2">Ir a Caja</a>
</div></div>

<?php endif ?>
</main>

<?php foot();
