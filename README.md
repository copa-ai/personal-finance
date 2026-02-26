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



===

-  La misión principal es poder estimar gastos.  Por ejemplo, podemos comprar un producto pero no comenzarlo a consumir hasta dentro de un mes.
Asi que cuando volvamos a comprar el producto tendríamos que poder estimar a futuro su gasto teniendo en cuenta cuanto tiempo quedó por gastarse y cuanto tenemos en stock ....  ¿deberíamos tener una tabla de productos?   Claro, producto. .. ¿es un producto concreto de una marca concreta? ¿o algo que cubre un uso?   .... son dos cosas distintas. ... Ademas, queremos poder tomar decisiones sobre si es mas rentable usar un producto x o un producto y.  Y poder hacer valoraciones sobre que nos parece cada producto (de marca concreta) para poder remplazarlo con otro de otra marca si nos interesa ....   La idea es poder controlar precios, gastos, etc ...  Buscar el punto de máxima eficiencia entre calidad y precio.  Y, ademas, poder tomar decisiones sobre que gastos priorizar frente a otros, basandonos en datos.

¿Que cambios o agregados debemos de hacer en nuestra propuesta de proyecto actual?



====


**✅ Propuesta actualizada de esquema (v2.0)**

El modelo actual (**gastos** + **subgastos**) es sólido para el tracking de tickets y líneas, pero **no resuelve** los nuevos requisitos que mencionas:

- Normalizar “producto concreto de marca” vs “uso/categoría”.
- Gestionar **stock real** y **consumo diferido** (“compro hoy pero no lo abro hasta dentro de 1 mes”).
- Comparar rentabilidad (calidad/precio) entre alternativas.
- Valoraciones subjetivas para decidir remplazos.
- Priorizar gastos basados en datos (no solo importe).

**Cambios mínimos + potentes** que propongo (manteniendo 100 % compatibilidad con lo que ya tienes):

### 1. Nuevos ENUMS (opcionales pero recomendados)
```sql
CREATE TYPE tipo_movimiento_enum AS ENUM ('COMPRA', 'CONSUMO', 'AJUSTE', 'PERDIDA');
-- Solo si quieres full audit de inventario (recomendado a medio plazo)
```

### 2. Nuevas tablas maestras

#### Tabla: `categorias_producto` (el “uso”)
```sql
CREATE TABLE categorias_producto (
    id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    nombre        VARCHAR(100) NOT NULL UNIQUE,   -- "Café matutino", "Detergente lavadora", "Champú diario"
    descripcion   TEXT,
    prioridad     SMALLINT DEFAULT 5 CHECK (prioridad BETWEEN 1 AND 10), -- para priorización
    creado_en     TIMESTAMPTZ DEFAULT NOW()
);
```

#### Tabla: `productos` (el “producto concreto de marca”)
```sql
CREATE TABLE productos (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    nombre                   VARCHAR(255) NOT NULL,          -- "Lavazza Crema e Gusto 250g"
    marca                    VARCHAR(100),
    variante                 VARCHAR(100),                   -- "Rojo", "Intenso", "Sin cafeína"
    categoria_id             UUID NOT NULL REFERENCES categorias_producto(id) ON DELETE RESTRICT,
    unidad_medida            VARCHAR(20) NOT NULL,           -- 'kg', 'unidades', 'l', 'ml'
    es_consumible            BOOLEAN NOT NULL DEFAULT true,
    tasa_consumo_diaria_default NUMERIC(10,5),               -- 0.015 kg/día (para estimaciones automáticas)
    precio_objetivo          NUMERIC(10,2),
    notas                    TEXT,
    activo                   BOOLEAN DEFAULT true,
    creado_en                TIMESTAMPTZ DEFAULT NOW()
);
-- Índice único útil
CREATE UNIQUE INDEX idx_productos_unique ON productos (nombre, marca, variante);
CREATE INDEX idx_productos_categoria ON productos(categoria_id);
```

#### Tabla: `valoraciones_productos` (tus opiniones de calidad/precio)
```sql
CREATE TABLE valoraciones_productos (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    producto_id     UUID NOT NULL REFERENCES productos(id) ON DELETE CASCADE,
    rating_calidad  NUMERIC(2,1) CHECK (rating_calidad BETWEEN 1 AND 10),   -- 1-10
    rating_valor    NUMERIC(2,1) CHECK (rating_valor BETWEEN 1 AND 10),     -- relación calidad/precio
    comentario      TEXT,
    subgasto_id     UUID REFERENCES subgastos(id) ON DELETE SET NULL,        -- opcional, para contextualizar
    fecha           TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_valoraciones_producto ON valoraciones_productos(producto_id);
```

