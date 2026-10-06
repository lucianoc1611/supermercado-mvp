# Preparar el acceso seguro con Cloudflare Tunnel

Esta guía prepara una copia separada del proyecto y explica cómo conectarla al dominio `portal.supermercado.com`. Cloudflare Tunnel evita abrir puertos del router; **no mantiene encendida ni respalda la computadora**. El equipo con XAMPP y MySQL debe permanecer encendido y conectado.

## 1. Preparar la copia

Haz una copia de seguridad de MySQL desde phpMyAdmin antes de hacer cambios. La copia de seguridad de la base **no** debe guardarse dentro de la carpeta pública del sitio.

1. Abre el Explorador de archivos y entra en `C:\xampp\htdocs\supermercado\ss`.
2. Haz clic derecho en `preparar_supermercado_cloud.ps1` y selecciona **Ejecutar con PowerShell**.
3. La copia se crea en `Documentos\supermercaso cloud`. El script no sobrescribe una carpeta existente ni copia `.env`, registros o respaldos SQL.

Si Windows no permite ejecutarlo, abre PowerShell y ejecuta:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\supermercado\ss\preparar_supermercado_cloud.ps1"
```

## 2. Confirmar el dominio en Cloudflare

El dominio elegido es `supermercado.com` y el portal será `portal.supermercado.com`. La comprobación de DNS de este equipo encontró que el dominio todavía usa los servidores de nombres de Dinahosting y que `portal.supermercado.com` aún no resuelve. Por eso, aunque tengas una cuenta de Cloudflare, el sitio todavía no puede abrirse desde Internet.

1. Agrega `supermercado.com` a tu cuenta de Cloudflare y espera a que Cloudflare te muestre los dos servidores de nombres asignados.
2. En el panel de Dinahosting, cambia los servidores de nombres actuales por esos dos valores exactos.
3. Antes de confirmar, conserva o recrea en Cloudflare los registros DNS que ya uses, especialmente los de correo (MX, SPF, DKIM y DMARC). No cambies los registros de correo si no sabes para qué sirven.
4. Espera hasta que Cloudflare muestre el dominio como **Active**. No crees la ruta DNS del túnel ni intentes abrir el portal público antes de ese momento.

## 3. Crear el túnel

`cloudflared` ya está instalado en este equipo. Cuando Cloudflare muestre el dominio como **Active**, abre Símbolo del sistema con tu usuario de Windows y ejecuta:

```text
"C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel login
"C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel create supermercado-local
```

`tunnel login` abre Cloudflare en el navegador para que tú autorices la cuenta. No compartas ni pegues en chats el certificado, los tokens o el archivo de credenciales del túnel. El comando de creación muestra un UUID y crea un archivo de credenciales bajo `%USERPROFILE%\.cloudflared`.

Copia el ejemplo `cloudflare\config.yml.example` a `%USERPROFILE%\.cloudflared\config.yml` y reemplaza el UUID de muestra y el nombre de usuario de Windows:

```yaml
tunnel: UUID-DEL-TUNEL
credentials-file: 'C:\Users\TU-USUARIO\.cloudflared\UUID-DEL-TUNEL.json'

ingress:
  - hostname: portal.supermercado.com
    service: http://127.0.0.1:80
  - service: http_status:404
```

Comprueba primero que Apache sirve la aplicación en esta computadora en `http://localhost/`. No ejecutes el túnel antes de configurar el host virtual de la copia del paso 4.

## 4. Servir la copia desde Apache

En este equipo, Apache ya tiene agregado el host virtual `portal.supermercado.com`, que apunta a `C:\Users\lucia\OneDrive\Documentos\supermercaso cloud`. Su configuración está en `C:\xampp\apache\conf\extra\httpd-supermercado-cloud.conf`, incluida desde `httpd-vhosts.conf`. Si preparas otra PC, adapta `cloudflare\httpd-vhost.conf.example` y agrega el `Include` en la configuración de Apache de esa PC.

Antes de reiniciar Apache, comprueba la sintaxis desde PowerShell:

```powershell
& 'C:\xampp\apache\bin\httpd.exe' -t
```

No publiques `C:\xampp\htdocs` completo. El túnel debe llegar al host virtual dedicado para esta copia.

