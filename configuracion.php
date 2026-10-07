<?php
require 'lib.php';
$u=need('configuracion','editar');
$sucursalId=sucursal_actual_id();

if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  
  $razon=trim($_POST['razon_social']??'');
  $cuit=trim($_POST['cuit']??'');
  $condicion=$_POST['condicion']??'RI';
  $pv=(int)($_POST['punto_venta']??1);
  $tiendaUrl=trim((string)($_POST['tienda_url']??''));
  $mercadopagoUrl=trim((string)($_POST['mercadopago_url']??''));
  $pagoVirtualNombre=trim((string)($_POST['pago_virtual_nombre']??''));
  $pagoVirtualUrl=trim((string)($_POST['pago_virtual_url']??''));
  $pagoCvu=trim((string)($_POST['pago_cvu']??''));
  
  if(!$razon){
    $_SESSION['m']='Especifica la razón social.';
  }elseif(strlen($tiendaUrl)>255||strlen($mercadopagoUrl)>500||strlen($pagoVirtualUrl)>500
    ||($pagoCvu!==''&&!preg_match('/^\d{22}$/D',$pagoCvu))
    ||($tiendaUrl!==''&&!url_https_valida($tiendaUrl,true))
    ||($mercadopagoUrl!==''&&!url_https_valida($mercadopagoUrl))
    ||($pagoVirtualUrl!==''&&!url_https_valida($pagoVirtualUrl))
    ||($pagoVirtualUrl!==''&&$pagoVirtualNombre==='')){
    $_SESSION['m']='La tienda debe ser una URL HTTPS pública terminada en /tienda.php (o /supermercado); los enlaces de pago deben usar HTTPS y el CVU debe tener 22 dígitos.';
  }else{
    $cfg=q1('SELECT id FROM config WHERE sucursal_id=? ORDER BY id LIMIT 1',[$sucursalId]);
    if($cfg){
      q('UPDATE config SET razon_social=?,cuit=?,condicion=?,punto_venta=?,tienda_url=?,mercadopago_url=?,pago_virtual_nombre=?,pago_virtual_url=?,pago_cvu=? WHERE id=?',
        [$razon,$cuit,$condicion,$pv,$tiendaUrl?:null,$mercadopagoUrl?:null,$pagoVirtualNombre?substr($pagoVirtualNombre,0,60):null,$pagoVirtualUrl?:null,$pagoCvu?:null,$cfg['id']]);
    }else{
      q('INSERT INTO config(razon_social,cuit,condicion,punto_venta,tienda_url,mercadopago_url,pago_virtual_nombre,pago_virtual_url,pago_cvu,sucursal_id) VALUES(?,?,?,?,?,?,?,?,?,?)',
        [$razon,$cuit,$condicion,$pv,$tiendaUrl?:null,$mercadopagoUrl?:null,$pagoVirtualNombre?substr($pagoVirtualNombre,0,60):null,$pagoVirtualUrl?:null,$pagoCvu?:null,$sucursalId]);
    }
    auditar('actualizar_config','config',1,[]);
    $_SESSION['m']='Configuración actualizada ✔';
  }
  
  header('Location: configuracion.php');
  exit;
}

$cfg=cfg();

head('Configuración'); ?>

