<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\Occupancy;
use App\Models\Unit;
use App\Shared\Support\Milli;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Fiche d'un locataire pour un mois clôturé (EF-36, EF-37) : « Participation aux frais d'eau et
 * d'électricité ». Sert à la fiche PDF et au message WhatsApp / SMS.
 */
final readonly class TenantStatement
{
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
    ) {}

    public static function fcfa(int $amount): string
    {
        return Milli::format($amount * 1000) . ' FCFA';
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

        return 'https://wa.me/' . ($phone === null ? '' : ltrim($phone, '+')) . '?text=' . rawurlencode($this->shortText());
    }

    public function smsUrl(): string
    {
        return 'sms:' . ($this->occupancy->tenant->phone ?? '') . '?body=' . rawurlencode($this->shortText());
    }
}
