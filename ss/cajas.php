<?php
require 'lib.php';
$u=need('cajas','asignar');
if(!verificar_esquema_operativo([
  'asignaciones_caja'=>['sucursal_id','fecha_fin','usuario_id'],
  'usuario_sucursales'=>['usuario_id','sucursal_id'],
  'ventas'=>['sucursal_id']
]))solicitar_migracion_operativa('Cajas');
$sucursalId=sucursal_actual_id();

if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  $accion=$_POST['accion']??'';
  
  if($accion=='asignar'){
    $usuario_id=(int)($_POST['usuario_id']??0);
    $caja_numero=(int)($_POST['caja']??0);
    
    $usuario=q1('SELECT * FROM usuarios WHERE id=? AND rol="cajero" AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=usuarios.id AND us.sucursal_id=?)',[$usuario_id,$sucursalId]);
    
    if(!$usuario || $caja_numero<=0){
      $_SESSION['m']='Datos inválidos.';
    }else{
      // Finalizar asignación anterior
      $anterior=q1('SELECT * FROM asignaciones_caja WHERE usuario_id=? AND sucursal_id=? AND fecha_fin IS NULL',[$usuario_id,$sucursalId]);
      if($anterior){
        q('UPDATE asignaciones_caja SET fecha_fin=NOW() WHERE id=? AND sucursal_id=?',[$anterior['id'],$sucursalId]);
      }
      
      // Nueva asignación
      q('INSERT INTO asignaciones_caja(usuario_id,caja_numero,fecha_inicio,sucursal_id) VALUES(?,?,NOW(),?)',
        [$usuario_id,$caja_numero,$sucursalId]);
      auditar('asignar_caja','asignaciones_caja',db()->lastInsertId(),['caja'=>$caja_numero]);
      $_SESSION['m']='Cajero asignado ✔';
    }
  }
  
  elseif($accion=='desasignar'){
    $id=(int)($_POST['id']??0);
    $asig=q1('SELECT * FROM asignaciones_caja WHERE id=? AND sucursal_id=?',[$id,$sucursalId]);
    if($asig && !$asig['fecha_fin']){
      q('UPDATE asignaciones_caja SET fecha_fin=NOW() WHERE id=? AND sucursal_id=?',[$id,$sucursalId]);
      $_SESSION['m']='Cajero desasignado ✔';
    }
  }
  
  header('Location: cajas.php');
  exit;
}

// Cajas activas (en servicio)
$activas=q('
  SELECT a.*,u.nombre,
    (SELECT COUNT(*) FROM ventas WHERE cajero_id=u.id AND sucursal_id=? AND DATE(fecha)=CURDATE()) ventas_hoy
  FROM asignaciones_caja a
  JOIN usuarios u ON a.usuario_id=u.id
  WHERE a.fecha_fin IS NULL AND a.sucursal_id=?
  ORDER BY a.caja_numero
',[$sucursalId,$sucursalId])->fetchAll();

// Historial
$historial=q('
  SELECT a.*,u.nombre,
    (SELECT COUNT(*) FROM ventas WHERE cajero_id=u.id AND sucursal_id=? AND DATE(fecha)=CURDATE()) ventas_hoy
  FROM asignaciones_caja a
  JOIN usuarios u ON a.usuario_id=u.id
  WHERE a.fecha_fin IS NOT NULL AND a.sucursal_id=? AND DATE(a.fecha_inicio)=CURDATE()
  ORDER BY a.fecha_fin DESC LIMIT 10
',[$sucursalId,$sucursalId])->fetchAll();

$cajeros=q('SELECT id,nombre FROM usuarios WHERE rol="cajero" AND activo=1 AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=usuarios.id AND us.sucursal_id=?) ORDER BY nombre',[$sucursalId])->fetchAll();

head('Asignación de Cajas'); ?>

<div class="row g-3">
 <div class="col-lg-8">
  <div class="card shadow-sm mb-3"><div class="card-header bg-success text-white">
   <h5 class="mb-0">Cajas en servicio</h5>
  </div><div class="table-responsive"><table class="table align-middle mb-0">
   <thead class="table-light"><tr><th>Caja</th><th>Cajero</th><th>Asignado</th><th>Ventas</th><th></th></tr></thead>
   <tbody>
   <?php foreach($activas as $a): ?>
    <tr class="table-success">
     <td><h6 class="mb-0">Caja #<?=$a['caja_numero']?></h6></td>
     <td><?=e($a['nombre'])?></td>
     <td><small class="text-muted"><?=date('H:i',strtotime($a['fecha_inicio']))?></small></td>
     <td><span class="badge bg-info"><?=$a['ventas_hoy']?></span></td>
     <td>
      <form method="post" class="d-inline" onsubmit="return confirm('¿Desasignar?')">
       <input type="hidden" name="c" value="<?=csrf()?>">
       <input type="hidden" name="accion" value="desasignar">
       <input type="hidden" name="id" value="<?=$a['id']?>">
       <button class="btn btn-sm btn-outline-danger">Desasignar</button>
      </form>
     </td>
    </tr>
   <?php endforeach ?>
   </tbody>
  </table></div></div>

  <?php if(!empty($historial)): ?>
   <div class="card shadow-sm"><div class="card-header">
    <h5 class="mb-0">Historial de hoy</h5>
   </div><div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead class="table-light"><tr><th>Caja</th><th>Cajero</th><th>Entrada</th><th>Salida</th><th>Ventas</th></tr></thead>
    <tbody>
    <?php foreach($historial as $h): ?>
     <tr class="text-muted">
      <td>Caja #<?=$h['caja_numero']?></td>
      <td><?=e($h['nombre'])?></td>
      <td><?=date('H:i',strtotime($h['fecha_inicio']))?></td>
      <td><?=date('H:i',strtotime($h['fecha_fin']))?></td>
      <td><small><?=$h['ventas_hoy']?></small></td>
     </tr>
    <?php endforeach ?>
    </tbody>
   </table></div></div>
  <?php endif ?>
 </div>

 <div class="col-lg-4">
  <div class="card shadow-sm"><div class="card-body">
   <h5>Asignar cajero</h5>
   <form method="post">
    <input type="hidden" name="c" value="<?=csrf()?>">
    <input type="hidden" name="accion" value="asignar">
    <select name="usuario_id" class="form-select mb-2" required>
     <option value="">Selecciona cajero</option>
     <?php foreach($cajeros as $c): 
      $ya_asignado=in_array($c['id'],array_column($activas,'usuario_id'));
     ?>
      <option value="<?=$c['id']?>" <?=$ya_asignado?'disabled':''?>>
       <?=e($c['nombre'])?><?=$ya_asignado?' (en caja)':''?>
      </option>
     <?php endforeach ?>
    </select>
    <select name="caja" class="form-select mb-3" required>
     <option value="">Selecciona caja</option>
     <?php for($i=1;$i<=5;$i++): 
      $ocupada=in_array($i,array_column($activas,'caja_numero'));
     ?>
      <option value="<?=$i?>" <?=$ocupada?'disabled':''?>>
       Caja #<?=$i?><?=$ocupada?' (en uso)':''?>
      </option>
     <?php endfor ?>
    </select>
    <button class="btn btn-primary w-100">Asignar</button>
   </form>
  </div></div>
 </div>
</div>

<?php foot();
