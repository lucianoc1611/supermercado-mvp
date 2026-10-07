<?php
$scripts=[
  'index.php','setup.php','login.php','mfa.php','logout.php','dashboard.php',
  'personal.php','reponer.php','productos.php','categorias.php','caja.php',
  'tienda.php','pedidos.php','ventas.php','cajas.php','promociones.php',
  'reportes.php','accesos.php','errores.php','health.php','configuracion.php',
  'dueno.php','comunicaciones.php'
];
$script=$_GET['__script']??'index.php';
if(!is_string($script)||!in_array($script,$scripts,true)){
  http_response_code(404);
  exit('Página no encontrada.');
}

$root=dirname(__DIR__);
chdir($root);
require $root.DIRECTORY_SEPARATOR.$script;
