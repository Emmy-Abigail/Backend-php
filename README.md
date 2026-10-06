# Sistema de Gestión de Envíos (RIVA) — Backend PHP

API REST transaccional desarrollada con PHP 8.4 y Slim Framework bajo los principios de Arquitectura Hexagonal (Puertos y Adaptadores).

---

## 1. Puesta en marcha desde un clon limpio

Para levantar el proyecto completo desde cero con Docker:

```powershell
# 1. Copiar el archivo de configuración
cp .env.example .env

# 2. Construir e iniciar los contenedores
docker compose up --build
```

* **API REST:** `http://localhost:8081`
* **Health Check:** `http://localhost:8081/health`
* **Documentación Swagger UI:** `http://localhost:8081/docs/`
* **Base de Datos MySQL:** disponible en `localhost:3307` (Base de datos: `riva`, Usuario: `riva`, Contraseña: `riva_password`).

Al iniciar, Docker ejecuta automáticamente las migraciones con Phinx (`phinx migrate`) y el sembrado inicial (`InitialAdministratorSeeder`), creando el Administrador por defecto:
* **Correo:** `admin@email.com`
* **Contraseña inicial:** `Admin2026#Seguro`

---

## 2. Política de Contraseñas (OWASP / Rúbrica)

La seguridad de contraseñas está centralizada en `src/Domain/User/PasswordPolicy.php`:
* **Longitud:** Mínimo 8 y máximo 72 caracteres.
* **Complejidad obligatoria:**
  * Al menos una letra mayúscula (`[A-Z]`).
  * Al menos un número (`[0-9]`).
  * Al menos un carácter especial del conjunto estricto: `@ # $ % &`.
* **Contraseñas temporales generadas:** Se generan con 12 caracteres cumpliendo estrictamente con la política y empleando únicamente símbolos del conjunto `@#$%&`.

---

## 3. Autenticación y Control de Sesión con JWT

* **Manejo de tokens:** Firmados con HMAC-SHA256 (`HS256`) mediante `firebase/php-jwt`.
* **Temporizador y Expiración:**
  * **Configuración:** Variable `JWT_TTL_SECONDS=28800` (8 horas) en `.env`.
  * **Inyección:** Leída en `public/index.php` e inyectada en `JwtTokenService`.
  * **Aplicación:** `src/Infrastructure/Adapter/Out/Security/JwtTokenService.php` en el método `issue()`, calculando `'exp' => time() + $this->ttlSeconds`.
* **Cómo probar la expiración en vivo para la demo (1 minuto):**
  1. Cambiar en `.env` la variable: `JWT_TTL_SECONDS=60`
  2. Aplicar el cambio: `docker compose up -d` (usa `up -d` para recargar variables).
  3. Iniciar sesión para obtener un token nuevo.
  4. Pasado 1 minuto, cualquier petición a un endpoint protegido responderá `401 Unauthorized` (`"Token expirado"`).
  5. Restaurar `JWT_TTL_SECONDS=28800` al terminar la prueba.

* **Revocación de Sesiones:**
  * Si un usuario cambia su contraseña, se actualiza `password_changed_at` en UTC.
  * Cualquier token emitido previamente (`iat < password_changed_at`) queda inmediatamente invalidado.

* **Cambio Obligatorio de Contraseña (Primer Inicio de Sesión):**
  * Usuarios creados con contraseña temporal tienen `debe_cambiar_password = true`.
  * El middleware `MustChangePasswordMiddleware` bloquea cualquier acceso operativo con `403 Forbidden` (`"Debes cambiar tu contraseña"`), permitiendo únicamente acceder a `/auth/me` y a `/auth/change-password`.

---

## 4. Endpoints Disponibles (Épica 1)

### Salud del Sistema
* `GET /health` — Verificación de operatividad del servicio.

### Autenticación y Seguridad
* `POST /api/v1/auth/login` — Iniciar sesión (responde 401 `"Credenciales incorrectas"` en fallos).
* `GET /api/v1/auth/me` — Datos del usuario autenticado (incluye sede para Operadores).
* `PATCH /api/v1/auth/change-password` — Cambio de contraseña (emite nuevo JWT).
* `POST /api/v1/auth/password-reset/request` — Solicitud de recuperación por correo.
* `POST /api/v1/auth/password-reset/confirm` — Restablecimiento de contraseña con token.

### Catálogos Maestros (Públicos)
* `GET /api/v1/geography` — Departamentos, provincias, distritos y zonas tarifarias.
* `GET /api/v1/sedes` — Centros de distribución operativos.
* `GET /api/v1/vehicle-types` — Tipos de vehículo con pesos y dimensiones máximas.
* `GET /api/v1/failure-reasons` — Catálogo estandarizado de motivos de fallo de entrega.

### Gestión de Personal (Solo Administrador)
* `POST /api/v1/users` — Alta de Conductor u Operador con contraseña temporal.
* `GET /api/v1/users` — Listado de usuarios con filtros por rol y sede.
* `PATCH /api/v1/users/{id}/status` — Activar o inhabilitar a un usuario.