# pf-market-data

Pasarela de datos de mercado públicos para personal-finance, empaquetada como
servicio Celaut. Es el **único componente con salida a Internet** y no guarda datos
personales: pf-app (Laravel) le pide cotizaciones, históricos, búsquedas y tipos de
cambio por HTTP, y él consulta a los proveedores públicos.

Python 3.11+ y **solo biblioteca estándar** (`http.server`, `urllib`, `sqlite3`,
`ssl`, `json`...). Sin dependencias de pip.

## API

Todo es JSON UTF-8. Los errores devuelven 4xx/5xx con `{"error": "mensaje"}`.

| Método y ruta | Respuesta |
|---|---|
| `GET /health` | `{"ok": true, "providers": {"yahoo": "ok\|cooldown\|error", ...}}` |
| `GET /quote?symbols=a,b,...` | cotizaciones (máx. 50 símbolos) |
| `GET /history?symbol=&range=&interval=` | velas OHLCV |
| `GET /search?q=` | búsqueda de símbolos (máx. 20 resultados) |
| `GET /fx` | tipos de referencia del BCE (base EUR) |
| `POST /notify` | reenvía una notificación a ntfy |

### `GET /quote`

```sh
curl -s 'http://<instancia>:8080/quote?symbols=crypto:BTC-USD,index:%5EGSPC,stock:XXXX'
```

```json
{"quotes": [{"symbol": "crypto:BTC-USD", "name": "Bitcoin", "price": 82976.1,
             "currency": "USD", "previous_close": 82912.7, "change": 63.4,
             "change_pct": 0.0765, "as_of": "2026-10-11T11:45:43Z",
             "source": "kraken", "stale": false}],
 "errors": [{"symbol": "stock:XXXX", "error": "no encontrado"}]}
```

- Siempre **200** si la petición está bien formada, aunque fallen símbolos (van a
  `errors`). Un símbolo con formato inválido hace que toda la petición sea **400**.
- `previous_close`, `change` y `change_pct` pueden ser `null`. `change_pct` en %.
- `stale: true`: todos los proveedores fallaron y se sirve el último valor cacheado
  (cualquier antigüedad; `as_of` es el del dato original).
- Mensajes de error por símbolo: `no encontrado` (todos los proveedores dicen que no
  existe) o `proveedores no disponibles`.
- Divisas en subunidad (`GBp`, `ZAc`, `ILA`) se normalizan a ISO (`GBP`...) y el
  precio se divide entre 100.

### `GET /history`

```sh
curl -s 'http://<instancia>:8080/history?symbol=index:%5EGSPC&range=1mo&interval=1d'
```

```json
{"symbol": "index:^GSPC", "currency": "USD", "source": "yahoo",
 "candles": [{"t": "2026-10-01T13:30:00Z", "o": 6700.1, "h": 6720.4, "l": 6690.0, "c": 6712.3, "v": 3123450000.0}]}
```

`range`: `1d 5d 1mo 3mo 6mo 1y 5y max` (por defecto `1mo`). `interval`: `1h 1d 1wk 1mo`
(por defecto `1d`). Combinaciones admitidas (el resto, **400**):

| interval | ranges |
|---|---|
| `1h` | 1d, 5d, 1mo, 3mo, 6mo, 1y |
| `1d` | todos |
| `1wk` | 1mo, 3mo, 6mo, 1y, 5y, max |
| `1mo` | 3mo, 6mo, 1y, 5y, max |

**404** si ningún proveedor conoce el símbolo, **502** si no responde ninguno y no hay
caché. `v` puede ser `null`. La vela del periodo en curso puede estar incompleta.

### `GET /search`

```json
{"results": [{"symbol": "stock:AAPL", "name": "Apple Inc.", "type": "stock", "exchange": "NASDAQ"}]}
```

Yahoo (`quoteType` → kind: EQUITY→stock, ETF→etf, INDEX→index, CURRENCY→fx,
FUTURE→commodity, MUTUALFUND→fund, CRYPTOCURRENCY→crypto) seguido de CoinGecko
(`crypto:<SÍMBOLO>-USD`), sin duplicados. `exchange` es `null` en los resultados de
CoinGecko. `q`: 1–64 caracteres. **502** si fallan los dos proveedores.

### `GET /fx`

```json
{"date": "2026-10-09", "base": "EUR", "rates": {"USD": 1.1206, "JPY": 177.34, ...}, "source": "ecb"}
```

### `POST /notify`

```json
{"title": "Alerta", "message": "BTC superó 90 000 €", "priority": "default", "tags": ["moneybag"]}
```

`message` obligatorio (≤ 4096); `title` opcional (≤ 250); `priority` `default` o `high`;
`tags` hasta 10 etiquetas `[A-Za-z0-9_+-]`. Respuesta `{"ok": true}`. Sin `MD_NTFY_URL`:
**503** `{"error": "ntfy no configurado"}`. Si ntfy falla: **502**. Se publica en JSON
contra la raíz del servidor ntfy, así que título y mensaje admiten UTF-8.

