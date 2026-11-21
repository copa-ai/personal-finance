<?php

namespace Tests\Feature;

use App\Services\CopiaBase;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** La copia de seguridad no lleva el estado volatil del Coder. DatabaseMigrations (sin transaccion): el volcado abre la suya. */
class CopiaBaseCoderTest extends TestCase
{
    use DatabaseMigrations;

    public function test_el_volcado_omite_la_tabla_coder_estado(): void
    {
        DB::table('coder_estado')->insert(['clave' => 'saldo', 'valor' => '{"mu":1}', 'created_at' => now(), 'updated_at' => now()]);

        $sql = '';
        (new CopiaBase)->volcarSql(function (string $trozo) use (&$sql): void {
            $sql .= $trozo;
        });

        $this->assertStringContainsString('"users"', $sql);
        $this->assertStringNotContainsString('coder_estado', $sql);
    }
}
