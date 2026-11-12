<?php

namespace App\Services;

use App\Enums\OcrProviderStatus;
use App\Models\OcrProvider;
use App\Services\Celaut\Nodo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Gestiona el servicio OCR activo: recibe un paquete .celaut.bee, lo lanza en el nodo Celaut con `celaut acquire --package`
 * (App\Services\Celaut\Nodo, el mismo canal con el que se lanza la base de datos) y guarda dónde escucha.
 *
 * La instancia la guarda `celaut` en el pool de este servicio (fichero de estado); aquí solo se recuerda su dirección «ip:puerto»
 * y se localiza por ella en `Nodo::instancias()` para pararla o comprobar que sigue viva.
 */
class OcrProviderManager
{
    private const DIRECTORY = 'ocr-providers';

    private ?Nodo $nodo = null;

    public function isAvailable(): bool
    {
        return $this->baseUrl() !== null;
    }

    /**
     * URL base del servicio OCR a usar, sin barra final.
     */
    public function baseUrl(): ?string
    {
        $override = trim((string) config('services.ocr.url'));
        $uri = $override !== '' ? $override : OcrProvider::active()?->uri;

        if ($uri === null || trim($uri) === '') {
            return null;
        }

        $uri = trim($uri);

        if (! str_starts_with($uri, 'http://') && ! str_starts_with($uri, 'https://')) {
            $uri = 'http://'.$uri;
        }

        return rtrim($uri, '/');
    }

    /**
     * Nombre del servicio en uso, para registrarlo en cada OcrJob.
     */
    public function activeLabel(): ?string
    {
        if (trim((string) config('services.ocr.url')) !== '') {
            return 'OCR_SERVICE_URL';
        }

        return OcrProvider::active()?->label();
    }

    public function install(UploadedFile $file, ?string $name = null): OcrProvider
    {
        $path = $file->storeAs(self::DIRECTORY, $file->hashName().'.celaut.bee', 'local');

        if ($path === false) {
            throw new RuntimeException('No se pudo guardar el archivo .celaut.bee.');
        }

        $provider = OcrProvider::query()->create([
            'name' => $name ?: $this->nameFromFilename($file->getClientOriginalName()),
            'bee_path' => $path,
            'status' => OcrProviderStatus::STOPPED,
        ]);

        return $this->start($provider);
    }

