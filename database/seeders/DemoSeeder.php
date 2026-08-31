<?php

namespace Database\Seeders;

use App\Models\AperoFlag;
use App\Models\Category;
use App\Models\ClubSettings;
use App\Models\Debrief;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\InformationPage;
use App\Models\Location;
use App\Models\NotificationOutbox;
use App\Models\Qualification;
use App\Models\QuotaTag;
use App\Models\Registration;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Notifications\NotificationType;
use App\Services\AperoService;
use App\Services\RegistrationService;
use App\Services\SessionNotificationService;
use App\Services\TemplateGenerationService;
use App\Support\AgeCategory;
use App\Support\Markup;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

// Seed de DÉMONSTRATION — club fictif « TEAM44 ».
//
// ⚠️ Jeu de données de démonstration : personnes, séances, codes et identifiants sont INVENTÉS.
// À ne jamais exécuter sur une instance de production (il crée des comptes dont le mot de passe
// est « password »). Seuls les lieux sont des équipements municipaux réels et publics, choisis
// pour que le géocodage et la météo soient démontrables sur des coordonnées valides.
//
// Génère des templates + séances sur 6 semaines glissantes à partir de today().
// Idempotent sur les lieux et utilisateurs fixes (firstOrCreate), séances toujours ajoutées.
class DemoSeeder extends Seeder
{
    // Jour ISO 1=lundi … 7=dimanche
    private const MARDI = 2;

    private const MERCREDI = 3;

    private const JEUDI = 4;

    private const VENDREDI = 5;

    private const SAMEDI = 6;

    private const DIMANCHE = 7;

