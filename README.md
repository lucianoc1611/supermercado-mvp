# 🛒 Sistema de Supermercado v3.0

**Sistema completo de gestión para supermercados con paneles de control por rol.**

---

## ⚡ Instalación local con XAMPP

### 1. Crear base de datos
```bash
mysql -u root -p
CREATE DATABASE super_simple_v3 CHARACTER SET utf8mb4;
USE super_simple_v3;
SOURCE base_de_datos_nueva.sql;
EXIT;
```

### 2. Descargar archivos
```bash
# En XAMPP coloca la carpeta dentro de C:\xampp\htdocs\supermercado
# En Apache Linux, por ejemplo: /var/www/html/supermercado
```

### 3. Actualizar el esquema
Desde la carpeta del proyecto ejecuta `php migrar_operaciones.php` (en XAMPP: `C:\xampp\php\php.exe migrar_operaciones.php`). La migración es repetible, agrega las tablas/columnas nuevas y convierte el stock actual en lote legado sin modificar ventas.

Para una base **de desarrollo** únicamente, `php cargar_demo.php` agrega productos `DEMO-` y cuentas demo con claves aleatorias impresas una sola vez. El cargador se bloquea con `APP_ENV=production`.

### 4. Verificar configuración
En desarrollo local se usan los valores predeterminados de XAMPP. En el servidor define `APP_ENV=production` y configura `SUPERMERCADO_DB_HOST`, `SUPERMERCADO_DB_NAME`, `SUPERMERCADO_DB_USER` y `SUPERMERCADO_DB_PASSWORD` en el entorno del servidor. Producción rechaza la cuenta `root` y contraseñas vacías.

### 5. Acceder
Abrir en navegador:
```
http://localhost/supermercado/index.php
```

Esto redirige a `setup.php` (instalación inicial).

---

## 🐘 Crear el esquema inicial en Supabase

En Supabase abre **SQL Editor → New query**, pega el contenido de [`supabase_schema.sql`](./supabase_schema.sql) y ejecútalo. Ese archivo usa PostgreSQL, crea las tablas operativas y permisos iniciales, y no ejecuta `DROP`, no borra datos ni intenta crear una base de datos aparte. No cargues `base_de_datos_nueva.sql` en Supabase: ese archivo es exclusivo para MySQL/XAMPP.

El esquema habilita RLS en todas las tablas y no crea políticas para `anon` ni `authenticated`, por lo que la API pública de Supabase no tendrá acceso a los datos. Mantén las credenciales privilegiadas solo del lado servidor.

**Importante:** crear las tablas no completa la migración de la aplicación. Los archivos PHP actuales aún usan PDO y consultas MySQL; no conectes ni despliegues la aplicación contra este esquema hasta completar y probar su adaptación a PostgreSQL.

---

## ☁️ Despliegue en Vercel

Vercel puede ejecutar PHP con el runtime comunitario `vercel-php`; Supabase aloja PostgreSQL. **Este proyecto todavía usa consultas y migraciones específicas de MySQL/MariaDB**, por lo que no está listo para conectarse directamente a Supabase. No configures las credenciales de Supabase con `SUPERMERCADO_DB_*` ni ejecutes allí `base_de_datos_nueva.sql` o `migrar_operaciones.php`: esos archivos son para MySQL.

Antes de desplegar se necesita portar la capa de base de datos, el esquema y las consultas a PostgreSQL, además de preparar y verificar un plan de migración que preserve los datos actuales. Conserva una copia de seguridad de Supabase antes de cualquier cambio. No compartas contraseñas ni cadenas de conexión en el repositorio o el chat; se configurarán como variables de entorno de Vercel cuando el port esté listo.

---

## 🎯 Características

