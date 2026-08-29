<?php

namespace App\Console\Commands;

use App\Support\FrontBuild;
use Illuminate\Console\Command;

// Inscrit dans le bundle l'empreinte des sources dont il est issu. Lancé par `npm run build`
// juste après Vite (cf. package.json) — Vite vide son dossier de sortie à chaque build, l'empreinte
// doit donc être écrite APRÈS lui, jamais avant.
class FrontStampCommand extends Command
{
    protected $signature = 'front:stamp';

    protected $description = 'Inscrit l\'empreinte des sources front dans le bundle qui vient d\'être construit';

    public function handle(): int
    {
        $front = $this->laravel->make(FrontBuild::class);

        if (! $front->isBuilt()) {
            $this->components->error('Aucun bundle dans public/build/ — lance `npm run build`, pas cette commande seule.');

            return self::FAILURE;
        }

        $front->writeStamp();

        $this->components->info('Empreinte du front inscrite ('.count($front->fingerprint()).' fichiers source).');

        return self::SUCCESS;
    }
}
