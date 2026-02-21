<?php

namespace App\Filament\Resources\Deals\Pages;

use App\Enums\DealStage;
use App\Filament\Resources\Deals\DealResource;
use App\Models\Deal;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

class ListDeals extends ListRecords
{
    protected static string $resource = DealResource::class;

    protected string $view = 'filament.resources.deals.pages.list-deals';

    public string $viewMode = 'board';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Deal'),
        ];
    }

    public function showBoard(): void
    {
        $this->viewMode = 'board';
    }

    public function showTable(): void
    {
        $this->viewMode = 'table';
    }

    /**
     * @return array<int, array{stage: DealStage, deals: Collection<int, Deal>, count: int, total_value: float}>
     */
    public function getBoardColumns(): array
    {
        $dealsByStage = Deal::query()
            ->with('contact')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (Deal $deal): string => $deal->stage->value);

        return collect(DealStage::cases())
            ->map(function (DealStage $stage) use ($dealsByStage): array {
                /** @var Collection<int, Deal> $deals */
                $deals = $dealsByStage->get($stage->value, collect());

                return [
                    'stage' => $stage,
                    'deals' => $deals,
                    'count' => $deals->count(),
                    'total_value' => (float) $deals->sum(fn (Deal $deal): float => (float) ($deal->value ?? 0)),
                ];
            })
            ->all();
    }
}
