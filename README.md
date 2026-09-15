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

