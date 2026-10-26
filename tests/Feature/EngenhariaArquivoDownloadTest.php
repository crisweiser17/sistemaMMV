<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\Liberacao;
use App\Models\Perfil;
use App\Models\StatusEngenharia;
use App\Models\User;
use App\Services\AnexoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * Anexo da linha de engenharia: depois de anexado, o clique baixa o arquivo.
 * Em producao fpassthru() e desativado, entao Storage::download()/response() dao 500:
 * os downloads tem de sair como BinaryFileResponse (AnexoService::enviar).
 */
class EngenhariaArquivoDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_anexo_da_linha_e_baixado(): void
    {
        Storage::fake('local');
        $perfil = Perfil::create(['nome' => 'Eng', 'permissoes' => ['engenharia' => 'editar'], 'ativo' => true]);
        $user = User::create(['name' => 'Eng', 'email' => 'e@mmv.test', 'password' => 'segredo123', 'perfil_id' => $perfil->id, 'ativo' => true]);

        $cliente = Cliente::create(['nome' => 'Suzano SA', 'ativo' => true]);
        $pi = Liberacao::create(['numero_pi' => 'PI-1173', 'cliente_id' => $cliente->id]);
        $itemPi = $pi->itens()->create(['numero_item' => 1, 'descricao' => 'Rolo raspador', 'quantidade' => 1]);
        $demanda = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => $pi->id]);
        $header = EngenhariaHeader::create([
            'demanda_id' => $demanda->id, 'cliente_id' => $cliente->id, 'numero_referencia' => 'PI-1173',
            'nome_item' => 'Rolo raspador', 'data_alocacao' => now()->toDateString(), 'liberacao_item_id' => $itemPi->id,
            'status_id' => StatusEngenharia::firstOrCreate(['nome' => 'Em andamento'])->id,
        ]);
        $linha = $header->linhas()->create(['numero_linha' => 1, 'descricao' => 'Chapa', 'tipo_componente' => 'materia_prima', 'quantidade' => 2]);

        $this->actingAs($user)
            ->post(route('engenharia.linha.upload', [$header, $linha]), [
                'arquivo' => UploadedFile::fake()->createWithContent('Desenho Rolo Ø200.pdf', '%PDF-1.4 teste'),
            ])->assertOk();

        $resp = $this->get(route('engenharia.linha.arquivo.ver', [$header, $linha]));
        $resp->assertOk()->assertDownload('Desenho-Rolo-O200.pdf');
        $this->assertSame('%PDF-1.4 teste', $resp->streamedContent());
        $this->assertInstanceOf(BinaryFileResponse::class, $resp->baseResponse);
    }

    public function test_enviar_inline_com_nome_acentuado(): void
    {
        Storage::fake('local');
        AnexoService::disco()->put('cotacoes/1/x_Especificação.pdf', '%PDF');

        $resp = AnexoService::enviar('cotacoes/1/x_Especificação.pdf', 'Especificação.pdf', baixar: false);

        $disposicao = $resp->headers->get('Content-Disposition');
        $this->assertStringStartsWith('inline;', $disposicao);
        $this->assertStringContainsString('filename=Especificacao.pdf', $disposicao);
    }
}
