# 📖 Manual de Uso - Sistema Supermercado v3.0

## Tabla de contenidos
1. [Instalación](#instalación)
2. [Roles y permisos](#roles-y-permisos)
3. [Cómo usar cada módulo](#cómo-usar-cada-módulo)
4. [Portal del dueño y sucursales](#portal-del-dueño-y-sucursales)
5. [Preguntas frecuentes](#preguntas-frecuentes)

---

## Instalación

### Requisitos
- PHP 7.4 o superior
- MySQL/MariaDB 5.7 o superior
- Servidor web (Apache, Nginx)

Supabase ofrece PostgreSQL. Esta aplicación actualmente usa consultas y scripts de esquema específicos de MySQL/MariaDB, así que requiere un port completo a PostgreSQL antes de conectarse a Supabase. No ejecutes los SQL de instalación o migración MySQL sobre Supabase. Haz una copia de seguridad y verifica una estrategia de migración que preserve los datos antes de modificar la base.

Si empiezas con un proyecto Supabase vacío, abre **SQL Editor → New query**, ejecuta el archivo `supabase_schema.sql` del proyecto y confirma que las tablas se hayan creado. El archivo no borra tablas ni filas y habilita RLS sin políticas públicas. No uses `base_de_datos_nueva.sql` en Supabase; ese esquema es solo para MySQL/XAMPP. Crear las tablas no basta para ejecutar la aplicación: todavía requiere portar PDO y todas las consultas a PostgreSQL.

### Pasos

**1. Descargar archivos**
```
Copiar la aplicación a una carpeta protegida y configurar el DocumentRoot del sitio
para que apunte a la carpeta de esta aplicación (la URL pública no muestra esa ruta)
```

**2. Crear base de datos**
```bash
mysql -u root -p
mysql> CREATE DATABASE super_simple_v3 CHARACTER SET utf8mb4;
mysql> USE super_simple_v3;
mysql> SOURCE base_de_datos_nueva.sql;
```

**3. Actualizar esquema**
Haz una copia de seguridad y, desde la carpeta del proyecto, ejecuta `php migrar_operaciones.php` (XAMPP: `C:\xampp\php\php.exe migrar_operaciones.php`). La migración es repetible: asigna los datos existentes a la Sucursal Principal, conserva las ventas y prepara inventario separado por sucursal, socios compartidos, mensajes y órdenes. No convierte automáticamente a ningún jefe en propietario global.

**4. Configurar el servidor**
En producción define `APP_ENV=production`, `SUPERMERCADO_PUBLIC_URL=https://tu-dominio.com/supermercado` y las variables `SUPERMERCADO_DB_HOST`, `SUPERMERCADO_DB_NAME`, `SUPERMERCADO_DB_USER` y `SUPERMERCADO_DB_PASSWORD`. Usa HTTPS y una cuenta MySQL dedicada; no uses `root`.

**5. Acceder a instalación**
```
Si el DocumentRoot de Apache apunta a la carpeta de esta aplicación:
http://localhost/index.php

Si el DocumentRoot apunta a htdocs y la aplicación está en
htdocs/supermercado/ss:
http://localhost/supermercado/ss/index.php
→ Redirigirá a setup.php
→ Completar instalación
→ Crear cuenta administradora (clave mínima de 12 caracteres)
→ Listo
```

Después de crear la cuenta inicial, consulta su ID con `SELECT id,usuario,rol FROM usuarios;`. Solo cuando confirmes cuál cuenta será el dueño, promuévela ejecutando otra vez la migración con `php migrar_operaciones.php --propietario=ID`. El ID debe pertenecer a una cuenta activa; si ya existe otro propietario, la migración detiene la promoción. También puedes dejar la promoción pendiente y hacerla después, con copia de seguridad, usando el mismo comando.

### Vercel y Supabase

`vercel.json` prepara el runtime PHP comunitario y el enrutamiento, pero el acceso a Supabase aún no está implementado: el driver, las consultas y las migraciones actuales son de MySQL/MariaDB. Primero hay que convertirlos a PostgreSQL y probar la migración de los datos existentes en una copia de la base. Después se podrán configurar los datos de conexión como variables de entorno de Vercel, sin guardarlos en el código. Los errores de Functions se consultan en los logs de Vercel; el sistema de archivos de una función no es almacenamiento permanente.

---

## Portal del dueño y sucursales

- El portal está en **Portal del dueño** y se habilita únicamente a una cuenta con rol `propietario`. Para promover una cuenta existente, usa el procedimiento explícito de instalación; ningún jefe se vuelve propietario por orden de ID.
- El propietario puede consultar indicadores, movimientos, personal y stock bajo por sucursal; crear sucursales, asignar personal, activar/desactivar cuentas y enviar mensajes u órdenes. Los jefes de sucursal pueden tener asignadas varias sucursales y cambiar la sucursal activa desde el menú.
- Solo el propietario administra sucursales. Puede editar su nombre, dirección, localidad, provincia, código postal, teléfono, correo y coordenadas; dar de baja una sucursal la archiva sin borrar ventas ni movimientos. No se puede dar de baja la única sucursal activa.
- El catálogo de productos y el padrón de socios se comparten. Stock, lotes, pedidos, ventas, fichajes, cajas y configuración de pagos se separan por sucursal. La nueva sucursal empieza sin existencias: registra su inventario mediante **Reposición**; no se duplica stock automáticamente.
- Los mensajes y órdenes aparecen en **Mensajes y órdenes** para los equipos, que pueden responder; los encargados pueden marcar órdenes como completadas.
- La tienda pública recibe la sucursal mediante `?sucursal=ID`. En Configuración, copia el enlace mostrado para el QR de cada sucursal; configura para cada una su razón social/datos de pago y URL pública.
- La PWA se instala desde **Instalar app** cuando el navegador lo permite. Para abrirla desde cualquier lugar se debe desplegar en un servidor accesible por internet con HTTPS y una base de datos remota segura; XAMPP en una PC local no la publica ni da acceso remoto por sí solo. El portal requiere conexión para datos y acciones, y no guarda páginas privadas para uso sin conexión.

### Conectar una tablet dentro del local

1. Inicia Apache y MySQL desde el Panel de control de XAMPP.
2. Conecta la tablet y la computadora que tiene XAMPP a la misma red Wi-Fi.
3. En la computadora, abre **Símbolo del sistema**, ejecuta `ipconfig` y anota la dirección IPv4 del adaptador Wi-Fi o Ethernet (por ejemplo, `192.168.1.25`).
4. En el navegador de la tablet abre `http://IP-DE-LA-PC/tienda.php?sucursal=1`, reemplazando `IP-DE-LA-PC` por la dirección anotada. Si la aplicación está dentro de una subcarpeta de `htdocs`, incluye esa ruta; por ejemplo: `http://192.168.1.25/supermercado/ss/tienda.php?sucursal=1`.
5. La tablet permite elegir productos y enviar un pedido. El pedido aparece en **Caja**; un cajero debe cargarlo y confirmar el pago. No se cobra automáticamente.

No uses `localhost` en la tablet: ese nombre apunta a la propia tablet, no a la computadora con XAMPP. Si no abre, permite Apache en el Firewall de Windows para redes privadas y revisa que ambos dispositivos sigan en la misma red. No configures redirecciones de puertos en el router para este uso. Para clientes fuera de la red local, configura un dominio HTTPS y el enlace de tienda pública en **Configuración**.

### Registrar ventas y comprobantes

Las ventas se registran desde **Caja**: agrega los artículos, pulsa **Confirmar y cobrar**, elige el medio de pago y confirma. Al completarse, el sistema abre el comprobante de esa operación. Desde **Ventas**, **Ir a caja y registrar venta** lleva al punto de cobro y cada operación permite volver a abrir su comprobante para imprimirlo o guardarlo como PDF. Los cajeros solo pueden consultar sus propias ventas.

El comprobante es **no fiscal**. Muestra los artículos, totales, sucursal y medio de pago; los documentos del cliente y el CUIT se muestran parcialmente ocultos. Para emitir factura o ticket fiscal hace falta integrar un sistema autorizado; este comprobante no lo reemplaza.

---

## Roles y permisos

### 👑 JEFE DE SUCURSAL (`jefe`)
**Acceso:** Administración operativa de las sucursales que tenga asignadas; segundo factor TOTP obligatorio.
**Puede:**
- Crear y administrar cuentas con los roles predefinidos
- Cambiar cargos y restablecer contraseñas
- Activar/desactivar cuentas excepto la propia o el último Administrador
- Consultar accesos y errores del sistema
- Ver reportes completos
- Configurar sistema

**Menú:**
- Dashboard (datos de la sucursal activa)
- Personal (gestión completa)
- Caja (registrar ventas)
- Productos
- Categorías
- Pedidos
- Ventas
- Asignación de cajas
- Reportes
- Configuración

---

### 👔 GERENTE (`supervisor`)
**Acceso:** Operación y reportes; segundo factor TOTP obligatorio.
**Puede:**
- Crear encargados, repositores y cajeros; no puede crear Administradores ni Gerentes
- Ajustar costo/comisión/precio de productos
- Administrar socios clientes y el beneficio del 20%
- Asignar cajeros a cajas
- Ver ventas, reportes, actividad y personal

**Menú:**
- Dashboard (personal en servicio, horarios, stock bajo)
- Personal (ver y crear cargos operativos limitados)
- Socios (registrar clientes y activar/desactivar beneficios)
- Productos (ver y ajustar precios)
- Pedidos (solo ver)
- Ventas (solo ver)
- Asignación de cajas
- Reportes

**Tablero:**
```
┌─────────────────────────────────────────┐
│ 👥 En servicio    │ 📊 Stock bajo       │
│    3 empleados    │    5 productos      │
├─────────────────────────────────────────┤
│ Control de Personal - Horarios          │
│                                         │
│ Juan Pérez      08:00  -  -   4h 30m   │
│ María López     08:30  -  -   4h        │
│ Carlos Ruiz     -      -  -   Salió     │
└─────────────────────────────────────────┘
```

---

### 📦 ENCARGADO
**Acceso:** Operaciones, pedidos y asignación de cajas
**Puede:**
- Crear y gestionar pedidos
- Asignar cajeros a cajas
- Ver personal
- Ver productos
- Ver ventas

**Menú:**
- Dashboard (pedidos pendientes)
- Personal (solo ver)
- Reposición
- Productos (solo ver)
- Categorías (solo ver)
- Pedidos (crear, editar, recibir)
- Ventas (solo ver)
- Asignación de cajas

**Tareas típicas:**
1. Crear nuevo pedido (Pedidos > Nuevo)
2. Agregar items (producto, cantidad y costo de compra)
3. Esperar recepción
4. Marcar como recibido (actualiza stock)
5. Asignar cajeros (Cajas > Asignar)

---

### 📫 REPOSITOR
**Acceso:** Solo el panel Reposición
**Puede:**
- Registrar unidades recibidas usando costo/comisión autorizados
- Ver cantidades y faltantes
- No puede editar costos, comisiones ni precios

**Menú:** Reposición únicamente. No puede abrir Productos ni Categorías ni modificar costos, comisiones o precios.

---

### 💳 CAJERO
**Acceso:** Solo Caja y su propia jornada
**Puede:**
- Cargar pedidos enviados desde la tablet
- Revisar disponibilidad/precio actual y confirmar el cobro
- Consultar únicamente sus ventas

**Menú:** Caja

---

## Compra desde tablet y lotes

1. Publica la tienda con HTTPS y abre `https://tu-dominio.com/supermercado`. Codifica únicamente esa URL en el QR; no incluyas IP ni rutas internas.
2. El cliente agrega artículos, ingresa su nombre obligatorio y elige efectivo o un método digital configurado en **Configuración**. Si selecciona Mercado Pago y tiene un enlace configurado, la tienda crea el pedido y redirige directamente a ese enlace para pagar. Si no hay enlace, muestra el CVU configurado y permite copiarlo para transferir desde el banco o billetera. Cada pedido recibe un ID y reserva su stock hasta que caja lo procese o cancele.
3. Caja muestra el ID, el nombre, los artículos y el método elegido.
4. El cajero carga el pedido; Caja vuelve a comprobar el stock y calcula precios por lote FIFO. Para pagos digitales, debe verificar el pago en el proveedor: el enlace estático no confirma pagos ni envía automáticamente el total.
5. Al confirmar el cobro, la venta, el detalle, los lotes y el pedido se guardan en una transacción. La tienda descarga un comprobante de compra al detectar la confirmación de caja; el cliente también puede volver a descargarlo desde el seguimiento del pedido. El comprobante no reemplaza una factura fiscal. Si algo falla al confirmar el cobro, se revierte el descuento.

Configura enlaces HTTPS de Mercado Pago y de otra billetera virtual o un CVU de 22 dígitos en **Configuración → Tienda en tablet y pagos**. Cuando falta el enlace, los métodos digitales usan el CVU y la tienda lo muestra para copiar; no es posible iniciar automáticamente una transferencia solo con el CVU. Caja debe verificar manualmente el pago y el total final; un enlace simple tampoco confirma pagos automáticamente. Para confirmación automática hace falta integrar la API/webhook oficial del proveedor.

Para recibir mercadería, el gerente/admin carga costo y porcentaje. Por ejemplo, costo `$1.000` más comisión `50%` calcula venta `$1.500`. Los lotes nuevos conservan su precio; el stock viejo conserva el suyo salvo que Gerencia/Administración aplique explícitamente un cambio de precio a las unidades restantes.

## Seguridad y diagnóstico

- Administrador y Gerente configuran TOTP con una aplicación autenticadora en su primer acceso; deben guardar los códigos de recuperación fuera del equipo.
- `accesos.php` registra inicios, fallos, MFA y cierres. `errores.php` muestra el registro técnico de la aplicación solo al Administrador.
- `health.php` devuelve JSON de salud para un monitor externo. Si Apache/MySQL está apagado, el panel no puede arrancarlo; el servidor necesita un monitor de sistema con permisos externos.
- La tienda es un flujo básico de una sucursal. Para clientes remotos o despliegue multinacional hace falta infraestructura/API, HTTPS, rate limiting, copias probadas, monitoreo y pruebas de carga.

**Tablero:**
```
┌────────────────────────────────┐
│ 💰 Ventas hoy: $2.450,50       │
│ 🧾 Mi jornada: 4h 25m         │
│                                │
│      [Ir a Caja]              │
└────────────────────────────────┘
```

---

## Cómo usar cada módulo

### 1. PERSONAL

#### Crear empleado (Administrador/Gerente)
```
1. Ir a: Personal
2. Formulario "Nuevo empleado"
3. Nombre: Juan Pérez
4. Usuario: juan_perez (minúsculas, único)
5. Rol: Administrador puede elegir todos; Gerente solo cargos operativos
6. Contraseña: Mínimo 12 caracteres
7. Clic en "Crear"
```

#### Ver empleados
```
Todos los roles autorizados ven:
- Nombre
- Usuario (login)
- Rol
- Estado (activo/inactivo)
- Horas trabajadas (mes)
- Ventas realizadas (mes)

Jefe puede:
- Editar nombre y cargo, restablecer contraseña
- Activar/Desactivar; no puede desactivar su propia cuenta ni al último Administrador
```

---

### 2. PRODUCTOS

#### Crear producto (Jefe)
```
1. Productos > Nuevo producto
2. Código: "001-CCA-500" (único, identificador)
3. Nombre: "Gaseosa Coca-Cola 500ml"
4. Categoría: Seleccionar (ej: Bebidas)
5. Precio: 45.99
6. IVA: 21 (porcentaje)
7. Stock mínimo: 12 (cantidad de alerta)
8. Crear
```

#### Ingresar un lote (Repositor/Encargado/Gerencia)
```
1. Abrir Reposición
2. Seleccionar producto y unidades recibidas
3. Repositor usa el costo y comisión autorizados
4. Gerente/Administrador puede ajustar costo, comisión o precio final de ese lote
5. Registrar ingreso; las unidades quedan asociadas a ese precio
```

Los lotes mantienen precio independiente y Caja vende primero el más antiguo. El Administrador/Gerente puede cambiar explícitamente el precio del stock restante desde Productos.

#### Ver stock bajo
```
- Aparece en Dashboard (supervisor, encargado)
- Productos marcados en amarillo/rojo
- Va a reportes para detalles
```

---

### 3. CATEGORÍAS

#### Crear categoría (Jefe)
```
1. Categorías > Nueva categoría
2. Nombre: "Bebidas"
3. Ícono: "cup-straw" (bootstrap-icon)
   (Ver: https://icons.getbootstrap.com/)
4. Crear
```

#### Icono bootstrap
Ejemplos comunes:
- basket = Alimentos
- droplet = Lácteos
- egg-fried = Carnes
- house = Bazar
- stars = Limpieza
- heart = Perfumería

---

### 4. CAJA (Registrar ventas)

#### Flujo completo
```
1. Login como Cajero
2. Dashboard > [Ir a Caja]
   (O directamente: Caja en menú)

3. Buscar productos:
   - Escribir nombre (búsqueda en vivo)
   - Clic en producto
   - Se agrega al carrito

4. Carrito muestra:
   - Producto
   - Cantidad
   - Precio unitario
   - Total con IVA

5. Finalizar venta:
   - Clic en botón "Finalizar venta"
   - Modal con opciones:
     * Cliente (opcional)
     * DNI/Cuit (opcional)
     * DNI del socio (opcional): se valida y aplica 20% de descuento
     * Forma de pago (efectivo/tarjeta/etc)
     * Total con descuento visible antes de confirmar
   - Clic en "Cobrar"

6. Recibo:
   - Mensaje de éxito
   - Número de comprobante
   - Carrito limpio
   - Listo para siguiente cliente
```

#### Editar carrito
```
- Aumentar cantidad: agregar mismo producto otra vez
- Quitar producto: clic en "×" al lado
- Vaciar todo: botón "Vaciar carrito"
```

#### Validaciones
```
✓ No permite agregar si no hay stock
✓ No permite finalizar con carrito vacío
✓ Calcula automáticamente IVA
✓ Valida socios activos por DNI, aplica el 20% y registra el descuento en la venta
✓ Actualiza stock automáticamente
```

#### Socios
Administración y Gerencia pueden abrir **Socios**, registrar clientes con DNI de 7 u 8 dígitos y activar o desactivar su beneficio. En Caja, el cajero ingresa el DNI del cliente; se valida que esté activo y se descuenta el 20% del total antes de cobrar. La venta queda vinculada al socio y el historial y comprobante muestran el descuento.

---

### 5. PEDIDOS (Encargado)

#### Crear pedido
```
1. Pedidos > Nueva orden
2. Nombre del proveedor: "Fornax SA"
3. Crear
```

#### Agregar items
```
1. Clic en pedido
2. Botón "Agregar item"
3. Seleccionar producto
4. Cantidad: 50
5. Precio unitario: 22.50
6. Agregar
7. Repite para más items
```

#### Recibir pedido
```
1. Pedido en estado "pendiente"
2. Botón "Marcar como recibido"
3. Automático:
   - Suma stock
   - Resta de "entrante"
   - Registra fecha recepción
```

---

### 6. ASIGNACIÓN DE CAJAS

#### Asignar cajero
```
1. Asignación de Cajas
2. Seleccionar cajero (que no esté en caja)
3. Seleccionar caja disponible (1-5)
4. Asignar
   
Resultado:
- Cajero entra en servicio
- Caja asignada a ese cajero
- Se ve en "Cajas en servicio"
- Puede empezar a vender
```

#### Desasignar
```
1. Cajas en servicio
2. Clic en "Desasignar" del cajero
3. Se registra hora de salida
4. Caja disponible para otro cajero
5. Se ve en "Historial de hoy"
```

---

### 7. REPORTES (Gerente/Administrador)

#### Reporte diario
```
Filtro: Seleccionar fecha
Muestra:
- Ventas por hora
- Top 10 productos
- Gráfico de tendencias
```

#### Reporte por período
```
Filtro: Desde / Hasta
Muestra:
- Ventas por día
- Ventas por cajero
- Comparativa
```

#### Reporte de stock
```
Sin filtro, muestra:
- Productos con stock bajo
- Productos sin stock
- Cantidad por recibir
```

---

## Preguntas frecuentes

### P: ¿Cómo restablecer una contraseña?
**R:** Administrador abre Personal > Editar y establece una nueva clave de al menos 12 caracteres.

### P: ¿Se pueden ver ventas antiguas?
**R:** Sí. Ventas > Seleccionar fechas > Ver historial

### P: ¿Qué pasa si la BD se llena?
**R:** Implementar limpieza:
- Archivar ventas antiguas
- Borrar auditoría vieja

### P: ¿Máximo de usuarios?
**R:** Sin límite técnico, pero:
- 100+ usuarios: considerar particionamiento
- Ver performance con `EXPLAIN`

### P: ¿Se puede backup automático?
**R:** Sí, usar:
```bash
mysqldump -u root -p super_simple_v3 > backup.sql
# Programar con cron cada noche
```

### P: ¿Cómo agregar nuevas categorías?
**R:** Jefe > Categorías > Nueva categoría

### P: ¿Qué hacer si un empleado olvidó su contraseña?
**R:** El Administrador puede restablecer la contraseña. Si también perdió sus códigos TOTP, debe recuperar la cuenta desde un procedimiento administrativo seguro.

### P: ¿Se ven todas las ventas en reportes?
**R:** Sí. Gerente/Administrador ven las ventas; Cajero solo sus operaciones. El historial identifica los pedidos que llegaron desde tablet.

### P: ¿Puedo modificar precios después de vender?
**R:** Sí, no afecta ventas registradas (usan precio de venta)

---

## Errores comunes

### ❌ "No tenés permiso para acceder"
Significa: Tu rol no tiene permisos para eso
**Solución:** Contactar al jefe

### ❌ "Error de BD: Consulta SQL inválida"
Significa: Error en la consulta
**Solución:** Revisar que datos sean válidos

### ❌ "Sesión vencida"
Significa: Estuviste mucho tiempo sin actividad
**Solución:** Loguear nuevamente

### ❌ Servidor apagado
`errores.php` solo funciona mientras PHP y Apache estén disponibles. `health.php` puede consultarlo un monitor externo; reiniciar Apache/MySQL requiere un supervisor de procesos del servidor, nunca permisos de reinicio desde la web.

### ❌ "Usuario ya existe"
Significa: Ese usuario fue creado antes
**Solución:** Usar otro nombre de usuario

### ❌ "No puedes eliminar, tiene productos"
Significa: La categoría tiene items
**Solución:** Mover productos a otra categoría

---

## Atajos útiles

```
Dashboard: /dashboard.php
Portal del dueño: /dueno.php
Mensajes y órdenes: /comunicaciones.php
Personal: /personal.php
Caja: /caja.php
Productos: /productos.php
Categorías: /categorias.php
Pedidos: /pedidos.php
Ventas: /ventas.php
Cajas: /cajas.php
Reportes: /reportes.php
Configuración: /configuracion.php
Logout: /logout.php
```

---

## Soporte

Para problemas técnicos:
1. Revisar PRUEBAS_EXHAUSTIVAS.md
2. Verificar permisos del usuario
3. Revisar logs del servidor
4. Contactar desarrollador

---

**Versión:** 3.1  
**Última actualización:** 2026-09-19  
**Estado:** Migración y despliegue PWA pendientes de ejecutar y validar en el servidor del supermercado.
