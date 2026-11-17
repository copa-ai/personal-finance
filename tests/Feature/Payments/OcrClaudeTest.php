<?php

namespace Tests\Feature\Payments;

use App\Models\Ajuste;
use App\Models\Establishment;
use App\Services\Acciones\LanzaOcr;
use App\Services\Claude\EjecutorClaude;
use App\Services\Claude\ResultadoClaude;
use App\Services\Menu\TokenClaude;
use App\Services\OcrClaude;
use App\Services\OcrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\Concerns\CreatesExpenses;
use Tests\TestCase;

/** OCR de tickets a traves de Claude (App\Services\OcrClaude): se usa si «Chat con Claude» esta activo y hay clave. */
class OcrClaudeTest extends TestCase
{
    use CreatesExpenses;
    use RefreshDatabase;

    private string $agente;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // PNG de 1x1: Claude solo lee imagenes, y el formato se decide por el contenido.
        Storage::disk('local')->put('tickets/ticket.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

        $this->agente = sys_get_temp_dir().'/agente-ocr-'.uniqid();
        config(['agente.carpeta' => $this->agente, 'agente.almacen' => null, 'agente.remoto.url' => null, 'chat_claude.espera_agente_max_s' => 0]);
    }

    protected function tearDown(): void
    {
        is_dir($this->agente.'/encargos') && @rmdir($this->agente.'/encargos');
        is_dir($this->agente) && @rmdir($this->agente);

        parent::tearDown();
    }

    private function activarClaude(): void
    {
        Ajuste::fijar(Ajuste::CHAT_CLAUDE_HABILITADO, true);
        TokenClaude::guardar(str_repeat('t', 40));
    }

    private function claudeResponde(string $salida, int $codigo = 0, string $errores = ''): void
    {
        $this->mock(EjecutorClaude::class, function (MockInterface $m) use ($salida, $codigo, $errores): void {
            $m->shouldReceive('ejecutar')->once()->andReturnUsing(function (string $prompt, ?string $herramientas) use ($salida, $codigo, $errores): ResultadoClaude {
                $this->assertStringStartsWith(OcrClaude::MARCA, $prompt);
                $this->assertSame('Read', $herramientas, 'el agente solo necesita leer la foto');
                // La foto esta donde dice el prompt mientras Claude trabaja.
                $this->assertSame(1, preg_match('#encargos/ocr-[^/]+/ticket\.png#', $prompt, $ruta));
                $this->assertFileExists($this->agente.'/'.$ruta[0]);

                return new ResultadoClaude($salida, $errores, $codigo);
            });
        });
    }

    private function gasto(string $total = '4.00')
    {
        $gasto = $this->createExpense($total);
        $gasto->forceFill(['ticket_photo_hash' => 'tickets/ticket.png'])->save();

        return $gasto;
    }

    public function test_imports_the_lines_claude_reads_and_removes_the_photo_from_the_agent(): void
    {
        $this->activarClaude();
        Http::fake();
        $this->claudeResponde("```json\n".json_encode([
            'establishment' => 'Mercadona', 'category' => 'Alimentación',
            'items' => [['concept' => 'Leche', 'quantity' => 2, 'unit_price' => 1.10], ['concept' => 'Pan', 'quantity' => 1, 'unit_price' => 0.90]],
        ])."\n```");
        $gasto = $this->gasto();

        $importadas = app(OcrService::class)->importExpenseItemsFromTicketOcr($gasto);

        $this->assertSame(2, $importadas);
        $this->assertSame('4.00', $this->itemsTotal($gasto));   // 2 x 1.10 + 0.90 = 3.10 + ajuste de 0.90
        $this->assertSame('Mercadona', $gasto->fresh()->establishment->name);
        $this->assertTrue($gasto->fresh()->pending_review);
        $this->assertSame([], glob($this->agente.'/encargos/*') ?: [], 'la foto no se queda en el agente');
        Http::assertNothingSent();
    }

