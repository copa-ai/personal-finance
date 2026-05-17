<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Genera un backup de la base de datos PostgreSQL y lo guarda en disco.';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql') {
            $this->error('El comando solo soporta PostgreSQL.');

            return self::FAILURE;
        }

        $backupConfig = config('backup.database', []);
        $diskName = $backupConfig['disk'] ?? 'local';
        $directory = trim((string) ($backupConfig['directory'] ?? 'backups/postgres'), '/');
        $keepDays = (int) ($backupConfig['keep_days'] ?? 7);

        $disk = Storage::disk($diskName);

        $timestamp = now()->format('Y-m-d_His');
        $database = (string) config('database.connections.pgsql.database');
        $filename = sprintf('%s_%s.dump', $database, $timestamp);
        $relativePath = $directory === '' ? $filename : $directory.'/'.$filename;
        $absolutePath = $disk->path($relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));

        $process = new Process([
            'pg_dump',
            '--host='.(string) config('database.connections.pgsql.host'),
            '--port='.(string) config('database.connections.pgsql.port'),
            '--username='.(string) config('database.connections.pgsql.username'),
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--file='.$absolutePath,
            $database,
        ], null, [
            'PGPASSWORD' => (string) config('database.connections.pgsql.password'),
        ]);

        $process->setTimeout((int) ($backupConfig['timeout_seconds'] ?? 1200));

        try {
            $process->mustRun();
        } catch (Throwable $exception) {
            $this->error('No se pudo generar el backup de la base de datos.');
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup generado correctamente: '.$disk->path($relativePath));

        if ($keepDays > 0) {
            $this->pruneOldBackups($diskName, $directory, $keepDays);
        }

        return self::SUCCESS;
    }

    private function pruneOldBackups(string $diskName, string $directory, int $keepDays): void
    {
        $disk = Storage::disk($diskName);
        $cutoff = now()->subDays($keepDays);

        foreach ($disk->files($directory) as $file) {
            $lastModified = $disk->lastModified($file);

            if ($lastModified < $cutoff->timestamp) {
                $disk->delete($file);
                $this->line('Eliminado backup antiguo: '.$file);
            }
        }
    }
}