#### Tabla: `inventario_productos` (stock + consumo diferido)
```sql
CREATE TABLE inventario_productos (
    producto_id                 UUID PRIMARY KEY REFERENCES productos(id) ON DELETE CASCADE,

    cantidad_actual             NUMERIC(12,3) NOT NULL DEFAULT 0.0,
    fecha_proyectada_inicio     DATE,           -- ← CLAVE para tu caso "compro pero abro en 1 mes"
    fecha_real_inicio           DATE,           -- cuando realmente lo abriste
    tasa_consumo_diaria         NUMERIC(10,5),  -- se copia de default o se ajusta manualmente
    fecha_ultima_actualizacion  TIMESTAMPTZ DEFAULT NOW(),
    notas                       TEXT
);
```

### 3. Modificaciones mínimas a tablas existentes

**Tabla `subgastos`** (solo se añade 1 columna):
```sql
ALTER TABLE subgastos 
    ADD COLUMN producto_id UUID REFERENCES productos(id) ON DELETE SET NULL;

-- (concepto sigue existiendo → texto exacto del ticket; producto_id es la normalización)
```

**Recomendación fuerte**: crea un trigger o lógica en la app que, al insertar/actualizar un subgasto con `producto_id` y `es_consumible = true`, haga:
```sql
-- Pseudo-código
INSERT INTO inventario_productos (producto_id, cantidad_actual)
VALUES (NEW.producto_id, NEW.cantidad)
ON CONFLICT (producto_id) DO UPDATE 
    SET cantidad_actual = inventario_productos.cantidad_actual + NEW.cantidad;
```

### 4. Cómo queda el flujo que pedías

| Necesidad | Cómo se resuelve ahora |
|-----------|------------------------|
| Producto concreto vs uso | `productos` (marca + variante) ↔ `categorias_producto` (uso) |
| Compro pero consumo en 1 mes | `inventario_productos.fecha_proyectada_inicio` |
| ¿Está más caro el café? | `GROUP BY p.id, p.nombre, fecha(gasto)` → histórico precio_unitario |
| Estimación mes N+1 precisa | Recurrentes + variables promedio + **depleción de stock** (`fecha_proyectada_inicio + (cantidad_actual / tasa)`) |
| Comparar X vs Y (rentabilidad) | `SELECT p.nombre, AVG(s.precio_unitario), AVG(v.rating_valor) FROM productos p JOIN ... GROUP BY p.id` ordenado por `rating_valor / precio_promedio` |
| Priorizar gastos | Ordenar por `categorias_producto.prioridad` + importe proyectado |
| Valoración y decisión de remplazo | Tabla `valoraciones_productos` + vista `eficiencia_producto` |

### 5. Migración recomendada (orden)
1. Crear `categorias_producto` + `productos` + `valoraciones_productos` + `inventario_productos`.
2. Añadir `producto_id` a `subgastos`.
3. Script de backfill (una sola vez):
   - Crear productos únicos a partir de `DISTINCT concepto, establecimiento` de subgastos existentes.
   - Actualizar `subgastos.producto_id` donde coincida.
   - Inicializar `inventario_productos.cantidad_actual` sumando cantidades históricas de productos consumibles.

### 6. Índices recomendados (para velocidad)
```sql
CREATE INDEX idx_subgastos_producto ON subgastos(producto_id);
CREATE INDEX idx_subgastos_fecha_gasto ON subgastos(gasto_id) INCLUDE (fecha); -- join con gastos
CREATE INDEX idx_inventario_fecha_inicio ON inventario_productos(fecha_proyectada_inicio);
```

Con estos cambios el sistema pasa de “tracker de tickets” a **sistema de finanzas personales inteligente con control de inventario y toma de decisiones basada en datos reales**.  

¿Quieres que te pase el archivo SQL completo de migración (CREATE + backfill) o la vista materializada de “Próximas compras recomendadas”? Dime y te lo genero listo para copiar-pegar.


===


