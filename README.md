**# 💰 Expense Tracker - Finanzas Personales v2.0**

**Sistema inteligente de tracking de gastos con inventario, valoraciones y proyecciones financieras precisas.**

---

### 📋 Descripción

Aplicación completa para gestionar finanzas personales con **análisis granular**. Registra tickets completos (**gastos**) y cada línea de producto (**subgastos**), normaliza productos por marca/variante, controla stock real con consumo diferido (“compro hoy, abro en 1 mes”), permite valoraciones subjetivas y genera estimaciones automáticas del mes siguiente.

**Objetivo principal**: tomar decisiones basadas en datos (¿está más caro el café? ¿cambio de marca? ¿qué priorizar?) y alcanzar máxima eficiencia calidad-precio.

---

### ✨ Características Principales

- **Tracking completo**: Gastos + subgastos con foto de ticket, etiquetas JSONB y estados
- **Productos normalizados**: Categoría de uso ↔ Producto concreto (marca + variante)
- **Inventario inteligente**: Stock actual, fecha proyectada de inicio, tasa de consumo diaria, alertas de agotamiento
- **Valoraciones**: Rating calidad y valor (1-10) + comentarios para decidir reemplazos
- **Proyecciones automáticas**: Recurrentes + depleción de stock + variables promedio + inflación personal por producto
- **Análisis avanzado**: Histórico de precios, eficiencia (rating_valor / precio), ranking por categoría
- **Priorización**: Por categoría y datos reales

---

### 🛠️ Stack Tecnológico

- **Base de Datos**: **PostgreSQL 15+** (ENUMs, JSONB, GENERATED columns, triggers, GIN indexes)
- **Framework**: **Laravel 11**
- **Admin UI**: **Filament PHP 3** (paneles automáticos de CRUD, dashboards y reportes)
- **PHP**: 8.3+
- **Otros**: Laravel Sanctum (API si se necesita móvil), Queue + Scheduler para jobs diarios

---

### 🗄️ Modelo de Datos (v2.0)

**Enums**:
- `estado_gasto_enum`, `tipo_subgasto_enum`, `recurrencia_enum`, `tipo_movimiento_enum`

**Tablas principales**:
| Tabla                    | Propósito                              |
|--------------------------|----------------------------------------|
| `gastos`                 | Tickets/recibos                        |
| `subgastos`              | Líneas de producto (+ `producto_id`)   |
| `categorias_producto`    | “Uso” (Café matutino, Detergente…)    |
| `productos`              | Producto concreto (marca + variante)   |
| `valoraciones_productos` | Ratings y opiniones                    |
| `inventario_productos`   | Stock + consumo diferido               |

**Relaciones clave**:
- `subgastos.producto_id` → `productos.id`
- `productos.categoria_id` → `categorias_producto.id`
- `inventario_productos` 1:1 con `productos`

**Triggers recomendados**:
- `total_linea` generado automáticamente
- Actualización automática de inventario al crear subgasto consumible

---

### 🚀 Acciones Principales (ya implementadas en Filament)

#### Escritura
- Crear/Editar/Eliminar Gasto completo (con múltiples subgastos)
- CRUD Productos, Categorías, Valoraciones
- Actualizar inventario (COMPRA / CONSUMO / AJUSTE)
- Registrar consumo manual y fecha real de inicio

#### Lectura / Dashboards Filament
- Histórico de precios por producto
- Estimación mes siguiente (con depleción de stock)
- Ranking eficiencia calidad-precio
- Stock bajo y alertas
- Comparativas por categoría
- Inflación personal por producto

#### Jobs automáticos (Laravel Scheduler)
- Recálculo diario de fechas de agotamiento
- Alertas stock bajo (07:00)
- Sugerencias semanales de reemplazo
- Reporte mensual PDF

---

### 📥 Instalación Rápida

```bash
git clone <repo>
cd expense-tracker
composer install
cp .env.example .env
php artisan key:generate
```

**Base de datos**:
```bash
php artisan migrate --seed   # incluye creación de ENUMs y tablas v2.0
```

**Filament**:
```bash
php artisan filament:install --panels
php artisan make:filament-user
php artisan serve
```

Accede a `/admin` con las credenciales creadas.

---

### 📌 Notas de Desarrollo

- Todas las escrituras críticas van dentro de transacciones Laravel.
- Usa **Materialized Views** para consultas pesadas (estimaciones, rankings).
- Backfill inicial disponible para migrar datos antiguos a `productos` + `inventario`.
- Listo para escalar a API móvil o importación automática de tickets (OCR futuro).

---

**¡Proyecto listo para producción!**

¿Quieres el SQL completo de migración, el repositorio base de Laravel + Filament ya configurado, o los primeros Resource de Filament generados? Dime y te lo entrego en 2 minutos.
