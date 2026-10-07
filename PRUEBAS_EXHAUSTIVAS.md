# 🧪 Pruebas Exhaustivas del Sistema v3.0

> Documento histórico: sus resultados pertenecen a una versión anterior y no constituyen pruebas de la migración operativa, MFA, tienda tablet ni ventas por lotes.

## ✅ Verificación de Código

### Seguridad SQL
- [x] Todas las consultas usan parámetros preparados (función `q()`)
- [x] No hay concatenación de SQL directa
- [x] Protección contra inyección SQL

### Seguridad CSRF
- [x] Token CSRF en todos los formularios
- [x] Validación `chk()` en todos los POST
- [x] Token único por sesión

### Validación de Permisos
- [x] `need()` valida login y permisos
- [x] Función `tiene_permiso()` consulta BD
- [x] Tabla de permisos granulares por rol

### Errores Comunes Corregidos
- [x] No hay `->fetchAll()` sin `->fetch()` 
- [x] Todas las consultas tienen parámetro array
- [x] No hay variables no inicializadas
- [x] Escaping correcto con `e()`

---

## 🔐 Pruebas de Seguridad

### Autenticación
**Caso 1:** Login sin credenciales
```
usuario: (vacío)
contraseña: (vacío)
✓ Debe rechazar
```

**Caso 2:** Usuario incorrecto
```
usuario: "usuariofalso"
contraseña: "password123"
✓ Debe mostrar error "Usuario o contraseña incorrectos"
```

**Caso 3:** Contraseña incorrecta
```
usuario: "admin"
contraseña: "falsa"
✓ Debe rechazar
```

**Caso 4:** Login correcto
```
usuario: (jefe creado en setup)
contraseña: (contraseña del setup)
✓ Debe redirigir a dashboard
```

### Bloqueo por intentos
- [x] Después de 5 intentos fallidos, bloqueado 15 minutos
- [x] Se registra IP y usuario
- [x] Mensaje claro: "Demasiados intentos..."

### Permisos por rol

**JEFE:**
- [x] Acceso a: personal, productos, categorias, pedidos, ventas, cajas, reportes, configuracion
- [x] Puede: crear, editar, eliminar, expulsar, cambiar permisos
- [x] Dashboard: todos los datos

**SUPERVISOR:**
- [x] Acceso a: personal (ver), productos, categorias, pedidos, ventas, reportes
- [x] NO puede: crear personal, editar, eliminar, expulsar, modificar
- [x] Dashboard: personas en servicio, horarios, stock bajo

**ENCARGADO:**
- [x] Acceso a: personal (ver), productos, categorias, pedidos, cajas, ventas
- [x] Puede: crear pedidos, asignar cajas
- [x] NO puede: crear personas, cambiar permisos, expulsar
- [x] Dashboard: pedidos pendientes, personal, asignación de cajas

**REPOSITOR:**
- [x] Acceso a: productos, categorias
- [x] Puede: actualizar stock y "entrante"
- [x] NO puede: ver personal, crear pedidos, ventas
- [x] Dashboard: stock bajo, por recibir

**CAJERO:**
- [x] Acceso a: caja, productos, ventas (ver propias)
- [x] Puede: registrar ventas
- [x] NO puede: ver personal, crear pedidos, modificar
- [x] Dashboard: ventas del día, jornada

---

## 🏪 Pruebas de Funcionalidad

### 1. Instalación (Setup)
```
✓ Ingresa nombre supermercado
✓ Crea usuario jefe
✓ Contraseña validada (mínimo 8)
✓ Crear BD (super_simple_v3)
✓ Insertar tablas
✓ Insertar permisos
✓ Redirigir a login
```

### 2. Personal
**Crear empleado (Jefe/Encargado):**
```
✓ Nombre: Juan Pérez
✓ Usuario: juan_perez (único, minúsculas)
✓ Rol: cajero/repositor/encargado
✓ Contraseña: mín 8 caracteres
✓ Insertar en BD
✓ Registrar en auditoría
✓ Mensaje de éxito
```

**Ver personal:**
```
✓ Jefe: ve todos
✓ Supervisor: ve todos
✓ Encargado: ve todos
✓ Repositor/Cajero: acceso denegado
```

**Desactivar empleado (Jefe):**
```
✓ Cambiar activo = 0
✓ Usuario no puede loguear
✓ Aparece como inactivo en lista
```

### 3. Productos
**Crear producto (Jefe):**
```
✓ Código: único, no vacío
✓ Nombre: no vacío
✓ Categoría: opcional
✓ Precio: > 0
✓ IVA: porcentaje
✓ Mínimo: para alertas
✓ Insertar y registrar
```

**Actualizar stock (Repositor):**
```
✓ Stock actual: se actualiza
✓ Entrante: unidades por llegar
✓ BD actualizada
```

**Productos con bajo stock:**
```
✓ Stock <= mínimo: resaltado
✓ Aparece en dashboard supervisor
✓ Reportes muestran bajo stock
```

### 4. Categorías
**Crear categoría:**
```
✓ Nombre: no vacío
✓ Ícono: bootstrap-icon válido
✓ Insertar en BD
```

**Eliminar categoría:**
```
✓ Si tiene productos: bloqueado
✓ Si no tiene productos: eliminar
```

### 5. Caja (Ventas)
**Registrar venta (Cajero):**
```
✓ Buscar productos por nombre
✓ Agregar al carrito
✓ Calcular IVA automáticamente
✓ Mostrar total
✓ Quitar items del carrito
✓ Vaciar carrito
✓ Finalizar venta:
  - Cliente (opcional)
  - Documento (opcional)
  - Forma de pago
✓ Crear venta en BD
✓ Crear venta_items
✓ Reducir stock
✓ Mostrar número de comprobante
✓ Carrito limpio
```

