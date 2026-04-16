# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Sistema de gestión logística multiempresa en **PHP procedural puro + MySQL**, hosteado en DonWeb (hosting compartido). Sin frameworks, sin OOP, sin herramientas de build.

## Common Commands

No hay proceso de build. El desarrollo es directo sobre archivos PHP.

**Setup inicial de base de datos** (orden importante):
```sql
SOURCE creacion_base_Logistica.sql;   -- esquema principal + datos de ejemplo
SOURCE agregar_usuarios.sql;           -- tabla de usuarios + admin (admin/logistica2024)
SOURCE insert_articulos.sql;           -- catálogo base de artículos

-- Migraciones incrementales (aplicar según necesidad):
SOURCE migracion_v2.sql;
SOURCE migracion_entregas.sql;
SOURCE migracion_turnos.sql;
SOURCE migracion_agenda.sql;
SOURCE migracion_alter_entregas.sql;
SOURCE migracion_articulos_pallets.sql;
SOURCE migracion_cuit_clientes.sql;
```

**Generar hash para nueva contraseña**:
```
GET /hash.php?password=nueva_clave
```

**Configuración de base de datos**: `config/db.php` (excluido de git — copiar manualmente en servidor). Define `DB_*`, `APP_NAME`, `APP_TZ` y la constante `BASE_URL` (actualmente `/ops` — el subdirectorio donde vive el sistema en el servidor).

## Architecture

### Patrón General
Procedural PHP con SQL embebido y HTML inline. Sin MVC. Una responsabilidad por archivo.

- **Páginas de lista** (`*_lista.php`): consulta + render de tabla con filas expandibles
- **Páginas de formulario** (`*_form.php`): carga entidad por `?id=` (GET) → render form
- **Handlers de guardado** (`*_guardar.php`): POST → validar → INSERT/UPDATE → redirect a lista (patrón PRG)
- **Endpoints AJAX**: devuelven JSON; no incluyen HTML ni llaman a `require_login()` internamente (la sesión ya existe)

### Autenticación y Sesión
`config/auth.php` define los helpers globales que todo módulo usa:
- `require_login()` — redirige a login si no hay sesión
- `empresa_id()` — devuelve `$_SESSION['empresa_id']` (aislamiento multiempresa)
- `es_admin()` — verifica `usuario_rol === 'admin'`
- `h($str)` — escapa HTML con `htmlspecialchars` (usar en todo output)
- `url($path)` — genera URL absoluta usando la constante `BASE_URL`
- `fecha_legible($fecha)` — formatea `Y-m-d H:i:s` → `d/m/Y H:i`

Llamar `require_login()` al inicio de cada módulo. La sesión guarda: `usuario_id`, `usuario_nombre`, `usuario_rol`, `empresa_id`, `empresa_nombre`.

### Multiempresa (Tenant Isolation)
Todas las tablas de negocio tienen columna `empresa_id`. Toda consulta debe filtrar por ella:
```php
$eid = empresa_id();
$stmt = $db->prepare("SELECT * FROM remitos WHERE empresa_id = ? AND ...");
$stmt->execute([$eid, ...]);
```
No existe compartición de datos entre empresas.

### Conexión a Base de Datos
Singleton PDO en `config/db.php`:
```php
$db = db();  // Obtiene la conexión PDO (lazy init, utf8mb4)
```
Siempre usar prepared statements con `prepare()` + `execute([$param])`. Nunca concatenar input de usuario en SQL.

### Módulos Principales
| Módulo | Archivos | Descripción |
|--------|----------|-------------|
| Panel | `index.php` | Dashboard con stats y lista filtrable de remitos |
| Remitos | `modules/remitos_*.php` | Albaranes de entrada; el ingreso padre se crea inline en el form |
| Entregas (Salidas) | `modules/entregas_*.php`, `entrega_dia_*.php`, `entrega_asignar.php`, `entrega_confirmar.php` | Viajes de entrega; `entrega_dia_form.php` arma la salida del día |
| Turnos | `modules/turno_*.php` | Turnos agendados de entrega asignados a un remito |
| Agenda | `modules/agenda.php` | Vista semanal/mensual de entregas; vistas `dia`/`semana`/`mes` |
| Transportistas | `modules/transportistas_*.php`, `camiones_guardar.php`, `choferes_guardar.php` | Empresas transportistas con sus camiones y choferes inline |
| Config (admin) | `modules/configuracion/` | Clientes, proveedores, usuarios (solo `es_admin()`) |

### AJAX Endpoints
- `modules/remitos_ac_clientes.php` — autocomplete de clientes (JSON)
- `modules/remitos_ac_articulos.php` — autocomplete de artículos (JSON)
- `modules/remitos_afip_lookup.php` / `modules/ingresos/afip_lookup.php` — consulta CUIT a AFIP (JSON)
- `modules/transportistas_guardar_ajax.php` — guardado inline de transportista desde form de entrega

### Estados (State Machine)
- **Remito**: `pendiente` → `turnado` / `programado` / `en_camino` → `entregado` / `parcialmente_entregado` (o `en_stock`)
- **Entrega**: `armando` → `en_camino` → `completada` (o `con_incidencias`)
- **Turno**: `pendiente` → `en_camino` → `entregado` (o `cancelado`)

**Auto-transición**: `index.php` y `agenda.php` ejecutan al cargar un UPDATE que marca como `entregado` todo lo que estaba `en_camino` con fecha anterior a hoy. Este efecto de lado ocurre en cada visita a esas páginas.

### Relaciones Master-Detail
- `ingresos` (1) → `remitos` (N) → `remito_items` (N)
- `entregas` (1) → `entrega_remitos` (N bridge) ← `remitos` (N)
- `turnos` (1) → `remitos` (N, opcional)

### Patrón de WHERE Dinámico
Para filtros de lista con condiciones opcionales:
```php
$where = ['r.empresa_id = ?'];
$params = [$eid];
if ($filtro) { $where[] = 'r.campo = ?'; $params[] = $filtro; }
$sql = "SELECT ... WHERE " . implode(' AND ', $where);
$stmt = $db->prepare($sql);
$stmt->execute($params);
```

### Manejo de Errores en Formularios
```php
// En guardar.php al detectar error:
$_SESSION['form_error'] = 'Mensaje de error';
$_SESSION['form_post'] = $_POST;  // Retener input del usuario
header('Location: remitos_form.php'); exit;

// En form.php al renderizar:
$error = $_SESSION['form_error'] ?? null;
unset($_SESSION['form_error']);
$post = $_SESSION['form_post'] ?? [];
unset($_SESSION['form_post']);
```

### Frontend
- **Bootstrap 5.3.3** (CDN) + **Bootstrap Icons 1.11.3** (CDN)
- CSS personalizado mínimo en `assets/css/app.css`
- `includes/navbar.php`: incluye el JS de "Enter avanza campo" y "select-all on focus" (no hace falta `assets/js/forms.js` por separado — está embebido en la navbar)
- Variable `$nav_modulo` en cada página para marcar activo en `includes/navbar.php`

### Seguridad
- `.htaccess` bloquea listado de directorios y acceso directo a `config/`
- `config/db.php` está en `.gitignore` — nunca commitear credenciales
- Usar `h()` en todo output de variables a HTML
- La navbar oculta sección de Configuración a no-admins; verificar también con `es_admin()` en el servidor en cada página de config
