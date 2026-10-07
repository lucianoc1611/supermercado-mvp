<?php
require 'lib.php';

// Verificar si ya está configurado
if(cfg()){
  header('Location: login.php');
  exit;
}

$error='';
if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  $razon=trim($_POST['razon_social']??'');
  $usr=strtolower(trim((string)($_POST['usuario']??'')));
  $nom=trim($_POST['nombre']??'');
  $clave=$_POST['clave']??'';
  $clave2=$_POST['clave2']??'';
  
  if(!$razon || !preg_match('/^[a-z0-9_]{3,100}$/',$usr) || !$nom || !$clave){
    $error='Todos los campos son requeridos.';
  }elseif(strlen($clave)<12){
    $error='La contraseña debe tener al menos 12 caracteres.';
  }elseif($clave!==$clave2){
    $error='Las contraseñas no coinciden.';
  }else{
    try{
      db()->beginTransaction();
      $sucursalPrincipal=q1('SELECT id FROM sucursales WHERE activa=1 ORDER BY id LIMIT 1');
      if(!$sucursalPrincipal)throw new RuntimeException('Primero ejecuta la migración para crear la sucursal principal.');
      $sucursalId=(int)$sucursalPrincipal['id'];
      q('INSERT INTO config(razon_social,sucursal_id) VALUES(?,?)',[$razon,$sucursalId]);
      
      q('INSERT INTO usuarios(usuario,nombre,clave,rol,sucursal_id) VALUES(?,?,?,?,?)',
        [$usr,$nom,password_hash($clave,PASSWORD_DEFAULT),'jefe',$sucursalId]);
      q('INSERT INTO usuario_sucursales(usuario_id,sucursal_id) VALUES(?,?)',[db()->lastInsertId(),$sucursalId]);
      
      db()->commit();
      $_SESSION['m']='Sistema configurado ✔ Inicia sesión.';
      header('Location: login.php');
      exit;
    }catch(Throwable $x){
      if(db()->inTransaction())db()->rollBack();
      registrar_error_aplicacion('Instalación inicial fallida: '.$x->getMessage());
      $error='No se pudo completar la instalación. Revisa el registro de errores.';
    }
  }
}

head('Instalación'); ?>

<div class="d-flex align-items-center justify-content-center" style="min-height:100vh">
 <div class="mx-auto" style="max-width:450px">
  <div class="card shadow-lg">
   <div class="card-body p-5">
    <h3 class="text-center mb-4">🛒 Instalación</h3>
    
    <?php if($error): ?>
     <div class="alert alert-danger"><?=e($error)?></div>
    <?php endif ?>
    
    <form method="post">
      <input type="hidden" name="c" value="<?=csrf()?>">
     <div class="mb-3">
      <label class="form-label"><b>Razón social del supermercado</b></label>
      <input type="text" name="razon_social" class="form-control form-control-lg" 
             placeholder="Ej: Supermercado El Éxito" required autofocus>
     </div>
     
     <hr>
     
    <p class="text-muted mb-3"><b>Jefe de sucursal</b></p>
     
     <div class="mb-3">
      <label class="form-label">Usuario (para login)</label>
            <input type="text" name="usuario" class="form-control" 
              placeholder="Ej: admin" pattern="[a-z0-9_]{3,100}" minlength="3" maxlength="100" required autocomplete="username">
            <small class="text-muted">Solo minúsculas, números y guion bajo; mínimo 3 caracteres</small>
     </div>
     
     <div class="mb-3">
      <label class="form-label">Nombre del jefe de sucursal</label>
      <input type="text" name="nombre" class="form-control" 
             required>
     </div>
     
     <div class="mb-3">
      <label class="form-label">Contraseña</label>
      <input type="password" name="clave" class="form-control" 
             required minlength="12" autocomplete="new-password">
     </div>
     
     <div class="mb-4">
      <label class="form-label">Confirmar contraseña</label>
      <input type="password" name="clave2" class="form-control" 
             placeholder="Repite la contraseña" required>
     </div>
     
     <button type="submit" class="btn btn-success btn-lg w-100 mb-3">
      Instalar sistema
     </button>
    </form>
    
    <div class="alert alert-info mt-4">
     <small>
      <b>📝 Nota:</b> Después de instalar podrás agregar más empleados 
      desde el panel de personal.
     </small>
    </div>
   </div>
  </div>
 </div>
</div>

<?php foot();
