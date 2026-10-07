<?php
require 'lib.php';
$u=need('promociones','ver');

if($_SERVER['REQUEST_METHOD']==='POST'){
  chk();
  $accion=$_POST['accion']??'';
  if($accion==='crear'&&tiene_permiso('promociones','crear')){
    $nombre=trim((string)($_POST['nombre']??''));
    $tipo=$_POST['tipo']??'';
    $precio=(float)str_replace(',','.',(string)($_POST['precio']??'0'));
    $desde=trim((string)($_POST['fecha_inicio']??''));
    $hasta=trim((string)($_POST['fecha_fin']??''));
    $fechaInicio=$desde?DateTime::createFromFormat('Y-m-d\TH:i',$desde):null;
    $fechaFin=$hasta?DateTime::createFromFormat('Y-m-d\TH:i',$hasta):null;
    $errores=[];
    if($nombre===''||mb_strlen($nombre)>100)$errores[]='Escribe un nombre de hasta 100 caracteres.';
    if(!in_array($tipo,['producto','combo'],true))$errores[]='Tipo de oferta inválido.';
    if(!is_finite($precio)||$precio<=0||$precio>1000000000)$errores[]='El precio promocional debe ser mayor a cero.';
    if($desde&&(!$fechaInicio||$fechaInicio->format('Y-m-d\TH:i')!==$desde))$errores[]='Fecha de inicio inválida.';
    if($hasta&&(!$fechaFin||$fechaFin->format('Y-m-d\TH:i')!==$hasta))$errores[]='Fecha de fin inválida.';
    if($fechaInicio&&$fechaFin&&$fechaFin<=$fechaInicio)$errores[]='La fecha final debe ser posterior al inicio.';

    $items=[];
    if($tipo==='producto'){
      $productoId=(int)($_POST['producto_id']??0);
      if($productoId>0)$items[$productoId]=1;
    }elseif($tipo==='combo'){
      $ids=$_POST['combo_producto_id']??[];
      $cantidades=$_POST['combo_cantidad']??[];
      if(is_array($ids)&&is_array($cantidades)){
        foreach($ids as $indice=>$id){
          $id=(int)$id;
          $cantidad=filter_var($cantidades[$indice]??null,FILTER_VALIDATE_INT);
          if($id>0&&$cantidad!==false&&$cantidad>0&&$cantidad<=1000)$items[$id]=($items[$id]??0)+$cantidad;
        }
      }
      if(count($items)<2)$errores[]='Un combo debe incluir al menos dos productos distintos.';
    }

    if($items){
      $marcadores=implode(',',array_fill(0,count($items),'?'));
      $existentes=q('SELECT id FROM productos WHERE id IN ('.$marcadores.')',$marcadores?array_keys($items):[])->fetchAll();
      if(count($existentes)!==count($items))$errores[]='Selecciona productos existentes.';
    }elseif($tipo==='producto'){
      $errores[]='Selecciona el producto de la oferta.';
    }

    if($errores){
      $_SESSION['m']=implode(' ',$errores);
    }else{
      try{
        db()->beginTransaction();
        q('INSERT INTO promociones(nombre,tipo,precio,fecha_inicio,fecha_fin,usuario_id) VALUES(?,?,?,?,?,?)',[
          $nombre,$tipo,$precio,$fechaInicio?$fechaInicio->format('Y-m-d H:i:s'):null,$fechaFin?$fechaFin->format('Y-m-d H:i:s'):null,$u['id']
        ]);
        $promocionId=db()->lastInsertId();
        foreach($items as $productoId=>$cantidad){
          q('INSERT INTO promocion_items(promocion_id,producto_id,cantidad) VALUES(?,?,?)',[$promocionId,$productoId,$cantidad]);
        }
        auditar('crear_promocion','promociones',$promocionId,['nombre'=>$nombre,'tipo'=>$tipo,'precio'=>$precio,'items'=>$items]);
        db()->commit();
        $_SESSION['m']='Oferta guardada.';
      }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        registrar_error_aplicacion('Creación de promoción fallida: '.$error->getMessage());
        $_SESSION['m']='No se pudo guardar la oferta.';
      }
    }
  }elseif($accion==='estado'&&tiene_permiso('promociones','editar')){
    $id=(int)($_POST['id']??0);
    $promocion=q1('SELECT id,activa FROM promociones WHERE id=?',[$id]);
    if($promocion){
      q('UPDATE promociones SET activa=1-activa WHERE id=?',[$id]);
      auditar('cambiar_estado_promocion','promociones',$id,['activa'=>1-(int)$promocion['activa']]);
      $_SESSION['m']='Estado de oferta actualizado.';
    }
  }
  header('Location: promociones.php');
  exit;
}

