<?php
require 'lib.php';
$u=need('configuracion','editar');
$sucursalId=sucursal_actual_id();
$sucursal=q1('SELECT nombre FROM sucursales WHERE id=? AND activa=1',[$sucursalId]);
$cfg=cfg()?:[];
$tiendaUrl=trim((string)($cfg['tienda_url']??''));
$origenUrl=$tiendaUrl!==''?'Configuración de la sucursal':'';

if($tiendaUrl===''){
  $urlPublica=trim((string)(getenv('SUPERMERCADO_PUBLIC_URL')?:''));
  $partesUrlPublica=parse_url($urlPublica);
  if($partesUrlPublica
    &&strtolower($partesUrlPublica['scheme']??'')==='https'
    &&!empty($partesUrlPublica['host'])
    &&!isset($partesUrlPublica['user'])
    &&!isset($partesUrlPublica['pass'])
    &&($partesUrlPublica['path']??'')===''
    &&!isset($partesUrlPublica['query'])
    &&!isset($partesUrlPublica['fragment'])){
    $tiendaUrl=rtrim($urlPublica,'/').'/supermercado';
    $origenUrl='Dominio HTTPS configurado para el portal';
  }
}

$urlPublicaValida=$tiendaUrl!==''&&url_https_valida($tiendaUrl,true);
$urlQrPublica=$urlPublicaValida?$tiendaUrl.'?sucursal='.$sucursalId:'';
$ipsCandidatas=array_merge(
  [(string)($_SERVER['SERVER_ADDR']??'')],
  gethostbynamel(gethostname())?:[]
);
$ipLocal='';
foreach(array_unique($ipsCandidatas) as $ipCandidata){
  if(filter_var($ipCandidata,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)
    &&preg_match('/^(?:10\.|192\.168\.|172\.(?:1[6-9]|2\d|3[01])\.)/',$ipCandidata)){
    $ipLocal=$ipCandidata;
    break;
  }
}

$puerto=(int)($_SERVER['SERVER_PORT']??80);
$hostLocal=$ipLocal.($puerto&&$puerto!==80?':'.$puerto:'');
$urlQrLocal=$ipLocal!==''?'http://'.$hostLocal.'/supermercado?sucursal='.$sucursalId:'';
head('QR de tienda');
?>

