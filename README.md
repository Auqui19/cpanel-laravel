# Laravel cPanel Deploy Panel

Panel web de despliegue para proyectos Laravel alojados en cPanel cuando no se dispone de terminal ni acceso SSH.

Permite ejecutar acciones de Composer y Artisan desde un subdominio, mostrando el progreso y el log en tiempo real. La ejecución se realiza mediante un proceso desacoplado iniciado desde PHP; **no requiere cron**.

> El proyecto está pensado para cuentas cPanel individuales. Cada cuenta debe tener su propia instalación del panel.

## Características

- Login con múltiples usuarios.
- Protección CSRF en formularios y acciones.
- Bloqueo temporal después de intentos fallidos.
- Detección automática del usuario de cPanel.
- Detección de proyectos Laravel que contienen `artisan`.
- Detección del binario PHP y Composer.
- Selector de proyecto cuando existen varios proyectos.
- Ejecución inmediata desde botones.
- Logs en vivo mediante polling.
- Historial de ejecuciones.
- Cancelación de procesos cuando el servidor lo permite.
- Diagnóstico separado en:
  - `OK`
  - `CON AVISOS`
  - `ERROR`
- Generador de `.env` usando `.env.example` como plantilla.
- Generación automática de `APP_KEY`.
- Sidebar responsive basado en Basecoat CSS.
- Tema oscuro.
- Identidad visual AstroStudio + Laravel.
- Sin framework PHP: funciona como una aplicación PHP ligera.

## Acciones disponibles

| Acción | Descripción |
|---|---|
| Doctor | Comprueba PHP, Composer, Laravel, permisos, `.env`, `APP_KEY` y migraciones |
| Instalar vendor | Ejecuta `composer install --no-dev --optimize-autoloader` |
| Dump autoload | Ejecuta `composer dump-autoload -o` |
| Generar APP_KEY | Genera la clave si todavía no existe |
| Estado migraciones | Ejecuta `php artisan migrate:status` |
| Migrar BD | Ejecuta `php artisan migrate --force` |
| Ejecutar seeders | Ejecuta `php artisan db:seed --force` |
| storage:link | Crea el enlace público de almacenamiento |
| Cachear todo | Cachea configuración, rutas y vistas |
| Limpiar cachés | Ejecuta `php artisan optimize:clear` |
| Limpiar vistas | Ejecuta `php artisan view:clear` |
| Modo mantenimiento | Ejecuta `php artisan down` |
| Salir de mantenimiento | Ejecuta `php artisan up` |
| Actualizar todo | Composer + migraciones + limpieza de caché |

No existe un campo para ejecutar comandos arbitrarios. Las acciones están definidas en una lista blanca.

## Requisitos

### En el servidor

- cPanel.
- PHP 8.0 o superior.
- PHP CLI disponible, normalmente:

```text
/opt/cpanel/ea-php82/root/usr/bin/php
```

- Bash.
- `proc_open`, `exec` o `shell_exec` habilitado para ejecución desacoplada.
- Apache o LiteSpeed para que funcionen las reglas `.htaccess`.
- Un proyecto Laravel dentro de la misma cuenta cPanel.

### En el proyecto Laravel

- Archivo `artisan`.
- `composer.json`.
- Composer disponible o `composer.phar`.
- Permisos de escritura en:

```text
storage/
bootstrap/cache/
```

## Instalación en cPanel

### 1. Subir el proyecto

Sube todo el contenido del repositorio a la carpeta del subdominio:

```text
/home/USUARIO/public_html/deploy.tudominio.com/
```

La estructura debe quedar así:

```text
deploy.tudominio.com/
├── index.php
├── ajax.php
├── run.php
├── env.php
├── run.sh
├── composer.phar
├── .htaccess
├── app/
│   ├── .htaccess
│   ├── actions.php
│   ├── auth.php
│   ├── bootstrap.php
│   ├── config.php
│   ├── envfile.php
│   ├── exec.php
│   └── settings.php
├── assets/
│   ├── astrostudio.webp
│   ├── panel.css
│   └── panel.js
└── data/
    └── .htaccess
```

### 2. Configurar el subdominio

