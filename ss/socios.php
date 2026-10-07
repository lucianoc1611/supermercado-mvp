<?php
require 'lib.php';
$u=need('socios','ver');

if($_SERVER['REQUEST_METHOD']==='POST'){
  chk();
  $accion=(string)($_POST['accion']??'');

  if($accion==='crear'&&tiene_permiso('socios','crear')){
    $dni=normalizar_dni((string)($_POST['dni']??''));
    $nombre=trim((string)($_POST['nombre']??''));
    $telefono=trim((string)($_POST['telefono']??''));
    if($dni===null||$nombre===''||mb_strlen($nombre)>120||mb_strlen($telefono)>30){
      $_SESSION['m']='Ingresa un DNI válido de 7 u 8 dígitos, nombre y un teléfono de hasta 30 caracteres.';
    }else{
      try{
        db()->beginTransaction();
        q('INSERT INTO socios(dni,nombre,telefono) VALUES(?,?,?)',[$dni,$nombre,$telefono?:null]);
        $socioId=(int)db()->lastInsertId();
        auditar('crear_socio','socios',$socioId,[]);
        db()->commit();
        $_SESSION['m']='Socio agregado. Ya puede recibir el 20% de descuento en caja.';
      }catch(PDOException $error){
        if(db()->inTransaction())db()->rollBack();
        if($error->getCode()==='23000'&&(int)($error->errorInfo[1]??0)===1062){
          $_SESSION['m']='Ya existe un socio registrado con ese DNI.';
        }else{
          registrar_error_aplicacion('Alta de socio fallida: '.$error->getMessage());
          $_SESSION['m']='No se pudo guardar el socio. Revisa el registro de errores.';
        }
      }catch(Throwable $error){
        if(db()->inTransaction())db()->rollBack();
        registrar_error_aplicacion('Alta de socio fallida: '.$error->getMessage());
        $_SESSION['m']='No se pudo guardar el socio. Revisa el registro de errores.';
      }
    }
  }elseif($accion==='cambiar_estado'&&tiene_permiso('socios','editar')){
    $socioId=(int)($_POST['socio_id']??0);
    try{
      db()->beginTransaction();
      $socio=q1('SELECT id,activo FROM socios WHERE id=? FOR UPDATE',[$socioId]);
      if(!$socio)throw new DomainException('No se encontró ese socio.');
      $nuevoEstado=(int)!((int)$socio['activo']);
      q('UPDATE socios SET activo=? WHERE id=?',[$nuevoEstado,$socioId]);
      auditar($nuevoEstado?'activar_socio':'desactivar_socio','socios',$socioId,[]);
      db()->commit();
      $_SESSION['m']=$nuevoEstado?'Socio activado.':'Socio desactivado; ya no recibirá descuentos.';
    }catch(Throwable $error){
      if(db()->inTransaction())db()->rollBack();
      registrar_error_aplicacion('Cambio de estado de socio fallido: '.$error->getMessage());
      $_SESSION['m']=$error instanceof DomainException?$error->getMessage():'No se pudo actualizar el socio.';
    }
  }
  header('Location: socios.php');
  exit;
}

$socios=q('SELECT id,dni,nombre,telefono,activo,fecha_alta FROM socios ORDER BY activo DESC,nombre')->fetchAll();
$cantidadSocios=(int)q('SELECT COUNT(*) FROM socios WHERE activo=1')->fetchColumn();
$cantidadInactivos=(int)q('SELECT COUNT(*) FROM socios WHERE activo=0')->fetchColumn();

head('Socios'); ?>

