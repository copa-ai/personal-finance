# 💰 Finanzas Personales - Expense Tracker (Core Data Model)

Un sistema de gestión de finanzas personales enfocado en el análisis granular del gasto. Este proyecto divide las compras en transacciones completas (tickets) y líneas de productos (subgastos), permitiendo proyecciones financieras, control de suscripciones y análisis de inflación personal (variación de precio de un mismo producto).

Este documento contiene la **especificación exacta de la base de datos** para su implementación en PostgreSQL.

---

## 🏛️ Arquitectura de la Base de Datos

El sistema utiliza **PostgreSQL** por su robustez matemática y, sobre todo, por su soporte nativo para `JSONB` (crucial para el sistema de etiquetas dinámicas sin necesidad de crear complejas tablas intermedias).

### 1. Tipos de Datos Personalizados (ENUMS)

Antes de crear las tablas, es obligatorio definir estos tipos enumerados en la base de datos para restringir los valores posibles y evitar errores tipográficos:

- `estado_gasto_enum`: `('PAGADO', 'PENDIENTE', 'REEMBOLSADO', 'BLOQUEADO')`
- `tipo_subgasto_enum`: `('FIJO', 'VARIABLE_MENSUAL', 'VARIABLE_IRREGULAR')`
- `recurrencia_enum`: `('NINGUNA', 'MENSUAL', 'TRIMESTRAL', 'ANUAL')`

---

### 2. Esquema de Tablas

#### Tabla: `gastos`
Representa el recibo, ticket o factura a nivel global.

| Columna | Tipo PostgreSQL | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `UUID` | `PRIMARY KEY` | Identificador único (autogenerado). |
| `establecimiento` | `VARCHAR(150)` | `NOT NULL` | Nombre del lugar (ej. "Mercadona", "Amazon"). |
| `fecha` | `TIMESTAMPTZ` | `NOT NULL` | Fecha y hora de la transacción (con zona horaria). |
| `total` | `NUMERIC(10,2)` | `NOT NULL` | Suma total pagada. Formato: 99999999.99. |
| `estado` | `estado_gasto_enum`| `NOT NULL`, `DEFAULT 'PAGADO'`| Estado del pago. |
| `foto_ticket_hash`| `VARCHAR(255)` | `NULL` | Hash o ruta del S3 donde se aloja la foto del recibo. |
| `creado_en` | `TIMESTAMPTZ` | `NOT NULL`, `DEFAULT NOW()`| Auditoría: cuándo se insertó el registro en la DB. |

#### Tabla: `subgastos`
Representa cada línea o producto individual dentro de un ticket.

| Columna | Tipo PostgreSQL | Restricciones | Descripción |
| :--- | :--- | :--- | :--- |
| `id` | `UUID` | `PRIMARY KEY` | Identificador único (autogenerado). |
| `gasto_id` | `UUID` | `NOT NULL`, `FK` | Relación con `gastos(id)`. *Debe tener `ON DELETE CASCADE`*. |
| `concepto` | `VARCHAR(255)` | `NOT NULL` | Nombre del producto o servicio (ej. "Café Lavazza"). |
| `cantidad` | `NUMERIC(10,3)` | `NOT NULL`, `DEFAULT 1.0` | Cantidad comprada (acepta decimales para peso, ej. 1.5 kg). |
| `precio_unitario` | `NUMERIC(10,2)` | `NOT NULL` | Precio de una sola unidad. |
| `total_linea` | `NUMERIC(10,2)` | `NOT NULL` | *Recomendado:* Usar `GENERATED ALWAYS AS (cantidad * precio_unitario) STORED`. |
| `etiquetas` | `JSONB` | `NOT NULL`, `DEFAULT '[]'`| Array de tags (ej. `["desayuno", "capricho"]`). Facilita búsquedas. |
| `tipo` | `tipo_subgasto_enum`| `NOT NULL` | Fijo, variable mensual o irregular. |
| `es_consumible` | `BOOLEAN` | `NOT NULL`, `DEFAULT true`| `true` = comida/champú. `false` = portátil/mueble. |
| `fecha_fin` | `DATE` | `NULL` | Cuándo se estima que haya que volver a comprarlo. |
| `recurrencia` | `recurrencia_enum`| `NOT NULL`, `DEFAULT 'NINGUNA'`| Para suscripciones (ej. Netflix = 'MENSUAL'). |

---

## 🔍 Reglas de Negocio y Estrategia de Consultas (Queries)

Para asegurar que la base de datos cubre los casos de uso planteados en el proyecto:

1. **Análisis de Precios de un Producto ("¿Está más caro el café?"):**
   Se buscará agrupando por `concepto` (y filtrando mediante el array de `etiquetas` si es necesario) y analizando el histórico de la columna `precio_unitario`, ordenado por la `fecha` del `gasto` padre.

2. **Cálculo de Estimación del Mes Siguiente (N+1):**
   La previsión de gasto se obtendrá de una consulta que sume tres variables:
   * **Recurrentes:** `subgastos` donde `recurrencia != 'NINGUNA'`.
   * **Ciclo de vida:** `subgastos` donde `es_consumible = true` y `fecha_fin` cae dentro del mes que viene.
   * **Variables promediados:** La media aritmética de los `subgastos` marcados como `tipo = 'VARIABLE_MENSUAL'` en los últimos 3 meses.

3. **Integridad de Datos:**
   * La suma de todos los `total_linea` de los `subgastos` asociados a un `gasto_id` debería coincidir (salvo redondeos o descuentos aplicados a nivel global) con el `total` de la tabla `gastos`. 
   * Borrar un Gasto (ej. por error) eliminará automáticamente todos sus subgastos asociados gracias a la regla `ON DELETE CASCADE` en la Foreign Key.

## 🚀 Cómo inicializar la Base de Datos

Para desplegar este esquema, ejecuta el archivo de migración inicial (por crear) que ejecutará las sentencias DDL (Data Definition Language) en este orden estricto:
1. Creación de los tipos `ENUM`.
2. Creación de la tabla `gastos`.
3. Creación de la tabla `subgastos` (ya que depende de la anterior).
4. Creación de los índices (se recomienda indexar `gasto_id` en la tabla `subgastos` y crear un índice GIN para la columna `etiquetas`).
