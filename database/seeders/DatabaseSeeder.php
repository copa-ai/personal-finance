<?php

namespace Database\Seeders;

use App\Enums\ExpenseItemType;
use App\Enums\ExpenseStatus;
use App\Enums\MovementType;
use App\Enums\Recurrence;
use App\Models\Category;
use App\Models\Establishment;
use App\Models\Expense;
use App\Models\ExpenseItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Datos de ejemplo para desarrollo. Es idempotente (docker compose lo ejecuta en cada arranque): los usuarios se crean solo si no
 * existen y los gastos solo si no hay ninguno, así que nunca pisa ni duplica lo que ya haya en la base.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->usuarios();
        $this->gastos();
    }

    private function usuarios(): void
    {
        // Sin SEED_PASSWORD se genera una aleatoria y se muestra una vez: docker-compose.yml da `password`, solo para desarrollo local.
        $password = env('SEED_PASSWORD') ?: Str::password(20, symbols: false);
        $creados = 0;

        foreach ([
            ['name' => 'Admin', 'email' => 'admin@demo.com'],
        ] as $usuario) {
            if (User::query()->where('email', $usuario['email'])->exists()) {
                continue;
            }

            User::factory()->create([...$usuario, 'password' => Hash::make($password)]);
            $creados++;
        }

        if ($creados > 0 && ! env('SEED_PASSWORD')) {
            $this->command?->warn("Contraseña del usuario de ejemplo: {$password}");
        }
    }

    private function gastos(): void
    {
        if (Expense::query()->exists()) {
            $this->command?->info('Ya hay gastos; no se crean los de ejemplo.');

            return;
        }

        $categorias = collect(['Alimentación', 'Hogar', 'Higiene', 'Ocio'])
            ->mapWithKeys(fn (string $nombre) => [$nombre => Category::query()->firstOrCreate(['name' => $nombre])]);

        $tiendas = collect([
            'Mercadona' => 'Alimentación',
            'Carrefour' => 'Alimentación',
            'Leroy Merlin' => 'Hogar',
            'Primor' => 'Higiene',
            'Cines Yelmo' => 'Ocio',
        ])->map(fn (string $categoria, string $nombre) => Establishment::query()->firstOrCreate(
            ['name' => $nombre],
            ['category_id' => $categorias[$categoria]->id],
        ));

        // [tienda, hace_n_dias, [[concepto, cantidad, precio, movimiento, categoria|null, consumible], ...]]
        $compras = [
            ['Mercadona', 40, [
                ['Leche', 12, '0.95', 'PURCHASE', null, true],
                ['Huevos', 12, '0.30', 'PURCHASE', null, true],
                ['Arroz', 2, '1.40', 'PURCHASE', null, true],
                ['Pan', 4, '1.10', 'PURCHASE', null, true],
            ]],
            ['Carrefour', 25, [
                ['Leche', 12, '0.92', 'PURCHASE', null, true],
                ['Aceite de oliva', 2, '8.50', 'PURCHASE', null, true],
                ['Café', 1, '4.20', 'PURCHASE', null, true],
            ]],
            ['Primor', 20, [
                ['Papel higiénico', 24, '0.28', 'PURCHASE', null, true],
                ['Champú', 2, '3.50', 'PURCHASE', null, true],
            ]],
            ['Leroy Merlin', 15, [
                ['Bombilla LED', 4, '2.50', 'PURCHASE', null, false],
            ]],
            ['Mercadona', 8, [
                ['Huevos', 12, '0.32', 'PURCHASE', null, true],
                ['Pan', 3, '1.10', 'PURCHASE', null, true],
                ['Café', 1, '4.50', 'PURCHASE', null, true],
            ]],
            ['Cines Yelmo', 5, [
                ['Entradas', 2, '9.50', 'PURCHASE', 'Ocio', false],
            ]],
        ];

        foreach ($compras as [$tienda, $dias, $lineas]) {
            $total = collect($lineas)->sum(fn (array $l) => round((float) $l[1] * (float) $l[2], 2));

            $gasto = Expense::query()->create([
                'establishment_id' => $tiendas[$tienda]->id,
                'date' => now()->subDays($dias)->setTime(18, 30),
                'total' => number_format($total, 2, '.', ''),
                'status' => ExpenseStatus::PAID,
            ]);

            foreach ($lineas as [$concepto, $cantidad, $precio, $movimiento, $categoria, $consumible]) {
                ExpenseItem::query()->create([
                    'expense_id' => $gasto->id,
                    'category_id' => $categoria ? $categorias[$categoria]->id : null,
                    'concept' => $concepto,
                    'quantity' => $cantidad,
                    'unit_price' => $precio,
                    'item_type' => ExpenseItemType::VARIABLE_REGULAR,
                    'recurrence' => Recurrence::NONE,
                    'movement_type' => MovementType::from($movimiento),
                    'is_consumable' => $consumible,
                ]);
            }
        }

        // Un ticket recién subido por OCR, pendiente de revisar.
        Expense::query()->create([
            'establishment_id' => $tiendas['Carrefour']->id,
            'date' => now()->subDay()->setTime(13, 5),
            'total' => '23.47',
            'status' => ExpenseStatus::PENDING,
            'pending_review' => true,
        ]);
    }
}