**✅ Listado Completo de Acciones del Sistema**  
**Expense Tracker v2.0** (con productos, inventario, valoraciones y stock diferido)

He estructurado el listado en **Escrituras**, **Lecturas** y **Tareas en Segundo Plano**.  
Cada acción incluye:
- Nombre claro
- Pseudocódigo (estilo Python/TS, fácil de mapear a API o service layer)
- Notas importantes

---

### **1. Acciones de Escritura (Writes)**

#### **Gastos & Subgastos**
```python
def crearGastoCompleto(datos):
    tx = begin_transaction()
    gasto_id = insert("gastos", {
        "establecimiento": datos.establecimiento,
        "fecha": datos.fecha,
        "total": datos.total,
        "estado": datos.estado or "PAGADO",
        "foto_ticket_hash": datos.foto_ticket_hash
    })
    for linea in datos.subgastos:
        sub_id = insert("subgastos", {
            "gasto_id": gasto_id,
            "concepto": linea.concepto,
            "cantidad": linea.cantidad,
            "precio_unitario": linea.precio_unitario,
            "etiquetas": linea.etiquetas or [],
            "tipo": linea.tipo,
            "es_consumible": linea.es_consumible,
            "fecha_fin": linea.fecha_fin,
            "recurrencia": linea.recurrencia or "NINGUNA",
            "producto_id": linea.producto_id   # opcional (normalización)
        })
        if linea.producto_id and linea.es_consumible:
            actualizarInventario(linea.producto_id, linea.cantidad, "COMPRA")
    tx.commit()
    return gasto_id
```

```python
def actualizarGasto(gasto_id, nuevos_datos):          # solo campos de gastos
def eliminarGasto(gasto_id):                          # ON DELETE CASCADE automático
def crearSubgastoSolo(datos):                         # caso raro (gasto ya existe)
def actualizarSubgasto(subgasto_id, nuevos_datos):
def eliminarSubgasto(subgasto_id):
```

#### **Productos & Categorías**
```python
def crearProducto(datos):
    producto_id = insert("productos", {
        "nombre": datos.nombre,
        "marca": datos.marca,
        "variante": datos.variante,
        "categoria_id": datos.categoria_id,
        "unidad_medida": datos.unidad_medida,
        "es_consumible": datos.es_consumible,
        "tasa_consumo_diaria_default": datos.tasa_diaria or 0,
        "precio_objetivo": datos.precio_objetivo,
        "notas": datos.notas
    })
    if datos.es_consumible:
        insert("inventario_productos", {"producto_id": producto_id})
    return producto_id

def actualizarProducto(producto_id, datos):
def desactivarProducto(producto_id):                  # activo = false (soft delete)
def crearCategoriaProducto(datos):
def actualizarCategoriaProducto(cat_id, datos):
def eliminarCategoriaProducto(cat_id):                # solo si no tiene productos
```

#### **Valoraciones**
```python
def crearValoracion(datos):
    insert("valoraciones_productos", {
        "producto_id": datos.producto_id,
        "rating_calidad": datos.rating_calidad,   # 1-10
        "rating_valor": datos.rating_valor,       # 1-10
        "comentario": datos.comentario,
        "subgasto_id": datos.subgasto_id          # opcional
    })

def actualizarValoracion(valoracion_id, nuevos_datos):
def eliminarValoracion(valoracion_id):
```

#### **Inventario & Stock**
```python
def actualizarInventario(producto_id, cantidad_delta, tipo_mov: tipo_movimiento_enum):
    # tipo_mov = COMPRA | CONSUMO | AJUSTE | PERDIDA
    if tipo_mov in ["COMPRA", "AJUSTE"]:
        new_cant = cantidad_actual + cantidad_delta
    else:
        new_cant = cantidad_actual - cantidad_delta
    update("inventario_productos", {
        "cantidad_actual": new_cant,
        "fecha_ultima_actualizacion": now()
    }, where={"producto_id": producto_id})

def registrarConsumoManual(producto_id, cantidad, fecha_real_inicio=None):
    # permite marcar que "empecé a usar el producto hoy"
    actualizarInventario(producto_id, cantidad, "CONSUMO")
    if fecha_real_inicio:
        update("inventario_productos", {"fecha_real_inicio": fecha_real_inicio})

def ajustarTasaConsumo(producto_id, nueva_tasa_diaria):
    update("inventario_productos", {"tasa_consumo_diaria": nueva_tasa_diaria})
```

