<?php
require 'lib.php';

$codigosMostrar=null;
$pendiente=(int)($_SESSION['mfa_uid']??0);
if(!$pendiente){
  $codigosMostrar=$_SESSION['mfa_recovery_once']??null;
  unset($_SESSION['mfa_recovery_once']);
  if(!$codigosMostrar){header('Location: login.php');exit;}
}

$u=$pendiente?q1('SELECT * FROM usuarios WHERE id=? AND activo=1',[$pendiente]):null;
if($pendiente&&(!$u||!in_array($u['rol'],['propietario','jefe','supervisor'],true))){
  unset($_SESSION['mfa_uid']);
  header('Location: login.php');
  exit;
}

$error='';
$esAlta=$u&&(int)$u['otp_enabled']!==1;
if($_SERVER['REQUEST_METHOD']==='POST'&&$u){
  chk();
  $codigo=trim((string)($_POST['codigo']??''));
  $ip=$_SERVER['REMOTE_ADDR']??'';
  $intentos=(int)q1('SELECT COUNT(*) c FROM intentos WHERE (usuario=? OR ip=?) AND fecha>NOW()-INTERVAL 30 SECOND',[$u['usuario'],$ip])['c'];
  if($intentos>=20)$error='Demasiados intentos. Esperá 30 segundos.';
  $contador=verificar_totp($u['otp_secret']??'',$codigo,(int)$u['otp_last_counter']);
  $codigos=json_decode($u['otp_recovery_codes']??'[]',true)?:[];
  $indiceRecuperacion=null;

  if($contador===false&&!$esAlta){
    foreach($codigos as $indice=>$hash){
      if(password_verify($codigo,$hash)){$indiceRecuperacion=$indice;break;}
    }
  }

  if(!$error&&($contador!==false||$indiceRecuperacion!==null)){
    $nuevosCodigos=[];
    if($esAlta){
      for($i=0;$i<8;$i++){
        $nuevo=strtoupper(bin2hex(random_bytes(5)));
        $nuevosCodigos[]=$nuevo;
        $codigos[]=password_hash($nuevo,PASSWORD_DEFAULT);
      }
      q('UPDATE usuarios SET otp_enabled=1,otp_last_counter=?,otp_recovery_codes=? WHERE id=?',
        [$contador,json_encode($codigos),$u['id']]);
    }elseif($indiceRecuperacion!==null){
      unset($codigos[$indiceRecuperacion]);
      q('UPDATE usuarios SET otp_recovery_codes=? WHERE id=?',[json_encode(array_values($codigos)),$u['id']]);
    }else{
      $actualizado=q('UPDATE usuarios SET otp_last_counter=? WHERE id=? AND otp_last_counter<?',
        [$contador,$u['id'],$contador])->rowCount();
      if(!$actualizado){$error='Ese código ya fue utilizado. Esperá al siguiente y probá de nuevo.';}
    }

    if(!$error){
      registrar_acceso($u['usuario'],'mfa_ok',$u['id']);
      completar_login($u);
      if($nuevosCodigos){
        $_SESSION['mfa_recovery_once']=$nuevosCodigos;
        header('Location: mfa.php');
      }else{
        header('Location: dashboard.php');
      }
      exit;
    }
  }else{
    if(!$error){
      q('INSERT INTO intentos(usuario,ip) VALUES(?,?)',[$u['usuario'],$ip]);
      registrar_acceso($u['usuario'],'mfa_fallido',$u['id']);
      $error='Código incorrecto o vencido.';
    }
  }
}

head('Verificación en dos pasos'); ?>
<main class="mx-auto mt-5" style="max-width:520px">
 <div class="card shadow-sm"><div class="card-body p-4">
  <?php if($codigosMostrar): ?>
   <h1 class="h4">Guardá tus códigos de recuperación</h1>
   <p class="text-muted">Se muestran una sola vez. Cada código sirve para un único acceso.</p>
   <ul class="list-group mb-3">
    <?php foreach($codigosMostrar as $codigo): ?><li class="list-group-item font-monospace"><?=e($codigo)?></li><?php endforeach ?>
   </ul>
   <a class="btn btn-primary w-100" href="dashboard.php">Continuar</a>
  <?php else: ?>
   <h1 class="h4"><?=$esAlta?'Activar verificación en dos pasos':'Verificación en dos pasos'?></h1>
   <?php if($esAlta): ?>
    <p>Agregá esta clave a una aplicación autenticadora y escribí el código de seis dígitos que te muestre.</p>
    <label class="form-label" for="secreto">Clave de configuración</label>
    <input id="secreto" class="form-control form-control-lg font-monospace mb-3" value="<?=e($u['otp_secret'])?>" readonly>
   <?php else: ?>
    <p class="text-muted">Ingresá el código de seis dígitos de tu aplicación autenticadora o un código de recuperación.</p>
   <?php endif ?>
   <?php if($error): ?><div class="alert alert-danger"><?=e($error)?></div><?php endif ?>
   <form method="post">
    <input type="hidden" name="c" value="<?=csrf()?>">
    <label class="form-label" for="codigo">Código</label>
    <input id="codigo" name="codigo" class="form-control form-control-lg mb-3" inputmode="numeric" autocomplete="one-time-code" required autofocus>
    <button class="btn btn-success btn-lg w-100">Verificar</button>
   </form>
  <?php endif ?>
 </div></div>
</main>
<?php foot();