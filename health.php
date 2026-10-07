<?php
require 'lib.php';
header('Content-Type: application/json; charset=utf-8');
try{
  db()->query('SELECT 1');
  echo json_encode(['status'=>'ok','time'=>gmdate('c')]);
}catch(Throwable $error){
  registrar_error_aplicacion('Chequeo de salud fallido: '.$error->getMessage());
  http_response_code(503);
  echo json_encode(['status'=>'unavailable']);
}