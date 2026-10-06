<?php
require 'lib.php';
$u=need('accesos','ver');
$sucursalId=sucursal_actual_id();

$filtroSucursal=$u['rol']==='propietario'?'':' AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=usuarios.id AND us.sucursal_id='.$sucursalId.')';
$activos=q('SELECT id,nombre,usuario,rol,ultimo_acceso,ultima_actividad FROM usuarios WHERE activo=1'.$filtroSucursal.' ORDER BY ultima_actividad DESC,nombre')->fetchAll();
$resumen=q('SELECT ra.usuario,
  SUM(ra.evento="login_ok") inicios,
  SUM(ra.evento="login_fallido") fallos,
  MAX(CASE WHEN ra.evento="login_ok" THEN ra.fecha END) ultimo_ingreso
  FROM registro_accesos ra JOIN usuarios ON usuarios.id=ra.usuario_id
  WHERE ra.fecha>=NOW()-INTERVAL 30 DAY'.$filtroSucursal.' GROUP BY ra.usuario ORDER BY ultimo_ingreso DESC LIMIT 100')->fetchAll();
$eventos=q('SELECT ra.usuario,ra.evento,ra.ip,ra.agente,ra.fecha FROM registro_accesos ra JOIN usuarios ON usuarios.id=ra.usuario_id WHERE 1=1'.$filtroSucursal.' ORDER BY ra.fecha DESC LIMIT 250')->fetchAll();
head('Registro de accesos'); ?>

<h1 class="h3 mb-3">Registro de accesos</h1>
<p class="text-muted">Actividad de autenticación guardada durante los últimos 30 días.</p>

<section class="card shadow-sm mb-3"><div class="card-body">
 <h2 class="h5">Personal activo recientemente</h2>
 <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Persona</th><th>Cargo</th><th>Estado</th><th>Último acceso</th></tr></thead><tbody>
 <?php foreach($activos as $persona): $enLinea=$persona['ultima_actividad']&&strtotime($persona['ultima_actividad'])>=time()-300; ?>
  <tr><td><?=e($persona['nombre'])?> <small class="text-muted">(<?=e($persona['usuario'])?>)</small></td><td><?=e(etiqueta_rol($persona['rol']))?></td><td><span class="badge text-bg-<?=$enLinea?'success':'secondary'?>"><?=$enLinea?'Activo hace menos de 5 min':'Sin actividad reciente'?></span></td><td><?=$persona['ultimo_acceso']?date('d/m/Y H:i',strtotime($persona['ultimo_acceso'])):'Sin registro'?></td></tr>
 <?php endforeach ?>
 </tbody></table></div>
</div></section>

<section class="card shadow-sm mb-3"><div class="card-body">
 <h2 class="h5">Accesos por usuario</h2>
 <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Usuario</th><th>Inicios correctos</th><th>Fallidos</th><th>Último inicio</th></tr></thead><tbody>
 <?php foreach($resumen as $fila): ?><tr><td><?=e($fila['usuario'])?></td><td><?=$fila['inicios']?></td><td><?=$fila['fallos']?></td><td><?=$fila['ultimo_ingreso']?date('d/m/Y H:i',strtotime($fila['ultimo_ingreso'])):'-'?></td></tr><?php endforeach ?>
 </tbody></table></div>
</div></section>

<section class="card shadow-sm"><div class="card-body">
 <h2 class="h5">Eventos recientes</h2>
 <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Fecha</th><th>Usuario</th><th>Evento</th><th>IP</th></tr></thead><tbody>
 <?php foreach($eventos as $evento): ?><tr><td><?=date('d/m/Y H:i:s',strtotime($evento['fecha']))?></td><td><?=e($evento['usuario'])?></td><td><?=e(str_replace('_',' ',$evento['evento']))?></td><td><code><?=e($evento['ip'])?></code></td></tr><?php endforeach ?>
 </tbody></table></div>
</div></section>
<?php foot();