    public function test_it_is_available_only_with_the_setting_and_a_key(): void
    {
        $this->assertFalse(app(OcrClaude::class)->disponible());

        Ajuste::fijar(Ajuste::CHAT_CLAUDE_HABILITADO, true);
        $this->assertFalse(app(OcrClaude::class)->disponible(), 'sin clave');

        TokenClaude::guardar(str_repeat('t', 40));
        $this->assertTrue(app(OcrClaude::class)->disponible());

        Ajuste::fijar(Ajuste::CHAT_CLAUDE_HABILITADO, false);
        $this->assertFalse(app(OcrClaude::class)->disponible(), 'con el ajuste apagado');
    }

    public function test_ocr_can_be_launched_with_claude_and_no_ocr_service(): void
    {
        config(['services.ocr.url' => null]);
        $this->assertFalse(app(LanzaOcr::class)->disponible());

        $this->activarClaude();

        $this->assertTrue(app(LanzaOcr::class)->disponible());
    }

    public function test_without_claude_the_ocr_service_is_used(): void
    {
        config(['services.ocr.url' => 'http://ocr.test']);
        Http::fake(['ocr.test/ocr' => Http::response(['items' => [['concept' => 'Leche', 'quantity' => 1, 'unit_price' => 1.00]]])]);
        $this->mock(EjecutorClaude::class)->shouldNotReceive('ejecutar');
        $gasto = $this->gasto('1.00');

        app(OcrService::class)->importExpenseItemsFromTicketOcr($gasto);

        Http::assertSentCount(1);
        $this->assertSame('1.00', $this->itemsTotal($gasto));
    }

    public function test_it_falls_back_to_the_ocr_service_when_claude_fails(): void
    {
        $this->activarClaude();
        config(['services.ocr.url' => 'http://ocr.test']);
        Http::fake(['ocr.test/ocr' => Http::response(['establishment' => 'Carrefour', 'items' => [['concept' => 'Agua', 'quantity' => 1, 'unit_price' => 0.50]]])]);
        $this->claudeResponde('', 1, "You've hit your session limit · resets 3:20pm (UTC)");
        $gasto = $this->gasto('0.50');

        app(OcrService::class)->importExpenseItemsFromTicketOcr($gasto);

        Http::assertSentCount(1);
        $this->assertSame('Carrefour', $gasto->fresh()->establishment->name);
    }

    public function test_claude_failure_without_a_service_explains_the_usage_limit(): void
    {
        $this->activarClaude();
        config(['services.ocr.url' => null]);
        $this->claudeResponde('', 1, "You've hit your session limit · resets 3:20pm (UTC)");
        $gasto = $this->gasto();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('límite de uso');

        app(OcrService::class)->importExpenseItemsFromTicketOcr($gasto);
    }

    public function test_a_reply_that_is_not_json_fails_and_creates_nothing(): void
    {
        $this->activarClaude();
        config(['services.ocr.url' => null]);
        $this->claudeResponde('No puedo leer esta foto, lo siento.');
        $gasto = $this->gasto();

        try {
            app(OcrService::class)->importExpenseItemsFromTicketOcr($gasto);
            $this->fail('Tenia que fallar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no es un JSON válido', $e->getMessage());
        }

        $this->assertSame(0, $gasto->items()->count());
        $this->assertNull($gasto->fresh()->establishment);
        $this->assertSame(0, Establishment::query()->count());
    }

    public function test_formats_claude_cannot_read_are_rejected_before_calling_it(): void
    {
        $this->activarClaude();
        config(['services.ocr.url' => null]);
        Storage::disk('local')->put('tickets/ticket.txt', 'esto no es una foto');
        $this->mock(EjecutorClaude::class)->shouldNotReceive('ejecutar');
        $gasto = $this->createExpense('1.00');
        $gasto->forceFill(['ticket_photo_hash' => 'tickets/ticket.txt'])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('JPG, PNG');

        app(OcrService::class)->importExpenseItemsFromTicketOcr($gasto);
    }
}