---

### **2. Acciones de Lectura (Reads / Queries)**

```python
# Gastos
def listarGastos(filtro: {fecha_desde, fecha_hasta, establecimiento?, estado?}):
def obtenerGastoDetalle(gasto_id):                    # + join subgastos

# Subgastos
def listarSubgastosPorGasto(gasto_id):
def listarSubgastosPorProducto(producto_id, limit=100):

# Productos
def buscarProductos(texto: str, categoria_id=None, solo_activos=True):
def obtenerProductoCompleto(producto_id):             # + inventario + avg rating

# Histórico de precios (núcleo del sistema)
def historicoPreciosProducto(producto_id, meses=12):
    SELECT fecha(g.fecha), s.precio_unitario 
    FROM subgastos s JOIN gastos g ON s.gasto_id = g.id
    WHERE s.producto_id = producto_id
    ORDER BY g.fecha DESC

# Valoraciones
def obtenerValoracionesProducto(producto_id):
def calcularEficienciaProducto(producto_id):          # rating_valor / precio_promedio_3meses

# Inventario & Proyecciones
def obtenerStockActual(producto_id):
def listarStockBajo(umbral_dias=14):                  # productos que se acaban en < 14 días
def calcularFechaAgotamiento(producto_id):            # cantidad_actual / tasa_consumo_diaria

# Estimación mes siguiente (la estrella del sistema)
def estimacionGastoMesSiguiente(mes_objetivo: date):
    recurrentes = sum(subgastos where recurrencia != 'NINGUNA')
    ciclo_vida  = sum(subgastos where es_consumible and fecha_fin in mes_objetivo)
    variables   = avg_3meses(tipo='VARIABLE_MENSUAL')
    deplecion   = sum(inventario where fecha_proyectada_inicio in mes_objetivo)
    return recurrentes + ciclo_vida + variables + deplecion

# Comparativas y decisiones
def compararProductosPorUso(categoria_id):
    # devuelve ranking por (rating_valor / precio_unitario_promedio)
def productosRecomendadosParaRemplazo(producto_actual_id):
    # productos misma categoría con mejor rating_valor y precio < actual
```

---

### **3. Tareas en Segundo Plano (Background Jobs)**

| Tarea | Frecuencia | Pseudocódigo / Responsabilidad |
|-------|------------|-------------------------------|
| **Recálculo diario de fechas de agotamiento** | Cada día a las 03:00 | `for each inv in inventario_productos where tasa > 0: calcular y actualizar fecha_proyectada_fin` |
| **Alertas de stock bajo** | Cada día a las 07:00 | Generar notificaciones/push si días_restantes < 10 (o umbral por usuario) |
| **Actualización de medias variables** | Cada día | Materialized view o tabla cache `media_variables_3meses` |
| **Sugerencias de remplazo automáticas** | Semanal (domingo) | Para cada producto con rating_valor < 6 → buscar alternativas mejores |
| **Limpieza de datos antiguos** | Mensual | Archivar gastos > 5 años (o mover a cold storage) |
| **Backfill de inventario histórico** | Solo al activar feature | Script one-time: sumar todas las compras pasadas de productos consumibles |
| **Cálculo de inflación personal por producto** | Mensual | `(precio_actual - precio_hace_12m) / precio_hace_12m * 100` por producto |
| **Generación de reporte mensual PDF/Email** | 1er día de mes | Resumen + top 5 gastos + productos que más subieron de precio + recomendaciones |

**Notas de implementación recomendadas:**
- Todas las escrituras importantes van dentro de **transacciones** (especialmente crearGastoCompleto + actualizarInventario).
- Usa **triggers** PostgreSQL para:
  - Mantener `total_linea` generado.
  - Actualizar `fecha_fin` automáticamente cuando se registra consumo.
- Las lecturas pesadas (estimación mes, histórico precios, ranking eficiencia) → **materialized views** que se refrescan en background.

¿Quieres que ahora genere:
1. El archivo SQL completo con **todas las tablas + triggers + materialized views**?
2. El código de ejemplo de un servicio (Node.js / Python) con estas funciones?
3. El diagrama de flujo visual de “Registrar compra → actualizar stock”?

Dime qué siguiente pieza necesitas y te la entrego lista para copiar.