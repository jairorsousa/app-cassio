<?php

namespace App\Http\Controllers;

use App\Domains\Divisions\Models\DivisionGroup;
use App\Domains\Divisions\Services\DivisionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Extrato do grupo em PDF. Participante baixa só dos próprios grupos. */
class DivisionStatementPdfController extends Controller
{
    public function __invoke(Request $request, DivisionGroup $group, DivisionService $service)
    {
        $service->assertView($request->user(), $group);
        abort_unless($group->status === 'approved', 404);
        $date = fn ($v) => $v && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? Carbon::parse($v)->toDateString() : null;
        $from = $date($request->query('de'));
        $to = $date($request->query('ate'));
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $group->load('members');

        return Pdf::loadView('pdf.divisions.statement', [
            'group' => $group,
            'members' => $group->members->keyBy('id'),
            'report' => $service->statementReport($group, $from, $to),
            'rangeStart' => $from,
            'rangeEnd' => $to,
            'generatedAt' => now('America/Fortaleza'),
            'logo' => 'data:image/png;base64,'.base64_encode((string) @file_get_contents(public_path('icon-192.png'))),
            'money' => fn (int $c) => $service->money($c),
        ])->setPaper('a4', $group->members->count() > 4 ? 'landscape' : 'portrait')
            ->download('extrato-divisoes-'.Str::slug($group->name).'.pdf');
    }
}
