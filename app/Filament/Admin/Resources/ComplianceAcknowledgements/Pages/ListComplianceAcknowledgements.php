<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ComplianceAcknowledgements\Pages;

use App\Filament\Admin\Resources\ComplianceAcknowledgements\ComplianceAcknowledgementResource;
use Filament\Resources\Pages\ListRecords;

final class ListComplianceAcknowledgements extends ListRecords
{
    protected static string $resource = ComplianceAcknowledgementResource::class;
}
