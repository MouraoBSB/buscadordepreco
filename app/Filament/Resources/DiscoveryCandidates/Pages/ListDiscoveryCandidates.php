<?php

namespace App\Filament\Resources\DiscoveryCandidates\Pages;

use App\Filament\Resources\DiscoveryCandidates\DiscoveryCandidateResource;
use App\Models\DiscoveryCandidate;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListDiscoveryCandidates extends ListRecords
{
    protected static string $resource = DiscoveryCandidateResource::class;

    public function getTabs(): array
    {
        $pendingCount = DiscoveryCandidate::where('status', 'pending_review')->count();

        return [
            'all' => Tab::make('Todas as Ofertas'),
            'pending' => Tab::make('Pendentes de Revisão')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_review'))
                ->badge($pendingCount > 0 ? (string) $pendingCount : null)
                ->badgeColor('warning'),
            'approved' => Tab::make('Aprovadas e Monitoradas')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['approved', 'auto_approved'])),
            'rejected' => Tab::make('Rejeitadas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'rejected')),
        ];
    }
}
