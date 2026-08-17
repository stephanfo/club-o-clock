<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Support\Export\SafeValueBinder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Export XLSX des journaux (PRD §4.18.5 — J6.7). Une seule feuille, colonne `source` (audit|activity)
// pour le mode « Tous ». Applique les filtres en cours (résolus en amont). Anti-injection de formule
// via SafeValueBinder (acteurs/cibles/motifs sont du texte saisissable). Pur assemblage.
class JournalExportService
{
    public function __construct(private JournalService $journal) {}

    /**
     * Construit le classeur pour les filtres donnés.
     *
     * @param  array<string,mixed>  $filters
     */
    public function build(array $filters): Spreadsheet
    {
        // Binder anti-injection actif le temps de l'assemblage, restauré ensuite (global statique).
        $previousBinder = Cell::getValueBinder();
        Cell::setValueBinder(new SafeValueBinder);

        try {
            return $this->assemble($filters);
        } finally {
            Cell::setValueBinder($previousBinder);
        }
    }

    private function assemble(array $filters): Spreadsheet
    {
        $book = new Spreadsheet;
        // Créateur = le club, pas le logiciel : le fichier sort du club et circule chez lui
        // (bureau, comptable, fédération). « club-manager », ancien nom du dépôt, y traînait.
        $book->getProperties()->setTitle('Journaux')->setCreator(ClubSettings::current()->name);

        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Journaux');

        // Dates exportées en heure club (cf. app.timezone = UTC) — cohérent avec la liste et le détail.
        $tz = ClubSettings::current()->timezone;

        $header = ['Date', 'Source', 'Acteur', 'Rôle', 'Action', 'Cible', 'Séance liée', 'Motif'];
        $rows = array_map(fn ($r) => [
            $r['at']->copy()->setTimezone($tz)->format('d/m/Y H:i'),
            $r['source'],
            $r['actor'],
            $r['actor_role'] ?? '',
            $r['action'],
            $r['target'],
            $r['session'] ?? '',
            $r['motif'] ?? '',
        ], $this->journal->rows($filters));

        $sheet->fromArray($header, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $lastCol = $sheet->getHighestColumn(1);
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true);
        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle($col.'1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        return $book;
    }

    public function filename(): string
    {
        return 'journaux-'.Carbon::now()->format('Y-m-d').'.xlsx';
    }
}