    /**
     * Lanza el servicio del paquete y lo deja como el único activo (para antes el anterior, si lo hay).
     */
    public function start(OcrProvider $provider): OcrProvider
    {
        $lease = null;

        try {
            // Solo cabe una instancia: se para la anterior (si es otro proveedor, queda parado y su paquete se conserva hasta que
            // el nuevo arranque bien).
            $previous = OcrProvider::active();
            $this->stopInstance($previous);

            if ($previous !== null && $previous->isNot($provider)) {
                $previous->forceFill(['status' => OcrProviderStatus::STOPPED, 'uri' => null])->save();
            }

            $started = $this->acquirePackage(Storage::disk('local')->path($provider->bee_path));
            $lease = (int) $started['lease'];

            if (! $this->waitOpen($started['uri'], (int) config('services.ocr.start_timeout'))) {
                throw new RuntimeException('El servicio OCR no responde en '.$started['uri'].'.');
            }
        } catch (Throwable $e) {
            Log::error('[OcrProviderManager] No se pudo lanzar el servicio OCR', [
                'provider_id' => $provider->id,
                'error' => $e->getMessage(),
            ]);

            if ($lease !== null) {
                $this->stopLease($lease);
            }

            $provider->forceFill([
                'status' => OcrProviderStatus::FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        $provider->forceFill([
            'service_hash' => $this->serviceOf($lease),
            'uri' => $started['uri'],
            'status' => OcrProviderStatus::ACTIVE,
            'error_message' => null,
        ])->save();

        $this->pruneBeeFiles($provider);

        return $provider;
    }

    public function stop(): void
    {
        $active = OcrProvider::active();

        if ($active === null) {
            return;
        }

        $this->stopInstance($active);

        $active->forceFill(['status' => OcrProviderStatus::STOPPED, 'uri' => null])->save();
    }

    /**
     * Tras reiniciar el servicio: si el pool de `celaut` conserva la instancia del OCR activo y responde, se deja; si no, se vuelve
     * a lanzar el paquete.
     */
    public function restore(): ?OcrProvider
    {
        $active = OcrProvider::active();

        if ($active === null) {
            return null;
        }

        if ($active->uri !== null && $this->leaseOf($active->uri) !== null && $this->waitOpen($active->uri, 5)) {
            return $active;
        }

        return $this->start($active);
    }

    private function nodo(): Nodo
    {
        return $this->nodo ??= Nodo::desdeConfig();
    }

    /**
     * `celaut acquire --package FILE`: el paquete que subió el usuario no está entre las dependencias empaquetadas (`.dependencies`),
     * así que `Nodo::adquirir()` (por nombre) no sirve. Mismo binario y mismo fichero de estado que `Nodo`.
     *
     * @return array{lease: int, uri: string, new: bool}
     */
    private function acquirePackage(string $path): array
    {
        $estado = config('finance.celaut.estado');
        $args = [config('finance.celaut.binario', 'celaut'), ...($estado ? ['--state', $estado] : []), 'acquire', '--package', $path];

        // Un lanzamiento puede tardar minutos: el nodo envía el paquete, lo construye y arranca el servicio antes de contestar.
        $process = new Process($args, null, null, null, max(60, (int) config('services.ocr.start_timeout')));
        $process->run();

        if (! $process->isSuccessful()) {
            $error = json_decode($process->getErrorOutput(), true);
            $reason = is_array($error) ? ($error['error'] ?? 'error desconocido') : trim($process->getErrorOutput());

            throw new RuntimeException('celaut acquire: '.$reason);
        }

        $data = json_decode($process->getOutput(), true);

        if (! is_array($data) || empty($data['uri']) || ! isset($data['lease'])) {
            throw new RuntimeException('celaut acquire no devolvió "lease" y "uri": '.mb_substr(trim($process->getOutput()), 0, 300));
        }

        return $data;
    }

    private function stopInstance(?OcrProvider $provider): void
    {
        $lease = $provider?->uri !== null ? $this->leaseOf($provider->uri) : null;

        if ($lease !== null) {
            $this->stopLease($lease);
        }
    }

    private function stopLease(int $lease): void
    {
        try {
            $this->nodo()->parar($lease);
        } catch (Throwable $e) {
            Log::warning('[OcrProviderManager] No se pudo parar la instancia OCR', ['lease' => $lease, 'error' => $e->getMessage()]);
        }
    }

    private function leaseOf(string $uri): ?int
    {
        try {
            foreach ($this->nodo()->instancias() as $instance) {
                if (($instance['uri'] ?? null) === $uri) {
                    return (int) $instance['lease'];
                }
            }
        } catch (Throwable) {
            // Sin nodo (desarrollo) o sin pool: no hay instancia que localizar.
        }

        return null;
    }

    private function serviceOf(int $lease): ?string
    {
        try {
            foreach ($this->nodo()->instancias() as $instance) {
                if ((int) ($instance['lease'] ?? -1) === $lease) {
                    return $instance['service'] ?? null;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }

    /**
     * Espera a que «ip:puerto» acepte conexiones TCP.
     */
    private function waitOpen(string $uri, int $timeout): bool
    {
        [$host, $port] = [substr($uri, 0, (int) strrpos($uri, ':')), (int) substr($uri, (int) strrpos($uri, ':') + 1)];
        $deadline = time() + max(1, $timeout);

        do {
            $socket = @fsockopen($host, $port, $errno, $error, 2);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }

            sleep(2);
        } while (time() < $deadline);

        return false;
    }

    private function nameFromFilename(string $filename): string
    {
        return mb_substr(preg_replace('/\.celaut\.bee$/i', '', $filename) ?? $filename, 0, 100);
    }

    /**
     * Conserva solo el paquete del servicio activo: pueden pesar cientos de MB.
     */
    private function pruneBeeFiles(OcrProvider $keep): void
    {
        $stale = OcrProvider::query()->whereKeyNot($keep->id)->get();

        foreach ($stale as $old) {
            Storage::disk('local')->delete($old->bee_path);
            $old->delete();
        }
    }
}