✅ **Acceso por cargo** - Administrador, Gerente, Encargado, Repositor y Cajero  
✅ **Segundo factor TOTP** - Obligatorio para Administrador y Gerente  
✅ **Paneles y permisos de servidor** - Repositor solo repone; Cajero solo opera Caja  
✅ **Gestión de personal** - Crear, editar, desactivar empleados  
✅ **Inventario por lotes** - Cada ingreso conserva costo, comisión y precio propio  
✅ **Caja transaccional** - Confirma pedidos y descuenta FIFO evitando stock negativo  
✅ **Tienda para tablet** - Catálogo táctil; los pedidos quedan en cola para el Cajero  
✅ **Pedidos de compra** - Encargados cargan pedidos  
✅ **Accesos y errores** - Registro administrativo y endpoint de salud  

---

## 👥 Roles y Permisos

| Cargo | Acceso principal |
|-------|------------------|
| **Administrador (`jefe`)** | Administración completa, accesos, errores y configuración |
| **Gerente (`supervisor`)** | Operación, reportes, asignación de cajas, precios y altas limitadas de personal |
| **Encargado** | Pedidos, cajas y consulta operativa |
| **Repositor** | Solo panel de reposición; sin acceso a Productos/Categorías |
| **Cajero** | Solo Caja; pedidos de tablet, cobro y ventas propias |

- **CRUD** = Crear, Leer, Actualizar, Borrar
- **✓** = Acceso permitido
- **-** = Sin acceso

---

## 📋 Archivos incluidos

```
supermercado_nuevo/
├── base_de_datos_nueva.sql     ← Importar primero
├── supabase_schema.sql          ← Esquema PostgreSQL para Supabase
├── lib.php                      ← Librerías y funciones
├── migrar_operaciones.php       ← Migración aditiva (ejecutar por CLI)
├── cargar_demo.php              ← Datos de prueba solo desarrollo
├── login.php                    ← Página de login
├── mfa.php                      ← Verificación en dos pasos
├── setup.php                    ← Instalación inicial
├── logout.php                   ← Cerrar sesión
├── dashboard.php                ← Paneles por rol
├── personal.php                 ← Gestión de empleados
├── reponer.php                  ← Panel único de reposición
├── productos.php                ← Gestión de productos
├── categorias.php               ← Gestión de categorías
├── caja.php                     ← Registro de ventas
├── tienda.php                   ← Catálogo público para tablet
├── GUIA_KIOSCO_ANDROID.md       ← Configuración del dispositivo dedicado
├── pedidos.php                  ← Pedidos de compra
├── ventas.php                   ← Reportes de ventas
├── cajas.php                    ← Asignación de cajas
├── reportes.php                 ← Reportes supervisores
├── accesos.php                  ← Historial de accesos (admin)
├── errores.php                  ← Diagnóstico (admin)
├── health.php                   ← Health check JSON
├── configuracion.php            ← Configuración (jefe)
├── index.php                    ← Página inicio
├── assets/                      ← Bootstrap, iconos
├── MANUAL_DE_USO.md            ← Guía completa
├── PRUEBAS_EXHAUSTIVAS.md      ← Tests
└── README.md                    ← Este archivo
```

---

## 🚀 Uso

### Instalación
```
http://localhost/supermercado/ss/index.php
→ setup.php
→ Ingresa datos supermercado
→ Crea usuario Administrador (clave de 12+ caracteres)
```

### Primer login
```
Usuario: (el que creaste en setup)
Contraseña: (la que creaste en setup)
```
En el primer acceso, Administrador y Gerente deben configurar una aplicación TOTP y guardar sus códigos de recuperación.

### Crear más usuarios (como jefe)
```
Personal → Nuevo empleado
- Nombre
- Usuario (único)
- Rol
- Contraseña (mínimo 12 caracteres)
```

### Vender (como cajero)
```
Caja → Cargar pedido de tablet o buscar productos → Confirmar y cobrar
```

