<?php

namespace App\Console\Commands;

use App\Services\Fusion\Exportador;
use App\Services\Instancia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Vuelca los datos de esta base a un fichero que otra base puede fusionar (`finance:fusionar-datos`). */
class ExportarDatos extends Command
{
    protected $signature = 'finance:exportar-datos {salida : Fichero NDJSON que se crea}';

    protected $description = 'Vuelca los datos de esta base a un fichero para fusionarlos en otra';

    public function handle(): int
    {
        $flujo = @fopen((string) $this->argument('salida'), 'w');
        if ($flujo === false) {
            $this->error('No se puede crear el fichero.');

            return self::FAILURE;
        }
        try {
            $cuenta = (new Exportador)->exportar(DB::connection()->getPdo(), $flujo, (string) Instancia::id());
        } finally {
            fclose($flujo);
        }

        foreach ($cuenta as $nombre => $filas) {
            $this->line(str_pad($nombre, 26).$filas);
        }

        return self::SUCCESS;
    }
}
