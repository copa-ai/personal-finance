ESTRUCTURA DE ENTIDADES Y ACCIONES

Expense Tracker - Finanzas Personales

Tecnologías: PostgreSQL 18 + Laravel 12 + Filament PHP 5

ENUMs

```sql
CREATE TYPE estado_gasto_enum      AS ENUM ('PAGADO', 'PENDIENTE', 'REEMBOLSADO', 'BLOQUEADO');
CREATE TYPE tipo_subgasto_enum     AS ENUM ('FIJO', 'VARIABLE_REGULAR', 'VARIABLE_IRREGULAR');
CREATE TYPE recurrencia_enum       AS ENUM ('NINGUNA', 'MENSUAL', 'TRIMESTRAL', 'ANUAL');
CREATE TYPE tipo_movimiento_enum   AS ENUM ('COMPRA', 'CONSUMO', 'AJUSTE', 'PERDIDA');
```

ENTIDADES

gastos

| Columna            | Tipo            | Constraints                          | Descripción                     |
|--------------------|-----------------|--------------------------------------|---------------------------------|
| id                 | UUID            | PRIMARY KEY                          | Identificador único             |
| establecimiento    | VARCHAR(150)    | NOT NULL                             | Nombre del comercio             |
| fecha              | TIMESTAMPTZ     | NOT NULL                             | Fecha y hora real de la compra  |
| total              | NUMERIC(10,2)   | NOT NULL                             | Total pagado                    |
| estado             | estado_gasto_enum | NOT NULL DEFAULT 'PAGADO'          | Estado del pago                 |
| foto_ticket_hash   | VARCHAR(255)    | NULL                                 | Hash o ruta de la foto del ticket |
| creado_en          | TIMESTAMPTZ     | NOT NULL DEFAULT NOW()               | Fecha de creación del registro  |

subgastos

| Columna                  | Tipo              | Constraints                                      | Descripción                              |
|--------------------------|-------------------|--------------------------------------------------|------------------------------------------|
| id                       | UUID              | PRIMARY KEY                                      | Identificador único                      |
| gasto_id                 | UUID              | NOT NULL REFERENCES gastos(id) ON DELETE CASCADE | Relación con el gasto                    |
| producto_id              | UUID              | NULL REFERENCES productos(id) ON DELETE SET NULL | Normalización del producto               |
| concepto                 | VARCHAR(255)      | NOT NULL                                         | Texto exacto del ticket                  |
| cantidad                 | NUMERIC(10,3)     | NOT NULL DEFAULT 1.0                             | Cantidad comprada                        |
| precio_unitario          | NUMERIC(10,2)     | NOT NULL                                         | Precio por unidad                        |
| total_linea              | NUMERIC(10,2)     | GENERATED ALWAYS AS (cantidad * precio_unitario) STORED | Total de la línea                  |
| etiquetas                | JSONB             | NOT NULL DEFAULT '[]'                            | Array de etiquetas                       |
| tipo                     | tipo_subgasto_enum| NOT NULL                                         | Tipo de subgasto                         |
| es_consumible            | BOOLEAN           | NOT NULL DEFAULT true                            | Indica si es consumible                  |
| fecha_fin                | DATE              | NULL                                             | Fecha estimada de próxima compra         |
| recurrencia              | recurrencia_enum  | NOT NULL DEFAULT 'NINGUNA'                       | Tipo de recurrencia                      |
| fecha_proyectada_inicio  | DATE              | NULL                                             | Fecha proyectada de apertura del lote    |
| fecha_real_inicio        | DATE              | NULL                                             | Fecha real de apertura del lote          |

categorias_producto

| Columna      | Tipo         | Constraints                     | Descripción                              |
|--------------|--------------|---------------------------------|------------------------------------------|
| id           | UUID         | PRIMARY KEY                     | Identificador único                      |
| nombre       | VARCHAR(100) | NOT NULL UNIQUE                 | Nombre de la categoría de uso            |
| descripcion  | TEXT         | NULL                            | Descripción opcional                     |
| prioridad    | SMALLINT     | DEFAULT 5 CHECK (prioridad BETWEEN 1 AND 10) | Nivel de prioridad (1-10)          |
| creado_en    | TIMESTAMPTZ  | DEFAULT NOW()                   | Fecha de creación                        |

productos

