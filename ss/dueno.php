<?php
require 'lib.php';
$u=need_propietario();
if(!verificar_esquema_operativo(['sucursales'=>['localidad','provincia','codigo_postal','telefono','email']]))solicitar_migracion_operativa('gestión de sucursales');
$geografiaArchivo=__DIR__.'/assets/geografia-ar.json';
$geografia=json_decode((string)file_get_contents($geografiaArchivo),true);
if(!is_array($geografia)||!isset($geografia['provincias'])||!is_array($geografia['provincias'])){
  throw new RuntimeException('No se pudo cargar el listado local de provincias y departamentos.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  chk();
  $accion=(string)($_POST['accion']??'');
  try{
    if($accion==='seleccionar_sucursal'){
      $sucursalId=(int)($_POST['sucursal_id']??0);
      if(!usuario_puede_ver_sucursal($u,$sucursalId))throw new DomainException('La sucursal seleccionada no está disponible.');
      $_SESSION['sucursal_actual_id']=$sucursalId;
    }elseif($accion==='crear_sucursal'||$accion==='editar_sucursal'){
      $nombre=trim((string)($_POST['nombre']??''));
      $direccion=trim((string)($_POST['direccion']??''));
      $departamento=trim((string)($_POST['departamento']??''));
      $localidad=$departamento;
      $provincia=trim((string)($_POST['provincia']??''));
      $codigoPostal=trim((string)($_POST['codigo_postal']??''));
      $telefono=trim((string)($_POST['telefono']??''));
      $email=trim((string)($_POST['email']??''));
      $sucursalExistente=$accion==='editar_sucursal'
        ?q1('SELECT localidad,provincia FROM sucursales WHERE id=?',[(int)($_POST['sucursal_id']??0)])
        :null;
      $ubicacionLegada=$sucursalExistente
        &&$departamento===(string)($sucursalExistente['localidad']??'')
        &&$provincia===(string)($sucursalExistente['provincia']??'');
      $ubicacionVacia=$departamento===''&&$provincia==='';
      $ubicacionValida=false;
      foreach($geografia['provincias'] as $provinciaCatalogo){
        if($provinciaCatalogo['nombre']===$provincia
          &&in_array($departamento,$provinciaCatalogo['departamentos'],true)){
          $ubicacionValida=true;
          break;
        }
      }
      if($nombre===''||mb_strlen($nombre)>100||mb_strlen($direccion)>200||mb_strlen($localidad)>100
        ||mb_strlen($provincia)>100||mb_strlen($codigoPostal)>15||mb_strlen($telefono)>30
        ||mb_strlen($email)>150||($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))
        ||(!$ubicacionVacia&&!$ubicacionValida&&!$ubicacionLegada)){
        throw new DomainException('Revisa los datos de la sucursal.');
      }
      $datos=[$nombre,$direccion?:null,$localidad?:null,$provincia?:null,$codigoPostal?:null,$telefono?:null,$email?:null];
      if($accion==='crear_sucursal'){
        db()->beginTransaction();
        q('INSERT INTO sucursales(nombre,direccion,localidad,provincia,codigo_postal,telefono,email) VALUES(?,?,?,?,?,?,?)',$datos);
        $sucursalId=(int)db()->lastInsertId();
        q('INSERT INTO config(sucursal_id) VALUES(?)',[$sucursalId]);
        q('INSERT INTO stock_sucursales(sucursal_id,producto_id,stock,entrante,minimo) SELECT ?,id,0,0,0 FROM productos',[$sucursalId]);
        q('INSERT IGNORE INTO usuario_sucursales(usuario_id,sucursal_id) VALUES(?,?)',[$u['id'],$sucursalId]);
        auditar('crear_sucursal','sucursales',$sucursalId,['nombre'=>$nombre]);
        db()->commit();
        $_SESSION['sucursal_actual_id']=$sucursalId;
        $_SESSION['m']='Sucursal creada. El inventario comienza en cero y su configuración está lista.';
      }else{
        $sucursalId=(int)($_POST['sucursal_id']??0);
        if(!q1('SELECT id FROM sucursales WHERE id=?',[$sucursalId]))throw new DomainException('La sucursal que quieres editar ya no existe.');
        db()->beginTransaction();
        q('UPDATE sucursales SET nombre=?,direccion=?,localidad=?,provincia=?,codigo_postal=?,telefono=?,email=? WHERE id=?',[...$datos,$sucursalId]);
        auditar('editar_sucursal','sucursales',$sucursalId,['nombre'=>$nombre]);
        db()->commit();
        $_SESSION['m']='Datos de la sucursal actualizados.';
      }
    }elseif($accion==='cambiar_estado_sucursal'){
      $sucursalId=(int)($_POST['sucursal_id']??0);
      db()->beginTransaction();
      $estadosSucursales=q('SELECT id,nombre,activa FROM sucursales ORDER BY id FOR UPDATE')->fetchAll();
      $sucursal=null;
      $cantidadActivas=0;
      foreach($estadosSucursales as $estadoSucursal){
        if((int)$estadoSucursal['activa']===1)$cantidadActivas++;
        if((int)$estadoSucursal['id']===$sucursalId)$sucursal=$estadoSucursal;
      }
      if(!$sucursal)throw new DomainException('La sucursal no existe.');
      $nuevoEstado=!(bool)$sucursal['activa'];
      if(!$nuevoEstado&&$cantidadActivas<=1){
        throw new DomainException('No puedes dar de baja la única sucursal activa.');
      }
      q('UPDATE sucursales SET activa=? WHERE id=?',[$nuevoEstado?1:0,$sucursalId]);
      auditar('cambiar_estado_sucursal','sucursales',$sucursalId,['activa'=>$nuevoEstado]);
      db()->commit();
      $_SESSION['m']=$nuevoEstado?'Sucursal reactivada.':'Sucursal dada de baja; su historial y sus datos se conservaron.';
    }elseif($accion==='asignar_sucursales'){
      $usuarioId=(int)($_POST['usuario_id']??0);
      $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['sucursales']??[])))));
      db()->beginTransaction();
      $empleado=q1('SELECT id,rol FROM usuarios WHERE id=? AND activo=1 AND rol<>"propietario" FOR UPDATE',[$usuarioId]);
      if(!$empleado||!$ids)throw new DomainException('Selecciona un empleado activo y al menos una sucursal.');
      if($empleado['rol']!=='jefe'&&count($ids)>1)throw new DomainException('Solo los jefes de sucursal pueden estar asignados a varias sucursales.');
      foreach($ids as $id)if(!q1('SELECT id FROM sucursales WHERE id=? AND activa=1 FOR UPDATE',[$id]))throw new DomainException('Una de las sucursales elegidas no está activa.');
      if($empleado['rol']==='jefe'){
        $asignacionesActuales=q('SELECT sucursal_id FROM usuario_sucursales WHERE usuario_id=? FOR UPDATE',[$usuarioId])->fetchAll();
        foreach($asignacionesActuales as $asignacion){
          $sucursalAnterior=(int)$asignacion['sucursal_id'];
          if(in_array($sucursalAnterior,$ids,true))continue;
          $otrosJefes=q('SELECT u.id FROM usuarios u JOIN usuario_sucursales us ON us.usuario_id=u.id
            WHERE us.sucursal_id=? AND u.rol="jefe" AND u.activo=1 AND u.id<>? FOR UPDATE',
            [$sucursalAnterior,$usuarioId])->fetchAll();
          if(!$otrosJefes)throw new DomainException('Asigna otro jefe activo antes de quitar esta sucursal.');
        }
      }
      q('DELETE FROM usuario_sucursales WHERE usuario_id=?',[$usuarioId]);
      foreach($ids as $id)q('INSERT INTO usuario_sucursales(usuario_id,sucursal_id) VALUES(?,?)',[$usuarioId,$id]);
      q('UPDATE usuarios SET sucursal_id=? WHERE id=?',[$ids[0],$usuarioId]);
      auditar('asignar_sucursales','usuarios',$usuarioId,['sucursales'=>$ids]);
      db()->commit();
      $_SESSION['m']='Asignación de sucursales actualizada.';
    }elseif($accion==='cambiar_estado_usuario'){
      $usuarioId=(int)($_POST['usuario_id']??0);
      db()->beginTransaction();
      $empleado=q1('SELECT id,rol,activo FROM usuarios WHERE id=? FOR UPDATE',[$usuarioId]);
      if(!$empleado||$empleado['rol']==='propietario'||$usuarioId===(int)$u['id'])throw new DomainException('No se puede cambiar el estado de esa cuenta.');
      if($empleado['rol']==='jefe'&&$empleado['activo']){
        $sucursalesJefe=q('SELECT sucursal_id FROM usuario_sucursales WHERE usuario_id=? FOR UPDATE',[$usuarioId])->fetchAll();
        foreach($sucursalesJefe as $asignacion){
          $otrosJefes=q('SELECT u.id FROM usuarios u JOIN usuario_sucursales us ON us.usuario_id=u.id
            WHERE us.sucursal_id=? AND u.rol="jefe" AND u.activo=1 AND u.id<>? FOR UPDATE',
            [$asignacion['sucursal_id'],$usuarioId])->fetchAll();
          if(!$otrosJefes)throw new DomainException('Asigna otro jefe activo antes de desactivar esta cuenta.');
        }
      }
      q('UPDATE usuarios SET activo=1-activo WHERE id=?',[$usuarioId]);
      auditar('cambiar_estado_usuario','usuarios',$usuarioId,['activo'=>1-(int)$empleado['activo']]);
      db()->commit();
      $_SESSION['m']=$empleado['activo']?'Usuario desactivado.':'Usuario activado.';
    }elseif($accion==='enviar'){
      $sucursalId=(int)($_POST['sucursal_id']??0);
      $tipo=(string)($_POST['tipo']??'mensaje');
      $contenido=trim((string)($_POST['contenido']??''));
      if(!usuario_puede_ver_sucursal($u,$sucursalId)||!in_array($tipo,['mensaje','orden'],true)||$contenido===''||mb_strlen($contenido)>2000){
        throw new DomainException('Selecciona una sucursal y escribe un mensaje u orden de hasta 2000 caracteres.');
      }
      q('INSERT INTO comunicaciones_sucursal(sucursal_id,autor_id,tipo,contenido) VALUES(?,?,?,?)',[$sucursalId,$u['id'],$tipo,$contenido]);
      $_SESSION['m']=$tipo==='orden'?'Orden enviada a la sucursal.':'Mensaje enviado a la sucursal.';
    }else{
      throw new DomainException('Acción no reconocida.');
    }
  }catch(DomainException $error){
    if(db()->inTransaction())db()->rollBack();
    $_SESSION['m']=$error->getMessage();
  }catch(Throwable $error){
    if(db()->inTransaction())db()->rollBack();
    registrar_error_aplicacion('Portal del dueño: '.$error->getMessage());
    $_SESSION['m']='No se pudo completar la acción. El incidente fue registrado.';
  }
  header('Location: dueno.php');
  exit;
}