<style>
 .members-hero{background:linear-gradient(120deg,#173b35,#27735d);border-radius:14px;color:#fff;overflow:hidden}
 .members-stat{border:1px solid #dce4dc;border-radius:10px;background:#fff}
 .member-badge{background:#e7f2c8;color:#173b35}
 .member-row:hover{background:#f5f7f2}
</style>

<section class="members-hero p-4 p-lg-5 mb-4 shadow-sm">
 <div class="row align-items-center g-3">
  <div class="col-lg-8">
   <span class="badge member-badge mb-2">PROGRAMA DE BENEFICIOS</span>
   <h1 class="h2 mb-2">Socios del supermercado</h1>
   <p class="mb-0 text-white-50">20% de descuento en caja con DNI.</p>
  </div>
  <div class="col-lg-4 text-lg-end"><span class="display-4 fw-bold">20%</span><div>de descuento para socios activos</div></div>
 </div>
</section>

<div class="row g-3 mb-4">
 <div class="col-sm-6"><div class="members-stat p-3"><div class="text-muted small">SOCIOS ACTIVOS</div><div class="fs-3 fw-bold"><?=number_format($cantidadSocios)?></div></div></div>
 <div class="col-sm-6"><div class="members-stat p-3"><div class="text-muted small">SOCIOS INACTIVOS</div><div class="fs-3 fw-bold"><?=number_format($cantidadInactivos)?></div></div></div>
</div>

<div class="row g-3 align-items-start">
 <div class="col-lg-4">
  <section class="card shadow-sm"><div class="card-body">
   <h2 class="h5 mb-1">Sumar un socio</h2>
   <?php if(tiene_permiso('socios','crear')): ?>
    <form method="post">
     <input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="crear">
     <div class="mb-3"><label for="dniSocioNuevo" class="form-label">DNI</label><input id="dniSocioNuevo" name="dni" class="form-control" inputmode="numeric" autocomplete="off" pattern="[0-9.\-\s]{7,12}" maxlength="12" placeholder="Ej.: 12345678" required></div>
     <div class="mb-3"><label for="nombreSocioNuevo" class="form-label">Nombre y apellido</label><input id="nombreSocioNuevo" name="nombre" class="form-control" maxlength="120" autocomplete="name" required></div>
     <div class="mb-3"><label for="telefonoSocioNuevo" class="form-label">Teléfono <span class="text-muted">(opcional)</span></label><input id="telefonoSocioNuevo" name="telefono" class="form-control" maxlength="30" autocomplete="tel"></div>
     <button class="btn btn-success btn-lg w-100"><i class="bi bi-person-plus me-1"></i>Registrar socio</button>
    </form>
   <?php else: ?><div class="alert alert-secondary mb-0">No tienes permiso para registrar socios.</div><?php endif ?>
  </div></section>
  <div class="alert alert-light border mt-3 small"><strong>En caja:</strong> ingresa su DNI para aplicar el descuento.</div>
 </div>

 <div class="col-lg-8">
  <section class="card shadow-sm"><div class="card-body border-bottom">
   <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><h2 class="h5 mb-1">Directorio de socios</h2><p class="small text-muted mb-0">Inactivos: sin descuento.</p></div>
    <div class="input-group" style="max-width:300px"><span class="input-group-text"><i class="bi bi-search"></i></span><input id="buscarSocio" class="form-control" placeholder="Buscar por nombre o DNI" aria-label="Buscar socios"></div>
   </div>
  </div>
  <div class="table-responsive"><table class="table align-middle mb-0">
   <thead class="table-light"><tr><th>Socio</th><th>DNI</th><th>Contacto</th><th>Estado</th><th class="text-end">Acción</th></tr></thead>
   <tbody id="listaSocios">
   <?php foreach($socios as $socio): ?>
    <tr class="member-row" data-busqueda="<?=e(mb_strtolower($socio['nombre'].' '.$socio['dni']))?>">
     <td><strong><?=e($socio['nombre'])?></strong><small class="d-block text-muted">Desde <?=e(date('d/m/Y',strtotime($socio['fecha_alta'])))?></small></td>
     <td class="font-monospace"><?=e($socio['dni'])?></td><td><?=e($socio['telefono']?:'—')?></td>
     <td><span class="badge text-bg-<?=$socio['activo']?'success':'secondary'?>"><?=$socio['activo']?'Activo':'Inactivo'?></span></td>
     <td class="text-end"><?php if(tiene_permiso('socios','editar')): ?><form method="post"><input type="hidden" name="c" value="<?=csrf()?>"><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="socio_id" value="<?=$socio['id']?>"><button class="btn btn-sm btn-outline-<?=$socio['activo']?'danger':'success'?>"><?=$socio['activo']?'Desactivar':'Activar'?></button></form><?php endif ?></td>
    </tr>
   <?php endforeach ?>
   <?php if(!$socios): ?><tr><td colspan="5" class="text-center text-muted p-4">Todavía no hay socios registrados.</td></tr><?php endif ?>
   </tbody>
  </table></div>
  </section>
 </div>
</div>
<script>
document.getElementById('buscarSocio').addEventListener('input',function(){
  const consulta=this.value.trim().toLocaleLowerCase('es');
  document.querySelectorAll('#listaSocios tr[data-busqueda]').forEach(fila=>{
    fila.hidden=!fila.dataset.busqueda.includes(consulta);
  });
});
</script>
<?php foot();
