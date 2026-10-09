# Sistema de GestiÃ³n de EnvÃ­os (RIVA) â€” Backend PHP

API REST transaccional desarrollada con PHP 8.4 y Slim Framework bajo los principios de Arquitectura Hexagonal (Puertos y Adaptadores).

---

## 1. Puesta en marcha desde un clon limpio

Para levantar el proyecto completo desde cero con Docker:

```powershell
# 1. Copiar el archivo de configuraciÃ³n
cp .env.example .env

# 2. Construir e iniciar los contenedores
docker compose up --build
```

* **API REST:** `http://localhost:8081`
* **Health Check:** `http://localhost:8081/health`
* **DocumentaciÃ³n Swagger UI:** `http://localhost:8081/docs/`
* **Base de Datos MySQL:** disponible en `localhost:3307` (Base de datos: `riva`, Usuario: `riva`, ContraseÃ±a: `riva_password`).

Al iniciar, Docker ejecuta automÃ¡ticamente las migraciones con Phinx (`phinx migrate`) y el sembrado inicial (`InitialAdministratorSeeder`), creando el Administrador por defecto:
* **Correo:** `admin@email.com`
* **ContraseÃ±a inicial:** `Admin2026#Seguro`

---

## 2. PolÃ­tica de ContraseÃ±as (OWASP / RÃºbrica)

La seguridad de contraseÃ±as estÃ¡ centralizada en `src/Domain/User/PasswordPolicy.php`:
* **Longitud:** MÃ­nimo 8 y mÃ¡ximo 72 caracteres.
* **Complejidad obligatoria:**
  * Al menos una letra mayÃºscula (`[A-Z]`).
  * Al menos un nÃºmero (`[0-9]`).
  * Al menos un carÃ¡cter especial del conjunto estricto: `@ # $ % &`.
* **ContraseÃ±as temporales generadas:** Se generan con 12 caracteres cumpliendo estrictamente con la polÃ­tica y empleando Ãºnicamente sÃ­mbolos del conjunto `@#$%&`.

---

## 3. AutenticaciÃ³n y Control de SesiÃ³n con JWT

* **Manejo de tokens:** Firmados con HMAC-SHA256 (`HS256`) mediante `firebase/php-jwt`.
* **Temporizador y ExpiraciÃ³n:**
  * **ConfiguraciÃ³n:** Variable `JWT_TTL_SECONDS=28800` (8 horas) en `.env`.
  * **InyecciÃ³n:** LeÃ­da en `public/index.php` e inyectada en `JwtTokenService`.
  * **AplicaciÃ³n:** `src/Infrastructure/Adapter/Out/Security/JwtTokenService.php` en el mÃ©todo `issue()`, calculando `'exp' => time() + $this->ttlSeconds`.
* **CÃ³mo probar la expiraciÃ³n en vivo para la demo (1 minuto):**
  1. Cambiar en `.env` la variable: `JWT_TTL_SECONDS=60`
  2. Aplicar el cambio: `docker compose up -d` (usa `up -d` para recargar variables).
  3. Iniciar sesiÃ³n para obtener un token nuevo.
  4. Pasado 1 minuto, cualquier peticiÃ³n a un endpoint protegido responderÃ¡ `401 Unauthorized` (`"Token expirado"`).
  5. Restaurar `JWT_TTL_SECONDS=28800` al terminar la prueba.

* **RevocaciÃ³n de Sesiones:**
  * Si un usuario cambia su contraseÃ±a, se actualiza `password_changed_at` en UTC.
  * Cualquier token emitido previamente (`iat < password_changed_at`) queda inmediatamente invalidado.

* **Cambio Obligatorio de ContraseÃ±a (Primer Inicio de SesiÃ³n):**
  * Usuarios creados con contraseÃ±a temporal tienen `debe_cambiar_password = true`.
  * El middleware `MustChangePasswordMiddleware` bloquea cualquier acceso operativo con `403 Forbidden` (`"Debes cambiar tu contraseÃ±a"`), permitiendo Ãºnicamente acceder a `/auth/me` y a `/auth/change-password`.

---

## 4. Endpoints Disponibles (Ã‰pica 1)

### Salud del Sistema
* `GET /health` â€” VerificaciÃ³n de operatividad del servicio.

### AutenticaciÃ³n y Seguridad
* `POST /api/v1/auth/login` â€” Iniciar sesiÃ³n (responde 401 `"Credenciales incorrectas"` en fallos).
* `GET /api/v1/auth/me` â€” Datos del usuario autenticado (incluye sede para Operadores).
* `PATCH /api/v1/auth/change-password` â€” Cambio de contraseÃ±a (emite nuevo JWT).
* `POST /api/v1/auth/password-reset/request` â€” Solicitud de recuperaciÃ³n por correo.
* `POST /api/v1/auth/password-reset/confirm` â€” Restablecimiento de contraseÃ±a con token.

### CatÃ¡logos Maestros (PÃºblicos)
* `GET /api/v1/geography` â€” Departamentos, provincias, distritos y zonas tarifarias.
* `GET /api/v1/sedes` â€” Centros de distribuciÃ³n operativos.
* `GET /api/v1/vehicle-types` â€” Tipos de vehÃ­culo con pesos y dimensiones mÃ¡ximas.
* `GET /api/v1/failure-reasons` â€” CatÃ¡logo estandarizado de motivos de fallo de entrega.

### GestiÃ³n de Personal (Solo Administrador)
* `POST /api/v1/users` â€” Alta de Conductor u Operador con contraseÃ±a temporal.
* `GET /api/v1/users` â€” Listado de usuarios con filtros por rol y sede.
* `PATCH /api/v1/users/{id}/status` â€” Activar o inhabilitar a un usuario.