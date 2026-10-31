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
            ['Comptes athlètes actifs', $headline['active']],
            ['Pratiquants (≥ 1 séance sur la période)', $headline['practitioners']],
            ['Nouveaux depuis le début de saison', $headline['new_since_season']],
            ['Participations par semaine (moyenne)', $headline['weekly_avg'] ?? '—'],
            ['Taux de remplissage (%)', $headline['fill_rate'] ?? '—'],
            ['Séances à capacité prises en compte', $headline['fill_sessions']],
            ['Compétitions', $headline['competitions']],
            ['Compétiteurs distincts', $headline['competitors']],
            ['Overrides coach', $headline['overrides']],
        ]);

        // Bloc « à venir » : ignore la période, comme à l'écran. Date et heure en heure du club.
        $tz = ClubSettings::current()->timezone;
        $this->addSheet($book, 'Séances à encadrer', ['Date', 'Heure', 'Créneau', 'Discipline'],
            array_map(fn ($r) => [$this->frDate($r['start_at']->copy()->setTimezone($tz)), $r['start_at']->copy()->setTimezone($tz)->format('H:i'), $r['slot'], $r['discipline'] ?? '—'],
                $this->stats->sessionsToStaff($f)));

        $this->addSheet($book, 'Fréquentation hebdo', ['Semaine du', 'Séances', 'Participations', 'Athlètes distincts'],
            array_map(fn ($r) => [$this->frDate($r['start']).($r['partial'] ? ' (en cours)' : ''), $r['sessions'], $r['participations'], $r['athletes']],
                $this->stats->weeklyAttendance($f)));

        $this->addSheet($book, 'Créneaux', ['Créneau', 'Jour', 'Heure', 'Discipline', 'Séances tenues', 'Inscrits (moyenne)', 'Min', 'Max', 'Remplissage (%)', 'Sans encadrement (%)'],
            array_map(fn ($r) => [$r['label'], $r['day'] ?? '—', $r['time'] ?? '—', $r['discipline']->label ?? '—', $r['sessions'], $r['avg'], $r['min'], $r['max'], $r['fill'] ?? '—', $r['unstaffed']],
                $this->stats->slots($f)));

        $u = $this->stats->unregistrations($f);
        $this->addSheet($book, 'Désinscriptions', ['Discipline', 'Désinscriptions', 'Moins de 24 h avant', 'Part tardive (%)'],
            [...array_map(fn ($r) => [$r['discipline']->label ?? '—', $r['total'], $r['late'], $r['late_pct']], $u['rows']),
                ['Total', $u['total'], $u['late'], $u['late_pct'] ?? '—']]);

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

        $this->addSheet($book, 'Encadrement', ['Discipline', 'Séances tenues', 'Encadrées', 'Part encadrée (%)'],
            array_map(fn ($r) => [$r['discipline'], $r['sessions'], $r['staffed'], $r['pct']], $coach['staffed']));

        // Le décrochage (§4.16.2 bloc 5) n'a pas de feuille : une liste nominative de suivi ne circule pas en fichier.
        $this->addSheet($book, 'Adhérents actifs', ['Catégorie', 'Effectif', 'Pratiquants sur la période'],
            array_map(fn ($r) => [$r['label'], $r['count'], $r['practitioners']], $this->stats->activeMembersByCategory($f)));

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
        // Comparaison stricte : sinon PhpSpreadsheet assimile 0 à null et laisse la cellule vide.
        $sheet->fromArray($rows, null, 'A2', true);

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
