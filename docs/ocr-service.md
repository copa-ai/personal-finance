# Servicio OCR

pf-app no lleva OCR dentro. El OCR es un servicio Celaut aparte que se pasa a la app como
paquete `.celaut.bee`. Así la instancia pesa menos y se puede cambiar de OCR sin reconstruir.

## Subir un servicio

Panel > **Servicio OCR**, o por HTTP (sesión de Filament y token CSRF, o `Accept: application/json`):

```
POST /ocr-service            multipart/form-data
  service   archivo *.celaut.bee   (obligatorio)
  name      texto, opcional

201 {"id": "...", "name": "...", "service_hash": "...", "status": "ACTIVE"}
502 {"message": "..."}             el nodo no pudo lanzarlo
DELETE /ocr-service              para el servicio activo (204)
```

Solo hay un servicio activo: subir otro para al anterior y, cuando el nuevo arranca, borra su
paquete. Si el lanzamiento nuevo falla, el anterior queda parado (con su paquete) y el nuevo
en estado FAILED con el motivo; la página **Servicio OCR** lo muestra. Al arrancar el
servicio, `start.sh` ejecuta `ocr:restore`: si el pool de `celaut` conserva la instancia del
servicio activo y responde, la deja; si no, vuelve a lanzar su paquete.

El OCR de cada ticket corre en la cola `ocr` (`ProcessOcrJob`), con su propio worker.

## Contrato que debe cumplir el servicio

Puerto HTTP expuesto por el servicio (el que declare su `service.json`).

```
POST /ocr
Content-Type: image/jpeg | image/png | ...      cuerpo = la foto del ticket, sin codificar

200 application/json
{
  "establishment": "Mercadona",      cadena; "" si no se identifica
  "category": "Supermercado",        cadena; "" si no se identifica
  "items": [
    {"concept": "Leche", "quantity": 2, "unit_price": 1.10}
  ]
}
```

- `items` es obligatorio y no puede quedar vacío. `quantity` por defecto 1. Admite números o
  cadenas con coma o punto decimal.
- Redimensionar/orientar la imagen y el prompt, si lo hay, son cosa del servicio.
- Cualquier respuesta no 2xx cuenta como fallo del OCR y se muestra en el OcrJob.
- La petición tiene un tope de `OCR_TIMEOUT` segundos (1200 por defecto).

## Servicio de ejemplo

`services/glm-ocr/` define uno que cumple el contrato (Ollama + `glm-ocr`). No se empaqueta
como parte de pf-app.

## Variables

| Variable | Uso |
|---|---|
| `OCR_SERVICE_URL` | Solo desarrollo: URL de un servicio OCR ya levantado. Tiene prioridad sobre el subido. |
| `OCR_TIMEOUT` | Segundos por petición OCR. |
| `OCR_START_TIMEOUT` | Segundos que espera Laravel al lanzamiento (descarga de bloques y arranque). |

Fuera de un nodo Celaut (sin el binario `celaut` ni `/__config__`) no se pueden subir `.celaut.bee`:
usa `OCR_SERVICE_URL`.

## Cómo se lanza

Laravel guarda el paquete en `storage/app/private/ocr-providers/` y `App\Services\OcrProviderManager`
lo lanza con `celaut acquire --package <paquete>` (el binario `celaut` de service-library, el mismo
y con el mismo fichero de estado que usa `App\Services\Celaut\Nodo` para la base de datos). La
instancia la paga el saldo de este servicio, como la base si es hija, y queda en el pool de
`celaut`; la respuesta trae `lease` y `uri` (`ip:puerto`). El manager espera a que el puerto
acepte conexiones (hasta `OCR_START_TIMEOUT`), guarda la `uri` en `OcrProvider` y localiza la
instancia por ella con `Nodo::instancias()` para pararla (`Nodo::parar`). Al terminar el
servicio, `finance:apagar-nodo` (`Nodo::apagar`) la para junto con las demás. Ya no hay
controlador local ni `pf-entrypoint`.

El paquete tiene que ser de la arquitectura del nodo (el servicio es `linux/arm64`).
