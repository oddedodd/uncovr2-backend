<?php

namespace App\Console\Commands;

use App\Services\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;

class GenerateOpenApi extends Command
{
    protected $signature = 'api:openapi {--check : Fail if the committed document is stale}';

    protected $description = 'Generate the OpenAPI contract from registered API routes';

    public function handle(OpenApiGenerator $generator): int
    {
        $path = base_path('docs/openapi.json');
        $document = json_encode($generator->generate(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if ($this->option('check')) {
            if (! is_file($path) || file_get_contents($path) !== $document) {
                $this->error('docs/openapi.json is stale. Run php artisan api:openapi.');

                return self::FAILURE;
            }

            $this->info('OpenAPI document is current.');

            return self::SUCCESS;
        }

        file_put_contents($path, $document);
        $this->info('Generated docs/openapi.json.');

        return self::SUCCESS;
    }
}
