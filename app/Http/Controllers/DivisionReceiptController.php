<?php

namespace App\Http\Controllers;

use App\Domains\Divisions\Models\DivisionExpense;
use App\Domains\Divisions\Services\DivisionService;
use Illuminate\Support\Facades\Storage;

/** Comprovante de despesa: só quem enxerga o grupo baixa. */
class DivisionReceiptController extends Controller
{
    public function __invoke(DivisionExpense $expense, DivisionService $service)
    {
        $service->assertView(auth()->user(), $expense->group);
        abort_unless($expense->receipt_path && Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download($expense->receipt_path, $expense->receipt_original_name ?: 'comprovante', [
            'Content-Type' => $expense->receipt_mime ?: 'application/octet-stream',
        ]);
    }
}
