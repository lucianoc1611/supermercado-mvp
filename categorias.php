<?php
require 'lib.php';
$u=need('categorias','ver');

if($_SERVER['REQUEST_METHOD']=='POST'){
  chk();
  $accion=$_POST['accion']??'';
  
  if($accion=='crear' && tiene_permiso('categorias','crear')){
    $nombre=trim($_POST['nombre']??'');
    $icono=trim($_POST['icono']??'tag');
    
    if(!$nombre){
      $_SESSION['m']='El nombre es requerido.';
    }else{
      q('INSERT INTO categorias(nombre,icono) VALUES(?,?)',[$nombre,$icono]);
      auditar('crear_categoria','categorias',db()->lastInsertId(),['nombre'=>$nombre]);
      $_SESSION['m']='Categoría creada ✔';
    }
  }
  
  elseif($accion=='editar' && tiene_permiso('categorias','editar')){
    $id=(int)($_POST['id']??0);
    $nombre=trim($_POST['nombre']??'');
    $icono=trim($_POST['icono']??'tag');
    $cat=q1('SELECT * FROM categorias WHERE id=?',[$id]);
    
    if(!$cat){
      $_SESSION['m']='Categoría no encontrada.';
    }else{
      q('UPDATE categorias SET nombre=?,icono=? WHERE id=?',[$nombre,$icono,$id]);
      auditar('editar_categoria','categorias',$id,['nombre'=>$nombre]);
      $_SESSION['m']='Categoría actualizada ✔';
    }
  }
  
  elseif($accion=='eliminar' && tiene_permiso('categorias','eliminar')){
    $id=(int)($_POST['id']??0);
    $cat=q1('SELECT * FROM categorias WHERE id=?',[$id]);
    
    if(!$cat){
      $_SESSION['m']='Categoría no encontrada.';
    }else{
      $tiene_prods=q1('SELECT COUNT(*) c FROM productos WHERE categoria_id=?',[$id])['c'];
      if($tiene_prods>0){
        $_SESSION['m']='No puedes eliminar una categoría con productos.';
      }else{
        q('DELETE FROM categorias WHERE id=?',[$id]);
        auditar('eliminar_categoria','categorias',$id,[]);
        $_SESSION['m']='Categoría eliminada ✔';
      }
    }
  }
  
  header('Location: categorias.php');
  exit;
}

$editar=(isset($_GET['editar'])?(int)$_GET['editar']:null);
$editando=$editar?q1('SELECT * FROM categorias WHERE id=?',[$editar]):null;

$categorias=q('
  SELECT c.*,COUNT(p.id) productos FROM categorias c
  LEFT JOIN productos p ON c.id=p.categoria_id
  GROUP BY c.id
  ORDER BY c.nombre
')->fetchAll();

head('Categorías'); ?>

<div class="row g-3">
 <div class="col-lg-8">
  <div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
   <thead class="table-light"><tr><th>Categoría</th><th>Ícono</th><th>Productos</th><th></th></tr></thead>
   <tbody>
   <?php foreach($categorias as $c): ?>
    <tr>
     <td><?=e($c['nombre'])?></td>
     <td><i class="bi bi-<?=e($c['icono'])?>"></i> <?=e($c['icono'])?></td>
     <td><span class="badge bg-info"><?=$c['productos']?></span></td>
     <td class="text-end">
      <?php if(tiene_permiso('categorias','editar')): ?>
       <a href="?editar=<?=$c['id']?>" class="btn btn-sm btn-outline-primary">Editar</a>
      <?php endif ?>
      <?php if(tiene_permiso('categorias','eliminar') && $c['productos']==0): ?>
       <form method="post" class="d-inline" onsubmit="return confirm('¿Eliminar?')">
        <input type="hidden" name="c" value="<?=csrf()?>">
        <input type="hidden" name="accion" value="eliminar">
        <input type="hidden" name="id" value="<?=$c['id']?>">
        <button class="btn btn-sm btn-outline-danger">Eliminar</button>
       </form>
      <?php endif ?>
     </td>
    </tr>
   <?php endforeach ?>
   </tbody>
  </table></div></div>
 </div>

 <div class="col-lg-4">
  <?php if($editando && tiene_permiso('categorias','editar')): ?>
   <div class="card shadow-sm border-primary"><div class="card-body">
    <h5>Editar categoría</h5>
    <form method="post">
     <input type="hidden" name="c" value="<?=csrf()?>">
     <input type="hidden" name="accion" value="editar">
     <input type="hidden" name="id" value="<?=$editando['id']?>">
     <input type="text" name="nombre" value="<?=e($editando['nombre'])?>" class="form-control mb-2" required>
     <input type="text" name="icono" value="<?=e($editando['icono'])?>" class="form-control mb-3" placeholder="bootstrap-icon (ej: basket)">
     <small class="d-block mb-2 text-muted">Consulta: <a href="https://icons.getbootstrap.com/" target="_blank">Bootstrap Icons</a></small>
     <button class="btn btn-primary w-100 mb-2">Guardar</button>
     <a href="categorias.php" class="btn btn-secondary w-100">Cancelar</a>
    </form>
   </div></div>
  <?php elseif(tiene_permiso('categorias','crear')): ?>
   <div class="card shadow-sm"><div class="card-body">
    <h5>Nueva categoría</h5>
    <form method="post">
     <input type="hidden" name="c" value="<?=csrf()?>">
     <input type="hidden" name="accion" value="crear">
     <input type="text" name="nombre" class="form-control mb-2" placeholder="Nombre" required>
     <input type="text" name="icono" class="form-control mb-3" placeholder="bootstrap-icon (ej: basket)" value="tag">
     <small class="d-block mb-2 text-muted">Consulta: <a href="https://icons.getbootstrap.com/" target="_blank">Bootstrap Icons</a></small>
     <button class="btn btn-success w-100">Crear</button>
    </form>
   </div></div>
  <?php endif ?>
 </div>
</div>

<?php foot();
