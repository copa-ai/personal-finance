<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El chat vive en un SQLite aparte (conexion «chat»): en memoria y recien migrado en cada prueba.
        config(['database.connections.chat.database' => ':memory:']);
        app('db')->purge('chat');
        Artisan::call('migrate', ['--database' => 'chat', '--path' => 'database/migrations/chat', '--force' => true]);
    }
}