## Símbolos

Formato canónico `<kind>:<code>`, validado con expresiones estrictas (mayúsculas
exactas: se devuelven tal cual se piden).

| kind | code | ejemplos |
|---|---|---|
| `crypto` | `BASE-QUOTE` | `crypto:BTC-USD`, `crypto:ETH-EUR` |
| `fx` | 6 letras `BASEQUOTE` | `fx:EURUSD` (Yahoo: `EURUSD=X`) |
| `index`, `etf`, `stock`, `commodity`, `bond`, `fund` | ticker de Yahoo | `index:^GSPC`, `index:^IBEX`, `etf:IWDA.AS`, `stock:AAPL`, `commodity:GC=F`, `bond:^TNX` |

## Proveedores y cadenas de respaldo

Se prueban en orden; `source` dice cuál respondió. Los proveedores con clave se saltan
en silencio si su variable no está definida.

| Datos | Cadena |
|---|---|
| Cotización cripto | Kraken → CoinGecko → Binance → Yahoo |
| Histórico cripto | Kraken OHLC → Yahoo |
| Cotización fx | BCE (tabla cruzada) → Yahoo |
| Cotización resto | Yahoo → Twelve Data → Finnhub → Alpha Vantage |
| Histórico resto (y fx) | Yahoo → Twelve Data |
| Búsqueda | Yahoo + CoinGecko |

Detalles de cada uno:

- **Kraken** (Ticker en lote, pares reales en USD/EUR/GBP...; BTC→XBT, DOGE→XDG).
  `previous_close` es el campo `o`: la apertura del día en curso (00:00 UTC). Si un par
  no existe, Kraken rechaza el lote entero y se repite par a par. OHLC solo cuando el
  rango cabe en sus 720 velas (no `max`, ni `5y` diario, ni `1mo` de intervalo).
- **CoinGecko** (`simple/price` en lote con `include_24hr_change`). `previous_close` se
  deriva del cambio de 24 h (ventana móvil). Id de moneda: tabla integrada (~40
  principales, `service/providers/coins.py`) o `/search` cacheado 7 días.
- **Binance** (`ticker/24hr` en lote). USD usa el par `BASEUSDT` (USDT ≈ USD);
  `previous_close` es el precio de hace 24 h.
- **BCE**: tabla diaria cruzada para cualquier par entre sus divisas, en ambos
  sentidos. `previous_close` sale del día hábil anterior de la serie de 90 días del
  propio BCE (comparable con el precio, a diferencia de un cierre de Yahoo). `as_of` es
  la fecha de referencia a las 15:00 UTC (hora aproximada de publicación).
- **Yahoo** (chart `range=1d` para la cotización: precio `regularMarketPrice`, cierre
  previo `chartPreviousClose`/`previousClose`). User-Agent de navegador; alterna
  query1/query2; ante 401/403 obtiene cookie (`fc.yahoo.com`) + crumb una vez y
  reintenta.
- **Twelve Data** (cotización e histórico; clave en cabecera `Authorization`). Mapea
  sufijos de Yahoo a MIC (`.AS`→XAMS, `.MC`→XMAD, `.DE`→XETR...) y algunos índices
  (`^GSPC`→SPX) y metales (`GC=F`→XAU/USD).
- **Finnhub** y **Alpha Vantage**: solo cotización, solo acciones/ETF de EE. UU. (sin
  sufijo), que es lo que cubren sus planes gratuitos; divisa USD.

**Cooldown**: un HTTP 429 (o el aviso equivalente de Kraken, Twelve Data, Alpha
Vantage; 418 en Binance) pone el proveedor en cooldown 60 s, que se duplica en cada 429
seguido hasta 30 min y se reinicia con una respuesta correcta. Mientras dura, la cadena
lo salta. `/health` lo muestra como `cooldown`.

**Limitador local** (token bucket por proveedor, sin espera: si no hay token se pasa
al siguiente): Yahoo 30/min, CoinGecko 25/min, Kraken 60/min, Binance 300/min, BCE
10/min, Twelve Data 8/min, Finnhub 60/min, Alpha Vantage 5/min, ntfy 30/min.

## Caché

SQLite en `MD_CACHE_PATH` (por defecto `/data/cache.sqlite`; si no se puede escribir,
`$TMPDIR/pf-market-data/cache.sqlite`, y en último caso memoria).

| Dato | Fresco durante |
|---|---|
| Cotización | `MD_QUOTE_TTL` (120 s) |
| Histórico diario/semanal/mensual | 6 h |
| Histórico horario | 10 min |
| Búsqueda | 24 h |
| Tablas del BCE | 6 h |
| Id de CoinGecko | 7 días (1 día si no se encontró) |

