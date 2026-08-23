<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

// Amorçage du tout premier compte admin d'une instance fraîche (PRD §4.1.4, cadrage §7.3).
//
// Sans cette commande, une installation neuve est une impasse : l'inscription publique est
// désactivée (Features::registration() absent de config/fortify.php — les comptes sont créés par
// l'admin, §4.2) et le lien magique exige un utilisateur existant. Personne ne peut donc entrer
// dans une base vide. BootstrapAdmin::promoteIfMatches() ne couvre que la promotion d'un compte
// DÉJÀ créé ; il fallait de quoi créer le premier.
//
// Idempotente : relancée sur un email existant, elle promeut le compte au rôle admin ET lève ce
// qui bloquerait sa connexion (is_active, email_verified_at), sans toucher au mot de passe — c'est
// le filet pour récupérer la main sur une instance dont l'admin s'est verrouillé.
class CreateAdminCommand extends Command
{
    protected $signature = 'club:create-admin
                            {email? : Email du compte admin (défaut : BOOTSTRAP_ADMIN_EMAIL)}
                            {--first-name= : Prénom}
                            {--last-name= : Nom}
                            {--password= : Mot de passe (défaut : demandé en interactif)}';

    protected $description = 'Crée (ou promeut) le premier compte administrateur du club';

    public function handle(): int
    {
        $email = $this->argument('email') ?? config('club.bootstrap_admin_email');

        if (! $email) {
            $this->error('Aucun email fourni. Passe-le en argument, ou renseigne BOOTSTRAP_ADMIN_EMAIL dans le .env.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $email));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error("Email invalide : {$email}");

            return self::FAILURE;
        }

        // Compte déjà présent → promotion + déverrouillage, on ne réécrit ni l'identité ni le mot
        // de passe.
        $existing = User::findByEmail($email);

        if ($existing) {
            $promoted = $existing->grantRole('admin');

            // Réactivation explicite : la commande est vendue comme le moyen de « récupérer la main
            // sur une instance dont l'admin s'est verrouillé », or les TROIS portes d'entrée
            // (mot de passe, lien magique, OAuth) exigent is_active. Sans ça, elle répondait
            // « promu » en laissant l'exploitant dehors — échec silencieux sur le seul point
            // d'entrée d'une instance.
            $unlocked = $this->unlock($existing);

            $existing->save();

            match (true) {
                $promoted => $this->info("{$email} a été promu administrateur."),
                default => $this->info("{$email} est déjà administrateur."),
            };

            if ($unlocked !== []) {
                $this->info('Compte déverrouillé ('.implode(', ', $unlocked).').');
            } elseif (! $promoted) {
                $this->line('Rien à faire : le compte est déjà actif.');
            }

            return self::SUCCESS;
        }

        $firstName = $this->option('first-name') ?: $this->ask('Prénom', 'Admin');
        $lastName = $this->option('last-name') ?: $this->ask('Nom', 'Club');

        $password = $this->option('password') ?: $this->secret('Mot de passe (min. '.PasswordPolicy::MIN.' caractères)');

        // Password::defaults() : la commande hérite de la politique du club au lieu d'en porter une
        // copie. Elle exigeait 8 quand toutes les autres surfaces exigeaient 10 — le seul compte
        // créable sur une base vide était donc aussi le seul autorisé à être plus faible.
        $check = Validator::make(
            ['password' => $password],
            ['password' => ['required', 'string', Password::defaults()]],
        );

        if ($check->fails()) {
            $this->error((string) $check->errors()->first('password'));

            return self::FAILURE;
        }

        $user = User::create([
            'first_name' => trim((string) $firstName),
            'last_name' => trim((string) $lastName),
            'email' => $email,
            'password' => Hash::make($password),
            'roles' => ['admin'],
            'is_active' => true,
            'is_minor' => false,
        ]);

        // Compte créé en CLI par l'exploitant de l'instance : l'email n'a pas à être re-vérifié
        // par un aller-retour qui exigerait justement l'email déjà configuré (poule et œuf).
        $user->forceFill(['email_verified_at' => now()])->save();

        $this->newLine();
        $this->info("Compte administrateur créé : {$email}");
        $this->line('Connecte-toi sur '.config('app.url').'/login');
        $this->newLine();
        $this->warn('Pense à renseigner les Paramètres du club (nom, logo, palette, fuseau) après connexion.');

        return self::SUCCESS;
    }

    /**
     * Lève ce qui empêcherait la connexion d'un compte existant (sans le sauvegarder).
     *
     * @return array<int,string> libellés des verrous levés, vide si le compte était déjà ouvert
     */
    private function unlock(User $user): array
    {
        $lifted = [];

        if (! $user->is_active) {
            $user->is_active = true;
            $lifted[] = 'compte réactivé';
        }

        if ($user->email_verified_at === null) {
            $user->email_verified_at = now();
            $lifted[] = 'email marqué vérifié';
        }

        return $lifted;
    }
}
