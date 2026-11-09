<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\Occupancy;
use App\Models\Unit;
use App\Shared\Support\FrenchNumber;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Fiche d'un locataire pour un mois clôturé (EF-36, EF-37) : « Participation aux frais d'eau et
 * d'électricité ». Sert à la fiche PDF et au message WhatsApp / SMS.
 */
final readonly class TenantStatement
{
    /** Durée de validité du lien de partage de la fiche PDF. */
    public const SHARE_DAYS = 7;

    /**
     * @param list<array{utility: \App\Shared\Enums\Utility, priceLabel: string, segments: list<array{from: string, to: string, consumption: int}>, raw: int, creditApplied: int, consumption: int, creditBalance: int, amount: int, estimated: bool, regularization: bool, estimationBasis: string|null, photoReadingIds: list<int>}> $utilities
     */
    public function __construct(
        public BillingPeriod $period,
        public House $house,
        public Unit $unit,
        public Occupancy $occupancy,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $utilities,
        public int $total,
        public ?int $creditRefunded,
        public ?int $refundDue,
        public int $paid = 0,
        public ?CarbonImmutable $dueDate = null,
    ) {}

    /**
     * « dix-neuf mille trois cent un francs CFA ».
     */
    public static function inWords(int $amount): string
    {
        return FrenchNumber::toWords($amount) . ' franc' . (abs($amount) > 1 ? 's' : '') . ' CFA';
    }

    public static function fcfa(int $amount): string
    {
        return Milli::format($amount * 1000) . ' FCFA';
    }

    /**
     * Reste à payer à la date de génération (jamais négatif).
     */
    public function remaining(): int
    {
        return max(0, $this->net() - $this->paid);
    }

    /**
     * Lien signé et temporaire vers la fiche PDF, ouvrable sans compte (7 jours).
     */
    public function sharedPdfUrl(): string
    {
        return URL::temporarySignedRoute('fiches.partage', CarbonImmutable::now()->addDays(self::SHARE_DAYS), [
            'occupancy' => $this->occupancy->id,
            'mois' => $this->period->month->format('Y-m'),
        ]);
    }

    /**
     * Message WhatsApp : montant, reste à payer, date limite et lien vers la fiche PDF.
     */
    public function whatsappMessage(): string
    {
        $lines = [
            'Bonjour ' . $this->occupancy->tenant->name . ',',
            sprintf(
                "Votre participation aux frais d'eau et d'électricité %s (%s, %s) : %s.",
                (preg_match('/^[aeiouéh]/iu', $this->monthLabel()) ? "d'" : 'de ') . $this->monthLabel(),
                $this->unit->label,
                $this->house->name,
                self::fcfa($this->net()),
            ),
        ];
        if ($this->paid > 0) {
            $lines[] = 'Déjà payé : ' . self::fcfa($this->paid) . '.';
        }
        if ($this->remaining() > 0) {
            $lines[] = 'Reste à payer : ' . self::fcfa($this->remaining()) . ($this->dueDate ? ', avant le ' . $this->dueDate->format('d/m/Y') : '') . '.';
        } else {
            $lines[] = 'Rien à payer pour ce mois. Merci.';
        }
        if ($this->refundDue > 0) {
            $lines[] = 'À vous rembourser : ' . self::fcfa($this->refundDue) . '.';
        }
        $lines[] = 'Fiche détaillée (PDF, lien valable ' . self::SHARE_DAYS . ' jours) : ' . $this->sharedPdfUrl();

        return implode('
', $lines);
    }

    public function net(): int
    {
        return $this->total - ($this->creditRefunded ?? 0);
    }

    public function monthLabel(): string
    {
        return $this->period->label();
    }

    public function fileName(): string
    {
        return Str::slug('participation ' . $this->period->month->format('Y-m') . ' ' . $this->unit->label . ' ' . $this->occupancy->tenant->name) . '.pdf';
    }

    /**
     * Texte court pour WhatsApp ou SMS (EF-37).
     */
    public function shortText(): string
    {
        $lines = [
            'Participation aux frais d\'eau et d\'électricité — ' . $this->monthLabel(),
            $this->house->name . ' · ' . $this->unit->label . ' · ' . $this->occupancy->tenant->name,
        ];

        foreach ($this->utilities as $u) {
            $line = sprintf(
                '%s : %s %s = %s',
                Str::ucfirst($u['utility']->label()),
                Milli::format($u['consumption']),
                $u['utility']->unit(),
                self::fcfa($u['amount']),
            );
            if ($u['estimated']) {
                $line .= ' (estimée)';
            }
            if ($u['creditApplied'] > 0) {
                $line .= sprintf(' — dont %s %s de crédit déduit', Milli::format($u['creditApplied']), $u['utility']->unit());
            }
            $lines[] = $line;
        }

        $lines[] = 'Total : ' . self::fcfa($this->total);

        if ($this->creditRefunded !== null) {
            $lines[] = 'Crédit restant remboursé : −' . self::fcfa($this->creditRefunded);
            $lines[] = 'Net à payer : ' . self::fcfa($this->net());
            if ($this->refundDue > 0) {
                $lines[] = 'À rembourser au locataire : ' . self::fcfa($this->refundDue);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Lien WhatsApp : vers le numéro du locataire s'il est connu, sinon au choix du contact.
     */
    public function whatsappUrl(): string
    {
        $phone = $this->occupancy->tenant->phone;

        return 'https://wa.me/' . ($phone === null ? '' : ltrim($phone, '+')) . '?text=' . rawurlencode($this->whatsappMessage());
    }

    public function smsUrl(): string
    {
        return 'sms:' . ($this->occupancy->tenant->phone ?? '') . '?body=' . rawurlencode($this->shortText() . '
Fiche : ' . $this->sharedPdfUrl());
    }
}
