<?php

namespace App\Console\Commands;

use App\Services\Celaut\Nodo;
use App\Services\Celaut\NodoException;
use Illuminate\Console\Command;

/** Para las instancias Celaut que lanzo este servicio (pf-postgres). start.sh lo llama al terminar. */
class ApagarNodo extends Command
{
    protected $signature = 'finance:apagar-nodo';

    protected $description = 'Para las instancias Celaut que lanzo este servicio';

    public function handle(): int
    {
        try {
            Nodo::desdeConfig()->apagar();
        } catch (NodoException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
