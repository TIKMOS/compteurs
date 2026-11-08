<?php

declare(strict_types=1);

namespace App\Modules\Metering\Exceptions;

use DomainException;

/**
 * Paiement ou remboursement refusé (UC-18) : mois non clôturé, montant supérieur au reste dû…
 */
final class PaymentRejectedException extends DomainException {}
