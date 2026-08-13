<?php

namespace Tests\Feature;

use App\Models\Demanda;
use App\Models\EngenhariaHeader;
use App\Models\EngenhariaLinha;
use App\Models\Liberacao;
use App\Models\LiberacaoItem;
use App\Models\Perfil;
use App\Models\User;
use App\Services\AnexoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cobre os tres caminhos de anexo: linha de engenharia, item de liberacao e PI.
 * Garante que o desenho aceito e gravado no MESMO disco de onde e lido depois,
 * e que o arquivo rejeitado devolve mensagem util em vez de falhar em silencio.
 */
class AnexoUploadTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(array $permissoes): User
    {
        $perfil = Perfil::create(['nome' => 'Teste', 'permissoes' => $permissoes, 'ativo' => true]);

        return User::create([
            'name' => 'Engenheiro',
            'email' => 'eng@mmv.test',
            'password' => 'segredo123',
            'perfil_id' => $perfil->id,
            'ativo' => true,
        ]);
    }

    private function linhaEngenharia(): EngenhariaLinha
    {
        $demanda = Demanda::create(['tipo' => 'liberacao', 'referencia_id' => 1]);
        $header = EngenhariaHeader::create(['demanda_id' => $demanda->id, 'nome_item' => 'Suporte']);

        return EngenhariaLinha::create(['header_id' => $header->id, 'numero_linha' => 1]);
    }

    // ---- Engenharia: arquivo por linha -------------------------------------

    public function test_anexa_desenho_pdf_na_linha_de_engenharia(): void
    {
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));

        $resposta = $this->post(
            "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
            ['arquivo' => UploadedFile::fake()->create('desenho conjunto.pdf', 800, 'application/pdf')]
        );

        $resposta->assertOk()->assertJson(['ok' => true]);

        $linha->refresh();
        $this->assertNotNull($linha->arquivo_path);
        $this->assertStringStartsWith("engenharia/{$linha->header_id}/{$linha->id}/", $linha->arquivo_path);
        // Gravacao e leitura precisam apontar para o mesmo disco.
        Storage::disk(AnexoService::DISCO)->assertExists($linha->arquivo_path);
        // Nome sanitizado: sem espacos, extensao preservada.
        $this->assertSame('desenho-conjunto.pdf', AnexoService::nomeOriginal($linha->arquivo_path));
    }

    public function test_anexa_desenho_dwg_e_step_na_linha_de_engenharia(): void
    {
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));

        foreach (['peca.dwg', 'peca.dxf', 'montagem.step', 'montagem.stp'] as $nome) {
            $this->post(
                "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
                ['arquivo' => UploadedFile::fake()->create($nome, 4096)]
            )->assertOk();
        }

        Storage::disk(AnexoService::DISCO)->assertExists($linha->fresh()->arquivo_path);
    }

    public function test_baixa_o_desenho_recem_anexado(): void
    {
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));

        $this->post(
            "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
            ['arquivo' => UploadedFile::fake()->create('desenho.pdf', 120, 'application/pdf')]
        )->assertOk();

        $this->get("/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo")->assertOk();
    }

    public function test_rejeita_extensao_nao_suportada_com_mensagem_explicita(): void
    {
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));

        $resposta = $this->postJson(
            "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
            ['arquivo' => UploadedFile::fake()->create('macro.exe', 10)]
        );

        $resposta->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->assertStringContainsString(
            'PDF, DWG',
            $resposta->json('errors.arquivo.0'),
            'A mensagem precisa listar as extensões aceitas.'
        );
        $this->assertNull($linha->fresh()->arquivo_path);
    }

    public function test_rejeita_arquivo_acima_do_limite_com_mensagem_explicita(): void
    {
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));

        $resposta = $this->postJson(
            "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
            ['arquivo' => UploadedFile::fake()->create('gigante.pdf', AnexoService::MAX_KB + 1024, 'application/pdf')]
        );

        $resposta->assertStatus(422)->assertJsonValidationErrors('arquivo');
        $this->assertStringContainsString(
            (string) AnexoService::limiteMb(),
            $resposta->json('errors.arquivo.0'),
            'A mensagem precisa dizer qual e o limite.'
        );
        $this->assertNull($linha->fresh()->arquivo_path);
    }

    public function test_desenho_de_25mb_e_aceito(): void
    {
        // Regressao: o limite antigo (20 MB) barrava desenho tecnico comum.
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));

        $this->post(
            "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
            ['arquivo' => UploadedFile::fake()->create('conjunto.step', 25 * 1024)]
        )->assertOk();

        $this->assertNotNull($linha->fresh()->arquivo_path);
    }

    public function test_substituir_anexo_remove_o_arquivo_anterior(): void
    {
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));
        $rota = "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo";

        $this->post($rota, ['arquivo' => UploadedFile::fake()->create('v1.pdf', 50, 'application/pdf')])->assertOk();
        $primeiro = $linha->fresh()->arquivo_path;

        $this->post($rota, ['arquivo' => UploadedFile::fake()->create('v2.pdf', 50, 'application/pdf')])->assertOk();
        $segundo = $linha->fresh()->arquivo_path;

        $this->assertNotSame($primeiro, $segundo);
        Storage::disk(AnexoService::DISCO)->assertMissing($primeiro);
        Storage::disk(AnexoService::DISCO)->assertExists($segundo);
    }

    public function test_usuario_sem_permissao_de_edicao_nao_anexa(): void
    {
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'ver']));

        $this->postJson(
            "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
            ['arquivo' => UploadedFile::fake()->create('desenho.pdf', 50, 'application/pdf')]
        )->assertForbidden();
    }

    // ---- Liberacao: anexo por item e anexo do PI ---------------------------

    public function test_anexa_desenho_no_item_da_liberacao(): void
    {
        Storage::fake(AnexoService::DISCO);
        $this->actingAs($this->usuario(['liberacao' => 'editar']));

        $liberacao = Liberacao::create(['numero_pi' => 'PI-001']);
        $item = $liberacao->itens()->create(['numero_item' => 1, 'descricao' => 'Base', 'quantidade' => 1]);

        $this->post(
            "/liberacao/{$liberacao->id}/item/{$item->id}/anexo",
            ['arquivo' => UploadedFile::fake()->create('base.dwg', 2048)]
        )->assertRedirect();

        $anexo = $item->anexos()->firstOrFail();
        Storage::disk(AnexoService::DISCO)->assertExists($anexo->path);
        $this->assertSame('base.dwg', $anexo->nome_arquivo);
    }

    public function test_rejeita_anexo_invalido_no_item_e_devolve_erro_de_validacao(): void
    {
        Storage::fake(AnexoService::DISCO);
        $this->actingAs($this->usuario(['liberacao' => 'editar']));

        $liberacao = Liberacao::create(['numero_pi' => 'PI-002']);
        $item = $liberacao->itens()->create(['numero_item' => 1, 'descricao' => 'Base', 'quantidade' => 1]);

        $this->from("/liberacao/{$liberacao->id}")
            ->post(
                "/liberacao/{$liberacao->id}/item/{$item->id}/anexo",
                ['arquivo' => UploadedFile::fake()->create('planilha.xlsx', 10)]
            )
            ->assertRedirect("/liberacao/{$liberacao->id}")
            ->assertSessionHasErrors('arquivo');

        $this->assertSame(0, $item->anexos()->count());
    }

    public function test_anexa_desenho_no_pi(): void
    {
        Storage::fake(AnexoService::DISCO);
        $this->actingAs($this->usuario(['liberacao' => 'editar']));

        $liberacao = Liberacao::create(['numero_pi' => 'PI-003']);

        $this->post(
            "/liberacao/{$liberacao->id}/anexo",
            ['arquivo' => UploadedFile::fake()->create('geral.pdf', 300, 'application/pdf')]
        )->assertRedirect();

        $anexo = $liberacao->anexos()->firstOrFail();
        Storage::disk(AnexoService::DISCO)->assertExists($anexo->path);
        $this->get("/liberacao/{$liberacao->id}/anexo/{$anexo->id}")->assertOk();
    }

    // ---- Regra de disco ----------------------------------------------------

    public function test_disco_de_anexos_aponta_para_storage_app_private(): void
    {
        // Regressao Laravel 11+: o root do disco local mudou para app/private.
        // Gravacao e leitura tem de usar o mesmo disco explicito.
        $this->assertSame('local', AnexoService::DISCO);
        $this->assertSame(storage_path('app/private'), config('filesystems.disks.local.root'));
    }

    // ---- Formato do erro devolvido ao front ---------------------------------

    public function test_erro_de_upload_via_xhr_volta_em_json_e_nao_em_redirect_html(): void
    {
        // Causa raiz do "Erro ao anexar desenhos": bootstrap/app.php restringia o
        // shouldRenderJsonWhen a "api/*", entao a ValidationException virava 302 + HTML.
        // O fetch seguia o redirect, recebia 200 de outra pagina e o front nunca via o motivo.
        Storage::fake(AnexoService::DISCO);
        $linha = $this->linhaEngenharia();
        $this->actingAs($this->usuario(['engenharia' => 'editar']));

        $resposta = $this->post(
            "/engenharia/{$linha->header_id}/linha/{$linha->id}/arquivo",
            ['arquivo' => UploadedFile::fake()->create('macro.exe', 10)],
            ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']
        );

        $resposta->assertStatus(422);
        $this->assertStringContainsString('application/json', (string) $resposta->headers->get('Content-Type'));
        $this->assertNull($resposta->headers->get('Location'));
    }

    public function test_formulario_html_de_liberacao_continua_redirecionando_com_erros_na_sessao(): void
    {
        // Contrapartida do teste acima: sem Accept JSON o comportamento de formulario
        // (redirect + withErrors) tem de continuar igual.
        Storage::fake(AnexoService::DISCO);
        $this->actingAs($this->usuario(['liberacao' => 'editar']));

        $liberacao = Liberacao::create(['numero_pi' => 'PI-004']);

        $this->from("/liberacao/{$liberacao->id}")
            ->post(
                "/liberacao/{$liberacao->id}/anexo",
                ['arquivo' => UploadedFile::fake()->create('macro.exe', 10)]
            )
            ->assertRedirect("/liberacao/{$liberacao->id}")
            ->assertSessionHasErrors('arquivo');
    }
}