<div class="row g-3">
 <div class="col-lg-8">
  <div class="card shadow-sm"><div class="card-body">
   <h5 class="mb-4">Datos del supermercado</h5>
   <form method="post">
    <input type="hidden" name="c" value="<?=csrf()?>">
    <div class="mb-3">
     <label class="form-label">Razón social</label>
     <input type="text" name="razon_social" value="<?=e($cfg['razon_social']??'')?>" class="form-control form-control-lg" required>
    </div>
    <div class="mb-3">
     <label class="form-label">CUIT</label>
     <input type="text" name="cuit" value="<?=e($cfg['cuit']??'')?>" class="form-control" placeholder="20-12345678-9">
    </div>
    <div class="mb-3">
     <label class="form-label">Condición IVA</label>
     <select name="condicion" class="form-select">
      <option value="RI" <?=($cfg['condicion']??'RI')=='RI'?'selected':''?>>Responsable Inscripto</option>
      <option value="MONO" <?=($cfg['condicion']??'RI')=='MONO'?'selected':''?>>Monotributista</option>
     </select>
    </div>
    <div class="mb-4">
     <label class="form-label">Punto de venta</label>
     <input type="number" name="punto_venta" value="<?=e($cfg['punto_venta']??1)?>" class="form-control">
    </div>
    <hr>
    <h5 class="mb-3">Tienda en tablet y pagos</h5>
    <div class="mb-3">
     <label class="form-label" for="tienda_url">URL pública HTTPS para el QR</label>
     <input id="tienda_url" type="url" name="tienda_url" value="<?=e($cfg['tienda_url']??'')?>" class="form-control" placeholder="https://tu-app.up.railway.app/tienda.php" pattern="https://.*/(supermercado|tienda\.php)" maxlength="255">
     <div class="form-text">Usa un dominio HTTPS. Cloudflare Tunnel mantiene el enlace si cambia la IP.</div>
     <?php if(!empty($cfg['tienda_url'])&&url_https_valida($cfg['tienda_url'],true)): $qrTiendaUrl=$cfg['tienda_url'].'?sucursal='.$sucursalId; ?><div class="mt-2">Contenido para codificar en el QR de esta sucursal: <a href="<?=e($qrTiendaUrl)?>" target="_blank" rel="noopener noreferrer"><?=e($qrTiendaUrl)?></a></div><?php endif ?>
     <a class="btn btn-outline-success btn-sm mt-2" href="qr_tienda.php"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>Ver QR de tienda</a>
    </div>
    <div class="mb-3">
     <label class="form-label" for="mercadopago_url">Enlace de pago de Mercado Pago</label>
     <input id="mercadopago_url" type="url" name="mercadopago_url" value="<?=e($cfg['mercadopago_url']??'')?>" class="form-control" placeholder="https://link.mercadopago.com.ar/..." maxlength="500">
    </div>
    <div class="mb-3">
     <label class="form-label" for="pago_virtual_nombre">Nombre de otra billetera o tarjeta virtual</label>
     <input id="pago_virtual_nombre" type="text" name="pago_virtual_nombre" value="<?=e($cfg['pago_virtual_nombre']??'')?>" class="form-control" placeholder="Otra billetera virtual" maxlength="60">
    </div>
    <div class="mb-4">
     <label class="form-label" for="pago_virtual_url">Enlace de pago de esa billetera</label>
     <input id="pago_virtual_url" type="url" name="pago_virtual_url" value="<?=e($cfg['pago_virtual_url']??'')?>" class="form-control" placeholder="https://..." maxlength="500">
    </div>
    <div class="mb-4">
     <label class="form-label" for="pago_cvu">CVU para transferencias</label>
     <input id="pago_cvu" type="text" name="pago_cvu" value="<?=e($cfg['pago_cvu']??'')?>" class="form-control" inputmode="numeric" autocomplete="off" pattern="[0-9]{22}" maxlength="22" placeholder="22 dígitos">
     <div class="form-text">Se muestra en la tienda si no configuras un enlace de pago.</div>
    </div>
    <button class="btn btn-primary btn-lg w-100">Guardar cambios</button>
   </form>
  </div></div>
 </div>

 <div class="col-lg-4">
  <div class="card shadow-sm"><div class="card-body">
   <h5 class="mb-3">Sistema</h5>
   <div class="list-group list-group-flush">
    <a href="personal.php" class="list-group-item list-group-item-action">
     👥 Gestionar personal
    </a>
    <a href="productos.php" class="list-group-item list-group-item-action">
     📦 Gestionar productos
    </a>
    <a href="categorias.php" class="list-group-item list-group-item-action">
     🏷️ Gestionar categorías
    </a>
   </div>
  </div></div>

  <div class="card shadow-sm mt-3"><div class="card-body">
   <h5 class="mb-3">Información</h5>
   <small class="d-block mb-2"><b>Versión:</b> 3.0</small>
   <small class="d-block mb-2"><b>BD:</b> super_simple_v3</small>
   <small class="d-block mb-2"><b>PHP:</b> <?=PHP_VERSION?></small>
   <small class="d-block"><b>Usuario:</b> <?=e($u['nombre'])?></small>
  </div></div>
 </div>
</div>

<?php foot();