| Columna                     | Tipo              | Constraints               | Descripción                              |
|-----------------------------|-------------------|---------------------------|------------------------------------------|
| id                          | UUID              | PRIMARY KEY               | Identificador único                      |
| nombre                      | VARCHAR(255)      | NOT NULL                  | Nombre del producto                      |
| marca                       | VARCHAR(100)      | NULL                      | Marca                                    |
| variante                    | VARCHAR(100)      | NULL                      | Variante (sabor, tamaño, etc.)           |
| categoria_id                | UUID              | NOT NULL REFERENCES categorias_producto(id) | Categoría de uso                 |
| unidad_medida               | VARCHAR(20)       | NOT NULL                  | Unidad de medida (kg, l, unidades, etc.) |
| es_consumible               | BOOLEAN           | NOT NULL DEFAULT true     | Indica si es consumible                  |
| cantidad_actual             | NUMERIC(12,3)     | NOT NULL DEFAULT 0.0      | Stock total actual                       |
| tasa_consumo_diaria         | NUMERIC(10,5)     | NULL                      | Tasa de consumo diaria estimada          |
| precio_objetivo             | NUMERIC(10,2)     | NULL                      | Precio objetivo                          |
| notas                       | TEXT              | NULL                      | Notas adicionales                        |
| activo                      | BOOLEAN           | DEFAULT true              | Activo (soft delete)                     |
| fecha_ultima_actualizacion  | TIMESTAMPTZ       | DEFAULT NOW()             | Última actualización del stock           |
| creado_en                   | TIMESTAMPTZ       | DEFAULT NOW()             | Fecha de creación                        |

valoraciones_productos

| Columna         | Tipo         | Constraints                               | Descripción                              |
|-----------------|--------------|-------------------------------------------|------------------------------------------|
| id              | UUID         | PRIMARY KEY                               | Identificador único                      |
| producto_id     | UUID         | NOT NULL REFERENCES productos(id) ON DELETE CASCADE | Producto relacionado              |
| rating_calidad  | NUMERIC(2,1) | CHECK (rating_calidad BETWEEN 1 AND 10)   | Valoración de calidad (1-10)             |
| rating_valor    | NUMERIC(2,1) | CHECK (rating_valor BETWEEN 1 AND 10)     | Valoración calidad/precio (1-10)         |
| comentario      | TEXT         | NULL                                      | Comentario libre                         |
| subgasto_id     | UUID         | NULL REFERENCES subgastos(id) ON DELETE SET NULL | Subgasto asociado (opcional)       |
| fecha           | TIMESTAMPTZ  | DEFAULT NOW()                             | Fecha de la valoración                   |

RELACIONES

- gastos 1 → * subgastos  
- subgastos * → 1 productos  
- productos * → 1 categorias_producto  
- valoraciones_productos * → 1 productos  
- valoraciones_productos * → 0..1 subgastos  

TRIGGERS

```sql
CREATE OR REPLACE FUNCTION actualizar_stock_compra() 
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.producto_id IS NOT NULL AND NEW.es_consumible THEN
        UPDATE productos 
        SET cantidad_actual = cantidad_actual + NEW.cantidad,
            fecha_ultima_actualizacion = NOW()
        WHERE id = NEW.producto_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_subgasto_compra
    AFTER INSERT ON subgastos
    FOR EACH ROW 
    EXECUTE FUNCTION actualizar_stock_compra();
```

ACCIONES DEL SISTEMA

Escrituras

- CreateGastoCompleto (gasto + múltiples subgastos + actualización automática de stock)  
- UpdateGasto  
- DeleteGasto (ON DELETE CASCADE)  
- CreateSubgasto / UpdateSubgasto (permite editar fecha_proyectada_inicio y fecha_real_inicio)  
- CreateProducto / UpdateProducto (incluye tasa_consumo_diaria y cantidad_actual)  
- CreateCategoriaProducto / UpdateCategoriaProducto  
- CreateValoracion / UpdateValoracion  
- RegistrarConsumoManual (resta stock y actualiza fecha_real_inicio)  
- AjustarStockManual (tipo_movimiento_enum)  
- AjustarTasaConsumo  

Lecturas

- ListarGastos (con filtros)  
- ObtenerGastoDetalle  
- HistoricoPreciosProducto  
- EstimacionGastoMesSiguiente (recurrentes + variables + subgastos con fecha_proyectada_inicio en el mes + depleción de stock)  
- CalcularFechaAgotamiento  
- ListarStockBajo  
- CompararProductosPorCategoria  
- ProductosRecomendadosParaRemplazo  
- ObtenerValoracionesProducto  

Tareas en segundo plano (Laravel Scheduler)

