<?php
require 'lib.php';
$u=need('personal','ver');
$sucursalId=sucursal_actual_id();
$puedeAdministrar=in_array($u['rol'],['propietario','jefe'],true);

function es_ultimo_jefe_activo($usuarioId){
  return (int)q1('SELECT COUNT(*) total FROM usuario_sucursales propias
    JOIN sucursales s ON s.id=propias.sucursal_id AND s.activa=1
    WHERE propias.usuario_id=? AND NOT EXISTS(
      SELECT 1 FROM usuario_sucursales otras
      JOIN usuarios u ON u.id=otras.usuario_id
      WHERE otras.sucursal_id=propias.sucursal_id AND u.rol="jefe" AND u.activo=1 AND u.id<>?
    )',[$usuarioId,$usuarioId])['total']>0;
}

if($_SERVER['REQUEST_METHOD']=='POST'){ 
  chk();
  
  if(($_POST['a']??'')=='toggle'){
    $t=q1('SELECT u.* FROM usuarios u WHERE u.id=? AND u.rol<>"propietario" AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=u.id AND us.sucursal_id=?)',[(int)$_POST['id'],$sucursalId]);
    $ultimoJefe=$t&&$t['rol']==='jefe'&&$t['activo']&&es_ultimo_jefe_activo($t['id']);
    if($t && $puedeAdministrar && (int)$t['id']!==(int)$u['id'] && !$ultimoJefe){
      q('UPDATE usuarios SET activo=1-activo WHERE id=? AND EXISTS(SELECT 1 FROM usuario_sucursales WHERE usuario_id=? AND sucursal_id=?)',[$t['id'],$t['id'],$sucursalId]);
      auditar('toggle_usuario','usuarios',$t['id'],['activo'=>1-$t['activo']]);
      $_SESSION['m']='Estado actualizado ✔';
    }else{
      $_SESSION['m']='No se puede desactivar la propia cuenta ni al último jefe activo.';
    }
  }elseif(($_POST['a']??'')=='crear' && tiene_permiso('personal','crear')){
    $usuario=strtolower(trim((string)($_POST['usuario']??'')));
    $nombre=trim((string)($_POST['nombre']??''));
    $clave=(string)($_POST['clave']??'');
    $rol=$_POST['rol']??'';
    $rolesPermitidos=$puedeAdministrar
      ? ['jefe','supervisor','encargado','repositor','cajero']
      : ['encargado','repositor','cajero'];
    if(!$nombre || !preg_match('/^[a-z0-9_]{3,100}$/',$usuario) || strlen($clave)<12 || !in_array($rol,$rolesPermitidos,true)){
      $_SESSION['m']='Datos inválidos (usuario desde 3 caracteres y clave mínimo 12).';
    }else{
      try{
        db()->beginTransaction();
        q('INSERT INTO usuarios(usuario,nombre,clave,rol,sucursal_id) VALUES(?,?,?,?,?)',
          [$usuario,$nombre,password_hash($clave,PASSWORD_DEFAULT),$rol,$sucursalId]);
        $usuarioNuevo=(int)db()->lastInsertId();
        q('INSERT INTO usuario_sucursales(usuario_id,sucursal_id) VALUES(?,?)',[$usuarioNuevo,$sucursalId]);
        auditar('crear_usuario','usuarios',$usuarioNuevo,['usuario'=>$usuario,'rol'=>$rol]);
        db()->commit();
        $_SESSION['m']='Empleado creado ✔';
      }catch(PDOException $x){ 
        if(db()->inTransaction())db()->rollBack();
        $_SESSION['m']='Ese usuario ya existe.'; 
      }
    }
  }elseif(($_POST['a']??'')=='editar' && tiene_permiso('personal','editar')){
    $id=(int)($_POST['id']??0);
    $emp=q1('SELECT u.id,u.usuario,u.nombre,u.rol,u.activo FROM usuarios u WHERE u.id=? AND u.rol<>"propietario" AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=u.id AND us.sucursal_id=?)',[$id,$sucursalId]);
    $nombre=trim((string)($_POST['nombre']??''));
    $rol=$_POST['rol']??'';
    $clave=(string)($_POST['clave']??'');
    $rolesPermitidos=['jefe','supervisor','encargado','repositor','cajero'];
    $ultimoJefe=$emp&&$emp['rol']==='jefe'&&$emp['activo']&&$rol!=='jefe'&&es_ultimo_jefe_activo($id);
    if(!$emp||!$puedeAdministrar||!$nombre||!in_array($rol,$rolesPermitidos,true)
      ||($id===(int)$u['id']&&$rol!=='jefe')||$ultimoJefe||($clave!==''&&strlen($clave)<12)){
      $_SESSION['m']='No se pudo actualizar. Revisa el rol, nombre y clave (mínimo 12 caracteres).';
    }else{
      q('UPDATE usuarios SET nombre=?,rol=? WHERE id=?',[$nombre,$rol,$id]);
      if($clave!=='')q('UPDATE usuarios SET clave=? WHERE id=?',[password_hash($clave,PASSWORD_DEFAULT),$id]);
      auditar('editar_usuario','usuarios',$id,['nombre'=>$nombre,'rol'=>$rol,'clave_cambiada'=>$clave!=='']);
      $_SESSION['m']='Empleado actualizado ✔';
    }
  }
  header('Location: personal.php'); exit; 
}

$empleados=q('
  SELECT u.id,u.usuario,u.nombre,u.rol,u.activo,u.fecha_creacion,
    (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE,entrada,COALESCE(salida,NOW()))),0) FROM fichajes WHERE usuario_id=u.id AND sucursal_id=? AND entrada>=DATE_FORMAT(NOW(),"%Y-%m-01")) horas_mes,
    (SELECT COALESCE(SUM(total),0) FROM ventas WHERE cajero_id=u.id AND sucursal_id=? AND fecha>=DATE_FORMAT(NOW(),"%Y-%m-01")) ventas_mes,
    (SELECT COUNT(*) FROM fichajes WHERE usuario_id=u.id AND sucursal_id=? AND salida IS NULL) en_servicio,
    (SELECT COUNT(*) FROM usuario_sucursales propias JOIN sucursales s ON s.id=propias.sucursal_id AND s.activa=1
     WHERE propias.usuario_id=u.id AND NOT EXISTS(
       SELECT 1 FROM usuario_sucursales otras JOIN usuarios otro ON otro.id=otras.usuario_id
       WHERE otras.sucursal_id=propias.sucursal_id AND otro.rol="jefe" AND otro.activo=1 AND otro.id<>u.id
     )) ultimo_jefe
  FROM usuarios u 
  WHERE u.rol<>"propietario" AND EXISTS(SELECT 1 FROM usuario_sucursales us WHERE us.usuario_id=u.id AND us.sucursal_id=?)
  ORDER BY activo DESC, nombre