$productos=q('SELECT id,nombre,codigo FROM productos ORDER BY nombre')->fetchAll();
$promociones=q('SELECT pr.*,u.nombre usuario,
  GROUP_CONCAT(CONCAT(p.nombre," × ",pi.cantidad) ORDER BY p.nombre SEPARATOR ", ") componentes
  FROM promociones pr LEFT JOIN usuarios u ON u.id=pr.usuario_id
  LEFT JOIN promocion_items pi ON pi.promocion_id=pr.id LEFT JOIN productos p ON p.id=pi.producto_id
  GROUP BY pr.id ORDER BY pr.activa DESC,pr.fecha_creacion DESC')->fetchAll();
head('Ofertas y combos'); ?>

<div class="d-flex justify-content-between align-items-end gap-2 mb-3">
 <div><h1 class="h3 mb-1">Ofertas y combos</h1></div>
</div>
<div class="row g-3">
 <section class="col-lg-5">
  <div class="card shadow-sm"><div class="card-body">
   <h2 class="h5">Nueva oferta</h2>
   <form method="post" id="formOferta">
    <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="crear">
    <label class="form-label" for="tipoOferta">Tipo</label>
    <select class="form-select mb-3" name="tipo" id="tipoOferta"><option value="producto">Producto</option><option value="combo">Combo</option></select>
    <label class="form-label" for="nombreOferta">Nombre</label>
    <input class="form-control mb-3" name="nombre" id="nombreOferta" maxlength="100" required>
    <div id="productoUnico">
     <label class="form-label" for="productoOferta">Producto</label>
     <select class="form-select mb-3" name="producto_id" id="productoOferta">
      <option value="">Seleccionar</option>
      <?php foreach($productos as $producto): ?><option value="<?=$producto['id']?>"><?=e($producto['nombre'])?> · <?=e($producto['codigo'])?></option><?php endforeach ?>
     </select>
    </div>
    <div id="componentesCombo" hidden>
     <label class="form-label">Productos del combo</label>
     <div id="filasCombo"></div>
     <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="agregarComponente"><i class="bi bi-plus-lg me-1"></i>Agregar producto</button>
    </div>
    <label class="form-label" for="precioOferta">Precio promocional</label>
    <input class="form-control mb-3" type="number" id="precioOferta" name="precio" min="0.01" step="0.01" required>
    <div class="row g-2">
     <div class="col-6"><label class="form-label" for="inicioOferta">Desde (opcional)</label><input class="form-control" id="inicioOferta" name="fecha_inicio" type="datetime-local"></div>
     <div class="col-6"><label class="form-label" for="finOferta">Hasta (opcional)</label><input class="form-control" id="finOferta" name="fecha_fin" type="datetime-local"></div>
    </div>
    <button class="btn btn-success w-100 mt-3">Guardar oferta</button>
   </form>
  </div></div>
 </section>
 <section class="col-lg-7">
  <div class="card shadow-sm"><div class="card-body">
   <h2 class="h5">Promociones</h2>
   <?php if(!$promociones): ?><p class="text-muted mb-0">No hay ofertas cargadas.</p><?php else: ?>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Oferta</th><th>Productos</th><th class="text-end">Precio</th><th>Estado</th><th></th></tr></thead><tbody>
     <?php foreach($promociones as $promocion): ?><tr>
      <td><?=e($promocion['nombre'])?><small class="d-block text-muted"><?=$promocion['tipo']==='combo'?'Combo':'Producto'?></small></td>
      <td><?=e($promocion['componentes']??'')?></td><td class="text-end"><?=money($promocion['precio'])?></td>
      <td><span class="badge text-bg-<?=$promocion['activa']?'success':'secondary'?>"><?=$promocion['activa']?'Activa':'Pausada'?></span></td>
      <td><form method="post"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?=$promocion['id']?>"><button class="btn btn-sm btn-outline-secondary"><?=$promocion['activa']?'Pausar':'Activar'?></button></form></td>
     </tr><?php endforeach ?>
    </tbody></table></div>
   <?php endif ?>
  </div></div>
 </section>
</div>
<template id="filaProductoCombo">
 <div class="row g-2 mb-2 fila-combo">
  <div class="col"><select name="combo_producto_id[]" class="form-select" required><option value="">Producto</option><?php foreach($productos as $producto): ?><option value="<?=$producto['id']?>"><?=e($producto['nombre'])?></option><?php endforeach ?></select></div>
  <div class="col-3"><input type="number" name="combo_cantidad[]" class="form-control" min="1" max="1000" value="1" required aria-label="Cantidad"></div>
  <div class="col-auto"><button type="button" class="btn btn-outline-danger quitar-componente" aria-label="Quitar producto"><i class="bi bi-x-lg"></i></button></div>
 </div>
</template>
<script>
const tipoOferta=document.getElementById('tipoOferta');
const productoUnico=document.getElementById('productoUnico');
const productoOferta=document.getElementById('productoOferta');
const componentesCombo=document.getElementById('componentesCombo');
const filasCombo=document.getElementById('filasCombo');
const plantilla=document.getElementById('filaProductoCombo');
function agregarFila(){filasCombo.append(plantilla.content.cloneNode(true));}
function actualizarTipo(){
  const combo=tipoOferta.value==='combo';
  productoUnico.hidden=combo;
  componentesCombo.hidden=!combo;
  productoOferta.required=!combo;
  filasCombo.querySelectorAll('select,input').forEach(control=>control.required=combo);
  if(combo&&!filasCombo.children.length){agregarFila();agregarFila();}
}
tipoOferta.addEventListener('change',actualizarTipo);
document.getElementById('agregarComponente').addEventListener('click',agregarFila);
filasCombo.addEventListener('click',evento=>{
  const boton=evento.target.closest('.quitar-componente');
  if(boton&&filasCombo.children.length>2)boton.closest('.fila-combo').remove();
});
actualizarTipo();
</script>
<?php foot();