Pasado ese tiempo se vuelve a preguntar; si fallan todos los proveedores se sirve el
último valor (cotizaciones con `stale: true`). Las entradas sin tocar en 90 días se
borran al arrancar.

## Variables de entorno

| Variable | Defecto | |
|---|---|---|
| `MD_PORT` | `8080` | Puerto HTTP (debe coincidir con `api` en `service.json`). |
| `MD_CACHE_PATH` | `/data/cache.sqlite` | Fichero SQLite de caché. |
| `MD_QUOTE_TTL` | `120` | Segundos que una cotización se considera fresca. |
| `MD_HTTP_TIMEOUT` | `8` | Timeout de cada petición a un proveedor (s). |
| `MD_DNS_SERVERS` | *(ver abajo)* | 1–3 IPs de resolvers DNS, separadas por espacio o coma. |
| `MD_NTFY_URL` | — | URL https completa del topic, p. ej. `https://ntfy.sh/mitopic`. Su host se añade a la allowlist. |
| `MD_NTFY_TOKEN` | — | Token Bearer para ntfy. |
| `MD_COINGECKO_KEY` | — | Clave demo de CoinGecko (`x-cg-demo-api-key`). |
| `MD_TWELVEDATA_KEY` | — | Activa Twelve Data. |
| `MD_FINNHUB_KEY` | — | Activa Finnhub. |
| `MD_ALPHAVANTAGE_KEY` | — | Activa Alpha Vantage. |

Un valor inválido detiene el arranque en vez de sustituirse por el defecto.

## Red y salida a Internet

`service.json` declara `"network": [{"tags": ["*"]}]`, salida abierta, porque una
etiqueta de hostname no sirve a un cliente HTTP: nodo concede las IPs del nombre en
TCP 80/443 pero **no sirve DNS**, así que `getaddrinfo()` falla antes de usar la
concesión (`docs/NETWORKS.md` de nodo, *Hostname tags*). El servicio estrecha la
salida él mismo:

- **Allowlist de hosts exactos**, comprobada sobre la URL ya parseada antes de cada
  petición y de cada redirección: `query1.finance.yahoo.com`,
  `query2.finance.yahoo.com`, `fc.yahoo.com`, `api.kraken.com`, `api.coingecko.com`,
  `api.binance.com`, `www.ecb.europa.eu`, `api.twelvedata.com`, `finnhub.io`,
  `www.alphavantage.co` y el host de `MD_NTFY_URL`.
- **Solo HTTPS al 443**, sin credenciales en la URL. Las redirecciones solo se siguen
  dentro del mismo host. Se ignoran `http_proxy`/`https_proxy` del entorno.
- **Logs sin query**: se registra host y ruta, nunca la query (donde viajan claves).
- **Sin datos personales**: solo símbolos públicos; las notificaciones son las que
  envía pf-app.

**DNS.** Bajo nodo el invitado no tiene resolver. `start.sh` ejecuta como root
`service/resolver.py`, que escribe `/etc/resolv.conf` con `MD_DNS_SERVERS` o, si existe
`/__config__` (lo escribe el nodo), con `9.9.9.9 1.1.1.1 8.8.8.8`. Fuera de nodo y sin
la variable no toca nada (Docker ya da resolver). Después baja a uid 10001 con
`setpriv --no-new-privs`.

**Servicio padre.** Si pf-app lanza este servicio como dependencia, la cadena de
ancestros de nodo exige que el padre también declare `["*"]`; si no, arranca sin salida
y todas las cotizaciones fallan (o salen `stale`).

## Empaquetado

`.service/` sigue el patrón de `pf-postgres` y `pf-ollama`: `Dockerfile`
(`python:3.12.11-slim-bookworm`, sin pip), `service.json` (linux/amd64, 128 MB/256 MB
de memoria, 512 MB/1 GB de disco, puerto 8080 `http1.1`/`rest`), `pack_config.json`
(incluye `service/`) y `start.sh` (instalado en `/usr/local/bin/pf-market-data-start`).
Solo se exporta el filesystem: `CMD`/`ENTRYPOINT`/`ENV` del Dockerfile se ignoran.

Ejecución local sin nodo ni Docker:

```sh
MD_CACHE_PATH=/tmp/md.sqlite python3 dependencies/pf-market-data/service/server.py
```

## Tests

```sh
python3 -I -m unittest discover -s dependencies/pf-market-data/tests
```

Sin red: un transporte HTTP falso responde con fixtures (`tests/fixtures/`) con la
forma real de cada API (las de Kraken, Binance, CoinGecko y BCE son capturas reales
recortadas). Cubren símbolos, cada parser, orden de las cadenas, 429 → cooldown →
siguiente proveedor, caché `stale`, cruces de fx, errores parciales de `/quote`,
validación de `/history`, fusión de `/search`, allowlist, `/notify`, el servidor HTTP
de extremo a extremo en un puerto aleatorio y las reglas del empaquetado.