    public function run(TemplateGenerationService $generator): void
    {
        // --- Identité du club de démonstration (§4.17) ---
        // Le seed d'INSTALLATION (DatabaseSeeder) laisse volontairement les réglages neutres :
        // « Club », logo par défaut, palette livrée. C'est le jeu de DÉMO qui pose une identité,
        // par le même chemin qu'un admin réel passant par Paramètres du club, aux mêmes colonnes
        // près : ce qui est démontré, c'est que les colonnes sont bien prises en compte.
        // Les couleurs ci-dessous reprennent volontairement la palette par défaut du dépôt
        // (club-tokens.css / ClubPalette::DEFAULTS) : la démo doit ressembler à une installation
        // neuve, pas afficher une identité que le déployeur ne retrouverait pas chez lui. Les
        // garder ÉCRITES EN BASE reste utile — c'est ce qui exerce réellement la surcharge CSS.
        // Écrit SEULEMENT si l'identité est encore neutre : le seeder est rejouable (le reste est
        // additif/firstOrCreate) et sert aussi sur des instances de démo ou de recette où un admin
        // a pu personnaliser nom et palette. Les écraser en silence à chaque re-run serait une
        // perte de travail non signalée.
        $settings = ClubSettings::current();
        if ($settings->name === 'Club' && $settings->primary_color === null) {
            $settings->forceFill([
                'name' => 'TEAM44',
                'primary_color' => '#4338CA',   // indigo
                'accent_color' => '#F59E0B',    // ambre
                'info_color' => '#0891B2',      // cyan
            ])->save();
        } else {
            $this->command?->info("Identité du club déjà personnalisée ({$settings->name}) — conservée.");
        }

        // --- Plage de génération : lundi de la semaine courante → +6 semaines ---
        $start = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $end = $start->copy()->addWeeks(6)->endOfWeek(Carbon::SUNDAY);

        // --- Acteur admin (pour logs + created_by) ---
        $admin = User::firstOrCreate(
            ['email' => 'admin@demo.club'],
            [
                'first_name' => 'Admin',
                'last_name' => 'Demo',
                'password' => Hash::make('password'),
                'roles' => ['admin'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // --- Coaches ---
        // updateOrCreate (pas firstOrCreate) : aligné sur les athlètes plus bas — un re-seed répare
        // une base déjà seedée (dob/rôles manquants) sans exiger un migrate:fresh.
        $vincent = User::updateOrCreate(
            ['email' => 'vincent@demo.club'],
            [
                'first_name' => 'Vincent',
                'last_name' => 'Coach',
                'password' => Hash::make('password'),
                'roles' => ['coach'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $damien = User::updateOrCreate(
            ['email' => 'damien@demo.club'],
            [
                'first_name' => 'Damien',
                'last_name' => 'Coach',
                'password' => Hash::make('password'),
                'roles' => ['coach'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $karine = User::updateOrCreate(
            ['email' => 'karine@demo.club'],
            [
                'first_name' => 'Karine',
                'last_name' => 'BNSSA',
                'password' => Hash::make('password'),
                'roles' => ['coach'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        // Mathieu : rôles cumulés coach + athlete (§5.1) — coache ET s'inscrit aux séances.
        $mathieu = User::updateOrCreate(
            ['email' => 'mathieu@demo.club'],
            [
                'first_name' => 'Mathieu',
                'last_name' => 'Coach',
                'dob' => '1986-03-14',
                'password' => Hash::make('password'),
                'roles' => ['coach', 'athlete'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $julien = User::updateOrCreate(
            ['email' => 'julien@demo.club'],
            [
                'first_name' => 'Julien',
                'last_name' => 'Coach',
                'password' => Hash::make('password'),
                'roles' => ['coach'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $nathalie = User::updateOrCreate(
            ['email' => 'nathalie@demo.club'],
            [
                'first_name' => 'Nathalie',
                'last_name' => 'Coach',
                'password' => Hash::make('password'),
                'roles' => ['coach'],
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // --- Lieux --- (équipements municipaux publics d'Ancenis-Saint-Géréon, géocodés OSM)
        // Le jeu de démo n'utilise QUE des équipements ouverts au public : aucune adresse privée,
        // aucun local de club. Un club qui installe l'app crée les siens (§4.10).
        $piscine = Location::firstOrCreate(
            ['name' => 'Piscine de la Charbonnière'],
            ['address' => 'Boulevard Joubert, 44150 Ancenis-Saint-Géréon, France', 'kind' => 'piscine', 'latitude' => 47.3628580, 'longitude' => -1.1819259, 'created_by' => $admin->id],
        );
        $piste = Location::firstOrCreate(
            ['name' => 'Stade de la Davray'],
            ['address' => 'Impasse de l’Île Mouchet, 44150 Ancenis-Saint-Géréon, France', 'kind' => 'piste', 'latitude' => 47.3630299, 'longitude' => -1.1872746, 'created_by' => $admin->id],
        );
        // Départ/arrivée des sorties vélo du dimanche : le parking de la piscine (même site que
        // le bassin, ~40 m au nord). C'est le point de départ de toutes les traces GPX du jeu.
        $parkingVelo = Location::firstOrCreate(
            ['name' => 'Parking de la piscine de la Charbonnière'],
            ['address' => 'Boulevard Joubert, 44150 Ancenis-Saint-Géréon, France', 'kind' => 'parking', 'latitude' => 47.3632100, 'longitude' => -1.1816400, 'created_by' => $admin->id],
        );

        // --- Disciplines ---
        $natation = Discipline::firstOrCreate(['label' => 'Natation'], ['sort_order' => 0]);
        $velo = Discipline::firstOrCreate(['label' => 'Vélo'], ['sort_order' => 2]);
        $cap = Discipline::firstOrCreate(['label' => 'Course à pied'], ['sort_order' => 1]);
        $enchaine = Discipline::firstOrCreate(['label' => 'Enchaînement'], ['sort_order' => 3]);

        // --- Catégories (référentiel FFTri partiel — assez pour couvrir jeunes + adultes en démo) ---
        $benjamins = Category::firstOrCreate(['label' => 'Benjamins'], ['age_min' => 12, 'age_max' => 13, 'sort_order' => 4]);
        $minimes = Category::firstOrCreate(['label' => 'Minimes'], ['age_min' => 14, 'age_max' => 15, 'sort_order' => 5]);
        $cadets = Category::firstOrCreate(['label' => 'Cadets'], ['age_min' => 16, 'age_max' => 17, 'sort_order' => 6]);
        $juniors = Category::firstOrCreate(['label' => 'Juniors'], ['age_min' => 18, 'age_max' => 19, 'sort_order' => 7]);
        $adulte = Category::firstOrCreate(['label' => 'Adulte'], ['age_min' => 20, 'age_max' => 39, 'sort_order' => 8]);
        $master = Category::firstOrCreate(['label' => 'Master'], ['age_min' => 40, 'age_max' => 120, 'sort_order' => 9]);

        // --- Tags de quota (créés ici pour la démo — l'admin les gère en prod) ---
        $quotaNat = QuotaTag::firstOrCreate(['label' => 'Natation semaine'], ['code' => 'NAT', 'max_per_week' => 1]);

        // --- Lieu supplémentaire : salle de PPG, sur le site du stade ---
        $salle = Location::firstOrCreate(
            ['name' => 'Salle de la Davray'],
            ['address' => 'Impasse de l’Île Mouchet, 44150 Ancenis-Saint-Géréon, France', 'kind' => 'salle', 'latitude' => 47.3627500, 'longitude' => -1.1876800, 'created_by' => $admin->id],
        );

        // --- Groupes de catégories (référentiel FFTri ci-dessus, saison 2025-2026, âge au 31/12/2026) ---
        //   • adultes         = Adulte (20-39) + Master (40+)
        //   • jeunes          = Benjamins → Juniors (« non-adultes »)
        //   • tous            = Benjamins → Master (toutes catégories, aucune restriction effective)
        //   • quinzeEtPlus    = né avant 2011 inclus = ≥15 ans en 2026 = Minimes → Master
        $catAdultes = [$adulte->id, $master->id];
        $catJeunes = [$benjamins->id, $minimes->id, $cadets->id, $juniors->id];
        $catTous = [$benjamins->id, $minimes->id, $cadets->id, $juniors->id, $adulte->id, $master->id];
        $catQuinzeEtPlus = [$minimes->id, $cadets->id, $juniors->id, $adulte->id, $master->id];

        // --- Définition des templates (semaine type de démonstration) ---
        // Trame pensée pour EXERCER les règles du produit, pas pour décrire un club existant :
        // une séance à capacité saturable (file d'attente §4.9), deux créneaux qui se recouvrent,
        // un quota hebdo natation, des ciblages catégoriels distincts, et un bloc vélo du dimanche
        // à trois niveaux qui partage le même point de départ (démontre la bibliothèque §4.20).
        // Format : [label, kind, discipline, day_of_week, start_time, duration_min, location, capacity, coach_ids, category_ids, quota_tag_id]
        $templates = [
            // MARDI : Vélo sur piste 18:45 → 20:30 — toutes catégories (≥12 ans)
            [
                'label' => 'Vélo piste — mardi',
                'kind' => 'training',
                'discipline' => $velo,
                'day' => self::MARDI,
                'start' => '18:45',
                'duration' => 105,
                'location' => $piste,
                'capacity' => null,
                'coaches' => [$damien->id],
                'categories' => $catTous,
                'quota_tag' => null,
            ],
            // MERCREDI : Natation adultes 20:15 → 21:45
            [
                'label' => 'Natation mercredi soir',
                'kind' => 'training',
                'discipline' => $natation,
                'day' => self::MERCREDI,
                'start' => '20:15',
                'duration' => 90,
                'location' => $piscine,
                'capacity' => 42,
                'coaches' => [$vincent->id, $mathieu->id],
                'categories' => $catAdultes,
                'quota_tag' => $quotaNat,
            ],
            // JEUDI : Course à pied 19:30 au stade — Minimes → Master (≥15 ans)
            [
                'label' => 'Course à pied jeudi',
                'kind' => 'training',
                'discipline' => $cap,
                'day' => self::JEUDI,
                'start' => '19:30',
                'duration' => 90,
                'location' => $piste,
                'capacity' => null,
                'coaches' => [$julien->id],
                'categories' => $catQuinzeEtPlus,
                'quota_tag' => null,
            ],
            // VENDREDI : PPG 18:30 → 19:45 en salle — pour tous (toutes catégories)
            [
                'label' => 'PPG',
                'kind' => 'training',
                'discipline' => $cap,
                'day' => self::VENDREDI,
                'start' => '18:30',
                'duration' => 75,
                'location' => $salle,
                'capacity' => null,
                'coaches' => [$mathieu->id],
                'categories' => $catTous,
                'quota_tag' => null,
            ],
            // SAMEDI : Natation jeunes 09:30 → 10:45
            // Capacité 6 (lignes d'eau réservées aux jeunes) → démontre la file d'attente
            // « capacity » quand plus de jeunes s'inscrivent que de places (scénario waitlist §1).
            [
                'label' => 'Natation samedi matin — jeunes',
                'kind' => 'training',
                'discipline' => $natation,
                'day' => self::SAMEDI,
                'start' => '09:30',
                'duration' => 75,
                'location' => $piscine,
                'capacity' => 6,
                'coaches' => [$vincent->id],
                'categories' => $catJeunes,
                'quota_tag' => $quotaNat,
            ],
            // SAMEDI : Natation adultes 10:45 → 12:15 (enchaîne sur le créneau jeunes)
            [
                'label' => 'Natation samedi matin — adultes',
                'kind' => 'training',
                'discipline' => $natation,
                'day' => self::SAMEDI,
                'start' => '10:45',
                'duration' => 90,
                'location' => $piscine,
                'capacity' => 24,
                'coaches' => [$karine->id],
                'categories' => $catAdultes,
                'quota_tag' => $quotaNat,
            ],
            // DIMANCHE : les 3 sorties vélo, même départ (parking de la piscine), même heure —
            // seules la distance et l'allure changent. Arrivée 08h15 → départ 08h30.
            [
                'label' => 'Vélo — Grosses Cuisses',
                'kind' => 'training',
                'discipline' => $velo,
                'day' => self::DIMANCHE,
                'start' => '08:30',
                'duration' => 180,
                'location' => $parkingVelo,
                'capacity' => null,
                'coaches' => [$vincent->id],
                'categories' => $catAdultes,
                'quota_tag' => null,
            ],
            [
                'label' => 'Vélo — Moyennes Cuisses',
                'kind' => 'training',
                'discipline' => $velo,
                'day' => self::DIMANCHE,
                'start' => '08:30',
                'duration' => 150,
                'location' => $parkingVelo,
                'capacity' => null,
                'coaches' => [$damien->id],
                'categories' => $catAdultes,
                'quota_tag' => null,
            ],
            [
                'label' => 'Vélo — Petites Cuisses',
                'kind' => 'training',
                'discipline' => $velo,
                'day' => self::DIMANCHE,
                'start' => '08:30',
                'duration' => 120,
                'location' => $parkingVelo,
                'capacity' => null,
                'coaches' => [$mathieu->id],
                'categories' => $catAdultes,
                'quota_tag' => null,
            ],
            // DIMANCHE : Enchaînement jeunes 10:45 → 12:15 au stade
            [
                'label' => 'Enchaînement — jeunes',
                'kind' => 'training',
                'discipline' => $enchaine,
                'day' => self::DIMANCHE,
                'start' => '10:45',
                'duration' => 90,
                'location' => $piste,
                'capacity' => null,
                'coaches' => [$nathalie->id],
                'categories' => $catJeunes,
                'quota_tag' => null,
            ],
        ];

        foreach ($templates as $def) {
            $template = SessionTemplate::create([
                'label' => $def['label'],
                'kind' => $def['kind'],
                'discipline_id' => $def['discipline']->id,
                'day_of_week' => $def['day'],
                'start_time_of_day' => $def['start'],
                'duration_min' => $def['duration'],
                'location_id' => $def['location']->id,
                'capacity' => $def['capacity'],
                'quota_tag_id' => $def['quota_tag']?->id,
                'generation_start_date' => $start->toDateString(),
                'generation_end_date' => $end->toDateString(),
                'created_by' => $admin->id,
                'status' => 'active',
            ]);

            if ($def['coaches']) {
                $template->defaultCoaches()->sync($def['coaches']);
            }
            if ($def['categories']) {
                $template->categories()->sync($def['categories']);
            }

            $generator->generate($template, $admin, $start, $end);
        }

        // --- Adhérents démo (athletes) ---
        // dob renseignée pour que la catégorie principale dérivée s'affiche (cf. AgeCategory).
        // Âges étalés pour couvrir Benjamins → Master (mineurs inclus).
        $athletes = [];
        $athDefs = [
            // [prénom, nom, email, date de naissance]  → catégorie au 31/08 de fin de saison
            ['Marie', 'Dupont', 'marie@demo.club', '1995-04-12'],       // Adulte
            ['Lucas', 'Martin', 'lucas@demo.club', '2002-11-23'],       // Adulte
            ['Sophie', 'Bernard', 'sophie@demo.club', '1980-07-05'],    // Master
            ['Hugo', 'Petit', 'hugo@demo.club', '2011-03-08'],          // Benjamins
            ['Léa', 'Robert', 'lea@demo.club', '2012-09-19'],           // Benjamins
            ['Nathan', 'Richard', 'nathan@demo.club', '2010-01-30'],    // Minimes
            ['Chloé', 'Durand', 'chloe@demo.club', '2009-06-14'],       // Minimes
            ['Enzo', 'Moreau', 'enzo@demo.club', '2008-12-02'],         // Cadets
            ['Manon', 'Laurent', 'manon@demo.club', '2007-08-21'],      // Cadets
            ['Tom', 'Simon', 'tom@demo.club', '2006-05-17'],            // Juniors
            ['Inès', 'Michel', 'ines@demo.club', '2005-10-09'],         // Juniors
            ['Hugo', 'Lefebvre', 'hugol@demo.club', '1998-02-25'],      // Adulte
            ['Camille', 'Leroy', 'camille@demo.club', '1992-11-11'],    // Adulte
            ['Maxime', 'Roux', 'maxime@demo.club', '1989-07-03'],       // Adulte
            ['Julie', 'David', 'julie@demo.club', '2000-04-28'],        // Adulte
            ['Antoine', 'Bertrand', 'antoine@demo.club', '1996-09-06'], // Adulte
            ['Sarah', 'Morel', 'sarah@demo.club', '2001-12-19'],        // Adulte
            ['Paul', 'Fournier', 'paul@demo.club', '1975-03-22'],       // Master
            ['Émilie', 'Girard', 'emilie@demo.club', '1983-08-15'],     // Master
            ['Romain', 'Bonnet', 'romain@demo.club', '1978-01-07'],     // Master
            ['Laura', 'Dupuis', 'laura@demo.club', '1969-05-30'],       // Master
            ['Kévin', 'Lambert', 'kevin@demo.club', '1994-10-12'],      // Adulte
        ];
        $activeCats = Category::query()->whereNull('archived_at')->get();

        // Mathieu (coach + athlète, §5.1) est créé plus haut, hors de la boucle ci-dessous : sans ce
        // rattachement il resterait sans catégorie active et ne pourrait s'inscrire nulle part —
        // « Je participe » serait proposé puis refusé en CATEGORY_MISMATCH.
        $this->attachPrimaryCategory($mathieu, $activeCats);

        foreach ($athDefs as [$fn, $ln, $email, $dob]) {
            // updateOrCreate (pas firstOrCreate) : corrige aussi les comptes démo déjà seedés sans dob.
            $athlete = User::updateOrCreate(
                ['email' => $email],
                [
                    'first_name' => $fn,
                    'last_name' => $ln,
                    'dob' => $dob,
                    'password' => Hash::make('password'),
                    'roles' => ['athlete'],
                    'is_active' => true,
                    'is_minor' => AgeCategory::isMinor(Carbon::parse($dob)),
                    'email_verified_at' => now(),
                ],
            );

            $this->attachPrimaryCategory($athlete, $activeCats);

            $athletes[] = $athlete;
        }
        $athletes = collect($athletes);

        // --- États athlète non-nominaux (démo admin §5.1) ---
        // Kévin : accès athlète suspendu (athlete_access_suspended) — ne peut plus s'inscrire,
        // mais compte toujours actif (distinct de is_active). Démontre l'état dans l'admin.
        User::where('email', 'kevin@demo.club')->update(['athlete_access_suspended' => true]);

        // --- Couples parent garant / enfant mineur (PRD §4.2, §4.15.5) ---
        // Deux phases démontrées :
        //   • P1 : mineur SANS credential (email/password null), géré par son garant seul.
        //          NotificationDispatcher route les notifs vers le garant uniquement.
        //   • P2 : mineur AVEC son propre compte (email + password), lien garant actif.
        //          Notifs routées vers l'enfant ET le garant.
        // Le garant est un User adulte (rôle athlete ici, mais le statut garant est une RELATION
        // via guardian_id, pas un rôle — cf. User::wards()).

        // Garant 1 : Florence Garnier, mère de Lucie (P1, sans compte).
        $florence = User::updateOrCreate(
            ['email' => 'florence@demo.club'],
            [
                'first_name' => 'Florence',
                'last_name' => 'Garnier',
                'dob' => '1984-02-17',
                'password' => Hash::make('password'),
                'roles' => ['athlete'],
                'is_active' => true,
                'is_minor' => false,
                'email_verified_at' => now(),
            ],
        );

        $this->attachPrimaryCategory($florence, $activeCats);

        // P1 : Lucie Garnier (Benjamins), aucun credential → guardian_id = Florence, email null.
        $lucie = User::updateOrCreate(
            ['first_name' => 'Lucie', 'last_name' => 'Garnier', 'guardian_id' => $florence->id],
            [
                'email' => null,
                'password' => null,
                'email_verified_at' => null,
                'dob' => '2012-05-09',
                'roles' => ['athlete'],
                'is_active' => true,
                'is_minor' => true,
                'guardianship_linked_at' => now(),
            ],
        );

        // Garant 2 : Olivier Mercier, père de Théo (P2, compte propre activé).
        // PARENT PUR : aucun rôle (ni athlete, ni coach) — il n'existe dans le club QUE comme
        // garant (le statut garant est une relation §4.2, pas un rôle). Il se connecte, consulte,
        // gère Théo via « Mes enfants »/le sélecteur, mais ne peut pas s'inscrire lui-même.
        $olivier = User::updateOrCreate(
            ['email' => 'olivier@demo.club'],
            [
                'first_name' => 'Olivier',
                'last_name' => 'Mercier',
                'dob' => '1981-09-30',
                'password' => Hash::make('password'),
                'roles' => [],
                'is_active' => true,
                'is_minor' => false,
                'email_verified_at' => now(),
            ],
        );

        // P2 : Théo Mercier (Minimes), compte propre activé + lien garant actif vers Olivier.
        // NB : email distinct du coach Théo (theo@demo.club) — sinon updateOrCreate écraserait le coach.
        $theoMercier = User::updateOrCreate(
            ['email' => 'theo.mercier@demo.club'],
            [
                'first_name' => 'Théo',
                'last_name' => 'Mercier',
                'dob' => '2010-11-04',
                'password' => Hash::make('password'),
                'roles' => ['athlete'],
                'is_active' => true,
                'is_minor' => true,
                'email_verified_at' => now(),
                'guardian_id' => $olivier->id,
                'guardianship_linked_at' => now(),
            ],
        );

        // Garant 3 : Sandrine Faure, athlète du club ELLE-MÊME, mère de DEUX enfants — l'une en P1
        // (sans compte), l'autre en P2 (compte propre). Démontre un garant multi-enfants ET le fait
        // qu'un garant peut être un athlète actif (le statut garant est une relation, pas un rôle).
        $sandrine = User::updateOrCreate(
            ['email' => 'sandrine@demo.club'],
            [
                'first_name' => 'Sandrine',
                'last_name' => 'Faure',
                'dob' => '1982-06-25',
                'password' => Hash::make('password'),
                'roles' => ['athlete'],
                'is_active' => true,
                'is_minor' => false,
                'email_verified_at' => now(),
            ],
        );

        // Enfant A — P1 : Jade Faure (Benjamins), aucun credential → guardian_id = Sandrine.
        $jade = User::updateOrCreate(
            ['first_name' => 'Jade', 'last_name' => 'Faure', 'guardian_id' => $sandrine->id],
            [
                'email' => null,
                'password' => null,
                'email_verified_at' => null,
                'dob' => '2013-04-02',
                'roles' => ['athlete'],
                'is_active' => true,
                'is_minor' => true,
                'guardianship_linked_at' => now(),
            ],
        );

        // Enfant B — P2 : Noah Faure (Cadets), compte propre activé + lien garant actif vers Sandrine.
        $noah = User::updateOrCreate(
            ['email' => 'noah.faure@demo.club'],
            [
                'first_name' => 'Noah',
                'last_name' => 'Faure',
                'dob' => '2009-01-18',
                'password' => Hash::make('password'),
                'roles' => ['athlete'],
                'is_active' => true,
                'is_minor' => true,
                'email_verified_at' => now(),
                'guardian_id' => $sandrine->id,
                'guardianship_linked_at' => now(),
            ],
        );

        // Sandrine s'entraîne aussi → entre dans le pool d'inscriptions démo.
        // Catégorie principale dérivée comme tout athlète actif (elle est créée hors boucle $athDefs).
        $this->attachPrimaryCategory($sandrine, $activeCats);
        $athletes->push($sandrine);

        // Mineur SANS garant NI credential (orphelin de tutelle) : Timéo Vidal. Cas P1 pur mais sans
        // garant — le seul mineur sans guardian_id ET sans compte du jeu. Cible naturelle du rattrapage
        // admin « Rattacher un garant » (fiche du mineur) et « Ajouter un pupille » (fiche du parent, §4.2).
        $timeo = User::updateOrCreate(
            ['first_name' => 'Timéo', 'last_name' => 'Vidal'],
            [
                'email' => null,
                'password' => null,
                'email_verified_at' => null,
                'dob' => '2012-09-14',
                'roles' => ['athlete'],
                'is_active' => true,
                'is_minor' => true,
                'guardian_id' => null,
            ],
        );

        // Catégorie principale dérivée pour tous les enfants + ajout au pool d'athlètes démo.
        foreach ([$lucie, $theoMercier, $jade, $noah, $timeo] as $child) {
            $this->attachPrimaryCategory($child, $activeCats);
            $athletes->push($child);
        }

        // Pose un flag apéro sur chaque séance CAP mercredi (passées ou 1 semaine à venir).
        $capSessions = Session::whereHas('discipline', fn ($q) => $q->where('label', 'Course à pied'))
            ->where('start_at', '<=', now()->addWeek())
            ->with('categories')
            ->orderBy('start_at')
            ->get();

        foreach ($capSessions as $session) {
            // 1 payeur tiré au sort — mais UNIQUEMENT parmi les athlètes ciblés par la séance
            // (§4.5). Cette écriture est directe (firstOrCreate ≠ RegistrationService) : elle ne
            // passe pas par la garde catégorielle, il faut donc filtrer ici, sinon un Benjamin peut
            // être inscrit sur la CAP mercredi ciblée Minimes→Master.
            $eligible = $athletes->filter(fn (User $u) => $u->isTargetedBy($session));
            if ($eligible->isEmpty()) {
                continue;
            }
            $payer = $eligible->random();

            // Inscription obligatoire avant le flag (FK NOT NULL registration_id).
            $reg = Registration::firstOrCreate(
                ['session_id' => $session->id, 'user_id' => $payer->id],
                // copy() obligatoire : start_at (accessor) renvoie une instance mise en cache par
                // Eloquent ; la muter (subDay) rendrait la séance dirty et un save() ultérieur (ex.
                // annulation) persisterait le décalage. Bug observé : CAP mercredi décalée au mardi.
                ['status' => 'participating', 'registered_at' => $session->start_at->copy()->subDay()],
            );

            AperoFlag::firstOrCreate(
                ['session_id' => $session->id, 'user_id' => $payer->id],
                [
                    'registration_id' => $reg->id,
                    'motif' => 'Premier arrivé → il offre !',
                    'flagged_at' => $session->start_at,
                    'flagged_by' => $payer->id,
                ],
            );
        }

        // --- Inscriptions scénarisées sur la semaine courante + la suivante ---
        // Passe par RegistrationService (vrai moteur : capacité, quota, waitlist FIFO) pour
        // produire des états réalistes et testables. On ne touche qu'aux séances FUTURES
        // (register refuse une séance déjà commencée).
        $service = app(RegistrationService::class);
        $now = now();
        $twoWeeksEnd = $now->copy()->addWeek()->endOfWeek(Carbon::SUNDAY);

        $futureSessions = Session::query()
            ->whereNull('cancelled_at')
            ->whereBetween('start_at', [$now, $twoWeeksEnd])
            ->orderBy('start_at')
            ->get();

        $tryRegister = function (Session $s, User $u, bool $confirmQuota = false) use ($service): ?Registration {
            try {
                return $service->register($s, $u, $u, confirmQuota: $confirmQuota);
            } catch (\RuntimeException $e) {
                // Quota à confirmer → on rejoue en confirmant (place en file quota_exceeded, §4.10.3).
                if ($e->getMessage() === RegistrationService::QUOTA_NEEDS_CONFIRM) {
                    return $service->register($s, $u, $u, confirmQuota: true);
                }

                return null; // séance commencée, doublon, etc. : on ignore en démo.
            }
        };

        $scenarioStats = ['full_waitlist' => 0, 'quota' => 0, 'normal' => 0, 'cancelled' => 0];

        // (1) Séance pleine + file capacité : la natation jeunes du samedi (cap 6) reçoit PLUS de
        //     candidats que de places → les surnuméraires tombent en waitlist « capacity ».
        //     Les candidats sont filtrés sur le ciblage réel de la séance (isTargetedBy) : la
        //     séance ne cible que les jeunes, inscrire les 9 premiers athlètes de la liste ferait
        //     refuser les adultes par la garde catégorielle §4.5 — la file resterait vide.
        $capped = $futureSessions->firstWhere('title', 'Natation samedi matin — jeunes');
        if ($capped) {
            $capped->load('categories'); // relation fraîche pour isTargetedBy()
            $eligibleForCapped = $athletes->filter(fn (User $u) => $u->isTargetedBy($capped));
            foreach ($eligibleForCapped->take(($capped->capacity ?? 6) + 3) as $u) {
                if ($tryRegister($capped, $u)) {
                    $scenarioStats['full_waitlist']++;
                }
            }
        }

        // (2) File quota dépassé : quota « NAT » = 1 natation/semaine. On inscrit le même athlète
        //     sur DEUX natations de la même semaine ISO → la 2ᵉ tombe en waitlist « quota_exceeded ».
        //     On prend le 1er groupe (semaine ISO) qui contient au moins 2 séances à quota que le
        //     MÊME athlète peut rejoindre : deux séances à quota de catégories disjointes (jeunes
        //     vs adultes) ne permettraient à personne de dépasser le quota.
        $quotaSessions = $futureSessions->filter(fn (Session $s) => $s->quota_tag_id !== null);
        $quotaSessions->load('categories'); // chargement groupé (une requête, pas une par séance)

        // On cherche un athlète et DEUX séances de sa semaine qu'il peut toutes deux rejoindre —
        // pas toutes les séances à quota du groupe : une même semaine mêle des créneaux jeunes et
        // adultes (catégories disjointes), donc exiger l'éligibilité à l'ensemble ne trouverait
        // jamais personne.
        $quotaAthlete = null;
        $natWeek = collect();
        foreach ($quotaSessions->groupBy(fn (Session $s) => $s->start_at->isoWeekYear.'-'.$s->start_at->isoWeek) as $group) {
            if ($group->count() < 2) {
                continue;
            }
            foreach ($athletes as $candidate) {
                $reachable = $group->filter(fn (Session $s) => $candidate->isTargetedBy($s))->values();
                if ($reachable->count() >= 2) {
                    $quotaAthlete = $candidate;
                    $natWeek = $reachable;
                    break 2;
                }
            }
        }
        if ($quotaAthlete !== null && $natWeek->count() >= 2) {
            $tryRegister($natWeek[0], $quotaAthlete);           // participating
            if ($tryRegister($natWeek[1], $quotaAthlete, true)) { // waitlist quota_exceeded
                $scenarioStats['quota']++;
            }
        }

        // (3) Remplissage normal varié : chaque autre séance future reçoit un sous-ensemble
        //     pseudo-aléatoire mais déterministe (seedé) d'athlètes.
        mt_srand(20260627);
        foreach ($futureSessions as $s) {
            if ($s->id === $capped?->id) {
                continue; // déjà saturée plus haut.
            }
            $pool = $athletes->shuffle();
            $count = mt_rand(2, min(8, $pool->count()));
            foreach ($pool->take($count) as $u) {
                if ($tryRegister($s, $u)) {
                    $scenarioStats['normal']++;
                }
            }
        }

        // (4) Quelques annulations : sur la 1ʳᵉ séance de la semaine prochaine, 2 inscrits annulent
        //     (illustre l'historique + déclenche d'éventuelles promotions auto).
        $nextWeekFirst = $futureSessions->firstWhere(
            fn (Session $s) => $s->start_at->isoWeek === $now->copy()->addWeek()->isoWeek
        );
        if ($nextWeekFirst) {
            $cancellers = $nextWeekFirst->registrations()
                ->where('status', 'participating')
                ->orderBy('registered_at')
                ->take(2)->get();
            foreach ($cancellers as $reg) {
                $u = $athletes->firstWhere('id', $reg->user_id);
                if ($u) {
                    try {
                        $service->cancel($nextWeekFirst, $u, $u);
                        $scenarioStats['cancelled']++;
                    } catch (\RuntimeException) {
                        // ignore
                    }
                }
            }
        }

        // --- Parcours OpenRunner sur les séances vélo du dimanche (PRD §4.13) ---
        // Les codes d'embed OpenRunner sont des JETONS DE PARTAGE liés au compte qui a créé le
        // parcours : les publier reviendrait à publier des parcours réels et leur propriétaire.
        // Le jeu de démo utilise donc des codes factices — la carte OpenRunner ne s'affichera
        // pas dans la démo, c'est ASSUMÉ. Ce que le seed démontre ici, c'est le comportement de
        // l'app (champ renseigné → onglet « Parcours » présent sur la fiche, alternance d'un
        // parcours à l'autre au fil des semaines) ; la bibliothèque GPX §4.20, elle, affiche de
        // vraies traces (fixtures anonymisées). Un club remplace ces URLs par les siennes.
        $embed = fn (string $code): string => "https://www.openrunner.com/embed.html?code={$code}&lng=auto&unit=metric";
        $routesByLevel = [
            'Vélo — Grosses Cuisses' => [$embed('DEMO-PARCOURS-GC-01'), $embed('DEMO-PARCOURS-GC-02')],
            'Vélo — Moyennes Cuisses' => [$embed('DEMO-PARCOURS-MC-01'), $embed('DEMO-PARCOURS-MC-02')],
            'Vélo — Petites Cuisses' => [$embed('DEMO-PARCOURS-PC-01'), $embed('DEMO-PARCOURS-PC-02')],
        ];
        foreach ($routesByLevel as $title => $urls) {
            $velos = Session::where('title', $title)->orderBy('start_at')->get();
            foreach ($velos as $i => $velo_s) {
                $velo_s->update(['route_openrunner_embed_url' => $urls[$i % count($urls)]]);
            }
        }

        // --- Séances COMPÉTITION (PRD §4.7, §4.12) — toutes datées en RELATIF par rapport au seed ---
        // On crée DEUX compétitions, le dimanche (les compétitions tombent le week-end) :
        //   • une PASSÉE (~10 j avant) → permet les débriefs (DebriefService exige hasStarted()) ;
        //   • une FUTURE (~2 sem après) → visible directement au planning.
        $triathlonM = EventType::where('label', 'Triathlon')->first();

        // Builder : crée une compétition + inscrit 5 athlètes adultes (insertion directe du statut
        // participating — séance passée → RegistrationService::register refuserait).
        $makeCompetition = function (Carbon $start, string $title) use ($piscine, $triathlonM, $adulte, $master, $vincent, $admin, $athletes, $routesByLevel): Session {
            $comp = Session::create([
                'kind' => 'competition',
                'title' => $title,
                // Pas de discipline : réservée aux entraînements (§4.7). Une compétition se
                // qualifie par son type d'épreuve, et sa couleur vient du repli par `kind`.
                'start_at' => $start,
                'duration_min' => 240,
                'location_id' => $piscine->id,
                'capacity' => null,
                'event_type_id' => $triathlonM?->id,
                'distance' => 'M (1.5 km / 40 km / 10 km)',
                'external_url' => 'https://www.fftri.com/epreuve/demo-triathlon',
                'photos_album_url' => 'https://photos.app.goo.gl/demo-album-triathlon',
                'route_openrunner_embed_url' => $routesByLevel['Vélo — Grosses Cuisses'][0],
                'created_by' => $admin->id,
            ]);
            $comp->categories()->sync([$adulte->id, $master->id]);
            $comp->coaches()->sync([$vincent->id]);
            $comp->load('categories'); // relation fraîche pour isTargetedBy() ci-dessous.

            // Écriture directe (firstOrCreate ≠ RegistrationService) : la garde catégorielle §4.5
            // n'est pas appliquée, on filtre donc sur le ciblage réel de la compétition
            // (Adulte+Master). ! is_minor ne suffit pas : un Junior/Cadet majeur y échapperait.
            $eligible = $athletes->filter(fn (User $u) => $u->isTargetedBy($comp));
            foreach ($eligible->take(5)->values() as $u) {
                Registration::firstOrCreate(
                    ['session_id' => $comp->id, 'user_id' => $u->id],
                    ['status' => 'participating', 'registered_at' => $start->copy()->subWeek()],
                );
            }

            return $comp;
        };

        // Dimanche ~10 j avant le seed (passée) et dimanche ~2 sem après (future).
        $pastCompStart = $now->copy()->subDays(10)->startOfWeek(Carbon::MONDAY)->next(Carbon::SUNDAY)->setTime(9, 0);
        $futureCompStart = $now->copy()->addWeeks(2)->startOfWeek(Carbon::MONDAY)->next(Carbon::SUNDAY)->setTime(9, 0);
        $competition = $makeCompetition($pastCompStart, 'Triathlon M de printemps');
        $makeCompetition($futureCompStart, 'Triathlon M du lac');

        // --- Débriefs sur la compétition PASSÉE (PRD §4.12) : 2 participants partagent leur ressenti ---
        $debriefTexts = [
            'Super course ! Natation un peu fraîche mais le parcours vélo roulait bien. '
                .'Belle bagarre sur le 10 km, content du chrono.',
            'Première M de la saison. Transition T1 à revoir, mais super ambiance au club. '
                .'Merci aux bénévoles !',
        ];
        $compParticipants = $competition->registrations()->where('status', 'participating')
            ->orderBy('registered_at')->take(2)->get();
        foreach ($compParticipants as $i => $reg) {
            Debrief::firstOrCreate(
                ['session_id' => $competition->id, 'author_id' => $reg->user_id],
                ['content_markdown' => $debriefTexts[$i]],
            );
        }

        // --- Séance CLUB_EVENT future (PRD §4.7) : Assemblée Générale + apéro ---
        // Convention club : l'AG tombe le 1er vendredi du mois. On vise le 1er vendredi du mois
        // prochain (toujours dans le futur, quel que soit le jour du seed).
        // subDay() avant next() pour que le 1er du mois soit retenu s'il tombe lui-même un vendredi.
        $clubEventStart = $now->copy()->addMonthNoOverflow()->startOfMonth()
            ->subDay()->next(Carbon::FRIDAY)->setTime(19, 0);
        $ag = Session::create([
            'kind' => 'club_event',
            'title' => 'Assemblée Générale + Apéro du club',
            'discipline_id' => null,
            'start_at' => $clubEventStart,
            'duration_min' => 120,
            'location_id' => $piste->id,
            'capacity' => null,
            'external_url' => 'https://www.helloasso.com/demo/ag-club',
            'agenda' => "## Ordre du jour\n\n"
                ."- Bilan sportif de la saison\n"
                ."- Bilan financier\n"
                ."- Renouvellement du bureau\n"
                ."- Projets 2026-2027\n\n"
                .'Apéro offert par le club pour clôturer la soirée. **Présence souhaitée !**',
            'created_by' => $admin->id,
        ]);

        // ═══════════════ PACK PITCH TESTEURS ═══════════════

        // (P1) Séance future ANNULÉE avec inscrits — via les vrais services, comme SessionShow::cancel :
        // promotions mécanisme B, flags apéro garés (cas limite « apéro parké »), notifs d'annulation
        // en outbox pour chaque participant (l'argument notifs du pitch devient visible).
        $regService = app(RegistrationService::class);
        $aperoService = app(AperoService::class);
        $sessionNotifier = app(SessionNotificationService::class);

        $toCancel = $capSessions->first(fn (Session $s) => $s->start_at->gt($now->copy()->addDay()));
        if ($toCancel && $toCancel->cancelled_at === null) {
            $toCancel->forceFill([
                'cancelled_at' => $now->copy()->subHours(3),
                'cancelled_by' => $vincent->id,
            ])->save();
            $regService->onSessionCancelled($toCancel);
            $aperoService->cascadeOnSessionCancel($toCancel); // gare le flag apéro (parked_at)
            $sessionNotifier->notifyParticipants($toCancel, NotificationType::SessionCancelled);
        }

        // (P2) Historique notifications : une partie des lignes outbox (annulation, promotions,
        // inscriptions par coach…) passe en « sent » — l'écran Alertes n'est pas vide au 1er login,
        // et l'écran admin Envois montre les deux états sent/pending.
        //
        // La bascule est directe (pas de drain : rien ne doit partir depuis un seeder), donc elle
        // court-circuite la purge qu'opère OutboxDrainer. On la rejoue à la main, sinon le jeu de
        // démo laisserait des prénoms d'enfants sur des lignes envoyées et donnerait à lire une
        // règle inverse de celle que l'application applique.
        $aBasculer = NotificationOutbox::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit((int) ceil(NotificationOutbox::where('status', 'pending')->count() * 0.6))
            ->get();

        foreach ($aBasculer as $ligne) {
            $ligne->update([
                'status' => 'sent',
                'sent_at' => $now->copy()->subHours(2),
                'payload' => Arr::except($ligne->payload ?? [], [
                    ...NotificationOutbox::SENSITIVE_PAYLOAD_KEYS,
                    ...NotificationOutbox::VOLATILE_PAYLOAD_KEYS,
                ]),
            ]);
        }

        // (P3) Qualifications coachs (PRD §4.11.4) : agrégées sur la fiche séance, badge expiration.
        $qualifs = Qualification::pluck('id', 'code');
        $attribution = fn (?string $expires) => [
            'expires_at' => $expires, 'attributed_at' => $now->copy()->subYear(), 'attributed_by' => $admin->id,
        ];
        $karine->qualifications()->syncWithoutDetaching([
            $qualifs['BNSSA'] => $attribution($now->copy()->addMonths(10)->toDateString()),
        ]);
        $vincent->qualifications()->syncWithoutDetaching([
            $qualifs['BF2'] => $attribution(null),
            // PSC1 EXPIRÉ : le badge « expirée » apparaît sur l'encadrement.
            $qualifs['PSC1'] => $attribution($now->copy()->subMonths(2)->toDateString()),
        ]);
        $damien->qualifications()->syncWithoutDetaching([
            $qualifs['BF1'] => $attribution($now->copy()->addMonths(4)->toDateString()),
        ]);

        // ═══════════════ PACK PAGES D'INFORMATION ═══════════════
        // Notes club (bons d'achat, codes partenaires, infos internes) avec visibilité par niveau
        // cumulatif (all|coach|admin) et épinglage en bannière d'accueil. Contenu sanitisé serveur
        // via Markup::clean (comme le formulaire admin). Ordre d'affichage piloté par `position`.
        //   • all   → 2 codes promo visibles par tous (dont 1 épinglé) ;
        //   • coach → code d'accès de l'équipement (coachs + bureau) ;
        //   • admin → identifiants d'un extranet fédéral (bureau seul).
        //
        // ⚠️ TOUS les partenaires, codes et mots de passe ci-dessous sont INVENTÉS, et écrits pour
        // qu'on le voie au premier coup d'œil (enseignes fictives, préfixe DEMO-, « demo-password »).
        // Ce bloc démontre une fonctionnalité — le partage d'informations confidentielles réservées
        // à un niveau de rôle — donc il ne doit contenir AUCUN secret ayant l'air authentique :
        // un lecteur du dépôt public ne doit jamais se demander s'il vient de trouver un vrai
        // identifiant. Ne jamais remplacer ces valeurs par des captures d'un club réel.
        // Idempotent : updateOrCreate sur le titre.
        $infoPages = [
            [
                'title' => 'Bon d’achat Sport Attitude –15 %',
                'visibility' => 'all',
                'pinned' => true,
                'position' => 0,
                'content' => 'Sur présentation de la **licence** du club, notre partenaire fictif '
                    ."**Sport Attitude** offre **-15 %** sur le rayon running et natation.\n\n"
                    ."Code partenaire en caisse : **DEMO-REMISE-15**\n\n"
                    .'Valable jusqu’au **31 août**. Un seul usage par adhérent et par saison.',
            ],
            [
                'title' => 'Code promo boutique en ligne Aquagliss',
                'visibility' => 'all',
                'pinned' => false,
                'position' => 1,
                'content' => 'Notre partenaire textile fictif **Aquagliss** propose **-20 %** aux '
                    ."membres du club sur sa boutique en ligne.\n\n"
                    ."Code à saisir au panier : **DEMO-CLUB-20**\n\n"
                    .'Cumulable avec les frais de port offerts dès 80 € d’achat.',
            ],
            [
                'title' => 'Code du portail d’accès à la piscine',
                'visibility' => 'coach',
                'pinned' => false,
                'position' => 2,
                'content' => "Rappel encadrants — **exemple de note réservée aux coachs**.\n\n"
                    ."Le portail latéral s’ouvre avec le code **DEMO-0000**.\n\n"
                    ."Le local matériel (planches, pull-buoys) utilise le cadenas à code **0000**.\n\n"
                    .'Merci de **refermer le portail** après le dernier créneau du soir.',
            ],
            [
                'title' => 'Identifiants extranet fédéral (bureau)',
                'visibility' => 'admin',
                'pinned' => false,
                'position' => 3,
                'content' => "Accès réservé au bureau — **exemple de note visible des seuls admins**.\n\n"
                    ."- Portail engagements : identifiant **demo-club** / mot de passe **demo-password**\n"
                    ."- Espace formation : identifiant **demo-formation** / mot de passe **demo-password**\n\n"
                    .'⚠️ Identifiants fictifs (jeu de démonstration). Sur une instance réelle, '
                    .'changer les mots de passe à chaque renouvellement du bureau.',
            ],
        ];
        foreach ($infoPages as $def) {
            InformationPage::updateOrCreate(
                ['title' => $def['title']],
                [
                    'visibility' => $def['visibility'],
                    'pinned' => $def['pinned'],
                    'position' => $def['position'],
                    'content_markdown' => Markup::clean($def['content']),
                    'created_by' => $admin->id,
                ],
            );
        }

        // ═══════════════ PACK ÉTATS ADMIN ═══════════════

        // Template ARCHIVÉ (ancien créneau) : figure dans la liste des modèles avec le filtre statut,
        // ne génère plus rien.
        SessionTemplate::firstOrCreate(
            ['label' => 'Natation lundi soir (ancien créneau)'],
            [
                'kind' => 'training',
                'discipline_id' => $natation->id,
                'day_of_week' => 1, // lundi — jour libéré depuis, d'où l'archivage
                'start_time_of_day' => '20:00',
                'duration_min' => 60,
                'location_id' => $piscine->id,
                'quota_tag_id' => $quotaNat->id,
                'generation_start_date' => $start->copy()->subMonths(6)->toDateString(),
                'generation_end_date' => $start->copy()->subMonth()->toDateString(),
                'created_by' => $admin->id,
                'status' => 'archived',
            ],
        );

        // Ancienne adhérente qui n'a pas renouvelé : ACCÈS ATHLÈTE SUSPENDU (§4.4), compte actif.
        // Pendant de Kévin côté fiche admin — c'est sur elle qu'on exerce la réactivation sans
        // toucher à Kévin, dont plusieurs scénarios E2E dépendent (S3, S15).
        //
        // Surtout PAS is_active=false, comme c'était le cas ici : le PRD réserve ce drapeau au
        // tampon de suppression (§4.3), et la bascule de saison a le sien, séparé (§4.4). Aucune
        // pastille ne se dérive de is_active — la liste comme la fiche affichaient donc « ● actif »
        // un compte dont les quatre voies de connexion étaient fermées, et que le filtre « Actifs »
        // écartait pourtant sans rien en dire. Un état que l'application ne sait ni produire ni
        // défaire.
        $brigitte = User::updateOrCreate(
            ['email' => 'brigitte@demo.club'],
            [
                'first_name' => 'Brigitte', 'last_name' => 'Ancienne', 'dob' => '1971-03-05',
                'password' => Hash::make('password'), 'roles' => ['athlete'],
                'is_active' => true, 'athlete_access_suspended' => true, 'email_verified_at' => now(),
            ],
        );

        $this->attachPrimaryCategory($brigitte, $activeCats);

        // Suppressions RGPD (§4.3) : une demande DANS le tampon 7 j (annulable) et une ÉLIGIBLE
        // (tampon écoulé → bandeau admin sur l'accueil + filtre Adhérents).
        $gilles = User::updateOrCreate(
            ['email' => 'gilles@demo.club'],
            [
                'first_name' => 'Gilles', 'last_name' => 'Partant', 'dob' => '1987-12-01',
                'password' => Hash::make('password'), 'roles' => ['athlete'],
                'is_active' => true, 'email_verified_at' => now(),
            ],
        );
        // is_active=false EN MÊME TEMPS que la date : c'est le couple posé par
        // MemberService::requestDeletion() (§4.3). Poser la date seule fabriquait un compte en
        // tampon de suppression qui pouvait encore se connecter — l'inverse de ce que la démo
        // prétend montrer.
        $gilles->forceFill(['deletion_requested_at' => $now->copy()->subDays(2), 'is_active' => false])->save();
        $daniel = User::updateOrCreate(
            ['email' => 'daniel@demo.club'],
            [
                'first_name' => 'Daniel', 'last_name' => 'Sorti', 'dob' => '1965-06-18',
                'password' => Hash::make('password'), 'roles' => ['athlete'],
                'is_active' => true, 'email_verified_at' => now(),
            ],
        );
        $daniel->forceFill(['deletion_requested_at' => $now->copy()->subDays(9), 'is_active' => false])->save();

        // Ces trois comptes du pack testeurs sont des athlètes à part entière (dob + rôle) : ils
        // doivent avoir leur catégorie, sinon leur fiche affiche « aucune catégorie » à tort.
        $this->attachPrimaryCategory($gilles, $activeCats);
        $this->attachPrimaryCategory($daniel, $activeCats);

        // ═══════════════ PACK CAS LIMITES ═══════════════
        // NB : « apéro parké » est déjà couvert par l'annulation ci-dessus (cascade §4.14.4).

        // Club_event avec inscrits : l'AG reçoit des déclarations de présence (statut uniforme §4.9.1).
        foreach ($athletes->filter(fn (User $u) => ! $u->is_minor)->take(4) as $u) {
            try {
                $regService->register($ag, $u, $u);
            } catch (\RuntimeException) {
                // suspendu, doublon… : on ignore en démo.
            }
        }

        // Athlète SURCLASSÉ (§4.5) : Noah (Cadets) rattaché aussi à Juniors (non-principale).
        $juniors = Category::where('label', 'Juniors')->first();
        if ($juniors) {
            $noah->categories()->syncWithoutDetaching([$juniors->id => ['is_primary' => false]]);
        }

        // Séance en LIEU TEXTE LIBRE (location_text, sans Location géocodée → pas de carte ni météo).
        $cap = Discipline::where('label', 'Course à pied')->first();
        Session::firstOrCreate(
            ['title' => 'Sortie trail nature', 'kind' => 'training'],
            [
                'discipline_id' => $cap?->id,
                'start_at' => $now->copy()->addDays(9)->setTime(9, 30),
                'duration_min' => 105,
                'location_id' => null,
                'location_text' => 'Parking du vieux pont, rive sud',
                'capacity' => null,
                'created_by' => $vincent->id,
            ],
        )->categories()->syncWithoutDetaching([$adulte->id, $master->id]);

        $this->command->info(sprintf(
            'DemoSeeder : packs testeurs — séance annulée:%s · qualifs:4 · template archivé:1 · désactivé/suppression:3 · AG inscrits · surclassement · lieu libre.',
            $toCancel?->title ?? 'aucune',
        ));

        $this->command->info(sprintf(
            'DemoSeeder : %d templates, séances générées du %s au %s. %d flags apéro CAP.',
            count($templates),
            $start->toDateString(),
            $end->toDateString(),
            $capSessions->count(),
        ));
        $this->command->info(sprintf(
            'DemoSeeder : %d adhérents. Inscriptions démo — pleine/waitlist:%d · quota:%d · normales:%d · annulées:%d.',
            $athletes->count(),
            $scenarioStats['full_waitlist'],
            $scenarioStats['quota'],
            $scenarioStats['normal'],
            $scenarioStats['cancelled'],
        ));

        // Bibliothèque de parcours (§4.20) — en dernier : rattache des traces aux séances vélo
        // qui viennent d'être générées.
        $this->call(GpxRouteSeeder::class);
    }

    /**
     * Rattache la catégorie principale dérivée de la dob (§4.5), comme le fait MemberService pour
     * tout compte créé par l'application. À appeler pour CHAQUE athlète du jeu de démo, y compris
     * ceux créés hors de la boucle $athDefs : sans catégorie active, un compte ne peut s'inscrire
     * à aucune séance (RegistrationService::CATEGORY_MISMATCH) — le compte paraît alors cassé.
     *
     * @param  Collection<int, Category>  $activeCats
     */
    private function attachPrimaryCategory(User $user, Collection $activeCats): void
    {
        if ($user->dob === null || ! $user->hasRole('athlete')) {
            return; // un coach-pur ou un parent-pur n'a pas de catégorie (§2).
        }

        $primary = AgeCategory::derive(Carbon::parse($user->dob), null, $activeCats);
        if ($primary !== null && ! $user->categories()->where('category_id', $primary->id)->exists()) {
            $user->categories()->syncWithoutDetaching([$primary->id => ['is_primary' => true]]);
        }

        // syncWithoutDetaching ne rafraîchit pas la relation en mémoire (un read antérieur l'a mise
        // en cache vide) : on la recharge pour que isTargetedBy() voie la catégorie rattachée plus
        // loin (bloc apéro CAP §4.5).
        $user->load('categories');
    }
}
