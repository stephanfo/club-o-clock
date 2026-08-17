<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Discipline;
use App\Models\EventType;
use App\Models\Location;
use App\Models\Qualification;
use App\Models\QuotaTag;
use App\Models\Session;
use App\Models\SessionTemplate;
use App\Models\User;
use App\Support\Logging\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

// Gestion des catalogues paramétrables (PRD §4.6, §4.17). Pattern commun à 6 entités :
// création / renommage rétroactif par ID / archivage soft (si référencé) / suppression dure
// (si zéro référence). Garde-fou « minimum 1 actif » sur Disciplines (§4.6.1) et Types d'épreuve
// (§4.6.2). Chaque opération → AuditLog dédié. Admin uniquement (vérifié en amont par Gate).
class CatalogueService
{
    /** Levée si l'opération viderait un catalogue à garde-fou « min 1 actif ». */
    public const MUST_KEEP_ONE_ACTIVE = 'must_keep_one_active';

    /** Levée si une suppression dure est tentée alors que l'entité est référencée. */
    public const STILL_REFERENCED = 'still_referenced';

    /**
     * Métadonnées par type de catalogue : modèle, colonne d'archivage, action d'audit, garde-fou
     * min-1-actif, et closure de comptage des références (pour archive vs delete).
     *
     * @return array{model:class-string<Model>,archive_col:string,audit:string,keep_one:bool,refs:callable}
     */
    private function meta(string $type): array
    {
        return match ($type) {
            'discipline' => [
                'model' => Discipline::class, 'archive_col' => 'archived_at',
                'audit' => 'discipline_modified', 'keep_one' => true,
                // Réfs Session ET SessionTemplate : un template référençant la discipline empêche
                // aussi la suppression dure (sinon FK template nullOnDelete → orpheline silencieuse).
                'refs' => fn (Model $m) => Session::where('discipline_id', $m->id)->count()
                    + SessionTemplate::where('discipline_id', $m->id)->count(),
            ],
            'category' => [
                'model' => Category::class, 'archive_col' => 'archived_at',
                'audit' => 'category_modified', 'keep_one' => false,
                'refs' => fn (Model $m) => $m->sessions()->count() + $m->users()->count()
                    + $m->templates()->count(),
            ],
            'event_type' => [
                'model' => EventType::class, 'archive_col' => 'archived_at',
                'audit' => 'event_type_modified', 'keep_one' => true,
                'refs' => fn (Model $m) => Session::where('event_type_id', $m->id)->count(),
            ],
            'quota_tag' => [
                'model' => QuotaTag::class, 'archive_col' => 'archived_at',
                'audit' => 'quota_tag_modified', 'keep_one' => false,
                'refs' => fn (Model $m) => Session::where('quota_tag_id', $m->id)->count()
                    + SessionTemplate::where('quota_tag_id', $m->id)->count(),
            ],
            'qualification' => [
                'model' => Qualification::class, 'archive_col' => 'archived_at',
                'audit' => 'qualification_modified', 'keep_one' => false,
                'refs' => fn (Model $m) => $m->users()->count(),
            ],
            'location' => [
                'model' => Location::class, 'archive_col' => 'is_archived',
                'audit' => 'location_modified', 'keep_one' => false,
                'refs' => fn (Model $m) => Session::where('location_id', $m->id)->count()
                    + SessionTemplate::where('location_id', $m->id)->count(),
            ],
            default => throw new RuntimeException("Catalogue inconnu : {$type}."),
        };
    }

    /** Crée une entrée. $attrs déjà validés en amont (Livewire). */
    public function create(string $type, array $attrs, User $actor): Model
    {
        $meta = $this->meta($type);
        /** @var Model $entity */
        $entity = $meta['model']::create($attrs);

        $this->audit($meta['audit'], $actor, $entity, 'create');

        return $entity;
    }

    /** Renommage / mise à jour rétroactive par ID (l'affichage suit la référence — §4.6). */
    public function update(string $type, Model $entity, array $attrs, User $actor): Model
    {
        $meta = $this->meta($type);
        // Défensif : l'archivage passe par archive()/restore() (garde min-1-actif). On exclut la
        // colonne d'archivage d'un update « rénommage » pour qu'aucun champ form ne la contourne.
        unset($attrs[$meta['archive_col']]);
        $entity->update($attrs);

        $this->audit($meta['audit'], $actor, $entity, 'update');

        return $entity;
    }

    /**
     * Archive soft (retire des sélecteurs, conserve l'historique). Bloqué si ce serait le dernier
     * actif d'un catalogue à garde-fou (§4.6.1/.2).
     */
    public function archive(string $type, Model $entity, User $actor): void
    {
        $meta = $this->meta($type);

        if ($meta['keep_one'] && $this->activeCount($type, $entity->id) === 0) {
            throw new RuntimeException(self::MUST_KEEP_ONE_ACTIVE);
        }

        $this->setArchived($entity, $meta['archive_col'], true);
        $this->audit($meta['audit'], $actor, $entity, 'archive');
    }

    public function restore(string $type, Model $entity, User $actor): void
    {
        $meta = $this->meta($type);
        $this->setArchived($entity, $meta['archive_col'], false);
        $this->audit($meta['audit'], $actor, $entity, 'restore');
    }

    /**
     * Suppression dure — autorisée seulement si zéro référence (§4.6). Sinon STILL_REFERENCED
     * (l'appelant doit archiver à la place). Garde min-1-actif appliqué aussi.
     */
    public function delete(string $type, Model $entity, User $actor): void
    {
        $meta = $this->meta($type);

        if (($meta['refs'])($entity) > 0) {
            throw new RuntimeException(self::STILL_REFERENCED);
        }
        if ($meta['keep_one'] && $this->activeCount($type, $entity->id) === 0) {
            throw new RuntimeException(self::MUST_KEEP_ONE_ACTIVE);
        }

        $this->audit($meta['audit'], $actor, $entity, 'delete');
        $entity->delete();
    }

    /** Nombre d'entrées actives (non archivées) hors $exceptId. */
    public function activeCount(string $type, ?int $exceptId = null): int
    {
        $meta = $this->meta($type);
        $col = $meta['archive_col'];

        $q = $meta['model']::query();
        $col === 'is_archived' ? $q->where('is_archived', false) : $q->whereNull($col);

        if ($exceptId !== null) {
            $q->whereKeyNot($exceptId);
        }

        return $q->count();
    }

    /** Pose / lève le flag d'archivage selon la convention de colonne du modèle. */
    private function setArchived(Model $entity, string $col, bool $archived): void
    {
        if ($col === 'is_archived') {
            $entity->update(['is_archived' => $archived]);
        } else {
            $entity->update([$col => $archived ? Carbon::now() : null]);
        }
    }

    private function audit(string $action, User $actor, Model $entity, string $op): void
    {
        AuditLogger::record($action, $actor, [
            'target_type' => $entity::class,
            'target_id' => $entity->id,
            'motif' => $op,
        ]);
    }
}
