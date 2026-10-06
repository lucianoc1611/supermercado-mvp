<?php
require 'lib.php';
$usuario=user();
if($usuario){
  registrar_acceso($usuario['usuario'],'logout',$usuario['id']);
  $fichaje=q1('SELECT id FROM fichajes WHERE usuario_id=? AND sucursal_id=? AND salida IS NULL ORDER BY entrada DESC LIMIT 1',[$usuario['id'],sucursal_actual_id()]);
  if($fichaje) q('UPDATE fichajes SET salida=NOW() WHERE id=? AND sucursal_id=?',[$fichaje['id'],sucursal_actual_id()]);
}
$_SESSION=[];
session_destroy();
setcookie(session_name(),'',[
  'expires'=>time()-42000,
  'path'=>session_get_cookie_params()['path']?:'/',
  'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off',
  'httponly'=>true,
  'samesite'=>'Strict'
]);
header('Location: login.php');
exit;
