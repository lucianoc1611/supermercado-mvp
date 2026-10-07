<?php
require 'lib.php';
try{ cfg(); }catch(Throwable $x){ header('Location: setup.php'); exit; }
$sesionActual=user();
if($sesionActual){
  $destino=$sesionActual['rol']==='repositor'?'reponer.php':($sesionActual['rol']==='cajero'?'caja.php':'dashboard.php');
  head('Sesión activa');
  echo '<main class="mx-auto mt-5" style="max-width:520px"><div class="card shadow-sm"><div class="card-body p-4 text-center"><h1 class="h4">Ya tienes una sesión iniciada</h1><p class="text-muted">'.e(etiqueta_rol($sesionActual['rol'])).'</p><a class="btn btn-primary btn-lg w-100" href="'.e($destino).'">Continuar al panel</a></div></div></main>';
  foot();
  exit;
}
if(!empty($_SESSION['mfa_uid'])){
  header('Location: mfa.php');
  exit;
}

$err='';
if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  $usr=strtolower(trim((string)($_POST['usuario']??'')));
  $clave=(string)($_POST['clave']??'');
  $ip=$_SERVER['REMOTE_ADDR'];
  
  // Verificar intentos fallidos
  $n=q1('SELECT COUNT(*) c FROM intentos WHERE (usuario=? OR ip=?) AND fecha>NOW()-INTERVAL 30 SECOND',[$usr,$ip])['c'];
  if($n>=20){
    registrar_acceso($usr,'login_fallido');
    $err='Demasiados intentos. Esperá 30 segundos.';
  }else{
    // Buscar usuario
    $u=q1('SELECT * FROM usuarios WHERE usuario=? AND activo=1',[$usr]);
    
    if($u && password_verify($clave,$u['clave'])){
      session_regenerate_id(true);
      $_SESSION['c']=bin2hex(random_bytes(32));
      if(in_array($u['rol'],['propietario','jefe','supervisor'],true)){
        if(empty($u['otp_secret'])){
          $secreto=base32_codificar(random_bytes(20));
          q('UPDATE usuarios SET otp_secret=?,otp_enabled=0,otp_last_counter=-1 WHERE id=?',[$secreto,$u['id']]);
        }
        $_SESSION['mfa_uid']=(int)$u['id'];
        header('Location: mfa.php');
        exit;
      }
      completar_login($u);
      header('Location: dashboard.php');
      exit;
    }
    
    // Login fallido
    q('INSERT INTO intentos(usuario,ip) VALUES(?,?)',[$usr,$ip]);
    registrar_acceso($usr,'login_fallido',$u['id']??null);
    $err='Usuario o contraseña incorrectos.';
  }
}

head('Ingreso'); ?>
<div class="mx-auto mt-5" style="max-width:380px"><div class="card shadow-sm"><div class="card-body p-4">
<h1 class="text-center mb-4 codex-logo">Codex</h1>
<?php if($err) echo '<div class="alert alert-danger">'.e($err).'</div>'; ?>
<form method="post">
 <input type="hidden" name="c" value="<?=csrf()?>">
 <div class="mb-3">
  <label class="form-label">Usuario</label>
  <input type="text" name="usuario" class="form-control" required autofocus autocomplete="username">
 </div>
 <div class="mb-3">
  <label class="form-label">Contraseña</label>
  <input type="password" name="clave" class="form-control" required autocomplete="current-password">
 </div>
 <button type="submit" class="btn btn-success w-100 btn-lg">Ingresar</button>
</form></div></div></div>
<?php foot();