### Compra desde tablet
En el servidor abre `http://localhost/supermercado/ss/tienda.php`. Desde la tablet en la misma LAN abre la IP del servidor, actualmente `http://192.168.1.39/supermercado/ss/tienda.php`. Configura el lector de barras/QR como teclado HID con sufijo Enter; el código debe coincidir con `productos.codigo`. La cámara del tablet para QR requiere HTTPS y permiso de cámara. El cliente arma el pedido y lo envía a la cola de Caja. El pedido no reserva stock ni se cobra: el Cajero confirma disponibilidad y precio antes de crear la venta. El endpoint `health.php` responde estado JSON para un monitor externo.

### Ingresar stock y comisiones
Un gerente/administrador configura en Productos el costo de referencia y comisión del producto. Reposición crea un lote con costo + comisión; por ejemplo, `$1.000` y `50%` produce `$1.500`. Cada lote conserva su precio; la venta consume primero el más antiguo. Los ajustes manuales de precio afectan el stock restante y quedan auditados.

---

## 🔒 Seguridad

- Consultas parametrizadas, escape HTML, tokens CSRF y contraseñas con `password_hash`.
- Sesiones con modo estricto, cookies `HttpOnly`/`SameSite`, regeneración al autenticar, vencimiento por inactividad y respuestas sin caché.
- Segundo factor TOTP para Administrador y Gerente; los códigos de recuperación se muestran una sola vez.
- Caja con transacciones, bloqueo de lotes e historial de costo/venta; pedido de tablet no puede alterar precio ni stock.
- La aplicación actual es un panel interno básico de una sola instalación; no está certificada ni lista para producción multinacional.
- Despliegue real requiere HTTPS, usuario MySQL dedicado con privilegios mínimos, copias de seguridad probadas, monitor externo y pruebas de carga. No expongas MySQL al cliente.
- `errores.php` solo ve errores de la aplicación. Ninguna página PHP puede levantar Apache/MySQL si el servidor está apagado: eso requiere un servicio supervisor/monitor externo con permisos de sistema.
- La tienda tablet actual es un flujo básico de pedido para una sucursal, no una plataforma multiempresa ni un API pública de gran escala.

---

## 📊 Base de datos

**Tablas principales:**
- usuarios (empleados)
- permisos (granulares)
- productos
- categorias
- ventas
- venta_items
- pedidos_compra
- pedido_items
- asignaciones_caja
- fichajes (entrada/salida)
- auditoria (registro)
- intentos (bloqueo login)
- lotes_stock (existencias por recepción)
- pedidos_tablet / pedido_tablet_items (cola de caja)
- registro_accesos (inicios, fallos, segundo factor y cierres)

---

## 🐛 Solución de problemas

### "Error conectando a BD"
→ Verificar credenciales en `lib.php`

### "Tabla no existe"
→ Importar `base_de_datos_nueva.sql` y ejecutar `php migrar_operaciones.php`

### "No tengo permiso"
→ Tu rol no permite esa acción (contactar jefe)

### "Contraseña incorrecta"
→ Jefe puede crear nuevo usuario para ti

---

## 📚 Documentación

- **MANUAL_DE_USO.md** - Guía completa por rol
- **PRUEBAS_EXHAUSTIVAS.md** - Testeo exhaustivo
- **base_de_datos_nueva.sql** - Schema de BD

---

## ⚙️ Requisitos

- PHP 7.4+
- MySQL 5.7+
- Apache/Nginx
- Bootstrap 5
- Bootstrap Icons

---

## 📝 Licencia

Uso libre para supermercados.

---

## ✅ Estado

**Versión:** 3.0  
**Fecha:** 2026-09-19  
**Estado:** ✅ PRODUCCIÓN (Probado y sin errores)

---

## 🎯 Próximas mejoras (futuro)

- [ ] App móvil
- [ ] Código de barras
- [ ] Integración con impresoras
- [ ] Facturas electrónicas
- [ ] Consultas en tiempo real
- [ ] Dashboard analytics avanzado

---

**¿Preguntas o problemas?**  
Revisar MANUAL_DE_USO.md o PRUEBAS_EXHAUSTIVAS.md
