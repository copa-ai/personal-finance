**📋 ESTRUCTURA COMPLETA DE ENTIDADES Y ACCIONES**  
**Expense Tracker v2.0 – Finanzas Personales Inteligente**  

**Tecnologías**: PostgreSQL 15+ + Laravel 11 + Filament PHP 3  
**Versión del documento**: 2.0 (27 febrero 2026)  
**Propósito**: Documento de referencia único para desarrollo, migraciones y mantenimiento.

---

### 1. Introducción

Sistema que registra **tickets completos** (`gastos`) y **líneas individuales** (`subgastos`), normaliza productos por marca, gestiona stock real con consumo diferido, permite valoraciones subjetivas y genera proyecciones automáticas del mes siguiente con depleción de inventario.

---

### 2. ENUMs (PostgreSQL)

```sql
CREATE TYPE estado_gasto_enum          AS ENUM ('PAGADO', 'PENDIENTE', 'REEMBOLSADO', 'BLOQUEADO');
CREATE TYPE tipo_subgasto_enum         AS ENUM ('FIJO', 'VARIABLE_MENSUAL', 'VARIABLE_IRREGULAR');
CREATE TYPE recurrencia_enum           AS ENUM ('NINGUNA', 'MENSUAL', 'TRIMESTRAL', 'ANUAL');
CREATE TYPE tipo_movimiento_enum       AS ENUM ('COMPRA', 'CONSUMO', 'AJUSTE', 'PERDIDA');
```

---

### 3. Entidades (Tablas)

#### `gastos` – Ticket / Recibo
| Columna              | Tipo              | Constraints                          | Descripción |
|----------------------|-------------------|--------------------------------------|-----------|
| id                   | UUID              | PK, default gen_random_uuid()        | Identificador único |
| establecimiento      | VARCHAR(150)      | NOT NULL                             | Nombre del comercio |
| fecha                | TIMESTAMPTZ       | NOT NULL                             | Fecha y hora real de compra |
| total                | NUMERIC(10,2)     | NOT NULL                             | Total pagado |
| estado               | estado_gasto_enum | NOT NULL DEFAULT 'PAGADO'            | Estado del pago |
| foto_ticket_hash     | VARCHAR(255)      | NULL                                 | Ruta/hash S3 foto |
| creado_en            | TIMESTAMPTZ       | NOT NULL DEFAULT NOW()               | Auditoría |

#### `subgastos` – Línea de producto
| Columna              | Tipo              | Constraints                                      | Descripción |
|----------------------|-------------------|--------------------------------------------------|-----------|
| id                   | UUID              | PK                                               | — |
| gasto_id             | UUID              | NOT NULL, FK → gastos.id ON DELETE CASCADE      | — |
| producto_id          | UUID              | NULL, FK → productos.id ON DELETE SET NULL      | Normalización |
| concepto             | VARCHAR(255)      | NOT NULL                                         | Texto exacto del ticket |
| cantidad             | NUMERIC(10,3)     | NOT NULL DEFAULT 1.0                             | — |
| precio_unitario      | NUMERIC(10,2)     | NOT NULL                                         | — |
| total_linea          | NUMERIC(10,2)     | GENERATED ALWAYS AS (cantidad * precio_unitario) STORED | — |
| etiquetas            | JSONB             | NOT NULL DEFAULT '[]'                            | Array de tags |
| tipo                 | tipo_subgasto_enum| NOT NULL                                         | — |
| es_consumible        | BOOLEAN           | NOT NULL DEFAULT true                            | — |
| fecha_fin            | DATE              | NULL                                             | Próxima compra estimada |
| recurrencia          | recurrencia_enum  | NOT NULL DEFAULT 'NINGUNA'                       | — |

#### `categorias_producto` – “Uso” o categoría funcional
| Columna         | Tipo         | Constraints                     | Descripción |
|-----------------|--------------|---------------------------------|-----------|
| id              | UUID         | PK                              | — |
| nombre          | VARCHAR(100) | NOT NULL UNIQUE                 | “Café matutino”, “Detergente” |
| descripcion     | TEXT         | NULL                            | — |
| prioridad       | SMALLINT     | DEFAULT 5 CHECK (1-10)          | Para priorización |
| creado_en       | TIMESTAMPTZ  | DEFAULT NOW()                   | — |

