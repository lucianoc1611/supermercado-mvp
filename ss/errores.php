<?php
require 'lib.php';
$u=need('errores','ver');

$lineas=[];
if(is_file(APP_LOG_PATH)&&is_readable(APP_LOG_PATH)){
  $archivo=fopen(APP_LOG_PATH,'rb');
  if($archivo){
    $tamano=filesize(APP_LOG_PATH);
    if($tamano>65536)fseek($archivo,-65536,SEEK_END);
    while(!feof($archivo)){
      $linea=fgets($archivo);
      if($linea!==false)$lineas[]=$linea;
    }
    fclose($archivo);
    $lineas=array_slice($lineas,-150);
  }
}
head('Diagnóstico del sistema'); ?>

<h1 class="h3 mb-3">Diagnóstico del sistema</h1>
<div class="alert alert-warning">Esta pantalla muestra errores registrados por la aplicación. No puede reiniciar Apache o MySQL; para eso hace falta un monitor externo y acceso al servidor.</div>
<section class="card shadow-sm"><div class="card-body">
 <div class="d-flex justify-content-between align-items-center gap-2 mb-3"><h2 class="h5 mb-0">Registro técnico reciente</h2><span class="badge text-bg-secondary"><?=count($lineas)?> eventos</span></div>
 <?php if(!$lineas): ?><p class="text-muted mb-0">No hay errores registrados.</p>
 <?php else: ?><pre class="bg-dark text-light p-3 rounded small" style="max-height:65vh;overflow:auto;white-space:pre-wrap"><?=e(implode('',$lineas))?></pre><?php endif ?>
</div></section>
<?php foot();