',[$sucursalId,$sucursalId,$sucursalId,$sucursalId])->fetchAll();

head('Personal'); ?>

<div class="row g-3">
 <div class="col-lg-8"><div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
  <thead class="table-light"><tr>
    <th>Empleado</th><th>Usuario</th><th>Rol</th><th>Estado</th>
    <th class="text-center">Horas (mes)</th><th class="text-end">Ventas (mes)</th><th></th>
  </tr></thead>
  <tbody>
  <?php foreach($empleados as $x): ?>
   <tr class="<?=$x['activo']?'':'text-muted'?>">
    <td><?=e($x['nombre'])?></td>
    <td><code><?=e($x['usuario'])?></code></td>
    <td><span class="badge bg-secondary"><?=e(etiqueta_rol($x['rol']))?></span></td>
    <td><?=$x['en_servicio']?'<span class="badge bg-success">🟢 En servicio</span>':'<span class="badge bg-secondary">Fuera</span>'?></td>
    <td class="text-center"><?=fh($x['horas_mes'])?></td>
    <td class="text-end"><?=money($x['ventas_mes'])?></td>
    <td class="text-end">
     <?php if($puedeAdministrar): ?>
      <button class="btn btn-sm btn-outline-secondary" onclick="editarUsuario(<?=e(json_encode($x,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP))?>)">Editar</button>
      <?php if((int)$x['id']!==(int)$u['id']&&!($x['rol']==='jefe'&&$x['activo']&&$x['ultimo_jefe']>0)): ?>
      <form method="post" class="d-inline">
       <input type="hidden" name="c" value="<?=csrf()?>">
       <input type="hidden" name="a" value="toggle">
       <input type="hidden" name="id" value="<?=$x['id']?>">
       <button class="btn btn-sm <?=$x['activo']?'btn-outline-danger':'btn-outline-success'?>"><?=$x['activo']?'Desactivar':'Activar'?></button>
      </form>
      <?php else: ?><small class="text-muted">Protegida</small><?php endif ?>
     <?php elseif($u['rol']=='supervisor'): ?>
      <a href="#" class="text-muted">Ver</a>
     <?php endif ?>
    </td>
   </tr>
  <?php endforeach ?>
  </tbody>
 </table></div></div></div>

 <div class="col-lg-4">
  <?php if(tiene_permiso('personal','crear')): ?>
   <div class="card shadow-sm"><div class="card-body">
    <h5>Nuevo empleado</h5>
    <form method="post">
     <input type="hidden" name="c" value="<?=csrf()?>">
     <input type="hidden" name="a" value="crear">
     <input name="nombre" class="form-control mb-2" placeholder="Nombre" required>
     <input name="usuario" class="form-control mb-2" placeholder="Usuario (login)" required pattern="[a-z0-9_]+" title="Solo minúsculas y números">
     <select name="rol" class="form-select mb-2" required>
      <option value="">Selecciona rol</option>
      <?php if($puedeAdministrar): ?>
      <option value="jefe">Jefe de sucursal</option>
      <option value="supervisor">Gerente</option>
       <option value="encargado">Encargado</option>
       <option value="repositor">Repositor</option>
       <option value="cajero">Cajero</option>
      <?php else: ?>
       <option value="encargado">Encargado</option>
       <option value="repositor">Repositor</option>
       <option value="cajero">Cajero</option>
      <?php endif ?>
     </select>
    <input type="password" name="clave" class="form-control mb-3" placeholder="Contraseña (mín 12)" required minlength="12" autocomplete="new-password">
     <button class="btn btn-success w-100">Crear</button>
    </form>
   </div></div>
  <?php endif ?>

  <div class="card shadow-sm mt-3"><div class="card-body">
   <h5>Permisos por rol</h5>
  <small class="d-block mb-2"><b>Propietario:</b> Acceso global a sucursales y administración</small>
  <small class="d-block mb-2"><b>Jefe de sucursal:</b> Administra una o más sucursales</small>
  <small class="d-block mb-2"><b>Gerente:</b> Operación, reportes y altas limitadas de personal</small>
   <small class="d-block mb-2"><b>Encargado:</b> Pedidos y cajas</small>
   <small class="d-block mb-2"><b>Repositor:</b> Stock y productos</small>
   <small><b>Cajero:</b> Ventas y facturas</small>
  </div></div>
 </div>
