<?php

namespace Database\Seeders;

use App\Models\CategoriaComponente;
use App\Models\Cliente;
use App\Models\ClienteUnidade;
use App\Models\ComponenteComercial;
use App\Models\Escopo;
use App\Models\Material;
use App\Models\Perfil;
use App\Models\ProcessoFabricacao;
use App\Models\StatusEngenharia;
use App\Models\TipoComponente;
use App\Models\UnidadeMedida;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ----- Perfis e permissoes por modulo -----
        $perfis = [
            'Administrador' => ['cotacao' => 'editar', 'liberacao' => 'editar', 'demandas' => 'editar', 'engenharia' => 'editar', 'admin' => 'editar'],
            'Engenharia' => ['cotacao' => 'ver', 'liberacao' => 'ver', 'demandas' => 'editar', 'engenharia' => 'editar', 'admin' => 'ver'],
            'Comercial' => ['cotacao' => 'editar', 'liberacao' => 'editar', 'demandas' => 'ver', 'engenharia' => 'ver'],
            'Consulta' => ['cotacao' => 'ver', 'liberacao' => 'ver', 'demandas' => 'ver', 'engenharia' => 'ver'],
        ];
        $perfilModels = [];
        foreach ($perfis as $nome => $permissoes) {
            $perfilModels[$nome] = Perfil::firstOrCreate(['nome' => $nome], ['permissoes' => $permissoes]);
        }

        // ----- Usuarios de teste (um por perfil) -----
        $usuarios = [
            ['name' => 'Admin MMV', 'email' => 'admin@mmv.com', 'perfil' => 'Administrador'],
            ['name' => 'Eng. Carlos', 'email' => 'engenharia@mmv.com', 'perfil' => 'Engenharia'],
            ['name' => 'Com. Ana', 'email' => 'comercial@mmv.com', 'perfil' => 'Comercial'],
            ['name' => 'Visitante', 'email' => 'consulta@mmv.com', 'perfil' => 'Consulta'],
        ];
        foreach ($usuarios as $u) {
            User::firstOrCreate(
                ['email' => $u['email']],
                ['name' => $u['name'], 'password' => Hash::make('password'), 'perfil_id' => $perfilModels[$u['perfil']]->id, 'ativo' => true]
            );
        }

        // ----- Status de engenharia -----
        $status = [
            ['nome' => 'A iniciar', 'cor_hex' => '#9ca3af', 'ordem' => 1],
            ['nome' => 'Aguardando', 'cor_hex' => '#f59e0b', 'ordem' => 2],
            ['nome' => 'Em andamento', 'cor_hex' => '#3b82f6', 'ordem' => 3],
            ['nome' => 'Finalizado', 'cor_hex' => '#10b981', 'ordem' => 4],
        ];
        foreach ($status as $s) {
            StatusEngenharia::firstOrCreate(['nome' => $s['nome']], $s);
        }

        // ----- Unidades de medida -----
        foreach (['KG' => 'Quilograma', 'PC' => 'Peca', 'LOTE' => 'Lote', 'M' => 'Metro', 'UN' => 'Unidade', 'M2' => 'Metro quadrado'] as $sigla => $desc) {
            UnidadeMedida::firstOrCreate(['sigla' => $sigla], ['descricao' => $desc]);
        }

        // ----- Escopos -----
        foreach ([['REC', 'Recuperacao'], ['FAB', 'Fabricacao'], ['MAN', 'Manutencao']] as [$cod, $desc]) {
            Escopo::firstOrCreate(['descricao' => $desc], ['codigo' => $cod]);
        }

        // ----- Clientes e suas unidades (amostra) -----
        // Um cliente pode ter varias unidades (ex.: Suzano SA -> Tres Lagoas e Ribas do Rio Pardo).
        $clientes = [
            ['Vale S.A.', 'PA-001', ['Carajas']],
            ['Gerdau', 'PA-002', ['Ouro Branco']],
            ['CSN', 'PA-003', ['Volta Redonda']],
            ['Suzano SA', 'PA-004', ['Tres Lagoas', 'Ribas do Rio Pardo']],
            ['CMPC', 'PA-005', ['Guaiba']],
        ];
        foreach ($clientes as [$nome, $pa, $unidades]) {
            $cliente = Cliente::firstOrCreate(['nome' => $nome], ['codigo_pa' => $pa]);
            foreach ($unidades as $unidade) {
                ClienteUnidade::firstOrCreate(['cliente_id' => $cliente->id, 'nome' => $unidade], ['ativo' => true]);
            }
        }

        // ----- Hierarquia de componentes (categoria -> tipo -> material) -----
        $catMP = CategoriaComponente::firstOrCreate(['nome' => 'Aco', 'tipo' => 'materia_prima']);
        $catServ = CategoriaComponente::firstOrCreate(['nome' => 'Usinagem', 'tipo' => 'servico']);
        $catCom = CategoriaComponente::firstOrCreate(['nome' => 'Fixadores', 'tipo' => 'comercial']);

        $tipoChapa = TipoComponente::firstOrCreate(['categoria_id' => $catMP->id, 'nome' => 'Chapa']);
        $tipoBarra = TipoComponente::firstOrCreate(['categoria_id' => $catMP->id, 'nome' => 'Barra Redonda']);
        TipoComponente::firstOrCreate(['categoria_id' => $catServ->id, 'nome' => 'Torneamento']);

        // Dimensoes em texto livre: chapa mede largura x comprimento, barra mede diametro x comprimento.
        foreach (['ASTM A36', 'SAE 1045', 'ASTM A572 Gr.50'] as $desc) {
            Material::firstOrCreate(
                ['tipo_id' => $tipoChapa->id, 'descricao' => $desc],
                ['norma' => $desc, 'dimensoes' => '1.200 × 6.000']
            );
        }
        Material::firstOrCreate(
            ['tipo_id' => $tipoBarra->id, 'descricao' => 'SAE 4140'],
            ['norma' => 'SAE 4140', 'dimensoes' => 'Ø 50 × 6.000']
        );

        // ----- Componentes comerciais (amostra) -----
        ComponenteComercial::firstOrCreate(['especificacao' => 'Parafuso M12 x 40'], ['categoria_id' => $catCom->id, 'tipo' => 'Parafuso', 'padrao' => 'DIN 933']);
        ComponenteComercial::firstOrCreate(['especificacao' => 'Rolamento 6205'], ['categoria_id' => $catCom->id, 'tipo' => 'Rolamento', 'padrao' => 'SKF']);

        // ----- Processos de fabricacao (amostra 4 niveis) -----
        ProcessoFabricacao::firstOrCreate(
            ['macro_processo' => 'Usinagem', 'processo' => 'Torneamento', 'sub_processo' => 'Desbaste'],
            ['descricao_servico' => 'Torneamento de desbaste externo']
        );
    }
}
