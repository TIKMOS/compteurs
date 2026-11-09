<?php

declare(strict_types=1);

namespace App\Shared\Support;

use OverflowException;

/**
 * Valeurs trop grandes pour un calcul exact en entiers 64 bits : presque toujours une faute de
 * frappe (des zéros en trop dans un index ou dans le montant d'une facture).
 */
final class ValueTooLargeException extends OverflowException
{
    public function __construct()
    {
        parent::__construct('Valeurs trop grandes pour être calculées : vérifiez les index saisis et le montant de la facture (un zéro de trop ?).');
    }
}
