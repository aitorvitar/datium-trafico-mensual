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

## Seguridad

- No subir `.env` a Git.
- Las credenciales van solo en variables de entorno.
