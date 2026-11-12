<?php

namespace App\Console\Commands;

use App\Services\Fusion\Fusionador;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Fusiona en esta base el fichero de otra (`finance:exportar-datos`). Todo o nada; lo descartado queda en la auditoria. */
class FusionarDatos extends Command
{
    protected $signature = 'finance:fusionar-datos {entrada : Fichero NDJSON de finance:exportar-datos}';

    protected $description = 'Fusiona en esta base los datos de otra (de un fichero de finance:exportar-datos)';

    public function handle(): int
    {
        $flujo = is_file((string) $this->argument('entrada')) ? fopen((string) $this->argument('entrada'), 'r') : false;
        if ($flujo === false) {
            $this->error('No se puede leer el fichero.');

            return self::FAILURE;
        }
        try {
            $resumen = (new Fusionador)->fusionar(DB::connection()->getPdo(), $flujo);
        } catch (Throwable $e) {
            $this->error('No se fusiono nada: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            fclose($flujo);
        }

        foreach ($resumen as $concepto => $n) {
            $this->line(str_pad((string) $concepto, 26).$n);
        }

        return self::SUCCESS;
    }
}
