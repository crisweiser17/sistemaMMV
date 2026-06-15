<?php

namespace App\Http\Controllers;

use App\Models\Demanda;
use App\Services\OutputService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OutputController extends Controller
{
    public function __construct(private OutputService $service) {}

    /** Preview HTML do PI (todos os itens da demanda) antes de gerar o PDF. */
    public function preview(Demanda $demanda): View
    {
        return view('output.pi', $this->service->montarDados($demanda));
    }

    public function gerar(Request $request, Demanda $demanda): RedirectResponse
    {
        $this->authorize('editar', 'engenharia');
        $this->service->gerarPdf($demanda, $request->user()->id);

        return redirect()->route('output.historico', $demanda)->with('success', 'PDF gerado com sucesso.');
    }

    public function download(Demanda $demanda): StreamedResponse
    {
        $output = $demanda->outputs()->firstOrFail();
        abort_unless(Storage::exists($output->path_pdf), 404);

        return Storage::download($output->path_pdf, "PI-demanda-{$demanda->id}.pdf");
    }

    public function historico(Demanda $demanda): View
    {
        $demanda->load(['outputs.autor']);
        $numeroReferencia = $demanda->headers()->first()?->numero_referencia ?? ('Demanda #'.$demanda->id);

        return view('output.historico', compact('demanda', 'numeroReferencia'));
    }
}