- RecalcularFechasAgotamiento (diario 03:00)  
- AlertasStockBajo (diario 07:00)  
- ActualizarMediasVariables (diario)  
- SugerenciasRemplazo (semanal)  
- ReporteMensual (primer día del mes)  
- CalculoInflacionPersonal (mensual)  

Los backups de base de datos se guardan en `storage/app/private/backups/postgres` por defecto.

Para hacer backup manual:
"""
docker exec -i personal_finance_db env PGPASSWORD=secret pg_dump -U laravel -d personal_finance | gzip > backup.sql.gz
"""

USUARIOS

Crear un usuario (no modifica uno existente). La contraseña se lee de `PF_ADMIN_PASSWORD` o se pregunta, mínimo 12 caracteres:
"""
php artisan app:ensure-user tu@email.com --name="Tu nombre"
"""

En Celaut, `start.sh` lo hace al arrancar (solo si no existe ya) con `PF_ADMIN_EMAIL` y `PF_ADMIN_PASSWORD` (`nodo execute -e ...`; contraseña de 12 caracteres o más). Sin ellas crea **`admin@demo.com` / `cambia-esta-clave`** y la página de estado avisa: define las variables o cambia la contraseña en el primer acceso.

`php artisan db:seed` crea los usuarios de ejemplo con la contraseña de `SEED_PASSWORD`, o con una aleatoria que muestra por consola.

TESTS

Los tests usan PostgreSQL (triggers, funciones de fusión y `uuidv7()`), no SQLite. Por defecto esperan una base `personal_finance_test` en `127.0.0.1:5432` con usuario `laravel` / `secret` (ver `phpunit.xml`; se puede sobrescribir con variables de entorno `DB_*`):
"""
docker compose up -d db
docker exec -i personal_finance_db psql -U laravel -d personal_finance -c 'CREATE DATABASE personal_finance_test'
DB_PORT=5433 php artisan test
"""

## Arranque (servicio Celaut)

