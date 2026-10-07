# Kiosco Android para compra en tablet

## URL pública y QR

En **Configuración → Tienda en tablet y pagos**, registra la URL pública como `https://tu-dominio.com/supermercado`. El QR debe codificar únicamente esa URL. No uses la IP local ni la ruta física de la carpeta. La aplicación resuelve `/supermercado` directamente a la tienda en Vercel y, con Apache, mediante la regla de `.htaccess`.

El dominio debe apuntar al servidor y tener un certificado TLS válido. En producción configura `APP_ENV=production` y `SUPERMERCADO_PUBLIC_URL=https://tu-dominio.com/supermercado`; la aplicación redirige las peticiones HTTP al mismo dominio HTTPS. Vercel proporciona HTTPS para los dominios asignados. En Apache habilita `mod_rewrite`, permite las reglas `.htaccess` y configura el DocumentRoot del sitio para que apunte a la carpeta de esta aplicación.

Para desarrollo local se puede abrir `http://localhost/.../tienda.php`, pero HTTP no permite usar la cámara QR en Android. No publiques ni imprimas una URL local o una IP.

## Dirección de la tablet

Configura como página de inicio de la tablet la URL pública `/supermercado`. El acceso QR muestra solo el dominio y esa ruta corta, no el nombre de la carpeta ni `tienda.php`.

## Bloqueo de la tablet

Una página PHP no puede impedir que Android cierre el navegador, abra otra aplicación o cambie la URL. Para que el usuario no salga del catálogo hace falta configurar el dispositivo Android en modo dedicado/kiosco:

1. Usa Android Enterprise en modo **Dedicated device / Lock task** mediante el MDM de la empresa, o un navegador kiosco administrado.
2. Configura como página de inicio la URL de producción y como navegación permitida únicamente la ruta `/supermercado` de tu dominio.
3. Permite cargar recursos del mismo servidor en `/assets/vendor/`; bloquea otras rutas y dominios.
4. Activa pantalla completa, inicio automático al encender y bloqueo de barra de notificaciones, Ajustes, Home, multitarea e instalación de aplicaciones.
5. Configura el PIN de salida en el MDM/navegador kiosco y consérvalo solo con Administración. No uses una clave del cliente ni una clave compartida con el cajero.
6. Prueba reinicio, pérdida de Wi-Fi, retorno automático al catálogo y salida con el PIN de administración antes de entregar la tablet.

Configura el lector físico como teclado HID y define sufijo **Enter**. Debe enviar el mismo código que figura en `productos.codigo`; las etiquetas demo usan SKU `DEMO-0001` a `DEMO-0100`. Si se usa la cámara del tablet para leer QR, habilita cámara en Chrome/MDM y publica la tienda bajo HTTPS; Android no permite cámara en la URL HTTP local.

El anclaje de pantalla estándar de Android es una alternativa para pruebas, pero no restringe las direcciones dentro de Chrome. Para un bloqueo empresarial usa modo dispositivo dedicado y allowlist.

## Flujo de compra

El cliente debe ingresar su nombre y recibe un ID generado para seguir el pedido. El pedido y su reserva permanecen hasta que caja lo procese o cancele. Puede elegir efectivo (pago en caja) o un enlace HTTPS configurado por administración para Mercado Pago/otra billetera. Los enlaces externos son enlaces de pago estáticos: no informan el importe ni notifican el pago a esta aplicación; caja debe verificarlo y confirmar la compra. Las páginas internas siguen protegidas por login y permisos. El PIN de salida del kiosco protege Android; no reemplaza la contraseña del personal.