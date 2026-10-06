<?php
require 'lib.php';
$u=need('ventas','ver');
$ventaId=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
if($ventaId===false||$ventaId<1){
  http_response_code(400);
  exit('Número de venta inválido.');
}
$condicion='v.id=? AND v.sucursal_id=?';
$parametros=[(int)$ventaId,sucursal_actual_id()];
if($u['rol']==='cajero'){
  $condicion.=' AND v.cajero_id=?';
  $parametros[]=(int)$u['id'];
}
$venta=q1('SELECT v.*,u.nombre cajero,s.nombre sucursal,s.direccion,s.localidad,s.provincia,s.codigo_postal,s.telefono,s.email,
  c.razon_social,c.cuit,c.condicion,c.punto_venta,soc.nombre socio_nombre
  FROM ventas v
  LEFT JOIN usuarios u ON u.id=v.cajero_id
  JOIN sucursales s ON s.id=v.sucursal_id
  LEFT JOIN config c ON c.sucursal_id=v.sucursal_id
  LEFT JOIN socios soc ON soc.id=v.socio_id
  WHERE '.$condicion.' ORDER BY c.id LIMIT 1',$parametros);
if(!$venta){
  http_response_code(404);
  exit('No encontramos esa venta en la sucursal activa o no tienes permiso para verla.');
}
$items=q('SELECT nombre,cantidad,precio,iva FROM venta_items WHERE venta_id=? ORDER BY id',[(int)$venta['id']])->fetchAll();
$formasPago=['efectivo'=>'Efectivo','tarjeta'=>'Tarjeta / billetera','transferencia'=>'Transferencia','cheque'=>'Cheque'];
$fechaVenta=strtotime($venta['fecha']);
$domicilio=implode(', ',array_filter([$venta['direccion'],$venta['localidad'],$venta['provincia'],$venta['codigo_postal']?'CP '.$venta['codigo_postal']:null]));
?>
<!doctype html>
<html lang="es">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
 <meta name="robots" content="noindex,nofollow"><title>Comprobante de compra #<?=e($venta['id'])?></title>
 <style>
  :root{color-scheme:light;--ink:#153a32;--leaf:#286f58;--muted:#66756e;--line:#dce5df}
  *{box-sizing:border-box}
  body{margin:0;background:#edf2ee;color:#1b2822;font:15px/1.45 Arial,Helvetica,sans-serif}
  .toolbar{max-width:820px;margin:20px auto 10px;display:flex;justify-content:flex-end;gap:8px;padding:0 16px}
  .button{border:0;border-radius:8px;padding:11px 16px;background:var(--ink);color:#fff;font-weight:700;cursor:pointer}
  .button.secondary{background:#fff;color:var(--ink);border:1px solid var(--line);text-decoration:none}
  .receipt{max-width:820px;margin:0 auto 30px;padding:38px 44px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 14px 42px #153a3214}
  .receipt-head{display:flex;justify-content:space-between;gap:24px;padding-bottom:24px;border-bottom:2px solid var(--ink)}
  .store-mark{display:flex;align-items:center;gap:12px;color:var(--ink)}
  .logo{display:grid;place-items:center;width:48px;height:48px;border-radius:13px;background:#e7f2ec;color:var(--leaf);font-size:25px}
  .store-name{margin:0;font-size:22px;font-weight:800}
  .store-data{margin:4px 0 0;color:var(--muted);font-size:13px}
  .ticket-tag{text-align:right;color:var(--ink)}
  .ticket-tag strong{display:block;font-size:13px;text-transform:uppercase;letter-spacing:.1em}
  .ticket-number{font-size:23px;font-weight:800}
  .receipt-title{margin:24px 0 4px;font-size:22px;color:var(--ink)}
  .subtle{color:var(--muted)}
  .meta-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 24px;margin:20px 0 26px;padding:16px;background:#f6f9f6;border-radius:10px}
  .meta-label{display:block;color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}
  .meta-value{display:block;margin-top:2px;font-weight:650;overflow-wrap:anywhere}
  table{width:100%;border-collapse:collapse}
  th{padding:11px 8px;border-bottom:1px solid var(--line);color:var(--muted);font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.05em}
  td{padding:12px 8px;border-bottom:1px solid #edf1ee}
  .number{text-align:right;white-space:nowrap}
  .totals{width:min(100%,360px);margin:18px 0 0 auto}
  .total-line{display:flex;justify-content:space-between;gap:20px;padding:5px 0}
  .grand-total{margin-top:7px;padding-top:13px;border-top:2px solid var(--ink);color:var(--ink);font-size:21px;font-weight:800}
  .discount{color:var(--leaf)}
  .notice{margin-top:28px;padding:13px 15px;border:1px solid #d9e6dd;border-radius:9px;background:#f5faf6;color:#52645a;font-size:12px}
  .receipt-foot{margin-top:24px;text-align:center;color:var(--muted);font-size:12px}
  @media(max-width:600px){.toolbar{margin-top:10px}.receipt{margin:0 8px 14px;padding:24px 18px;border-radius:10px}.receipt-head{gap:10px}.store-name{font-size:17px}.logo{width:40px;height:40px}.ticket-number{font-size:18px}.meta-grid{gap:10px;padding:12px}.receipt table{font-size:13px}.receipt th,.receipt td{padding:9px 4px}}
  @media print{
   @page{size:auto;margin:12mm}
   body{background:#fff;font-size:12px}
   .toolbar{display:none!important}
   .receipt{max-width:none;margin:0;padding:0;border:0;border-radius:0;box-shadow:none}
   .receipt-head{padding-bottom:14px}
   .receipt-title{margin-top:16px}
   .meta-grid{break-inside:avoid}
   tr{break-inside:avoid}
  }
 </style>
</head>
<body>
 <div class="toolbar">
  <a class="button secondary" href="ventas.php"><i aria-hidden="true">←</i> Volver a ventas</a>
  <button class="button" type="button" onclick="window.print()">Imprimir / Guardar PDF</button>
 </div>
 <main class="receipt">
  <header class="receipt-head">
   <div>
    <div class="store-mark"><span class="logo" aria-hidden="true">▦</span><h1 class="store-name"><?=e($venta['razon_social']?:$venta['sucursal'])?></h1></div>
    <p class="store-data"><strong><?=e($venta['sucursal'])?></strong><?php if($domicilio): ?><br><?=e($domicilio)?><?php endif ?>
     <?php if($venta['telefono']): ?><br>Tel. <?=e($venta['telefono'])?><?php endif ?>
     <?php if($venta['email']): ?><br><?=e($venta['email'])?><?php endif ?>
     <?php if($venta['cuit']): ?><br>CUIT: <?=e(enmascarar_documento($venta['cuit']))?><?php endif ?>
    </p>
   </div>
   <div class="ticket-tag"><strong>Comprobante</strong><span class="ticket-number">#<?=e($venta['id'])?></span><span class="store-data">Punto de venta <?=e($venta['punto_venta']?:1)?></span></div>
  </header>
  <h2 class="receipt-title">Comprobante de compra</h2>
  <p class="subtle">Gracias por tu compra. Conservá este comprobante para cualquier consulta.</p>
  <section class="meta-grid" aria-label="Datos de la operación">
   <div><span class="meta-label">Fecha y hora</span><span class="meta-value"><?=e(date('d/m/Y H:i',$fechaVenta))?></span></div>
   <div><span class="meta-label">Forma de pago</span><span class="meta-value"><?=e($formasPago[$venta['pago']]??'No especificada')?></span></div>
   <div><span class="meta-label">Cliente</span><span class="meta-value"><?=e($venta['cliente']?:'Consumidor final')?></span></div>
   <?php if($venta['doc']): ?><div><span class="meta-label">Documento (protegido)</span><span class="meta-value"><?=e(enmascarar_documento($venta['doc']))?></span></div><?php endif ?>
   <?php if($venta['socio_nombre']): ?><div><span class="meta-label">Beneficio socio</span><span class="meta-value"><?=e($venta['socio_nombre'])?></span></div><?php endif ?>
   <?php if($venta['cajero']): ?><div><span class="meta-label">Atendido por</span><span class="meta-value"><?=e($venta['cajero'])?></span></div><?php endif ?>
  </section>
  <table>
   <thead><tr><th>Producto</th><th class="number">Cant.</th><th class="number">Precio unit.</th><th class="number">Importe</th></tr></thead>
   <tbody>
    <?php foreach($items as $item): ?><tr><td><?=e($item['nombre'])?></td><td class="number"><?=(int)$item['cantidad']?></td><td class="number"><?=money($item['precio'])?></td><td class="number"><?=money((float)$item['precio']*(int)$item['cantidad'])?></td></tr><?php endforeach ?>
    <?php if(!$items): ?><tr><td colspan="4" class="subtle">No hay artículos detallados para esta venta.</td></tr><?php endif ?>
   </tbody>
  </table>
  <section class="totals" aria-label="Totales">
   <div class="total-line"><span>Subtotal</span><strong><?=money((float)$venta['total']+(float)$venta['descuento_monto'])?></strong></div>
   <?php if((float)$venta['descuento_monto']>0): ?><div class="total-line discount"><span>Descuento socio (<?=e($venta['descuento_pct'])?>%)</span><strong>−<?=money($venta['descuento_monto'])?></strong></div><?php endif ?>
   <div class="total-line"><span>Neto</span><span><?=money($venta['neto'])?></span></div>
   <div class="total-line"><span>IVA incluido</span><span><?=money($venta['iva'])?></span></div>
   <div class="total-line grand-total"><span>TOTAL</span><span><?=money($venta['total'])?></span></div>
  </section>
  <p class="notice"><strong>Comprobante no fiscal.</strong> Este documento es constancia de la operación comercial y no reemplaza una factura o ticket fiscal emitido por un sistema autorizado.</p>
  <footer class="receipt-foot">Venta #<?=e($venta['id'])?> · <?=e($venta['sucursal'])?> · <?=e(date('d/m/Y H:i',$fechaVenta))?></footer>
 </main>
</body>
</html>