El document root debe apuntar a:

```text
/home/USUARIO/public_html/deploy.tudominio.com
```

No apunta a una carpeta `public/`, porque este panel está preparado para trabajar con un document root fijo dentro de `public_html`.

### 3. Configurar credenciales

Edita:

```text
app/config.php
```

Ejemplo:

```php
<?php

return [
    'name' => 'Panel de despliegue Laravel',

    'users' => [
        'admin' => 'cambia-esta-clave',
    ],
];
```

Se pueden agregar varios usuarios:

```php
'users' => [
    'admin' => 'clave-del-administrador',
    'soporte' => 'clave-del-soporte',
],
```

También se aceptan hashes generados con `password_hash()`:

```php
'users' => [
    'admin' => '$2y$10$...',
],
```

Para producción se recomienda usar hashes en lugar de contraseñas en texto plano.

### 4. Permisos

Desde el Administrador de archivos de cPanel:

```text
run.sh  -> 755
data/   -> 755 o 700
app/    -> 755
assets/ -> 755
```

Los archivos PHP normalmente pueden usar `644`.

### 5. Activar SSL

Activa SSL para el subdominio y entra siempre usando:

```text
https://deploy.tudominio.com
```

### 6. Abrir el panel

Al entrar, el panel detecta:

- Usuario de cPanel.
- Proyectos Laravel.
- Ruta del proyecto.
- PHP disponible.
- Composer.

Si encuentra varios proyectos, puedes seleccionar cuál administrar.

## Flujo recomendado de despliegue

1. Ejecutar **Doctor**.
2. Crear el archivo `.env`.
3. Instalar `vendor`.
4. Generar `APP_KEY`, si falta.
5. Ejecutar **Estado migraciones**.
6. Ejecutar **Migrar BD**.
7. Ejecutar `storage:link`.
8. Ejecutar **Cachear todo**.
9. Ejecutar nuevamente **Doctor**.

## Generador de `.env`

El generador utiliza `.env.example` como plantilla.

Puede modificar:

- `APP_ENV`
- `APP_DEBUG`
- `APP_URL`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`

Conserva las demás variables existentes en `.env.example`.

La `APP_KEY` se genera usando bytes aleatorios:

```text
base64:<32 bytes aleatorios>
```

El panel no sobrescribe un `.env` existente. Para volver a generarlo, hay que eliminar el archivo manualmente desde el Administrador de archivos.

## Arquitectura

```text
Navegador
   │
   ├── index.php
   │      └── interfaz, login y formularios
   │
   ├── run.php
   │      └── valida la acción y crea el trabajo
   │
   ├── run.sh
   │      └── ejecuta Composer o Artisan en segundo plano
   │
   └── ajax.php
          └── devuelve estado y fragmentos del log
