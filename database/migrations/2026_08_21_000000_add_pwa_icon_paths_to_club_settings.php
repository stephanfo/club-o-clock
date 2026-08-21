<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Icônes PWA propres à l'instance (PRD §4.17, cadrage §7.16).
//
// Trois colonnes plutôt qu'une : les trois icônes ne sont pas trois tailles d'un même rendu (les
// formats manifest sont rognés en cercle par Android, l'icône iOS doit être opaque), et le club les
// téléverse indépendamment. Nullable, comme les autres colonnes de branding : NULL = « non
// personnalisé » → l'application sert le jeu versionné dans public/icons/, ce qui garde un
// déploiement neuf installable en PWA sans aucune étape.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            $table->string('icon_192_path')->nullable()->after('logo_path');
            $table->string('icon_512_path')->nullable()->after('icon_192_path');
            $table->string('icon_apple_path')->nullable()->after('icon_512_path');
        });
    }

    public function down(): void
    {
        Schema::table('club_settings', function (Blueprint $table) {
            $table->dropColumn(['icon_192_path', 'icon_512_path', 'icon_apple_path']);
        });
    }
};
