<?php
require 'lib.php';
$u=need_login();
$rolesPermitidos=['propietario','jefe','supervisor','encargado','repositor','cajero'];
if(!in_array($u['rol'],$rolesPermitidos,true)){http_response_code(403);exit('No tenés acceso a las comunicaciones.');}
$sucursalId=sucursal_actual_id();
if(!$sucursalId||!usuario_puede_ver_sucursal($u,$sucursalId)){http_response_code(403);exit('No tenés una sucursal asignada.');}

if($_SERVER['REQUEST_METHOD']==='POST'){
  chk();
  $accion=(string)($_POST['accion']??'');
  $comunicacionId=(int)($_POST['comunicacion_id']??0);
  $comunicacion=q1('SELECT id,sucursal_id,estado FROM comunicaciones_sucursal WHERE id=?',[$comunicacionId]);
  if(!$comunicacion||(int)$comunicacion['sucursal_id']!==$sucursalId){
    $_SESSION['m']='El mensaje ya no está disponible para esta sucursal.';
  }elseif($accion==='responder'){
    $contenido=trim((string)($_POST['contenido']??''));
    if($contenido===''||mb_strlen($contenido)>2000){
      $_SESSION['m']='La respuesta debe tener entre 1 y 2000 caracteres.';
    }else{
      db()->beginTransaction();
      q('INSERT INTO comunicaciones_sucursal(sucursal_id,autor_id,respuesta_a,tipo,contenido) VALUES(?,?,?,"mensaje",?)',
        [$sucursalId,$u['id'],$comunicacionId,$contenido]);
      q('UPDATE comunicaciones_sucursal SET estado="leido" WHERE id=? AND estado="enviado"',[$comunicacionId]);
      db()->commit();
      $_SESSION['m']='Respuesta enviada.';
    }
  }elseif($accion==='completar'&&in_array($u['rol'],['propietario','jefe','supervisor','encargado'],true)){
    q('UPDATE comunicaciones_sucursal SET estado="completado" WHERE id=?',[$comunicacionId]);
    $_SESSION['m']='Orden marcada como completada.';
  }elseif($accion==='leido'){
    q('UPDATE comunicaciones_sucursal SET estado="leido" WHERE id=? AND estado="enviado"',[$comunicacionId]);
    $_SESSION['m']='Mensaje marcado como leído.';
  }else{
    http_response_code(403);
    exit('Acción no permitida.');
  }
  header('Location: comunicaciones.php');
  exit;
}

$mensajes=q('SELECT c.id,c.tipo,c.contenido,c.estado,c.fecha,c.respuesta_a,c.autor_id autor_id,u.nombre autor,u.rol
  FROM comunicaciones_sucursal c JOIN usuarios u ON u.id=c.autor_id
  WHERE c.sucursal_id=? ORDER BY c.fecha DESC LIMIT 100',[$sucursalId])->fetchAll();
$sucursal=q1('SELECT nombre FROM sucursales WHERE id=?',[$sucursalId]);
head('Mensajes y órdenes');
?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
 <div><h1 class="h3 mb-1">Mensajes y órdenes</h1><p class="text-muted mb-0"><?=e($sucursal['nombre']??'Sucursal')?></p></div>
 <?php if($u['rol']==='propietario'): ?><a href="dueno.php" class="btn btn-outline-success">Volver al portal del dueño</a><?php endif ?>
</div>
<?php if(!$mensajes): ?><div class="alert alert-info">Todavía no hay comunicaciones para esta sucursal.</div><?php endif ?>
<div class="d-grid gap-3">
 <?php foreach($mensajes as $mensaje): ?>
  <article class="card shadow-sm <?=$mensaje['tipo']==='orden'?'border-warning':''?>">
   <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2">
     <div><span class="badge <?=$mensaje['tipo']==='orden'?'text-bg-warning':'text-bg-primary'?>"><?=e($mensaje['tipo']==='orden'?'Orden':'Mensaje')?></span>
      <strong class="ms-2"><?=e($mensaje['autor'])?></strong><small class="text-muted ms-2"><?=e(etiqueta_rol($mensaje['rol']))?></small></div>
     <div class="text-end"><span class="badge text-bg-light"><?=e($mensaje['estado'])?></span><small class="d-block text-muted"><?=e(date('d/m/Y H:i',strtotime($mensaje['fecha'])))?></small></div>
    </div>
    <?php if($mensaje['respuesta_a']): ?><p class="small text-muted mt-2 mb-1">Respuesta al mensaje #<?=$mensaje['respuesta_a']?></p><?php endif ?>
    <p class="mb-3 mt-3"><?=nl2br(e($mensaje['contenido']))?></p>
    <div class="d-flex flex-wrap gap-2">
     <?php if($mensaje['estado']==='enviado'&&(int)$mensaje['autor_id']!==(int)$u['id']): ?>
      <form method="post"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="leido"><input type="hidden" name="comunicacion_id" value="<?=$mensaje['id']?>"><button class="btn btn-sm btn-outline-secondary">Marcar leído</button></form>
     <?php endif ?>
     <?php if($mensaje['tipo']==='orden'&&$mensaje['estado']!=='completado'&&in_array($u['rol'],['propietario','jefe','supervisor','encargado'],true)): ?>
      <form method="post"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="completar"><input type="hidden" name="comunicacion_id" value="<?=$mensaje['id']?>"><button class="btn btn-sm btn-success">Completar orden</button></form>
     <?php endif ?>
    </div>
    <?php if((int)$mensaje['autor_id']!==(int)$u['id']): ?>
     <form method="post" class="mt-3 border-top pt-3">
      <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="responder"><input type="hidden" name="comunicacion_id" value="<?=$mensaje['id']?>">
      <label class="form-label" for="respuesta-<?=$mensaje['id']?>">Responder</label>
      <div class="input-group"><input id="respuesta-<?=$mensaje['id']?>" name="contenido" class="form-control" maxlength="2000" required placeholder="Escribe una respuesta"><button class="btn btn-outline-primary">Enviar</button></div>
     </form>
    <?php endif ?>
   </div>
  </article>
 <?php endforeach ?>
</div>
<?php foot(); ?>