#### `productos` – Producto concreto de marca
| Columna                     | Tipo              | Constraints                              | Descripción |
|-----------------------------|-------------------|------------------------------------------|-----------|
| id                          | UUID              | PK                                       | — |
| nombre                      | VARCHAR(255)      | NOT NULL                                 | “Lavazza Crema e Gusto 250g” |
| marca                       | VARCHAR(100)      | NULL                                     | — |
| variante                    | VARCHAR(100)      | NULL                                     | “Intenso”, “Sin cafeína” |
| categoria_id                | UUID              | NOT NULL FK → categorias_producto        | — |
| unidad_medida               | VARCHAR(20)       | NOT NULL                                 | ‘kg’, ‘l’, ‘unidades’ |
| es_consumible               | BOOLEAN           | NOT NULL DEFAULT true                    | — |
| tasa_consumo_diaria_default | NUMERIC(10,5)     | NULL                                     | Para cálculos automáticos |
| precio_objetivo             | NUMERIC(10,2)     | NULL                                     | — |
| notas                       | TEXT              | NULL                                     | — |
| activo                      | BOOLEAN           | DEFAULT true                             | Soft delete |
| creado_en                   | TIMESTAMPTZ       | DEFAULT NOW()                            | — |

#### `valoraciones_productos`
| Columna         | Tipo         | Constraints                               | Descripción |
|-----------------|--------------|-------------------------------------------|-----------|
| id              | UUID         | PK                                        | — |
| producto_id     | UUID         | NOT NULL FK → productos ON DELETE CASCADE | — |
| rating_calidad  | NUMERIC(2,1) | CHECK (1.0-10.0)                          | Calidad percibida |
| rating_valor    | NUMERIC(2,1) | CHECK (1.0-10.0)                          | Calidad/precio |
| comentario      | TEXT         | NULL                                      | — |
| subgasto_id     | UUID         | NULL FK → subgastos ON DELETE SET NULL    | Contexto |
| fecha           | TIMESTAMPTZ  | DEFAULT NOW()                             | — |

#### `inventario_productos` (1:1 con productos)
| Columna                     | Tipo          | Constraints                     | Descripción |
|-----------------------------|---------------|---------------------------------|-----------|
| producto_id                 | UUID          | PK, FK → productos ON DELETE CASCADE | — |
| cantidad_actual             | NUMERIC(12,3) | NOT NULL DEFAULT 0.0            | Stock real |
| fecha_proyectada_inicio     | DATE          | NULL                            | “Abro este paquete el…” |
| fecha_real_inicio           | DATE          | NULL                            | Fecha real de apertura |
| tasa_consumo_diaria         | NUMERIC(10,5) | NULL (copia de default)         | Ajustable manualmente |
| fecha_ultima_actualizacion  | TIMESTAMPTZ   | DEFAULT NOW()                   | — |
| notas                       | TEXT          | NULL                            | — |

---

### 4. Relaciones y Cardinalidad

- `gastos` **1** — **\* ** `subgastos` (FK gasto_id)
- `subgastos` **\* ** — **1** `productos` (FK producto_id)
- `productos` **\* ** — **1** `categorias_producto` (FK categoria_id)
- `productos` **1** — **1** `inventario_productos` (FK producto_id)
- `valoraciones_productos` **\* ** — **1** `productos`
- `valoraciones_productos` **\* ** — **?1** `subgastos`

---

### 5. Índices Recomendados

```sql
CREATE INDEX idx_subgastos_gasto_id ON subgastos(gasto_id);
CREATE INDEX idx_subgastos_producto_id ON subgastos(producto_id);
CREATE INDEX idx_subgastos_fecha_gasto ON subgastos USING BTREE ((gasto_id::text)) INCLUDE (fecha); -- join optimizado
CREATE INDEX idx_productos_categoria ON productos(categoria_id);
CREATE INDEX idx_valoraciones_producto ON valoraciones_productos(producto_id);
CREATE INDEX idx_inventario_fecha_inicio ON inventario_productos(fecha_proyectada_inicio);
CREATE INDEX idx_gastos_fecha ON gastos(fecha);
CREATE INDEX idx_etiquetas_gin ON subgastos USING GIN (etiquetas);
```

---

### 6. Triggers Sugeridos (PostgreSQL)

