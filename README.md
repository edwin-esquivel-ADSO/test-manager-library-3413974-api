# test-manager-library-3413974-api

> **Prueba técnica · Ficha ADSO 3413974**  
> **Backend:** PHP 8.2 con Laravel 11  
> **Arquitectura:** Hexagonal / Clean Architecture (SDD)  

Este repositorio contiene la implementación del **Backend** de *Manager Library*, respetando estrictamente el contrato HTTP de `api-contract.md` y el modelo de datos de `data-model.md`.

## Características Implementadas

- **Esquema de Base de Datos y Migraciones (ADR-001):** Todo el DDL reside en `database/migrations/`. La migración inicial crea las 6 tablas con llaves foráneas (`RESTRICT` / `CASCADE`), restricciones `CHECK` (`ck_book_available_range`, `ck_book_total_positive`, `ck_user_role`) y siembra las 5 categorías oficiales con UUIDs fijos.
- **Autenticación y Seguridad (CA-01, CA-02):** Emisión de tokens JWT con vigencia de 8 horas (`firebase/php-jwt`). Contraseñas encriptadas; nunca expuestas en logs ni respuestas JSON.
- **Gestión de Libros (HU-03, HU-04):** Búsqueda paginada, filtros, edición con recálculo de stock y baja lógica (`deleted_at`) protegiendo libros con préstamos abiertos (`409 BOOK_HAS_OPEN_LOANS`).
- **Gestión de Socios (HU-05):** Alta y consulta de socios con documento único. No se eliminan (CA-05.3).
- **Préstamos y Devoluciones (HU-06, HU-07, HU-08, HU-09):** Préstamos transaccionales de 1 a 3 libros por 14 días. Límite de 3 libros activos por socio y bloqueo por mora. Estados calculados dinámicamente (`open`, `overdue`, `closed`). Devolución por línea con restitución de ejemplar disponible.
- **Reporte de Libros Más Prestados (HU-10, ADR-004):** Consulta agregada que agrupa por valores históricos congelados (`book_title`, `category_name`), preservando la inmutabilidad histórica.
- **Manejo Centralizado de Errores (RNF-4):** Formato uniforme `{ "code": "...", "message": "...", "details": [...] }` para cualquier código HTTP (401, 403, 404, 409, 422).

## Requisitos y Puesta en Marcha

```bash
# 1. Instalar dependencias
composer install

# 2. Configurar entorno
cp .env.example .env
php artisan key:generate

# 3. Ejecutar migraciones
php artisan migrate:fresh

# 4. Aprovisionar usuario administrador inicial
php artisan library:ensure-admin

# 5. Ejecutar pruebas automatizadas
php artisan test

# 6. Iniciar servidor de desarrollo
php artisan serve --port=8000
```