<style>
 .store-qr{max-width:980px;margin:0 auto}
 .store-qr-hero{padding:1.5rem 1.75rem;border-radius:1rem;background:linear-gradient(120deg,#173b35,#27735d);color:#fff}
 .store-qr-hero h1{font-size:clamp(1.5rem,3vw,2rem);font-weight:750}
 .store-qr-hero p{max-width:640px;margin:0;color:#d5e5de}
 .store-qr-panel{border:1px solid #dce4dc;border-radius:1rem}
 .store-qr-local{border-color:#eadbb2}
 .store-qr-image{width:min(100%,360px);aspect-ratio:1;display:grid;place-items:center;margin:auto;padding:1rem;border:1px solid #e2e8e3;border-radius:1rem;background:#fff}
 .store-qr-image img{display:block;width:100%;height:auto;image-rendering:pixelated}
 .store-qr-address{overflow-wrap:anywhere}
 .store-qr-note{border-left:4px solid #27735d;background:#eff7f2}
 .store-qr-label{font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4e685c}
 @media(max-width:575.98px){.store-qr-hero{padding:1.25rem}.store-qr-image{width:min(100%,300px)}}
 @media print{
  body{background:#fff!important}
  .app-content{width:100%!important;margin:0!important;padding:0!important}
  .store-qr-hero,.store-qr-note,.no-print{display:none!important}
  .store-qr{max-width:none}
  .store-qr-panel{border:0!important;box-shadow:none!important}
  .store-qr-image{width:380px;max-width:100%;border:0}
 }
</style>

<main class="store-qr">
 <header class="store-qr-hero mb-4">
  <h1 class="mb-2"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>QR para abrir la tienda</h1>
  <p>Elige el código según dónde vas a usar el celular: en la misma Wi-Fi o desde Internet.</p>
 </header>

 <?php if($urlQrLocal!==''): ?>
  <section class="card store-qr-panel store-qr-local shadow-sm mb-4">
   <div class="card-body p-4 p-md-5 text-center">
    <span class="badge rounded-pill text-bg-success mb-3"><?=e($sucursal['nombre']??'Sucursal')?></span>
    <h2 class="h4 mb-3">QR local · mismo Wi-Fi</h2>
    <div class="store-qr-image mb-4">
     <img id="storeQrLocalImage" alt="Código QR local para abrir la tienda de <?=e($sucursal['nombre']??'la sucursal')?>"
       data-url="<?=e($urlQrLocal)?>" hidden>
     <span id="storeQrLocalLoading" class="text-muted" role="status">Generando el código QR…</span>
    </div>
    <a class="store-qr-address d-inline-block mb-4" href="<?=e($urlQrLocal)?>" target="_blank" rel="noopener noreferrer"><?=e($urlQrLocal)?></a>
    <div class="d-flex flex-wrap justify-content-center gap-2 no-print">
     <button id="downloadStoreQrLocal" class="btn btn-success" type="button" disabled>
      <i class="bi bi-download me-1" aria-hidden="true"></i>Descargar QR local
     </button>
     <a class="btn btn-outline-primary" href="<?=e($urlQrLocal)?>" target="_blank" rel="noopener noreferrer">
      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Probar desde esta PC
     </a>
    </div>
    <div id="storeQrLocalError" class="alert alert-danger mt-3 mb-0" role="alert" hidden>
     No se pudo generar el QR. Recarga la página; si continúa, avisa al soporte.
    </div>
    <div class="alert alert-warning text-start mt-4 mb-0">
     <strong>Funciona con el mismo Wi-Fi y Apache encendido.</strong>
     <div>Si cambia la IP, descarga e imprime el QR nuevo.</div>
    </div>
   </div>
  </section>
 <?php else: ?>
  <div class="alert alert-danger" role="alert">
   No pude detectar la IP local de esta PC. Comprueba que esté conectada a la red del local y vuelve a cargar esta página. Si continúa, configura una reserva DHCP en el router para el equipo de XAMPP.
  </div>
 <?php endif ?>

 <?php if($urlPublicaValida): ?>
  <section class="card store-qr-panel shadow-sm">
   <div class="card-body p-4 p-md-5 text-center">
    <span class="badge rounded-pill text-bg-primary mb-3"><?=e($sucursal['nombre']??'Sucursal')?></span>
    <h2 class="h4 mb-3">QR público · HTTPS</h2>
    <div class="store-qr-image mb-4">
     <img id="storeQrPublicImage" alt="Código QR público para abrir la tienda de <?=e($sucursal['nombre']??'la sucursal')?>"
       data-url="<?=e($urlQrPublica)?>" hidden>
     <span id="storeQrPublicLoading" class="text-muted" role="status">Generando el código QR…</span>
    </div>
    <a class="store-qr-address d-inline-block mb-4" href="<?=e($urlQrPublica)?>" target="_blank" rel="noopener noreferrer"><?=e($urlQrPublica)?></a>
    <div class="d-flex flex-wrap justify-content-center gap-2 no-print">
     <button id="downloadStoreQrPublic" class="btn btn-success" type="button" disabled>
      <i class="bi bi-download me-1" aria-hidden="true"></i>Descargar QR público
     </button>
     <button class="btn btn-outline-secondary" type="button" onclick="window.print()">
      <i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir
     </button>
     <a class="btn btn-outline-primary" href="<?=e($urlQrPublica)?>" target="_blank" rel="noopener noreferrer">
      <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Probar enlace público
     </a>
    </div>
    <div id="storeQrPublicError" class="alert alert-danger mt-3 mb-0" role="alert" hidden>
     No se pudo generar el QR. Recarga la página; si continúa, avisa al soporte.
    </div>
   </div>
  </section>
  <aside class="store-qr-note rounded p-3 mt-3">
   <strong><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Para imprimir y compartir</strong>
   <div>Requiere dominio HTTPS y Cloudflare activo.</div>
  </aside>
 <?php else: ?>
  <section class="card store-qr-panel shadow-sm mt-4">
   <div class="card-body p-4">
    <div class="alert alert-warning mb-0" role="status">
     <h2 class="h5"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>QR público todavía no disponible</h2>
     <p class="mb-0">Activa Cloudflare y configura la URL HTTPS en <a href="configuracion.php" class="alert-link">Configuración</a> para generar el QR público.</p>
    </div>
   </div>
  </section>
 <?php endif ?>
</main>

<?php if($urlQrLocal!==''||$urlPublicaValida): ?>
<script src="assets/vendor/qrcode-generator/qrcode.js"></script>
<script>
 (() => {
  const generarQr = (tipo) => {
   const imagen = document.getElementById('storeQr' + tipo + 'Image');
   if (!imagen) return;
   const mensaje = document.getElementById('storeQr' + tipo + 'Loading');
   const error = document.getElementById('storeQr' + tipo + 'Error');
   const descargar = document.getElementById('downloadStoreQr' + tipo);

   try {
    const codigo = qrcode(0, 'M');
    codigo.addData(imagen.dataset.url);
    codigo.make();
    imagen.src = codigo.createDataURL(8, 32);
    imagen.hidden = false;
    mensaje.hidden = true;
    descargar.disabled = false;
    descargar.addEventListener('click', () => {
     const enlace = document.createElement('a');
     enlace.href = imagen.src;
     enlace.download = 'qr-tienda-' + tipo.toLowerCase() + '-sucursal-<?=$sucursalId?>.gif';
     enlace.click();
    });
   } catch (fallo) {
    console.error('No se pudo generar el QR de la tienda:', fallo);
    mensaje.hidden = true;
    error.hidden = false;
   }
  };

  generarQr('Local');
  generarQr('Public');
 })();
</script>
<?php endif ?>
<?php foot();