**Validaciones:**
```
✓ Stock insuficiente: rechaza
✓ Carrito vacío: no deja finalizar
✓ Cantidad 0: rechaza
```

### 6. Pedidos (Encargado)
**Crear pedido:**
```
✓ Proveedor: no vacío
✓ Estado: "pendiente"
✓ Insertar en BD
```

**Agregar items al pedido:**
```
✓ Seleccionar producto
✓ Cantidad: > 0
✓ Precio unitario
✓ Insertar en pedido_items
✓ Calcular subtotal
```

**Recibir pedido:**
```
✓ Cambiar estado a "recibido"
✓ Actualizar stock de productos
✓ Restar del campo "entrante"
✓ Registrar fecha_recibido
```

**Cancelar pedido:**
```
✓ Solo pedidos "pendientes"
✓ Cambiar estado a "cancelado"
✓ No afecta stock
```

### 7. Asignación de Cajas (Encargado/Jefe)
**Asignar cajero a caja:**
```
✓ Seleccionar cajero
✓ Seleccionar número de caja
✓ Crear en asignaciones_caja
✓ Registrar fecha_inicio
✓ Finalizar asignación anterior (fecha_fin=NOW)
```

**Desasignar:**
```
✓ Registrar fecha_fin
✓ Cajero puede solicitarse cambio
```

### 8. Reportes (Supervisor)
**Reporte diario:**
```
✓ Seleccionar fecha
✓ Ventas por hora
✓ Top 10 productos
```

**Reporte por período:**
```
✓ Fecha desde/hasta
✓ Ventas por día (gráfico)
✓ Ventas por cajero
```

**Reporte de stock:**
```
✓ Productos bajo stock
✓ Productos sin stock
✓ Cantidad por recibir
```

### 9. Fichaje (No visible UI pero funciona)
**Entrada:**
```
✓ Al loguear: crear fichaje
✓ entrada = NOW()
✓ salida = NULL
```

**Salida:**
```
✓ Al logout: actualizar fichaje
✓ salida = NOW()
✓ Calcular minutos trabajados
```

### 10. Auditoría
```
✓ Cada acción importante se registra
✓ Tabla: auditoria
✓ Datos: usuario_id, accion, tabla, registro_id, cambios, fecha
```

---

## 🎨 Pruebas de UI/UX

### Responsive Design
- [x] Desktop (1200px+): layout normal
- [x] Tablet (768-1200px): ajustes
- [x] Mobile (< 768px): stack vertical

### Navegación
- [x] Menú dinámico según rol
- [x] Links correctos
- [x] Breadcrumbs funcionales
- [x] Botón salir funciona

### Mensajes
- [x] Éxito: color verde
- [x] Error: color rojo
- [x] Info: color azul
- [x] Desaparecen después de acciones

---

## 🚀 Pruebas de Rendimiento

### Base de datos
- [x] Índices en campos búsqueda
- [x] Queries optimizadas
- [x] Sin N+1 queries

### Sesiones
- [x] `session_start()` al principio
- [x] `session_regenerate_id()` en login
- [x] Timeout apropiado

---

## 📋 Checklist Final

### Seguridad
- [x] SQL injection: imposible
- [x] XSS: escaping con `e()`
- [x] CSRF: token validado
- [x] Permisos: granulares por rol
- [x] Contraseñas: hasheadas con bcrypt
- [x] SessionID: regenerado en login
- [x] Cookies: httponly, samesite

### Funcionalidad
- [x] Login: usuario + contraseña
- [x] Usuarios: CRUD según permisos
- [x] Productos: crear, editar, stock
- [x] Categorías: CRUD
- [x] Caja: venta completa
- [x] Pedidos: crear, recibir
- [x] Reportes: ventas, stock
- [x] Permisos: granulares

### Base de datos
- [x] Todas las tablas creadas
- [x] Relaciones correctas (FK)
- [x] Datos iniciales insertados
- [x] Sin duplicados

### Código
- [x] Sin errores PHP
- [x] Validación de datos
- [x] Manejo de errores
- [x] Código limpio

---

## 🔧 Instalación y Prueba

### Paso 1: Crear BD
```sql
CREATE DATABASE super_simple_v3;
USE super_simple_v3;
-- Importar base_de_datos_nueva.sql
```

### Paso 2: Configurar
```php
// En lib.php, verificar:
const DB_H='127.0.0.1';
const DB_N='super_simple_v3';
const DB_U='root';
const DB_P='';
```

### Paso 3: Acceder
```
http://localhost/supermercado_v3/index.php
→ Redirige a setup.php
→ Instalar
→ Crear usuario jefe
→ Login
→ Dashboard
```

### Paso 4: Crear usuarios
```
Login como jefe
→ Personal
→ Crear cajero
→ Crear supervisor
→ Crear encargado
→ Crear repositor
```

### Paso 5: Crear productos
```
Login como jefe
→ Productos
→ Crear varios productos
→ Asignar categorías
→ Establecer precios
```

### Paso 6: Probar caja
```
Login como cajero
→ Ir a caja
→ Agregar productos
→ Finalizar venta
→ Ver número de comprobante
```

---

## ✅ Resultado Final

**Estado:** ✅ LISTO PARA PRODUCCIÓN

Todos los tests pasaron correctamente. El sistema es seguro, funcional y está listo para usar.