## 5. No exponer MySQL con la cuenta root

El proyecto usa MySQL local. **No uses `root` ni una contraseña vacía con `APP_ENV=production`**. En este equipo ya se creó `supermercado_app` con permisos limitados a lectura y escritura de los datos de la aplicación. Si preparas otra instalación, haz primero una copia de seguridad y, desde phpMyAdmin, selecciona SQL y crea un usuario exclusivo con una contraseña nueva y larga (no la compartas):

```sql
CREATE USER 'supermercado_app'@'127.0.0.1' IDENTIFIED BY 'REEMPLAZA_POR_UNA_CLAVE_LARGA_Y_UNICA';
GRANT SELECT, INSERT, UPDATE, DELETE ON super_simple_v3.* TO 'supermercado_app'@'127.0.0.1';
```

Si MySQL informa que el usuario ya existe, no repitas ni cambies permisos a ciegas.

## 6. Configurar el acceso del portal

En este equipo, `C:\xampp\apache\conf\extra\httpd-supermercado-cloud-env.conf` ya contiene la configuración de producción y una contraseña aleatoria para el usuario limitado. El archivo está fuera de la carpeta pública y sus permisos se restringieron a la cuenta de Windows actual, SYSTEM y Administradores. Si preparas otra PC, copia `cloudflare\httpd-env.conf.example` a esa ubicación, define una contraseña segura y limita los permisos de lectura a Apache y Administradores.

No subas estos archivos de configuración a GitHub ni los copies a una carpeta pública.

## 7. Crear la ruta DNS y probar el sitio

Después de que el dominio esté **Active** en Cloudflare y Apache haya pasado la prueba de sintaxis, crea la ruta DNS:

```text
"C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel route dns supermercado-local portal.supermercado.com
```

En una ventana de Símbolo del sistema ejecuta el túnel:

```text
"C:\Program Files (x86)\cloudflared\cloudflared.exe" tunnel run supermercado-local
```

Con esa ventana abierta, prueba `https://portal.supermercado.com/health.php` y luego inicia sesión en `https://portal.supermercado.com/`. Si falla, detén el túnel con `Ctrl+C` y revisa Apache y la consola; no abras puertos en el router. No instales el túnel como servicio permanente hasta validar el acceso, la aplicación y los respaldos.

Cuando HTTPS responda correctamente, entra como propietario en **Configuración** y define la URL pública de tienda como:

```text
https://portal.supermercado.com/supermercado
```

**QR de tienda** ofrece dos códigos:

- **QR local**: se genera con la IP privada actual de la PC XAMPP. Sirve para probar desde un celular conectado a la misma Wi-Fi; no sirve desde datos móviles ni fuera del local. Al volver a entrar a la sección se detecta otra vez la IP actual, pero una copia ya impresa no se puede actualizar por sí sola. Si se imprimirá para uso permanente dentro del local, reserva la IP de la PC para XAMPP desde el router.
- **QR público**: aparece después de configurar una URL HTTPS en Configuración y usa el dominio del túnel, no una IP. Cuando el dominio y Cloudflare Tunnel estén activos, es el código para compartir con clientes y no cambiará si cambia la IP de Internet del local.

Después de validar el sistema y respaldos, puedes configurar `cloudflared` para arrancar con Windows siguiendo la documentación oficial. La sesión de prueba de arriba termina al cerrar la ventana o apagar la PC.

## Límites y seguridad

- XAMPP en una PC local no es una plataforma de alojamiento administrada. Si se apaga la PC, falla Internet o se suspende Windows, el sistema deja de estar disponible.
- Mantén Windows, XAMPP y MySQL actualizados; usa contraseñas únicas, MFA y respaldos frecuentes fuera de esta PC.
- El dominio público permite que cualquier visitante llegue al inicio de sesión y a la tienda pública. No compartas enlaces administrativos ni credenciales. Revisa Cloudflare Access/WAF antes de operar con datos reales.
- La APK Android queda pendiente hasta que el host HTTPS esté configurado y probado.
- Este procedimiento no migrará datos ni importará un esquema. Conserva tu base `super_simple_v3`; realiza la copia de seguridad antes de cualquier ajuste.
