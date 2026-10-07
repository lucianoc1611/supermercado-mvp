<?php
define('APP_ENV',getenv('APP_ENV')?:'development');
define('DB_H', getenv('MYSQLHOST') ?: (getenv('SUPERMERCADO_DB_HOST') ?: '127.0.0.1'));
define('DB_N', getenv('MYSQLDATABASE') ?: (getenv('SUPERMERCADO_DB_NAME') ?: 'railway'));
define('DB_U', getenv('MYSQLUSER') ?: (getenv('SUPERMERCADO_DB_USER') ?: 'root'));
define('DB_P', getenv('MYSQLPASSWORD') ?: (getenv('SUPERMERCADO_DB_PASSWORD') ?: ''));
if(APP_ENV==='production'&&(DB_U==='root'||DB_P==='')){
  http_response_code(500);
  exit('Configuración segura de base de datos requerida.');
}
if(APP_ENV==='production'){
  $https=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
  if(getenv('SUPERMERCADO_TRUST_CLOUDFLARE_PROXY')==='1'&&!empty($_SERVER['HTTP_CF_RAY'])){
    $datosCloudflare=json_decode((string)($_SERVER['HTTP_CF_VISITOR']??''),true);
    $https=$https||is_array($datosCloudflare)&&strtolower((string)($datosCloudflare['scheme']??''))==='https';
  }
  $https=$https||(getenv('VERCEL')&&($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
  if($https)header('Strict-Transport-Security: max-age=31536000');
  if(!$https){
    $url=getenv('SUPERMERCADO_PUBLIC_URL')?:'';
    $partes=parse_url($url);
    if(!$partes||($partes['scheme']??'')!=='https'||empty($partes['host'])||isset($partes['user'])||isset($partes['pass'])){
      http_response_code(500);
      exit('Configura SUPERMERCADO_PUBLIC_URL con el dominio HTTPS público.');
    }
    $host=$partes['host'].(isset($partes['port'])?':'.$partes['port']:'');
    $ruta=$_SERVER['REQUEST_URI']??'/';
    if(!is_string($ruta)||$ruta===''||$ruta[0]!=='/'||preg_match('/[\r\n]/',$ruta))$ruta='/';
    header('Location: https://'.$host.$ruta,true,308);
    exit;
  }
}

function db(){
  static $p;
  $opciones = [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ];
  if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
    $opciones[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET time_zone='-03:00'";
  }
  return $p??=new PDO(
    'mysql:host='.DB_H.';dbname='.DB_N.';charset=utf8mb4',
    DB_U, DB_P,
    $opciones
  );
}

class DatabaseSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface{
  private $sessionId;

  public function open(string $path,string $name):bool{return true;}

  public function close():bool{
    $this->releaseLock();
    return true;
  }

  public function read(string $id):string{
    $this->lock($id);
    $session=q1('SELECT data FROM app_sessions WHERE id=? AND last_activity>?',[$id,time()-ini_get('session.gc_maxlifetime')]);
    return $session?(string)$session['data']:'';
  }

  public function write(string $id,string $data):bool{
    $this->lock($id);
    q('INSERT INTO app_sessions(id,data,last_activity) VALUES(?,?,?)
      ON DUPLICATE KEY UPDATE data=VALUES(data),last_activity=VALUES(last_activity)',[$id,$data,time()]);
    return true;
  }

  public function destroy(string $id):bool{
    $this->lock($id);
    q('DELETE FROM app_sessions WHERE id=?',[$id]);
    return true;
  }

  public function gc(int $maxLifetime):int{
    return q('DELETE FROM app_sessions WHERE last_activity<?',[time()-$maxLifetime])->rowCount();
  }

  public function validateId(string $id):bool{
    $this->lock($id);
    return (bool)q1('SELECT id FROM app_sessions WHERE id=? AND last_activity>?',[$id,time()-ini_get('session.gc_maxlifetime')]);
  }

  public function updateTimestamp(string $id,string $data):bool{
    $this->lock($id);
    q('UPDATE app_sessions SET last_activity=? WHERE id=?',[time(),$id]);
    return true;
  }

  private function lock(string $id):void{
    if($this->sessionId===$id)return;
    $this->releaseLock();
    $nombre='ss_'.substr(hash('sha256',$id),0,61);
    if((int)q1('SELECT GET_LOCK(?,5) bloqueado',[$nombre])['bloqueado']!==1){
      throw new RuntimeException('No se pudo bloquear la sesión.');
    }
    $this->sessionId=$id;
  }

  private function releaseLock():void{
    if($this->sessionId===null)return;
    q('SELECT RELEASE_LOCK(?)',['ss_'.substr(hash('sha256',$this->sessionId),0,61)]);
    $this->sessionId=null;
  }
}

define('APP_LOG_PATH',getenv('SUPERMERCADO_LOG_PATH')?:sys_get_temp_dir().DIRECTORY_SEPARATOR.'supermercado_app_errors.log');
error_reporting(E_ALL);
ini_set('display_errors',APP_ENV==='production'?'0':'1');

function registrar_error_aplicacion($mensaje){
  $linea=date('c').' '.$mensaje;
  if(APP_ENV==='production'){
    error_log($linea);
    return;
  }
  @file_put_contents(APP_LOG_PATH,$linea.PHP_EOL,FILE_APPEND|LOCK_EX);
}

set_error_handler(function($nivel,$mensaje,$archivo,$linea){
  if(!(error_reporting()&$nivel))return false;
  registrar_error_aplicacion($mensaje.' en '.basename($archivo).':'.$linea);
  return APP_ENV==='production';
});

set_exception_handler(function($error){
  registrar_error_aplicacion(get_class($error).': '.$error->getMessage().' en '.basename($error->getFile()).':'.$error->getLine());
  if(!headers_sent())http_response_code(500);
  echo 'Detalle del error: ' . $error->getMessage() . ' en ' . $error->getFile() . ':' . $error->getLine();
});

register_shutdown_function(function(){
  $error=error_get_last();
  if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
    registrar_error_aplicacion($error['message'].' en '.basename($error['file']).':'.$error['line']);
  }
});

ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
session_set_cookie_params([
  'lifetime'=>0,
  'path'=>'/',
  'secure'=>APP_ENV==='production'||(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),
  'httponly'=>true,
  'samesite'=>'Strict'
]);
session_start();
if(isset($_SESSION['uid'])&&time()-(int)($_SESSION['last_activity']??0)>1800){
  $_SESSION=[];
  session_regenerate_id(true);
}
if(isset($_SESSION['uid']))$_SESSION['last_activity']=time();
date_default_timezone_set('America/Argentina/Buenos_Aires');
header('X-Frame-Options: SAMEORIGIN'); 
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, private');

// Menú dinámico según permisos
$MENU=['dashboard.php'=>['Inicio',null,'speedometer2'],
 'personal.php'=>['Personal',['jefe','supervisor'],'people'],
 'reponer.php'=>['Reposición',['repositor','encargado','supervisor','jefe'],'boxes'],
 'caja.php'=>['Caja',['cajero','jefe'],'upc-scan'],
 'productos.php'=>['Productos',['encargado','supervisor','jefe'],'box-seam'],
 'categorias.php'=>['Categorías',['encargado','supervisor','jefe'],'tags'],
 'pedidos.php'=>['Pedidos',['encargado','supervisor','jefe'],'cart-check'],
 'ventas.php'=>['Ventas',['encargado','supervisor','jefe','cajero'],'graph-up'],
 'cajas.php'=>['Asignación de Cajas',['encargado','supervisor','jefe'],'inbox'],
 'promociones.php'=>['Ofertas',['supervisor','jefe'],'tags'],
 'socios.php'=>['Socios',['supervisor','jefe'],'person-hearts'],
 'reportes.php'=>['Reportes',['supervisor','jefe'],'bar-chart'],
 'accesos.php'=>['Accesos',['jefe'],'clock-history'],
 'errores.php'=>['Errores',['jefe'],'exclamation-triangle'],
 'configuracion.php'=>['Configuración',['jefe'],'gear'],
 'qr_tienda.php'=>['QR de tienda',['jefe'],'qr-code'],
 'dueno.php'=>['Portal del dueño',['propietario'],'speedometer2'],
 'comunicaciones.php'=>['Mensajes y órdenes',['propietario','jefe','supervisor','encargado','repositor','cajero'],'chat-dots']];

// Funciones de consultas
function q($s,$a=[]){$st=db()->prepare($s);$st->execute($a);return $st;}
function q1($s,$a=[]){return q($s,$a)->fetch();}
function verificar_esquema_operativo(array $requisitos){
  foreach($requisitos as $tabla=>$columnas){
    if(!q1('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',[$tabla]))return false;
    foreach($columnas as $columna){
      if(!q1('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',[$tabla,$columna]))return false;
    }
  }
  return true;
}
function solicitar_migracion_operativa($modulo){
  http_response_code(503);
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actualización necesaria</title><link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet"></head><body class="bg-light"><main class="container py-5"><section class="alert alert-warning shadow-sm"><h1 class="h5">Hay que actualizar la base de datos antes de abrir '.e($modulo).'.</h1><p>La aplicación encontró tablas o campos de sucursales que todavía no existen. No borres ni vuelvas a importar la base de datos.</p><ol><li>Haz una copia de seguridad desde phpMyAdmin.</li><li>En Windows, abre Símbolo del sistema y ejecuta:<br><code>C:\\xampp\\php\\php.exe C:\\xampp\\htdocs\\supermercado\\ss\\migrar_operaciones.php</code></li><li>Cuando aparezca “Migración completada”, vuelve a cargar esta página.</li></ol><p class="mb-0">Si aparece un error, detenete y comparte el texto para revisarlo.</p></section></main></body></html>';
  exit;
}
function sucursales_de_usuario($usuarioId){
  return array_map('intval',array_column(q('SELECT us.sucursal_id FROM usuario_sucursales us JOIN sucursales s ON s.id=us.sucursal_id WHERE us.usuario_id=? AND s.activa=1 ORDER BY us.sucursal_id',[(int)$usuarioId])->fetchAll(),'sucursal_id'));
}
function usuario_puede_ver_sucursal($usuario,$sucursalId){
  if(!$usuario)return false;
  if($usuario['rol']==='propietario')return (bool)q1('SELECT id FROM sucursales WHERE id=? AND activa=1',[(int)$sucursalId]);
  return in_array((int)$sucursalId,sucursales_de_usuario((int)$usuario['id']),true);
}
function sucursal_actual_id(){
  $u=user();
  if(!$u){
    $publica=(int)($_SESSION['sucursal_actual_id']??0);
    if($publica&&q1('SELECT id FROM sucursales WHERE id=? AND activa=1',[$publica]))return $publica;
    return (int)(q1('SELECT id FROM sucursales WHERE activa=1 ORDER BY id LIMIT 1')['id']??0);
  }
  $seleccionada=(int)($_SESSION['sucursal_actual_id']??0);
  if($seleccionada&&usuario_puede_ver_sucursal($u,$seleccionada))return $seleccionada;
  $asignadas=$u['rol']==='propietario'
    ?array_map('intval',array_column(q('SELECT id FROM sucursales WHERE activa=1 ORDER BY id')->fetchAll(),'id'))
    :sucursales_de_usuario((int)$u['id']);
  $sucursal=(int)($asignadas[0]??0);
  if($sucursal)$_SESSION['sucursal_actual_id']=$sucursal;
  return $sucursal;
}
function need_propietario(){
  $u=need_login();
  if($u['rol']!=='propietario'){
    http_response_code(403);
    exit('Esta sección está disponible únicamente para el propietario.');
  }
  return $u;
}

// Seguridad
function e($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function normalizar_dni($dni){
  if(!is_string($dni)||trim($dni)==='')return null;
  $dni=trim($dni);
  if(!preg_match('/^[0-9.\-\s]+$/D',$dni))return null;
  $dni=preg_replace('/[.\-\s]/','',$dni);
  return preg_match('/^\d{7,8}$/D',$dni)?$dni:null;
}
function enmascarar_documento($documento){
  $digitos=preg_replace('/\D/','',(string)$documento);
  if($digitos==='')return '—';
  return str_repeat('•',max(0,strlen($digitos)-3)).substr($digitos,-3);
}
function url_https_valida($url,$urlTienda=false){
  if(!is_string($url)||$url===''||preg_match('/[\r\n]/',$url))return false;
  $partes=parse_url($url);
  if(!$partes||strtolower($partes['scheme']??'')!=='https'||empty($partes['host'])||isset($partes['user'])||isset($partes['pass']))return false;
  if($urlTienda){
    if(filter_var($partes['host'],FILTER_VALIDATE_IP)!==false
      ||!preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i',$partes['host'])
      ||($partes['path']??'')!=='/supermercado'
      ||isset($partes['query'])||isset($partes['fragment']))return false;
  }
  return true;
}
function csrf(){return $_SESSION['c']??=bin2hex(random_bytes(32));}
function chk(){
  $token=$_POST['c']??'';
  if(!is_string($token)||!hash_equals($_SESSION['c']??'',$token)){
    http_response_code(403);
    exit('Sesión vencida. Volvé atrás y recargá la página.');
  }
}

function etiqueta_rol($rol){
  return ['propietario'=>'Propietario','jefe'=>'Jefe de sucursal','supervisor'=>'Gerente'][$rol]??ucfirst($rol);
}

function registrar_acceso($usuario,$evento,$usuario_id=null){
  try{
    q('INSERT INTO registro_accesos(usuario_id,usuario,evento,ip,agente) VALUES(?,?,?,?,?)',[
      $usuario_id,$usuario,$evento,substr($_SERVER['REMOTE_ADDR']??'',0,45),substr($_SERVER['HTTP_USER_AGENT']??'',0,255)
    ]);
  }catch(Throwable $error){
    registrar_error_aplicacion('No se pudo guardar el registro de acceso: '.$error->getMessage());
  }
}

function base32_codificar($datos){
  $alfabeto='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits='';
  foreach(str_split($datos) as $byte)$bits.=str_pad(decbin(ord($byte)),8,'0',STR_PAD_LEFT);
  $salida='';
  foreach(str_split($bits,5) as $grupo)$salida.=$alfabeto[bindec(str_pad($grupo,5,'0'))];
  return $salida;
}

function base32_decodificar($texto){
  $alfabeto='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $bits='';
  foreach(str_split(strtoupper(rtrim($texto,'='))) as $caracter){
    $posicion=strpos($alfabeto,$caracter);
    if($posicion===false)return '';
    $bits.=str_pad(decbin($posicion),5,'0',STR_PAD_LEFT);
  }
  $datos='';
  foreach(str_split($bits,8) as $byte)if(strlen($byte)===8)$datos.=chr(bindec($byte));
  return $datos;
}

function verificar_totp($secreto,$codigo,$ultimoContador=-1,$instante=null){
  if(!preg_match('/^\d{6}$/',(string)$codigo))return false;
  $clave=base32_decodificar($secreto);
  if($clave==='')return false;
  $contador=(int)floor(($instante??time())/30);
  for($paso=$contador-1;$paso<=$contador+1;$paso++){
    if($paso<=$ultimoContador)continue;
    $binario=pack('N*',0,$paso);
    $hash=hash_hmac('sha1',$binario,$clave,true);
    $offset=ord($hash[19])&15;
    $numero=((ord($hash[$offset])&127)<<24)|((ord($hash[$offset+1])&255)<<16)|((ord($hash[$offset+2])&255)<<8)|(ord($hash[$offset+3])&255);
    if(hash_equals(str_pad((string)($numero%1000000),6,'0',STR_PAD_LEFT),(string)$codigo))return $paso;
  }
  return false;
}

function completar_login($u){
  session_regenerate_id(true);
  unset($_SESSION['mfa_uid']);
  unset($_SESSION['tienda_carrito'],$_SESSION['tablet_codigo_confirmacion']);
  $_SESSION['uid']=(int)$u['id'];
  $_SESSION['sucursal_actual_id']=(int)($u['sucursal_id']??0);
  $_SESSION['last_activity']=time();
  $_SESSION['db_activity']=time();
  $_SESSION['c']=bin2hex(random_bytes(32));
  q('UPDATE usuarios SET ultimo_acceso=NOW(),ultima_actividad=NOW() WHERE id=?',[$u['id']]);
  registrar_acceso($u['usuario'],'login_ok',$u['id']);
}

// Formato
function money($n){return '$ '.number_format((float)$n,2,',','.');}
function fh($m){return intdiv((int)$m,60).'h '.((int)$m%60).'m';}
function cfg(){
  static $configuraciones=[];
  $sucursalId=sucursal_actual_id();
  if(!array_key_exists($sucursalId,$configuraciones)){
    $configuraciones[$sucursalId]=q1('SELECT * FROM config WHERE sucursal_id=? ORDER BY id LIMIT 1',[$sucursalId])
      ?:q1('SELECT * FROM config ORDER BY id LIMIT 1');
  }
  return $configuraciones[$sucursalId];
}

function lotes_para_venta($productoId,$cantidad,$bloquear=false){
  if($cantidad<1)throw new DomainException('La cantidad debe ser mayor que cero.');
  $sql='SELECT id,cantidad_restante,costo_unitario,precio_venta FROM lotes_stock WHERE producto_id=? AND sucursal_id=? AND cantidad_restante>0 ORDER BY fecha,id';
  if($bloquear)$sql.=' FOR UPDATE';
  $lotes=q($sql,[$productoId,sucursal_actual_id()])->fetchAll();
  $pendiente=$cantidad;
  $salida=[];
  foreach($lotes as $lote){
    if($pendiente<=0)break;
    $tomado=min($pendiente,(int)$lote['cantidad_restante']);
    $salida[]=$lote+['cantidad'=>$tomado];
    $pendiente-=$tomado;
  }
  if($pendiente>0)throw new DomainException('No hay stock suficiente para completar la compra.');
  return $salida;
}

function clave_sesion_tienda(){
  return hash('sha256',session_id());
}

function limpiar_reservas_tienda(){
  q('DELETE FROM reservas_tablet WHERE pedido_id IS NULL AND vence_en IS NOT NULL AND vence_en<=NOW()');
}

function reservas_activas_producto($productoId,$omitirPedidoId=null){
  $sql='SELECT COALESCE(SUM(cantidad),0) FROM reservas_tablet WHERE producto_id=? AND sucursal_id=? AND (vence_en IS NULL OR vence_en>NOW())';
  $parametros=[(int)$productoId,sucursal_actual_id()];
  if($omitirPedidoId){
    $sql.=' AND (pedido_id IS NULL OR pedido_id<>?)';
    $parametros[]=(int)$omitirPedidoId;
  }
  return (int)q($sql,$parametros)->fetchColumn();
}

function stock_disponible_tablet($productoId,$omitirPedidoId=null){
  $producto=q1('SELECT stock FROM stock_sucursales WHERE producto_id=? AND sucursal_id=?',[(int)$productoId,sucursal_actual_id()]);
  if(!$producto)return 0;
  return max(0,(int)$producto['stock']-reservas_activas_producto($productoId,$omitirPedidoId));
}

// Usuario actual
function user(){
  static $u;
  if(empty($_SESSION['uid']))return null;
  if(!isset($u))$u=q1('SELECT * FROM usuarios WHERE id=? AND activo=1',[$_SESSION['uid']]);
  if($u&&time()-(int)($_SESSION['db_activity']??0)>=60){
    q('UPDATE usuarios SET ultima_actividad=NOW() WHERE id=?',[$u['id']]);
    $_SESSION['db_activity']=time();
  }
  return $u;
}

// Verificar permisos
function tiene_permiso($modulo,$accion){
  $u=user();
  if(!$u)return false;
  if(in_array($u['rol'],['propietario','jefe'],true))return true;
  if($u['rol']=='repositor')return $modulo==='reponer'&&$accion==='ver';
  if($u['rol']=='cajero'&&!in_array($modulo,['ventas','caja'],true))return false;
  if($u['rol']=='cajero'&&$modulo==='ventas'&&!in_array($accion,['ver','registrar'],true))return false;
  
  $perm=q1(
    'SELECT permitido FROM permisos WHERE rol=? AND modulo=? AND accion=?',
    [$u['rol'],$modulo,$accion]
  );
  return $perm && $perm['permitido']==1;
}

// Requerir login
function need_login(){
  if(!user()){
    header('Location: login.php');
    exit;
  }
  $u=user();
  if(isset($_GET['sucursal'])){
    $sucursalSolicitada=filter_var($_GET['sucursal'],FILTER_VALIDATE_INT);
    if($sucursalSolicitada===false||!usuario_puede_ver_sucursal($u,(int)$sucursalSolicitada)){
      http_response_code(403);
      exit('No tenés acceso a esa sucursal.');
    }
    $_SESSION['sucursal_actual_id']=(int)$sucursalSolicitada;
  }
  if($u['rol']!=='propietario'&&!sucursal_actual_id()){
    http_response_code(403);
    exit('Tu cuenta no tiene una sucursal activa asignada. Contacta al propietario.');
  }
  return $u;
}

// Requerir permiso
function need($modulo,$accion='ver'){
  need_login();
  $u=user();
  if(!tiene_permiso($modulo,$accion)){
    head('Sin permiso');
    echo '<div class="alert alert-danger">No tenés permiso para acceder a esta sección.</div>';
    foot();
    exit;
  }
  return $u;
}

// Header HTML
function head($t='Super'){
  global $MENU;
  $u=user();
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#173b35"><link rel="manifest" href="manifest.webmanifest"><title>'.e($t).'</title>
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>
 :root{--sidebar-width:260px;--sidebar-ink:#173b35;--sidebar-hover:#ffffff16}
 .app-sidebar{position:fixed;inset:0 auto 0 0;z-index:1040;width:var(--sidebar-width);display:flex;flex-direction:column;padding:1rem .8rem;background:linear-gradient(180deg,#173b35,#102b27);color:#fff;box-shadow:4px 0 24px #102b271a}
 .app-brand{display:flex;align-items:center;gap:.75rem;padding:.5rem .55rem 1rem;color:#fff;text-decoration:none;font-size:1.05rem}
 .app-brand-mark{display:grid;place-items:center;width:2.5rem;height:2.5rem;border-radius:.8rem;background:#ffffff1c;font-size:1.35rem}
 .app-branch{padding:.8rem;margin:.15rem 0 1rem;border:1px solid #ffffff20;border-radius:.8rem;background:#ffffff0b}
 .app-branch-label{display:block;color:#b9d3c8;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em}
 .app-branch-name{display:block;margin-top:.2rem;font-weight:700;line-height:1.25}
 .app-branch .form-select{margin-top:.65rem;background-color:#fff}
 .app-sidebar .navbar-nav{display:flex;flex-direction:column;gap:.2rem;overflow-y:auto}
 .app-sidebar .nav-link{display:flex;align-items:center;gap:.75rem;padding:.62rem .7rem;border-radius:.65rem;color:#d7e6de}
 .app-sidebar .nav-link i{width:1.2rem;text-align:center;font-size:1rem}
 .app-sidebar .nav-link:hover,.app-sidebar .nav-link:focus{background:var(--sidebar-hover);color:#fff}
 .app-sidebar .nav-link[aria-current=page]{background:#ffffff20;color:#fff;font-weight:700}
 .app-sidebar-user{margin-top:auto;padding:.8rem .55rem .25rem;border-top:1px solid #ffffff20}
 .app-sidebar-user-name{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff;font-weight:600}
 .app-sidebar-user-role{display:block;margin-bottom:.7rem;color:#b9d3c8;font-size:.8rem}
 .app-content{width:calc(100% - var(--sidebar-width));min-height:100vh;margin-left:var(--sidebar-width);padding:1.5rem clamp(1rem,2vw,2rem)}
 .app-content>.container{width:100%;max-width:none;padding:0}
 .app-sidebar-toggle,.app-sidebar-backdrop{display:none}
 @media(max-width:767.98px){
  .app-sidebar{transform:translateX(-105%);transition:transform .2s ease}
  body.sidebar-open .app-sidebar{transform:translateX(0)}
  .app-content{width:100%;margin-left:0;padding:4.3rem 1rem 1.25rem}
  .app-sidebar-toggle{position:fixed;top:.65rem;left:.65rem;z-index:1041;display:inline-flex;align-items:center;gap:.45rem}
  .app-sidebar-backdrop{position:fixed;inset:0;z-index:1039;background:#091b18a8}
  body.sidebar-open .app-sidebar-backdrop{display:block}
 }
 @media(prefers-reduced-motion:reduce){.app-sidebar{transition:none}}
 @media print{.no-print{display:none!important}body{background:#fff!important}}
</style></head><body class="bg-light'.($u?' app-with-sidebar':'').'">';
  if($u){
    $sucursalActual=q1('SELECT nombre FROM sucursales WHERE id=?',[sucursal_actual_id()]);
    echo '<button class="btn btn-dark app-sidebar-toggle no-print" type="button" aria-controls="app-sidebar" aria-expanded="false"><i class="bi bi-list"></i><span>Menú</span></button><div class="app-sidebar-backdrop no-print" data-sidebar-close></div><aside id="app-sidebar" class="app-sidebar no-print"><a class="app-brand" href="dashboard.php"><span class="app-brand-mark" aria-hidden="true">🛒</span><span>'.e(cfg()['razon_social']??'Supermercado').'</span></a><div class="app-branch"><span class="app-branch-label">Sucursal actual</span><strong class="app-branch-name">'.e($sucursalActual['nombre']??'Sin sucursal').'</strong>';
    $sucursalesDisponibles=$u['rol']==='propietario'
      ?q('SELECT id,nombre FROM sucursales WHERE activa=1 ORDER BY nombre')->fetchAll()
      :q('SELECT s.id,s.nombre FROM sucursales s JOIN usuario_sucursales us ON us.sucursal_id=s.id WHERE us.usuario_id=? AND s.activa=1 ORDER BY s.nombre',[$u['id']])->fetchAll();
    if(count($sucursalesDisponibles)>1){
      echo '<form method="get" action="dashboard.php"><label class="visually-hidden" for="sucursal-actual">Cambiar sucursal</label><select id="sucursal-actual" name="sucursal" class="form-select form-select-sm" onchange="this.form.submit()">';
      foreach($sucursalesDisponibles as $local)echo '<option value="'.(int)$local['id'].'"'.((int)$local['id']===sucursal_actual_id()?' selected':'').'>'.e($local['nombre']).'</option>';
      echo '</select><noscript><button class="btn btn-sm btn-light mt-2 w-100">Cambiar</button></noscript></form>';
    }
    echo '</div><nav class="navbar-nav">';
    foreach($MENU as $f=>[$n,$r,$i]) {
      if((!$r || in_array($u['rol'],['propietario','jefe'],true) || in_array($u['rol'],$r,true))
        && !($u['rol']=='repositor'&&!in_array($f,['reponer.php','comunicaciones.php'],true))
        && !($u['rol']=='cajero'&&!in_array($f,['caja.php','comunicaciones.php'],true))
        && !($u['rol']!=='propietario'&&$f==='dueno.php')) {
        echo '<a class="nav-link" href="'.$f.'"'.(basename($_SERVER['SCRIPT_NAME']??'')===$f?' aria-current="page"':'').'><i class="bi bi-'.$i.'" aria-hidden="true"></i><span>'.$n.'</span></a>';
      }
    }
    echo '</nav><div class="app-sidebar-user"><span class="app-sidebar-user-name">'.e($u['nombre']).'</span><span class="app-sidebar-user-role">'.e(etiqueta_rol($u['rol'])).'</span><a class="btn btn-sm btn-outline-light w-100" href="logout.php"><i class="bi bi-box-arrow-right me-1"></i>Salir</a></div></aside>';
  }
  echo '<div class="'.($u?'app-content':'container pb-5').'">';
  if(!empty($_SESSION['m'])){echo '<div class="alert alert-info no-print">'.e($_SESSION['m']).'</div>';unset($_SESSION['m']);}
}

// Footer HTML
function foot(){
  echo '</div><script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script><script>const sidebarToggle=document.querySelector(".app-sidebar-toggle");if(sidebarToggle){sidebarToggle.addEventListener("click",()=>{const abierto=document.body.classList.toggle("sidebar-open");sidebarToggle.setAttribute("aria-expanded",String(abierto));});document.querySelectorAll("[data-sidebar-close],.app-sidebar .nav-link").forEach(elemento=>elemento.addEventListener("click",()=>{document.body.classList.remove("sidebar-open");sidebarToggle.setAttribute("aria-expanded","false");}));}if("serviceWorker" in navigator)window.addEventListener("load",()=>navigator.serviceWorker.register("service-worker.js").catch(error=>console.error("No se pudo registrar la PWA:",error)));</script></body></html>';
}

// Auditoría
function auditar($accion,$tabla,$registro_id,$cambios=[]){
  $u=user();
  if($u){
    q('INSERT INTO auditoria(usuario_id,accion,tabla,registro_id,cambios,sucursal_id) VALUES(?,?,?,?,?,?)',
      [$u['id'],$accion,$tabla,$registro_id,json_encode($cambios),sucursal_actual_id()?:null]);
  }
}
