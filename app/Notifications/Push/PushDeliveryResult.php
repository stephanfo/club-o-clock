<?php

namespace App\Notifications\Push;

// Issue d'un envoi push vers UN abonnement. « expired » = endpoint mort (404/410) ou refusé (401/403) → l'abonnement
// doit être purgé ; « delivered » = accepté par le service push ; sinon échec transitoire (retry).
final class PushDeliveryResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly bool $expired,
    ) {}

    public static function delivered(): self
    {
        return new self(true, false);
    }

    /** Endpoint mort ou refusé : à purger, inutile de retenter. */
    public static function expired(): self
    {
        return new self(false, true);
    }

    /** Échec transitoire (réseau, 5xx…) : l'abonnement reste, le drain retentera. */
    public static function failed(): self
    {
        return new self(false, false);
    }
}
