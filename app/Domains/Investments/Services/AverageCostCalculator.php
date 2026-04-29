<?php

namespace App\Domains\Investments\Services;

use App\Domains\Investments\Models\Asset;
use App\Domains\Investments\Models\AssetOperation;

class AverageCostCalculator
{
    /**
     * Recalcula posição do zero a partir de todas as operações,
     * preservando histórico de PnL realizado em cada venda.
     *
     * @return array{
     *   quantity: float,
     *   average_price: float,
     *   total_invested: float,
     *   realized_pnl_total: float,
     *   updated_operations: array<int, array{id: int, realized_pnl: float}>,
     * }
     */
    public function recalculate(Asset $asset): array
    {
        $quantity = 0.0;
        $totalInvested = 0.0;
        $realizedTotal = 0.0;
        $updatedOps = [];

        $operations = AssetOperation::where('asset_id', $asset->id)
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        foreach ($operations as $op) {
            $opQty = (float) $op->quantity;
            $opUnit = (float) $op->unit_price;
            $opFees = (float) $op->fees;
            $opTotal = (float) $op->total;

            if ($op->type === 'buy') {
                $totalInvested += $opTotal;
                $quantity += $opQty;
                $realized = null;
            } else { // sell
                $avg = $quantity > 0 ? $totalInvested / $quantity : 0.0;
                $costBasis = $avg * $opQty;
                $proceeds = ($opUnit * $opQty) - $opFees;
                $realized = round($proceeds - $costBasis, 2);

                $totalInvested -= $costBasis;
                $quantity -= $opQty;

                if ($quantity <= 1e-6) {
                    $quantity = 0.0;
                    $totalInvested = 0.0;
                }

                $realizedTotal += $realized;
            }

            if ($realized !== null && (float) ($op->realized_pnl ?? 0) !== $realized) {
                $updatedOps[] = ['id' => $op->id, 'realized_pnl' => $realized];
            }
        }

        $avgPrice = $quantity > 0 ? round($totalInvested / $quantity, 4) : 0.0;

        return [
            'quantity' => round($quantity, 6),
            'average_price' => $avgPrice,
            'total_invested' => round($totalInvested, 2),
            'realized_pnl_total' => round($realizedTotal, 2),
            'updated_operations' => $updatedOps,
        ];
    }
}