</div>

<div class="modal fade" id="editModal"><div class="modal-dialog"><form method="post" class="modal-content">
 <div class="modal-header"><h5 class="modal-title">Editar empleado</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
 <div class="modal-body">
  <input type="hidden" name="c" value="<?=csrf()?>">
  <input type="hidden" name="a" value="editar">
  <input type="hidden" name="id">
  <label class="form-label" for="editar_nombre">Nombre</label>
  <input id="editar_nombre" name="nombre" class="form-control mb-3" required>
  <label class="form-label" for="editar_rol">Cargo</label>
  <select id="editar_rol" name="rol" class="form-select mb-3" required>
   <option value="jefe">Jefe de sucursal</option><option value="supervisor">Gerente</option>
   <option value="encargado">Encargado</option><option value="repositor">Repositor</option><option value="cajero">Cajero</option>
  </select>
  <label class="form-label" for="editar_clave">Nueva contraseña (opcional)</label>
  <input id="editar_clave" name="clave" type="password" class="form-control" minlength="12" autocomplete="new-password">
  <small class="text-muted">Deja vacío para conservar la contraseña actual.</small>
 </div>
 <div class="modal-footer"><button class="btn btn-success">Guardar</button></div>
</form></div></div>

<script>
const modal=document.getElementById('editModal');
const M=()=>bootstrap.Modal.getOrCreateInstance(modal);
function editarUsuario(u){
  modal.querySelector('[name="id"]').value=u.id;
  modal.querySelector('[name="nombre"]').value=u.nombre;
  modal.querySelector('[name="rol"]').value=u.rol;
  modal.querySelector('[name="clave"]').value='';
  M().show();
}
</script>
<?php foot();
