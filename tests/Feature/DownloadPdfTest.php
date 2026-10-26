<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\StatusEngenharia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadPdfTest extends TestCase
{
    use RefreshDatabase;

    private function demanda(): Demanda
    {
        $cliente = Cliente::create(['nome' => 'Suzano SA', 'ativo' => true]);
        $pi = Liberacao::create(['numero_pi' => 'PI-1173', 'cliente_id' => $cliente->id]);
        $itemPi = $pi->itens()->create(['numero_item' => 1, 'descricao' => 'Rolo raspador', 'quantidade' => 1]);
        $demanda = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => $pi->id]);
        $header = EngenhariaHeader::create([
            'demanda_id' => $demanda->id,
            'cliente_id' => $cliente->id,
            'numero_referencia' => 'PI-1173',
            'nome_item' => 'Rolo raspador',
            'data_alocacao' => now()->toDateString(),
            'liberacao_item_id' => $itemPi->id,
            'status_id' => StatusEngenharia::firstOrCreate(['nome' => 'Em andamento'])->id,
        ]);
        $header->linhas()->create(['numero_linha' => 1, 'descricao' => 'Chapa', 'tipo_componente' => 'materia_prima', 'quantidade' => 2]);

        return $demanda;
    }

    /** Download e BinaryFileResponse (sem fpassthru, desativado na producao): le o arquivo enviado. */
    private function conteudo($resp): string
    {
        return file_get_contents($resp->assertOk()->getFile()->getPathname());
    }

    public function test_baixar_ultimo_pdf_gerado(): void
    {
        Storage::fake();
        $perfil = Perfil::create(['nome' => 'Eng', 'permissoes' => ['engenharia' => 'editar'], 'ativo' => true]);
        $user = User::create(['name' => 'Eng', 'email' => 'e@mmv.test', 'password' => 'segredo123', 'perfil_id' => $perfil->id, 'ativo' => true]);
        $demanda = $this->demanda();

        $this->actingAs($user)->post(route('output.gerar', $demanda))->assertRedirect(route('output.historico', $demanda));

        $this->get(route('output.historico', $demanda))->assertOk()->assertSee(route('output.download', $demanda));

        $resp = $this->get(route('output.download', $demanda));
        $resp->assertOk()->assertDownload("PI-demanda-{$demanda->id}.pdf");
        $this->assertStringStartsWith('%PDF', $this->conteudo($resp));
    }

    public function test_cada_versao_do_historico_baixa_o_proprio_pdf(): void
    {
        Storage::fake();
        $perfil = Perfil::create(['nome' => 'Eng', 'permissoes' => ['engenharia' => 'editar'], 'ativo' => true]);
        $user = User::create(['name' => 'Eng', 'email' => 'e@mmv.test', 'password' => 'segredo123', 'perfil_id' => $perfil->id, 'ativo' => true]);
        $demanda = $this->demanda();

        // Duas geracoes no mesmo segundo: nenhuma pode sobrescrever o arquivo da outra.
        $this->actingAs($user)->post(route('output.gerar', $demanda));
        $this->post(route('output.gerar', $demanda));
        [$nova, $antiga] = $demanda->outputs()->get()->all();
        $this->assertNotSame($antiga->path_pdf, $nova->path_pdf);
        Storage::put($antiga->path_pdf, '%PDF-versao-antiga');

        $this->get(route('output.historico', $demanda))->assertOk()
            ->assertSee(route('output.download', [$demanda, $antiga->id]))
            ->assertSee(route('output.download', [$demanda, $nova->id]));

        $this->assertSame('%PDF-versao-antiga', $this->conteudo($this->get(route('output.download', [$demanda, $antiga->id]))));
        $this->assertNotSame('%PDF-versao-antiga', $this->conteudo($this->get(route('output.download', $demanda))));

        // Versao de outra demanda nao vaza pela URL desta.
        $outra = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => $demanda->referencia_id]);
        $this->get(route('output.download', [$outra, $antiga->id]))->assertNotFound();
    }
}
