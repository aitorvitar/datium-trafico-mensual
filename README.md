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

## Ejecucion local

- URL: `http://localhost/Datium - trafico mensual/`
- API JSON para frontend moderno: `http://localhost/Datium - trafico mensual/api/report.php`

## Frontend Next.js (nuevo)

Carpeta: `frontend-next`

1. Copia `frontend-next/.env.example` a `frontend-next/.env.local`.
2. Instala dependencias:
   - `cd frontend-next`
   - `npm install`
3. Arranca:
   - `npm run dev`
4. Abre:
   - `http://localhost:3000`

## Seguridad

- No subir `.env` a Git.
- Las credenciales van solo en variables de entorno.
