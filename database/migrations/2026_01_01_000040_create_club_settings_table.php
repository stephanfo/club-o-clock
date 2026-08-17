<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Club');
            $table->string('tagline', 120)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 9)->nullable();
            $table->string('accent_color', 9)->nullable();
            $table->string('info_color', 9)->nullable();
            $table->unsignedTinyInteger('season_start_month')->default(9);
            $table->string('timezone')->default('Europe/Paris');
            $table->unsignedSmallInteger('invitation_link_days')->default(30);
            $table->timestamp('season_rollover_at')->nullable();
            // Interrupteurs d'instance (§4.17). Un club décide quels canaux de notification il ouvre
            // — il n'a pas forcément de clés VAPID ni de fournisseur d'email — et quels moyens de
            // connexion il propose. Le login par mot de passe n'en a pas : c'est la voie garantie,
            // ce qui borne le risque de verrouillage (cf. AuthMethodService::lockedOutBy).
            //
            // DEFAULT true, contrairement au pattern nullable des colonnes de branding : il n'y a
            // pas de distinction utile entre « non personnalisé » et « activé », et une instance
            // neuve doit se comporter comme avant l'ajout du réglage.
            $table->boolean('notif_push_enabled')->default(true);
            $table->boolean('notif_email_enabled')->default(true);
            $table->boolean('auth_magic_link_enabled')->default(true);
            $table->boolean('auth_google_enabled')->default(true);
            // Mentions légales propres à l'instance (page publique /mentions-legales, §OS3).
            // Saisies en admin plutôt qu'écrites dans la vue : un club ne doit pas éditer le code
            // source pour publier ses mentions, sinon son fork diverge à chaque `git pull`.
            // Toutes nullables — NULL = « non renseigné », la page affiche alors un marqueur.
            $table->string('legal_publisher', 500)->nullable();
            $table->string('legal_host', 500)->nullable();
            $table->string('legal_director')->nullable();
            $table->string('legal_contact_email')->nullable();
            $table->string('legal_source_url')->nullable();
            $table->string('legal_mail_provider')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_settings');
    }
};
