<?php

namespace App\Support\Export;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

// Binder anti-injection de formule pour les exports XLSX (PRD §4.16.2). Une cellule texte commençant
// par un méta-caractère de formule (= + - @, tabulation, retour chariot) est interprétée comme formule
// par Excel/LibreOffice à l'ouverture — vecteur d'injection puisque noms d'adhérents, titres et motifs
// d'override sont saisis par les utilisateurs. On force ces chaînes en type STRING (stockées telles
// quelles, jamais évaluées) ; les nombres et chaînes anodines suivent le binder par défaut.
class SafeValueBinder extends DefaultValueBinder
{
    private const FORMULA_LEADERS = ['=', '+', '-', '@', "\t", "\r"];

    public function bindValue(Cell $cell, mixed $value = null): bool
    {
        if (is_string($value) && $value !== '' && in_array($value[0], self::FORMULA_LEADERS, true)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