```

El botón no ejecuta directamente un comando enviado por el usuario. Envía únicamente el identificador de una acción incluida en la lista blanca.

Los trabajos se guardan en:

```text
data/jobs/
```

Los logs se guardan en:

```text
data/logs/
```

La configuración detectada se guarda en:

```text
data/settings.php
data/settings.env
```

## Ejecución sin cron

El panel lanza `run.sh` desacoplado del request web mediante `nohup`, `setsid` y Bash cuando el servidor lo permite.

Esto permite que tareas largas como:

- `composer install`
- migraciones
- actualización de dependencias

continúen aunque la petición HTTP termine.

### Si el hosting bloquea `exec` o `proc_open`

El panel muestra el modo detectado.

En ese caso:

- Las acciones Artisan pueden ejecutarse dentro de la petición PHP.
- Composer no podrá ejecutarse desde la web.
- El hosting debe habilitar una función de ejecución, o debes subir `vendor/` ya generado.

## Doctor

El Doctor usa tres estados:

### OK

Todo correcto.

### CON AVISOS

Faltan pasos normales de una instalación nueva, por ejemplo:

- `vendor/` todavía no instalado.
- `.env` todavía no creado.
- `APP_KEY` todavía no definida.
- `public/storage` todavía no enlazado.

No se considera un error.

### ERROR

Existe una falla real, como:

- Artisan no puede arrancar.
- Composer no responde.
- No hay permisos de escritura.
- Laravel no puede conectarse a la base de datos.

Cuando una acción falla, el log intenta mostrar también las últimas líneas de:

```text
storage/logs/laravel.log
```

## Seguridad

Incluido actualmente:

- Login.
- Hash de sesión.
- Regeneración de sesión después del login.
- Expiración por inactividad.
- CSRF.
- Bloqueo temporal por IP después de intentos fallidos.
- Lista blanca de acciones.
- Validación estricta de rutas.
- Protección contra `..` en las rutas.
- IDs de trabajos con formato hexadecimal fijo.
- `app/` y `data/` protegidos mediante `.htaccess`.
- Archivos de datos guardados como PHP cuando corresponde.

Recomendaciones:

- Usar HTTPS.
- Usar contraseñas largas o hashes.
- Restringir el subdominio por IP si es posible.
- No reutilizar la contraseña principal de cPanel.
- No publicar el panel innecesariamente.
- Eliminar el subdominio cuando termine el mantenimiento.
- Revisar que el servidor use Apache o LiteSpeed; en nginx puro `.htaccess` no funciona.

## Diseño

El panel usa:

- [Basecoat CSS](https://basecoatui.com/)
- Basecoat Rhea.
- Tailwind Play CDN para prototipado.
- JavaScript vanilla.
- Sidebar responsive de Basecoat.
- Tema oscuro.

Recursos visuales:

```text
assets/astrostudio.webp
assets/panel.css
assets/panel.js
```

El favicon usa el logo de AstroStudio:

```html
<link rel="icon" type="image/webp" href="assets/astrostudio.webp">
```

## Desarrollo local

Para revisar la interfaz:

```powershell
cd cpanel-laravel
php -S 127.0.0.1:8080 -t .
```

Abre:

```text
http://127.0.0.1:8080
```

La ejecución real de comandos está diseñada para Linux/cPanel. En Windows se puede revisar la interfaz, pero no se debe asumir que Composer, Bash y las rutas `/home/...` funcionarán igual que en producción.

## Validaciones antes de publicar cambios

Comprobar sintaxis PHP:

```powershell
Get-ChildItem -Recurse -Filter *.php |
  ForEach-Object { php -l $_.FullName }
```

Comprobar Bash:

```bash
bash -n run.sh
```

Comprobar JavaScript:

```bash
node --check assets/panel.js
```

## Estructura de acciones

Las acciones están definidas en:

```text
app/actions.php
```

El ejecutor Bash está en:

```text
run.sh
```

No agregues comandos basados directamente en valores recibidos por `POST`. Para agregar una acción nueva:

1. Añádela a `deploy_actions()`.
2. Añade su caso en `run.sh`.
3. Marca si requiere shell.
4. Define si necesita confirmación.
5. Actualiza este README.
6. Prueba el resultado con un proyecto de prueba.

## Limitaciones

- Una instalación solo puede administrar la cuenta cPanel donde está instalada.
- No puede ejecutar comandos como otro usuario cPanel.
- No es un panel central multi-cuenta.
- No crea subdominios ni cuentas automáticamente.
- No reemplaza SSH para tareas administrativas del servidor.
- Composer depende de las funciones permitidas por el hosting.
- `.htaccess` no protege carpetas si el servidor usa nginx sin Apache/LiteSpeed.

## Contribuir

Las contribuciones son bienvenidas.

Antes de crear un Pull Request:

1. Mantén las acciones en lista blanca.
2. No agregues ejecución de comandos arbitrarios.
3. No expongas credenciales en logs.
4. Ejecuta los chequeos de sintaxis.
5. Prueba login, CSRF, logout y bloqueo de intentos.
6. Prueba una ejecución exitosa y una fallida.
7. Actualiza la documentación si cambia el flujo de instalación.

## Licencia

Este proyecto se distribuye como código abierto. Define aquí la licencia que quieras utilizar, por ejemplo MIT:

```text
MIT License
Copyright (c) 2026 AstroStudio
```

## Créditos

- AstroStudio
- Laravel
- Basecoat CSS
- Tailwind CSS
