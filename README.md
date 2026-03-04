# Datium - Trafico Mensual

Herramienta interna de reporting VoIP con exportacion CSV.

## Requisitos

- PHP 8.2+
- `mysqli`
- `pdo`
- Para SQL Server (castiphone): `pdo_sqlsrv` o `pdo_dblib`

## Configuracion

1. Copia `.env.example` a `.env`.
2. Rellena usuarios/passwords.
3. En XAMPP, reinicia Apache.
4. Para Chat IA, anade en `.env`:
   - `OPENAI_API_KEY=...`
   - `OPENAI_MODEL=gpt-4.1-mini` (opcional)
   - `CHAT_BILLING_DETERMINISTIC=0` (IA libre) o `1` (facturacion estable)
5. Recomendado para Chat IA:
   - usuarios de BBDD solo lectura
   - limitar permisos a tablas necesarias

## Ejecucion local

- URL: `http://localhost/Datium - trafico mensual/`
- API JSON para frontend moderno: `http://localhost/Datium - trafico mensual/api/report.php`

## Frontend Next.js (nuevo)

Carpeta: `frontend-next`

1. Copia `frontend-next/.env.example` a `frontend-next/.env.local`.
2. En `frontend-next/.env.local`, define tambien:
   - `OPENAI_API_KEY=...`
   - `OPENAI_MODEL=gpt-4.1-mini` (opcional)
   - `CHAT_BILLING_DETERMINISTIC=0` (IA libre) o `1` (facturacion estable)
3. Instala dependencias:
   - `cd frontend-next`
   - `npm install`
4. Arranca:
   - `npm run dev`
5. Abre:
   - `http://localhost:3000`

## Docker (listo para nuevo servidor)

Incluye 3 servicios:

- `backend`: PHP 8.2 + Apache + `mysqli` + `pdo_sqlsrv`
- `frontend`: Next.js 14
- `proxy`: Nginx (entrada unica)

### Arranque

1. Copia `.env.example` a `.env` en la raiz del proyecto.
2. Completa credenciales reales.
   - Incluye `OPENAI_API_KEY` para habilitar `Chat IA`.
3. Ejecuta:
   - `docker compose build`
   - `docker compose up -d`
4. Abre:
   - `http://IP_SERVIDOR:8080`

Rutas:
- UI moderna: `/`
- Backend PHP legacy: `/legacy/`
- Herramientas IA backend: `/legacy/api/ai_tools.php`

## Seguridad

- No subir `.env` a Git.
- Las credenciales van solo en variables de entorno.