$sucursales=q('SELECT id,nombre,direccion,activa FROM sucursales WHERE activa=1 ORDER BY nombre')->fetchAll();
$sucursalesTodas=q('SELECT * FROM sucursales ORDER BY activa DESC,nombre')->fetchAll();
$sucursalEditarId=filter_var($_GET['editar_sucursal']??null,FILTER_VALIDATE_INT);
$sucursalEditar=$sucursalEditarId?q1('SELECT * FROM sucursales WHERE id=?',[(int)$sucursalEditarId]):null;
$sucursalId=sucursal_actual_id();
if(!$sucursalId){http_response_code(503);exit('No hay sucursales activas configuradas.');}
$sucursal=q1('SELECT id,nombre,direccion FROM sucursales WHERE id=? AND activa=1',[$sucursalId]);
if(!$sucursal){http_response_code(503);exit('La sucursal actual no está disponible.');}

$metricas=q1('SELECT COUNT(*) operaciones,COALESCE(SUM(total),0) total,COUNT(DISTINCT cajero_id) cajeros
  FROM ventas WHERE sucursal_id=? AND estado="pagada" AND DATE(fecha)=CURDATE()',[$sucursalId]);
$personal=q1('SELECT COUNT(DISTINCT u.id) total,COUNT(DISTINCT CASE WHEN f.id IS NOT NULL THEN u.id END) en_turno
  FROM usuarios u LEFT JOIN fichajes f ON f.usuario_id=u.id AND f.sucursal_id=? AND f.salida IS NULL
  WHERE u.activo=1 AND u.rol<>"propietario" AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=u.id AND us.sucursal_id=?)',[$sucursalId,$sucursalId]);
$movimientos=q('SELECT v.id,v.cliente,v.total,v.pago,v.fecha,u.nombre cajero
  FROM ventas v LEFT JOIN usuarios u ON u.id=v.cajero_id
  WHERE v.sucursal_id=? AND v.estado="pagada" ORDER BY v.fecha DESC LIMIT 8',[$sucursalId])->fetchAll();
$comunicaciones=q('SELECT c.id,c.tipo,c.contenido,c.estado,c.fecha,u.nombre autor
  FROM comunicaciones_sucursal c JOIN usuarios u ON u.id=c.autor_id
  WHERE c.sucursal_id=? ORDER BY c.fecha DESC LIMIT 8',[$sucursalId])->fetchAll();
$ventasSemana=q('SELECT DATE(fecha) dia,COALESCE(SUM(total),0) total FROM ventas
  WHERE sucursal_id=? AND estado="pagada" AND fecha>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
  GROUP BY DATE(fecha) ORDER BY dia',[$sucursalId])->fetchAll();
$bajoStock=(int)q1('SELECT COUNT(*) total FROM stock_sucursales WHERE sucursal_id=? AND stock<=minimo',[$sucursalId])['total'];
$empleados=q('SELECT u.id,u.nombre,u.usuario,u.rol,u.activo,u.sucursal_id,
  (SELECT GROUP_CONCAT(us.sucursal_id ORDER BY us.sucursal_id) FROM usuario_sucursales us WHERE us.usuario_id=u.id) sucursales_asignadas
  FROM usuarios u WHERE u.rol<>"propietario" ORDER BY u.activo DESC,u.nombre')->fetchAll();

head('Portal del dueño');
?>
<style>
 .owner-app{--owner-ink:#173b35;--owner-leaf:#27735d;--owner-muted:#60736b;color:var(--owner-ink)}
 .owner-hero{position:relative;overflow:hidden;border-radius:1.4rem;padding:clamp(1.4rem,4vw,2.4rem);background:radial-gradient(circle at 90% 0,#70b89955,transparent 35%),linear-gradient(125deg,#173b35,#27735d);color:white;box-shadow:0 1rem 3rem #173b3524}
 .owner-hero h1{font-size:clamp(1.7rem,4vw,2.5rem);font-weight:800;letter-spacing:-.035em}
 .owner-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:1rem}
 .owner-card{border:1px solid #e1e9e4;border-radius:1rem;background:white;box-shadow:0 .4rem 1.4rem #173b350b}
 .owner-stat{padding:1.15rem;min-height:132px}
 .owner-stat span{color:var(--owner-muted);font-size:.9rem}.owner-stat strong{display:block;margin-top:.55rem;font-size:clamp(1.5rem,3vw,2rem)}
 .owner-section-title{font-weight:750;letter-spacing:-.02em}
 .owner-chart{height:180px;display:flex;align-items:end;gap:.55rem;padding:.5rem .25rem}
 .owner-chart-item{height:100%;flex:1;display:flex;flex-direction:column;justify-content:end;align-items:center;gap:.5rem;min-width:0}
 .owner-chart-bar{width:min(100%,2.2rem);min-height:4px;border-radius:.55rem .55rem .2rem .2rem;background:linear-gradient(180deg,#70b899,#27735d);transition:height .45s ease}
 .owner-chart-item small{font-size:.72rem;color:var(--owner-muted)}
 @media(max-width:767px){.owner-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
 @media(max-width:420px){.owner-grid{gap:.6rem}.owner-stat{padding:.9rem;min-height:115px}}
 @media(prefers-reduced-motion:reduce){.owner-chart-bar{transition:none}}
</style>
<main class="owner-app">
 <section class="owner-hero mb-4">
  <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
   <div><h1 class="mb-0">Portal del dueño</h1></div>
   <button id="installApp" class="btn btn-light fw-semibold d-none" type="button"><i class="bi bi-download me-1"></i>Instalar app</button>
  </div>
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mt-4">
   <div><small class="d-block opacity-75 mb-1">Sucursal seleccionada</small><strong class="fs-5"><?=e($sucursal['nombre'])?></strong></div>
   <form method="post" class="d-flex gap-2">
    <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="seleccionar_sucursal">
    <select name="sucursal_id" class="form-select" aria-label="Seleccionar sucursal">
     <?php foreach($sucursales as $local): ?><option value="<?=$local['id']?>" <?=(int)$local['id']===$sucursalId?'selected':''?>><?=e($local['nombre'])?></option><?php endforeach ?>
    </select><button class="btn btn-outline-light">Ver</button>
   </form>
  </div>
 </section>

 <section class="owner-grid mb-4" aria-label="Indicadores de hoy">
  <article class="owner-card owner-stat"><span><i class="bi bi-cash-stack me-1"></i>Ventas de hoy</span><strong><?=money($metricas['total'])?></strong><small class="text-muted"><?=$metricas['operaciones']?> operaciones</small></article>
  <article class="owner-card owner-stat"><span><i class="bi bi-receipt me-1"></i>Ticket promedio</span><strong><?=money($metricas['operaciones']?(float)$metricas['total']/(int)$metricas['operaciones']:0)?></strong><small class="text-muted">En esta sucursal</small></article>
  <article class="owner-card owner-stat"><span><i class="bi bi-people me-1"></i>Personal en turno</span><strong><?=$personal['en_turno']?> / <?=$personal['total']?></strong><small class="text-muted">Empleados asignados</small></article>
  <article class="owner-card owner-stat"><span><i class="bi bi-exclamation-triangle me-1"></i>Productos para revisar</span><strong><?=$bajoStock?></strong><small class="text-muted">En o debajo del mínimo</small></article>
 </section>

 <section class="row g-3 mb-4">
  <div class="col-lg-5"><article class="owner-card p-3 p-md-4 h-100">
   <div class="d-flex justify-content-between align-items-center"><h2 class="h5 owner-section-title mb-0">Ritmo de ventas</h2><span class="small text-muted">Últimos 7 días</span></div>
   <?php $maximo=max(array_map(static fn($dia)=>(float)$dia['total'],$ventasSemana)?:[0]); ?>
   <div class="owner-chart" aria-label="Gráfico de ventas de los últimos siete días">
    <?php foreach($ventasSemana as $dia): $altura=$maximo>0?max(4,(int)((float)$dia['total']/$maximo*100)):4; ?>
     <div class="owner-chart-item" title="<?=e(date('d/m',strtotime($dia['dia'])).' · '.money($dia['total']))?>">
      <span class="owner-chart-bar" style="height:<?=$altura?>%"></span><small><?=e(date('D',strtotime($dia['dia'])))?></small>
     </div>
    <?php endforeach ?>
   </div>
  </article></div>
  <div class="col-lg-7"><article id="movimientos" class="owner-card p-3 p-md-4 h-100">
   <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 owner-section-title mb-0">Movimientos recientes</h2><a href="ventas.php" class="small">Ver ventas</a></div>
   <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Hora</th><th>Cajero</th><th>Cliente</th><th class="text-end">Total</th></tr></thead><tbody>
    <?php foreach($movimientos as $movimiento): ?><tr><td><?=e(date('H:i',strtotime($movimiento['fecha'])))?></td><td><?=e($movimiento['cajero']??'—')?></td><td><?=e($movimiento['cliente']?:'Consumidor final')?></td><td class="text-end fw-semibold"><?=money($movimiento['total'])?></td></tr><?php endforeach ?>
    <?php if(!$movimientos): ?><tr><td colspan="4" class="text-center text-muted py-4">Todavía no hay ventas en esta sucursal.</td></tr><?php endif ?>
   </tbody></table></div>
  </article></div>
 </section>

 <section class="row g-3">
  <div class="col-xl-5"><article class="owner-card p-3 p-md-4 mb-3">
   <h2 class="h5 owner-section-title">Enviar mensaje u orden</h2>
   <form method="post">
    <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="enviar"><input type="hidden" name="sucursal_id" value="<?=$sucursalId?>">
    <select name="tipo" class="form-select mb-2"><option value="mensaje">Mensaje</option><option value="orden">Orden de trabajo</option></select>
    <textarea name="contenido" class="form-control mb-2" rows="3" maxlength="2000" placeholder="Escribe para el equipo de esta sucursal" required></textarea>
    <button class="btn btn-success w-100">Enviar al equipo</button>
   </form>
  </article>
  <article id="comunicaciones" class="owner-card p-3 p-md-4">
   <div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h5 owner-section-title mb-0">Comunicaciones</h2><a href="comunicaciones.php" class="small">Abrir bandeja</a></div>
   <?php foreach($comunicaciones as $comunicacion): ?><div class="border-top py-2">
    <div class="d-flex justify-content-between gap-2"><strong><?=e($comunicacion['tipo']==='orden'?'Orden':'Mensaje')?> · <?=e($comunicacion['autor'])?></strong><small class="text-muted"><?=e(date('d/m H:i',strtotime($comunicacion['fecha'])))?></small></div>
    <p class="mb-1"><?=nl2br(e($comunicacion['contenido']))?></p><small class="text-muted">Estado: <?=e($comunicacion['estado'])?></small>
   </div><?php endforeach ?>
   <?php if(!$comunicaciones): ?><p class="text-muted mb-0">No hay mensajes todavía.</p><?php endif ?>
  </article></div>
  <div class="col-xl-7"><article id="personal" class="owner-card p-3 p-md-4 mb-3">
   <h2 class="h5 owner-section-title">Personal y acceso por sucursal</h2>
   <p class="small text-muted">Los jefes pueden tener varias sucursales asignadas.</p>
   <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Empleado</th><th>Rol</th><th>Asignación</th><th>Estado</th><th></th></tr></thead><tbody>
    <?php foreach($empleados as $empleado): ?>
     <?php $asignados=array_map('intval',array_filter(explode(',',(string)$empleado['sucursales_asignadas']))); ?>
     <tr><td><?=e($empleado['nombre'])?><small class="d-block text-muted">@<?=e($empleado['usuario'])?></small></td><td><?=e(etiqueta_rol($empleado['rol']))?></td>
      <td><form method="post" class="d-flex flex-wrap gap-1">
       <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="asignar_sucursales"><input type="hidden" name="usuario_id" value="<?=$empleado['id']?>">
       <?php foreach($sucursales as $local): ?><label class="badge text-bg-light border fw-normal"><input type="checkbox" name="sucursales[]" value="<?=$local['id']?>" <?=in_array((int)$local['id'],$asignados,true)?'checked':''?>> <?=e($local['nombre'])?></label><?php endforeach ?>
       <button class="btn btn-sm btn-outline-primary">Guardar</button>
      </form></td>
      <td><span class="badge <?=$empleado['activo']?'text-bg-success':'text-bg-secondary'?>"><?=$empleado['activo']?'Activo':'Inactivo'?></span></td>
      <td><?php if($empleado['rol']!=='propietario'&&(int)$empleado['id']!==(int)$u['id']): ?><form method="post">
       <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="cambiar_estado_usuario"><input type="hidden" name="usuario_id" value="<?=$empleado['id']?>">
       <button class="btn btn-sm <?=$empleado['activo']?'btn-outline-danger':'btn-outline-success'?>"><?=$empleado['activo']?'Dar de baja':'Reactivar'?></button>
      </form><?php endif ?></td>
     </tr>
    <?php endforeach ?>
   </tbody></table></div>
  </article>
  <article id="sucursales" class="owner-card p-3 p-md-4 mb-3">
   <div class="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-3">
    <div><h2 class="h5 owner-section-title mb-1">Sucursales</h2><p class="small text-muted mb-0">Las sucursales dadas de baja conservan su historial.</p></div>
    <?php if($sucursalEditar): ?><a class="btn btn-sm btn-outline-secondary" href="dueno.php">Cancelar edición</a><?php endif ?>
   </div>
   <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th>Sucursal</th><th>Ubicación</th><th>Contacto</th><th>Estado</th><th></th></tr></thead><tbody>
     <?php foreach($sucursalesTodas as $local): ?>
      <tr><td><strong><?=e($local['nombre'])?></strong><?php if($local['codigo_postal']): ?><small class="d-block text-muted">CP <?=e($local['codigo_postal'])?></small><?php endif ?></td>
       <td><?=e($local['direccion']?:'—')?><?php if($local['localidad']||$local['provincia']): ?><small class="d-block text-muted"><?=e(implode(', ',array_filter([$local['localidad'],$local['provincia']])))?></small><?php endif ?>
         </td>
       <td><?=e($local['telefono']?:'—')?><?php if($local['email']): ?><small class="d-block text-muted"><?=e($local['email'])?></small><?php endif ?></td>
       <td><span class="badge <?=$local['activa']?'text-bg-success':'text-bg-secondary'?>"><?=$local['activa']?'Activa':'De baja'?></span></td>
       <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="?editar_sucursal=<?=$local['id']?>">Editar</a>
        <form method="post" class="d-inline" onsubmit="return confirm('<?=$local['activa']?'¿Dar de baja esta sucursal? Se conservarán sus datos e historial.':'¿Reactivar esta sucursal?'?>')">
         <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="cambiar_estado_sucursal"><input type="hidden" name="sucursal_id" value="<?=$local['id']?>">
         <button class="btn btn-sm <?=$local['activa']?'btn-outline-danger':'btn-outline-success'?>"><?=$local['activa']?'Dar de baja':'Reactivar'?></button>
        </form>
       </td>
      </tr>
     <?php endforeach ?>
    </tbody>
   </table></div>
  </article>
  <article class="owner-card p-3 p-md-4">
   <h2 class="h5 owner-section-title"><?= $sucursalEditar?'Editar sucursal':'Agregar sucursal' ?></h2>
   <?php $formBranch=$sucursalEditar?:['id'=>'','nombre'=>'','direccion'=>'','localidad'=>'','provincia'=>'','codigo_postal'=>'','telefono'=>'','email'=>'']; ?>
   <form method="post" class="row g-3">
    <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="<?=$sucursalEditar?'editar_sucursal':'crear_sucursal'?>">
    <?php if($sucursalEditar): ?><input type="hidden" name="sucursal_id" value="<?=$sucursalEditar['id']?>"><?php endif ?>
    <div class="col-md-6"><label class="form-label">Nombre de la sucursal *</label><input name="nombre" class="form-control" maxlength="100" value="<?=e($formBranch['nombre'])?>" required></div>
    <div class="col-md-6"><label class="form-label">Dirección</label><input name="direccion" class="form-control" maxlength="200" value="<?=e($formBranch['direccion']??'')?>" placeholder="Calle y número"></div>
    <div class="col-md-4"><label for="branchProvince" class="form-label">Provincia</label>
     <select id="branchProvince" name="provincia" class="form-select"><option value="">Seleccionar provincia</option></select>
    </div>
    <div class="col-md-4"><label for="branchDepartment" class="form-label">Departamento</label>
     <select id="branchDepartment" name="departamento" class="form-select"><option value="">Seleccionar departamento</option></select>
    </div>
    <div class="col-md-4"><label class="form-label">Código postal</label><input name="codigo_postal" class="form-control" maxlength="15" value="<?=e($formBranch['codigo_postal']??'')?>"></div>
    <div class="col-md-6"><label class="form-label">Teléfono</label><input name="telefono" type="tel" class="form-control" maxlength="30" value="<?=e($formBranch['telefono']??'')?>"></div>
    <div class="col-md-6"><label class="form-label">Correo electrónico</label><input name="email" type="email" class="form-control" maxlength="150" value="<?=e($formBranch['email']??'')?>"></div>
    <div class="col-12"><button class="btn btn-success"><?= $sucursalEditar?'Guardar cambios':'Crear sucursal' ?></button></div>
   </form>
  </article></div>
 </section>
</main>
<script>
const geografiaAR=<?=json_encode($geografia,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?>;
const provinciaSucursal=document.getElementById('branchProvince');
const departamentoSucursal=document.getElementById('branchDepartment');
const provinciaInicial=<?=json_encode((string)($formBranch['provincia']??''),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?>;
const departamentoInicial=<?=json_encode((string)($formBranch['localidad']??''),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR)?>;
geografiaAR.provincias.forEach(provincia=>{
  const opcion=document.createElement('option');
  opcion.value=provincia.nombre;
  opcion.textContent=provincia.nombre;
  provinciaSucursal.appendChild(opcion);
});
if(provinciaInicial&&!Array.from(provinciaSucursal.options).some(opcion=>opcion.value===provinciaInicial)){
  const opcion=document.createElement('option');
  opcion.value=provinciaInicial;
  opcion.textContent='Actual: '+provinciaInicial;
  provinciaSucursal.appendChild(opcion);
}
provinciaSucursal.value=provinciaInicial;
function cargarDepartamentos(valor=''){
  departamentoSucursal.replaceChildren(new Option('Seleccionar departamento',''));
  const provincia=geografiaAR.provincias.find(item=>item.nombre===provinciaSucursal.value);
  if(provincia){
    provincia.departamentos.forEach(nombre=>{
      departamentoSucursal.add(new Option(nombre,nombre));
    });
  }
  if(valor&&!Array.from(departamentoSucursal.options).some(opcion=>opcion.value===valor)){
    departamentoSucursal.add(new Option('Actual: '+valor,valor));
  }
  departamentoSucursal.value=valor;
}
cargarDepartamentos(departamentoInicial);
provinciaSucursal.addEventListener('change',()=>cargarDepartamentos());
let eventoInstalacion;
window.addEventListener('beforeinstallprompt',evento=>{
  evento.preventDefault();
  eventoInstalacion=evento;
  document.getElementById('installApp').classList.remove('d-none');
});
document.getElementById('installApp').addEventListener('click',async()=>{
  if(!eventoInstalacion)return;
  eventoInstalacion.prompt();
  await eventoInstalacion.userChoice;
  eventoInstalacion=null;
  document.getElementById('installApp').classList.add('d-none');
});
</script>
<?php foot(); ?>
