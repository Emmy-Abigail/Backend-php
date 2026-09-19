# Sistema de Gestión de Envíos — Backend PHP

API REST transaccional construida con Slim.

## Entorno Docker

```powershell
docker compose up --build
```

La API quedará en `http://localhost:8081/health` y MySQL estará disponible para herramientas locales en `localhost:3307`.

Al iniciar, la API ejecuta `phinx migrate`. Phinx registra las migraciones aplicadas en la tabla `phinxlog`, por lo que únicamente ejecuta las nuevas. Tras agregar una migración, vuelve a construir e iniciar:

```powershell
docker compose up -d --build
```

El primer arranque crea un administrador de desarrollo con las variables `INITIAL_ADMIN_NOMBRES`, `INITIAL_ADMIN_CORREO` e `INITIAL_ADMIN_PASSWORD` de `.env`. La contraseña se hashea y el seeder no duplica al administrador en reinicios posteriores.

Para detener los servicios conservando los datos:

```powershell
docker compose down
```

## Autenticación (JWT)

### Variables de entorno
El sistema requiere las siguientes variables de entorno configuradas en el archivo `.env`:
- `JWT_SECRET`: Clave secreta para la firma criptográfica HMAC-SHA256 (mínimo 32 caracteres).
- `JWT_ISSUER`: Identificador del emisor del token (ej. `gestion-envios-api`).
- `JWT_TTL_SECONDS`: Tiempo de vida del token en segundos (por defecto `28800`, equivalente a 8 horas).

### Flujo de autenticación
1. **Inicio de sesión:** El cliente realiza una solicitud `POST /api/v1/auth/login` con sus credenciales (`correo` y `password`).
2. **Emisión del token:** La API verifica las credenciales y devuelve un token JWT con vigencia de 8 horas (`expires_at` en formato ISO 8601) junto con los datos del usuario autenticado.
3. **Uso del token:** En solicitudes subsecuentes a endpoints protegidos, el cliente debe incluir la cabecera HTTP `Authorization: Bearer <token>`.

### Ejemplo con curl

Inicio de sesión para obtener el token:
```bash
curl -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"correo":"admin@email.com","password":"CAMBIA_ESTA_CLAVE_POR_UNA_SEGURA"}'
```

Consulta del perfil del usuario autenticado con el token recibido:
```bash
curl -X GET http://localhost:8081/api/v1/auth/me \
  -H "Authorization: Bearer <TOKEN_OBTENIDO>"
```

Cambio de contraseña del usuario autenticado (requiere token JWT):
```bash
curl -X PATCH http://localhost:8081/api/v1/auth/change-password \
  -H "Authorization: Bearer <TOKEN_OBTENIDO>" \
  -H "Content-Type: application/json" \
  -d '{"password_actual":"CAMBIA_ESTA_CLAVE_POR_UNA_SEGURA","password_nuevo":"NuevaClaveSegura2026!"}'
```
> La nueva contraseña debe cumplir con la política de seguridad: mínimo 8 caracteres (máx. 72), mayúscula, minúscula, número y carácter especial. Tras la actualización, `debe_cambiar_password` se actualiza a `false`.


### Protección de rutas con Middlewares

Para proteger una ruta y requerir autenticación JWT junto con validación de roles, se encadenan `JwtAuthMiddleware` y `RoleMiddleware`:

```php
$roleMiddleware = new RoleMiddleware('Admin');

// En Slim, el último middleware añadido con ->add() se ejecuta primero.
// Por tanto, se añade RoleMiddleware primero y JwtAuthMiddleware después:
$app->get('/api/v1/ruta-protegida', $action)
    ->add($roleMiddleware)
    ->add($jwtAuthMiddleware);
```

