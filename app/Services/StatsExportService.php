<?php

namespace App\Services;

use App\Models\ClubSettings;
use App\Support\Export\SafeValueBinder;
use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Export XLSX du dashboard bureau (PRD §4.16.2, cadrage §7.11). Une feuille par tableau, en-têtes en
// gras, dates au format FR. Reprend les filtres en cours (passés résolus). Volumes faibles (quelques
// dizaines de lignes) → PhpSpreadsheet en mémoire convient. Pur assemblage : la donnée vient de StatsService.
class StatsExportService
{
    public function __construct(private StatsService $stats) {}

    /**
     * Construit le classeur complet pour la fenêtre + filtres donnés.
     *
     * @param  array{from:Carbon,to:Carbon,discipline_id?:?int,category_id?:?int}  $f
     * @param  array{from:Carbon,to:Carbon,label:string}  $period
     */
    public function build(array $f, array $period): Spreadsheet
    {
        // Binder anti-injection actif le temps de l'assemblage, restauré ensuite (global statique).
        $previousBinder = Cell::getValueBinder();
        Cell::setValueBinder(new SafeValueBinder);

        try {
            return $this->assemble($f, $period);
        } finally {
            Cell::setValueBinder($previousBinder);
        }
    }

    private function assemble(array $f, array $period): Spreadsheet
    {
        $book = new Spreadsheet;
        // Créateur = le club, pas le logiciel (cf. JournalExportService) : le fichier circule
        // hors de l'application, il ne doit pas y porter l'ancien nom du dépôt.
        $book->getProperties()->setTitle('Statistiques bureau')->setCreator(ClubSettings::current()->name);

        $headline = $this->stats->headline($f);

        // Feuille 1 — Synthèse (réutilise la feuille active par défaut).
        $synthese = $book->getActiveSheet();
        $synthese->setTitle('Synthèse');
        $this->writeTable($synthese, ['Indicateur', 'Valeur'], [
            ['Période', $period['label']],
            ['Du', $this->frDate($period['from'])],
            ['Au', $this->frDate($period['to'])],
            ['Adhérents actifs', $headline['active']],
            ['Nouveaux depuis le début de saison', $headline['new_since_season']],
            ['Taux de remplissage (%)', $headline['fill_rate'] ?? '—'],
            ['Compétitions', $headline['competitions']],
            ['Overrides coach', $headline['overrides']],
        ]);

        $this->addSheet($book, 'Inscriptions mensuelles', ['Mois', 'Inscriptions'],
            array_map(fn ($r) => [$r['label'], $r['count']], $this->stats->monthlyRegistrations($f)));

        $this->addSheet($book, 'Top séances', ['Séance', 'Date', 'Remplissage (%)'],
            array_map(fn ($r) => [$r['title'], $this->frDate($r['date']), $r['fill']], $this->stats->topSessions($f, 20)));

        $w = $this->stats->waitlist($f);
        $this->addSheet($book, 'Liste d\'attente', ['Indicateur', 'Valeur'], [
            ['En file d\'attente', $w['total']],
            ['Dont capacité', $w['capacity']],
            ['Dont quota', $w['quota']],
            ['Taux de promotion (%)', $w['promotion_rate'] ?? '—'],
        ]);

        $this->addSheet($book, 'Compétitions', ['Course', 'Date', 'Participants'],
            array_map(fn ($r) => [$r['title'], $this->frDate($r['date']), $r['participants']], $this->stats->competitionsPerCourse($f)));

        $this->addSheet($book, 'Overrides', ['Motif', 'Nombre'],
            array_map(fn ($r) => [$r['motif'], $r['count']], $this->stats->overridesPerMotif($f)));

        $coach = $this->stats->coachActivity($f);
        $coachHeader = array_merge(['Coach'], $coach['disciplines']->pluck('label')->all(), ['Total']);
        $coachRows = array_map(function ($row) use ($coach) {
            $cells = [$row['coach']];
            foreach ($coach['disciplines'] as $d) {
                $cells[] = $row['by_discipline'][$d->id] ?? 0;
            }
            $cells[] = $row['total'];

            return $cells;
        }, $coach['rows']);
        $this->addSheet($book, 'Activité coachs', $coachHeader, $coachRows);

        $this->addSheet($book, 'Adhérents actifs', ['Catégorie', 'Effectif'],
            array_map(fn ($r) => [$r['label'], $r['count']], $this->stats->activeMembersByCategory($f)));

        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function filename(): string
    {
        return 'stats-bureau-'.Carbon::now()->format('Y-m-d').'.xlsx';
    }

    // ── Helpers ──

    private function addSheet(Spreadsheet $book, string $title, array $header, array $rows): void
    {
        // PhpSpreadsheet borne les titres d'onglet à 31 caractères et interdit certains caractères.
        $sheet = $book->createSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $this->writeTable($sheet, $header, $rows);
    }

    /** Écrit un tableau (en-tête gras + lignes), auto-dimensionne les colonnes. */
    private function writeTable($sheet, array $header, array $rows): void
    {
        $sheet->fromArray($header, null, 'A1');
        $sheet->fromArray($rows, null, 'A2');

        $lastCol = $sheet->getHighestColumn(1); // dernière colonne de l'en-tête
        $sheet->getStyle('A1:'.$lastCol.'1')->getFont()->setBold(true);

        foreach (range('A', $lastCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->getStyle($col.'1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }
    }

    private function frDate(Carbon $date): string
    {
        return $date->format('d/m/Y');
    }
}