`.service/` empaqueta la aplicación como servicio de [celaut-project/nodo](https://github.com/celaut-project/nodo) (`docs/PACKING.md`, `docs/TUNNELING.md`), con el mismo modelo que personal-nutrition. Es **un solo microVM arm64** con todas las piezas, arrancadas por `docker/servicio/start.sh` (ya no hay `pf_entrypoint.py` ni `artisan serve`): FrankenPHP (Caddy con PHP embebido; solo HTTP, el TLS lo pone `nodo tunnel`) en el puerto 8080, tres workers de cola (`default`, `chat` y `ocr`, cada uno en un bucle que lo relanza al pasar `--memory`/`--max-jobs`, porque `queue:work` sale solo y, si saliera directo, caería todo el servicio), `schedule:work` (copia diaria y datos de mercado), el vigilante `php artisan finance:vigilar-base` (cada 300 s), el runner del agente (`127.0.0.1:9000`, no tunelable, `AGENTE_MAX_SIMULTANEAS=1`) y el traductor gRPC del chat y de cards (50051 y 50052). Cada pieza corre con su usuario y un entorno vacío (`env -i`): el agente no ve `APP_KEY` ni la base. Mientras arranca, `/estado` enseña las fases y `/app.log` el log; `/memoria.json` el consumo.

La base de datos es la PostgreSQL compartida por la red `postgres` (o `pf-postgres` lanzado como hijo con `celaut`). `FINANCE_RED` (opcional; la da Ajustes → Variables compartidas) trae `APP_KEY|cluster|clave_bd` para unirse a una red existente; sin ella el dispositivo genera las suyas. Las migraciones corren en el arranque (`migrate` y `migrate --database=chat`).

| Recurso | `at_init` = `at_most` | Por qué |
|---|---|---|
| Memoria | 1,5 GiB | PHP (web + 3 colas) ~0,5 GiB; cada `claude -p` ronda 0,5 GiB, así que el agente corre de uno en uno; el servicio OCR y la base, si los lanza como hijos, se pagan aparte |
| CPU | 1 núcleo | los modelos son remotos o hijos de Celaut; la CPU solo la usan PHP y Claude Code |
| Disco | 5 GiB | imagen (PHP + Node + Claude Code + `vendor`, ~1,5 GiB) + paquete `.celaut.bee` del OCR guardado en `storage` + copias |
| Red | `*` (salida abierta) | el agente llama a `api.anthropic.com` por nombre; el OCR y el mercado también salen |
| API | 8080 (HTTP), 50051 (chat), 50052 (cards) | el 9000 del agente y el 8001 interno **no** se declaran, así que no se pueden tunelar |

Son cifras de partida: la memoria real se mide con `/memoria.json` (lo escribe `start.sh` cada 15 s: memoria usada, pico observado desde el arranque, pico y límite del cgroup si el guest los expone y los 10 procesos que más pesan). Con el túnel, `curl <túnel>/memoria.json` tras subir un ticket, ejecutar un OCR y mantener un turno de chat da el pico real; con él se ajusta `mem_limit` de `service.json`.

```bash
nodo pack .                                  # genera el .celaut.bee (requiere packer configurado, ver docs/PACKING.md)
nodo estimate personal-finance               # veredicto de memoria y coste
nodo inspect personal-finance                # variables aceptadas: FINANCE_RED, PF_ADMIN_EMAIL, PF_ADMIN_PASSWORD (todas opcionales)
nodo instances                               # id (= token) de la instancia
nodo tunnel <instancia> 8080 --listen 8080   # y abre http://127.0.0.1:8080 (Filament)
```

`docker-compose.yml` es el entorno de desarrollo local anterior (`artisan serve` + `docker/php/`, ya borrado): no refleja el servicio Celaut.

## Base de datos compartida

PostgreSQL es la única base de los datos. Varios dispositivos pueden compartir una (red Celaut `postgres`, la misma que declara `dependencies/pf-postgres`) y dos bases que se separaron se pueden volver a unir:

- **Claves primarias UUID** en todas las tablas de datos (también `users` y `establishments`; las claves foráneas, `ocr_jobs.user_id` y los morph de comentarios, notificaciones y notas rápidas, igual). Categorías y establecimientos usan un UUIDv5 del nombre (`UuidDeClaveNatural`): la misma categoría creada en dos bases tiene el mismo id. Sin enums nativos de PostgreSQL (texto + enum PHP) y sin columnas generadas: el importe de una línea (`line_total`) lo calcula `ExpenseItem` (cantidad × precio unitario).
- **Auditoría**: triggers de PostgreSQL anotan cada alta, cambio y baja de las tablas de datos en `auditorias` (con usuario, IP e instancia, que deja `ContextoAuditoria` en la sesión de la base); consulta en Ajustes → Auditoría. No se auditan ni se fusionan `price_bars` (datos de mercado que `SyncMarketData` vuelve a bajar), `ocr_jobs` y `ocr_providers` (estado local del OCR) ni las tablas de paquetes con id numérico (reacciones, suscripciones, notas rápidas).
- **Fusión**: `php artisan finance:exportar-datos fichero` y `finance:fusionar-datos fichero` (todo o nada); `finance:vigilar-base` fusiona solo las bases de la red que sobran y para la que ya no escribe nadie, tras guardar una copia. Gana la fila con `updated_at` mayor; lo descartado queda en la auditoría.
- **Copias**: Ajustes → Copia de seguridad (descarga `.sql.gz`) y copias periódicas de la base elegida (`config/finance.php`, `copias_automaticas`).
- **Saldo de la base**: una instancia solo gasta el saldo que tiene y el nodo la para al agotarse; en cada vuelta de `finance:vigilar-base` (5 minutos), antes que nada y aunque la base no responda, `Recarga` mira el saldo de la base que lanzó este servicio (`celaut metrics`), calcula el mantenimiento de 24 h (`celaut cost`: un tick de 10 s por 8640, con un margen del 25 %) y le añade la diferencia con `celaut deposit`, que sale del saldo de este servicio; si no alcanza, añade lo que pueda y lo avisa en el log (se audita `base.recarga` solo un fallo, un depósito parcial o uno grande). Ajustes en `config/finance.php` (`recarga`). El saldo de este servicio sigue siendo tuyo: `nodo increase_deposit <id del servicio> <cantidad>`. Solo toca la base, no el OCR.
- **Variables compartidas**: Ajustes → Variables compartidas da `FINANCE_RED` (base64url de `APP_KEY|cluster|clave_bd`) para arrancar otro dispositivo con la misma base.

**Esquema que expone el servicio.** El `formal` de la red `postgres` es simple y estable: solo tablas y columnas del núcleo (`database/esquema-nucleo.json`: `users`, `categories`, `establishments`, `expenses`, `expense_items`, `ajustes`, `auditorias`), con sus claves primarias, únicas y foráneas entre ellas y sin índices. Lo demás (bancos, activos, cripto, mercado, OCR...) puede cambiar sin cambiar de red. `database/esquema.json` es el resultado (gramática en `App\Services\EsquemaBaseDatos`) y se copia a `.service/service.json` y `dependencies/pf-postgres/.service/service.json`. Tras una migración que toque el núcleo:

```bash
php artisan migrate
php artisan finance:esquema --escribir     # regenera database/esquema.json desde la base migrada
python3 database/sincronizar-esquema.py    # lo copia a los dos service.json (--comprobar solo verifica)
```

El workflow `.github/workflows/esquema.yml` lo hace solo en CI y falla si no coincide.

## Chat

El servicio declara un slot de API con la interfaz «chat» de [chatui](https://github.com/celaut-project/chatui) (`tcp` + `http2` + `grpc` + `chat`, puerto 50051); la instancia **es** la conversación (una por instancia). El cliente habla gRPC con un traductor sin estado en Python (`docker/chat/`, usuario propio, entorno vacío), que habla HTTP con la API interna de Laravel (`routes/interno.php`, prefijo `/interno/chat`, solo `127.0.0.1:8001`, no declarada en `service.json`, `Authorization: Bearer $CHAT_SECRETO`). Laravel es la fuente de verdad.

```
 chatui ──gRPC h2c──▶ nodo (túnel) ──▶ :50051 traductor ──HTTP+Bearer, 127.0.0.1:8001──▶ Laravel
                                                                       │ SQLite local          │ encola «responder» (cola `chat`)
                                                          storage/app/chat/chat.sqlite         ▼
                                                          (conexión `chat`)         bot básico (sin IA)  o  modo Claude
                                                                                       └─ runner 127.0.0.1:9000 → claude -p (usuario agente)
```

- **Historial y fotos** en un SQLite del disco de la instancia (`storage/app/chat/chat.sqlite`, conexión `chat`, migraciones en `database/migrations/chat`; `migrate --database=chat` en el arranque). No entra en las copias de la base ni en la fusión: cada instancia ve solo su conversación. Las fotos de tickets se guardan además, al crear el gasto, en `storage/app/private/tickets/{hash}.ext` (el disco `local`, como las que sube el formulario) y su ruta va en `expenses.ticket_photo_hash`.
- **Bot básico (sin IA)**, `App\Services\Chat\Bot`: una foto de ticket crea un gasto pendiente de revisar (`pending_review = true`) y encola el OCR si hay servicio (`LanzaOcr`); «cuánto he gastado hoy / esta semana / este mes / por categoría» y «qué hay pendiente de revisar». Responde con widgets (bloques `html`) y teclados (bloques `keyboard`) hechos con `Services/Chat/Marcado`.
- **Modo Claude** (si hay token en Ajustes → IA → Conexión con Claude, cifrado con `APP_KEY` en `storage`, y «Chat con Claude» está activo): por cada pregunta, `InstantaneaDatos` escribe una **instantánea de solo lectura** (`categories`, `establishments`, gastos recientes y todos los pendientes, sus líneas, totales por mes y categoría) en `agente/encargos/chat-N/`; un turno de `claude -p` (skill `agente/.claude/skills/chat-finanzas`) responde en streaming y propone **acciones** en `workspace/chat-acciones-N.json`. Laravel las valida (`AccionesChat` + `Acciones/Grupo{Gastos,Lineas,Catalogo}`) y las aplica con las mismas reglas que el panel (`App\Services\Acciones`: `GestionGastos`, `GestionLineas`, `GestionCatalogo`, `LanzaOcr`, `ResumenGastos`), y todo queda en la auditoría (`chat.accion_*` y los eventos `gasto.*`, `categoria.*`, `establecimiento.*`).
  - Acciones: `crear_gasto`, `editar_gasto`, `marcar_revisado`, `marcar_pendiente`, `adjuntar_foto`, `borrar_gasto`\*, `anadir_linea`, `editar_linea`, `quitar_linea`, `borrar_lineas`\*, `asignar_categoria`, `crear_categoria`, `editar_categoria`, `borrar_categoria`\*, `crear_establecimiento`, `editar_establecimiento`, `fusionar_establecimientos`\* (\* = destructiva: no se ejecuta al llegar; Laravel guarda la propuesta en `metadata` del mensaje con un token que el agente no ve, enseña un teclado Confirmar / Cancelar y la ejecuta solo cuando llega «Confirmar acción TOKEN» como mensaje de la persona; idempotente, caduca a los 30 min y se revalida al confirmar). Tope de 10 acciones por turno.
  - Quedan sin acción (se hacen desde el panel): usuarios y contraseñas, ajustes del servicio, token de Claude, copias de seguridad, auditoría, bancos, activos, cripto, carteras demo, mercado y proveedores OCR.
- **Modelo de amenazas.** El agente ejecuta herramientas (la lista del turno es `Read,Glob,Grep,Write`) y procesa texto que no controlamos (lo que escribe la persona, el texto de un ticket, nombres de establecimientos), así que una inyección de prompt debe poder, como mucho, **proponer** una acción, nunca ejecutarla: el agente no tiene credenciales ni SQL, no ve `APP_KEY` ni `CHAT_SECRETO`, solo lee archivos y la instantánea exporta con lista blanca de columnas únicamente categorías, establecimientos, gastos y líneas. **Privacidad:** nunca bancos, cuentas, activos, cripto, carteras demo, mercado, proveedores OCR, usuarios, ajustes, copias ni auditoría. El túnel del nodo es la frontera: quien llega al slot puede leer el historial y, con Claude, esos datos de gasto. Interruptores en Ajustes → IA → Ajustes del servicio (`Chat habilitado`, `Chat con Claude`, tabla `ajustes`, activados por defecto): con el chat apagado la API interna responde `503 deshabilitado`.
- **Límites**: fotos de 4 MiB, hasta 4 por mensaje, `image/jpeg` y `image/png`; texto de hasta 16 384 bytes; un agente simultáneo; la cola `chat` tiene su propio worker.

El traductor genera sus stubs con `docker/chat/generar.sh` (todos los `.proto` de la carpeta) y levanta además el slot «cards» (50052) si junto a él está `cartas.py`.

### Revisar gastos con tarjetas (slot «cards»)

El mismo traductor levanta un segundo slot, «cards» (`http2` + `grpc` + `cards`, puerto 50052, interfaz `cards.md` de chatui; `docker/chat/cartas.py` y `cards.proto`, copia fiel de personal-nutrition), que habla con la API interna `/interno/cards` (`routes/interno-cards.php`, mismo Bearer, mismo interruptor `Chat habilitado`).

- **Mazo** (`App\Services\Cards\Mazo` + `ReviewCards`): una tarjeta por gasto con `pending_review = true`, los más antiguos primero. Título = establecimiento y total; texto = fecha y número de líneas; detalle = líneas (concepto, cantidad, precio, categoría; hasta 40); grupo = categoría de la línea de mayor importe; imagen = foto del ticket si es PNG o JPEG (se importa a `chat_archivos` por hash; HEIC, WebP o un fichero que falte dejan la tarjeta solo con texto).
- **Acciones**: derecha = *Revisado* (`pending_review = false`), izquierda = *Luego* (la tarjeta vuelve al final) y botón *Corregir con el agente* (solo la deja para luego; el `done_text` avisa de que la corrección se hace en el chat). Deshacer vuelve a poner `pending_review = true`. Nunca se toca otro dato. Cada decisión es idempotente por clave y queda en la auditoría (`cards.decision`, `cards.decision_deshecha`, más `gasto.revisado`).
- **Estado** en el SQLite del chat (`cards_mazos`, `cards_eventos` con versión, `cards_decisiones`; migración en `database/migrations/chat`), con un cerrojo de fichero junto a él. El mazo se genera al pedirlo con lo pendiente de ese momento y se reanuda si la pantalla reconecta en menos de `cards.reanudar_s` (300 s) con tarjetas por resolver. No hay ilustraciones.
- **Enlace**: «revisar tickets» (o parecido) en el chat da un mensaje con `slot_link` al puerto 50052; lo atiende siempre el bot básico, aunque Claude esté activo (`Interprete::soloBot`, solo frases cortas). Ajustes → IA → «Revisar tickets» es una página de solo lectura con la misma lista.

## OCR de tickets

El OCR no va dentro de la app: se sube un servicio como paquete `.celaut.bee` desde el panel
(**Servicio OCR**) o con `POST /ocr-service`. Sin servicio activo, el OCR de tickets falla con un
mensaje claro y el resto de la app funciona. Contrato, variables y funcionamiento en
[docs/ocr-service.md](docs/ocr-service.md).

Los tests de `tests/Feature/Payments` cubren el dinero: cuadre de gastos y ajustes, importación de Revolut (estados, reembolsos, comisiones, divisa, duplicados, céntimos), OCR, stock y analíticas por mes en hora local.

Todas las escrituras críticas se ejecutan dentro de transacciones Laravel. Las consultas pesadas utilizan materialized views. El sistema está preparado para Filament Resources y Actions.
