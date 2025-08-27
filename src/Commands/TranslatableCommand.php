<?php

namespace Nramos\Translatable\Commands;

use Illuminate\Console\Command;

class TranslatableCommand extends Command
{
    protected $signature = 'translatable:missing {model?} {--locale=}';

    protected $description = 'Trouve les traductions manquantes';

    public function handle(): void
    {
        $this->info('Recherche des traductions manquantes...');

        // Logique pour trouver les traductions manquantes
        // Implementation selon tes besoins
    }
}
