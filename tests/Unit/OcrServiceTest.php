<?php

namespace Tests\Unit;

use App\Services\OcrService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OcrServiceTest extends TestCase
{
    public function test_it_parses_plain_json_response(): void
    {
        $service = new OcrService;

        $items = $service->parseResponseContent('{"items":[{"concept":" Pan integral ","quantity":2,"unit_price":1.5}]}');

        $this->assertCount(1, $items);
        $this->assertSame('Pan integral', $items[0]['concept']);
        $this->assertSame('2.000', $items[0]['quantity']);
        $this->assertSame('1.50', $items[0]['unit_price']);
    }

    public function test_it_parses_fenced_json_response(): void
    {
        $service = new OcrService;

        $content = <<<TXT
```json
{
  "items": [
    { "concept": "Leche", "quantity": "1", "unit_price": "0,95" }
  ]
}
```
TXT;

        $items = $service->parseResponseContent($content);

        $this->assertCount(1, $items);
        $this->assertSame('Leche', $items[0]['concept']);
        $this->assertSame('1.000', $items[0]['quantity']);
        $this->assertSame('0.95', $items[0]['unit_price']);
    }

    public function test_it_throws_if_items_are_missing(): void
    {
        $this->expectException(RuntimeException::class);

        $service = new OcrService;
        $service->parseResponseContent('{"foo":"bar"}');
    }
}