```sql
-- 1. Actualizar inventario automáticamente al crear subgasto consumible
CREATE OR REPLACE FUNCTION actualizar_inventario_compra() RETURNS TRIGGER AS $$
BEGIN
    IF NEW.producto_id IS NOT NULL AND NEW.es_consumible THEN
        INSERT INTO inventario_productos (producto_id, cantidad_actual)
        VALUES (NEW.producto_id, NEW.cantidad)
        ON CONFLICT (producto_id) DO UPDATE
            SET cantidad_actual = inventario_productos.cantidad_actual + NEW.cantidad,
                fecha_ultima_actualizacion = NOW();
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_subgasto_compra
    AFTER INSERT ON subgastos
    FOR EACH ROW EXECUTE FUNCTION actualizar_inventario_compra();
```

(Opcional: trigger para `total_linea` y recalcular fecha_fin al registrar consumo).

---

### 7. Acciones del Sistema (Laravel + Filament)

#### 7.1 Escrituras (Actions / Form Actions)

```php
// Gastos
CreateGastoCompletoAction     // transacción completa (gasto + subgastos + inventario)
UpdateGastoAction
DeleteGastoAction             // cascade automático

// Productos y Categorías
CreateProductoAction
UpdateProductoAction
DeactivateProductoAction
CreateCategoriaProductoAction

// Valoraciones
CreateValoracionAction
UpdateValoracionAction

// Inventario
ActualizarInventarioAction(tipo: tipo_movimiento_enum)
RegistrarConsumoManualAction
AjustarTasaConsumoAction
```

#### 7.2 Lecturas (Filament Resources + Tables + Widgets)

- `GastoResource` (List, View, Edit)
- `SubgastoResource`
- `ProductoResource` (con relación inventario y avg rating)
- `CategoriaProductoResource`
- `ValoracionResource`
- `InventarioResource`

**Queries clave (Repositories o Scopes)**:
- `historicoPreciosProducto($productoId, $meses = 12)`
- `estimacionGastoMesSiguiente(Carbon $mes)`
- `calcularFechaAgotamiento($productoId)`
- `listarStockBajo($dias = 14)`
- `compararProductosPorCategoria($categoriaId)`
- `productosRecomendadosParaRemplazo($productoActualId)`

#### 7.3 Tareas en Segundo Plano (Laravel Scheduler + Jobs)

| Job                              | Frecuencia          | Responsabilidad |
|----------------------------------|---------------------|-----------------|
| RecalcularFechasAgotamientoJob   | Daily 03:00         | Actualiza fecha_proyectada_fin |
| AlertasStockBajoJob              | Daily 07:00         | Notificaciones |
| ActualizarMediasVariablesJob     | Daily               | Cache materialized |
| SugerenciasRemplazoSemanalJob    | Weekly (Sunday)     | Email/recomendaciones |
| ReporteMensualJob                | 1er día de mes      | PDF + email |
| CalculoInflacionPersonalJob      | Monthly             | Por producto |

---

### 8. Implementación Laravel + Filament

**Modelos** (app/Models):
- `Gasto.php`, `Subgasto.php`, `Producto.php`, `CategoriaProducto.php`, `ValoracionProducto.php`, `InventarioProducto.php`

**Migrations**: carpeta `database/migrations` con orden estricto:
1. `create_enums.php`
2. `create_gastos_table.php`
3. `create_subgastos_table.php`
4. `create_categorias_producto_table.php`
5. `create_productos_table.php`
6. `create_valoraciones_productos_table.php`
7. `create_inventario_productos_table.php`
8. `add_producto_id_to_subgastos.php`

**Filament**:
- Resources en `app/Filament/Resources/`
- Widgets personalizados en dashboard: Estimación Mes, Stock Bajo, Top Productos Inflación
- Custom Pages: “Próximas Compras”, “Comparativa Marcas”

**Backfill inicial** (script Artisan command):
```bash
php artisan expense:backfill-productos
```

---

**Documento listo para copiar-pegar en `docs/ENTIDADES_Y_ACCIONES.md`**

¿Quieres ahora el **archivo SQL completo de migración** o el **proyecto Laravel base ya configurado** (con migrations, models y primer Resource de Filament)? Dime y te lo entrego en el siguiente mensaje.
