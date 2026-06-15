@extends('layouts.app')
@section('title', 'Manual de Uso')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

    {{-- Indice lateral --}}
    <div class="lg:col-span-1">
        <x-card title="Conteúdo">
            <nav class="text-sm space-y-1">
                <a href="#visao-geral" class="block text-accent-700 hover:underline">1. Visão geral</a>
                <a href="#perfis" class="block text-accent-700 hover:underline">2. Perfis e acesso</a>
                <a href="#fluxo" class="block text-accent-700 hover:underline">3. Fluxo do sistema</a>
                <a href="#cotacao" class="block text-accent-700 hover:underline">4. Cotações</a>
                <a href="#liberacao" class="block text-accent-700 hover:underline">5. Liberações (PI)</a>
                <a href="#demandas" class="block text-accent-700 hover:underline">6. Controle de Demandas</a>
                <a href="#engenharia" class="block text-accent-700 hover:underline">7. Engenharia</a>
                <a href="#output" class="block text-accent-700 hover:underline">8. Output (PDF)</a>
                <a href="#admin" class="block text-accent-700 hover:underline">9. Cadastros (Admin)</a>
                <a href="#tempo-real" class="block text-accent-700 hover:underline">10. Tempo real</a>
                <a href="#dicas" class="block text-accent-700 hover:underline">11. Dúvidas frequentes</a>
            </nav>
        </x-card>
    </div>

    {{-- Conteudo do manual --}}
    <div class="lg:col-span-3 space-y-6">

        <x-card>
            <h2 class="text-xl font-bold text-mmv-700">Manual de Uso — Sistema MMV</h2>
            <p class="text-sm text-gray-600 mt-2">
                Este guia descreve cada tela e o passo a passo do fluxo de trabalho, desde a cotação até a
                geração do documento de processo de produção (PI). Use o índice ao lado para navegar.
            </p>
        </x-card>

        <x-card id="visao-geral" title="1. Visão geral">
            <p class="text-sm text-gray-700">
                O sistema controla o caminho de um pedido dentro da MMV. Ele substitui as planilhas e organiza
                tudo em cinco áreas, que aparecem no menu lateral:
            </p>
            <ul class="text-sm text-gray-700 list-disc list-inside mt-3 space-y-1">
                <li><b>Cotações</b> — registro de orçamentos pedidos pelo cliente.</li>
                <li><b>Liberações (PI)</b> — pedidos confirmados que entram em produção.</li>
                <li><b>Controle de Demandas</b> — fila central de tudo que precisa passar pela engenharia.</li>
                <li><b>Engenharia</b> — detalhamento técnico de cada item (matéria-prima, mão de obra, etc.).</li>
                <li><b>Output (PDF)</b> — geração do documento final do processo de produção.</li>
                <li><b>Cadastros (Admin)</b> — tabelas de apoio (clientes, materiais, status…).</li>
            </ul>
        </x-card>

        <x-card id="perfis" title="2. Perfis e acesso">
            <p class="text-sm text-gray-700">Cada usuário tem um perfil que define o que pode ver ou editar:</p>
            <div class="overflow-x-auto mt-3">
                <table class="min-w-full text-sm">
                    <thead><tr class="text-left text-gray-500 border-b">
                        <th class="py-2 pr-4">Perfil</th><th class="py-2 pr-4">Cotação</th><th class="py-2 pr-4">Liberação</th>
                        <th class="py-2 pr-4">Demandas</th><th class="py-2 pr-4">Engenharia</th><th class="py-2 pr-4">Admin</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        <tr><td class="py-2 pr-4 font-medium">Administrador</td><td>Editar</td><td>Editar</td><td>Editar</td><td>Editar</td><td>Editar</td></tr>
                        <tr><td class="py-2 pr-4 font-medium">Engenharia</td><td>Ver</td><td>Ver</td><td>Editar</td><td>Editar</td><td>Ver</td></tr>
                        <tr><td class="py-2 pr-4 font-medium">Comercial</td><td>Editar</td><td>Editar</td><td>Ver</td><td>Ver</td><td>—</td></tr>
                        <tr><td class="py-2 pr-4 font-medium">Consulta</td><td>Ver</td><td>Ver</td><td>Ver</td><td>Ver</td><td>—</td></tr>
                    </tbody>
                </table>
            </div>
            <p class="text-sm text-gray-500 mt-3">
                Quem só tem permissão de <b>Ver</b> não enxerga os botões de criar/editar/excluir naquela tela.
                Para sair do sistema, use <b>Sair</b> no canto superior direito.
            </p>
        </x-card>

        <x-card id="fluxo" title="3. Fluxo do sistema (passo a passo)">
            <ol class="text-sm text-gray-700 list-decimal list-inside space-y-2">
                <li>O <b>Comercial</b> registra uma <b>Cotação</b> (opcional) ou já cria uma <b>Liberação (PI)</b> quando o pedido é confirmado.</li>
                <li>Ao salvar uma cotação ou PI, o sistema <b>gera automaticamente uma Demanda</b> no Controle de Demandas.</li>
                <li>No <b>Controle de Demandas</b>, alguém da Engenharia/Admin <b>aloca um responsável</b>. Isso cria, para cada item do pedido, um registro em <b>Engenharia</b>.</li>
                <li>Na <b>Engenharia</b>, o responsável <b>detalha cada item</b> em linhas (matéria-prima, serviços, componentes) e pode montar o <b>Gantt</b> das etapas.</li>
                <li>Quando termina, clica em <b>Finalizar Item</b>. Quando todos os itens do pedido estão finalizados, a Demanda vira <b>“Finalizado”</b> sozinha.</li>
                <li>A qualquer momento é possível <b>gerar o PDF (Output)</b> do processo de produção.</li>
            </ol>
            <p class="text-sm text-gray-500 mt-3">Resumo: <b>Cotação/PI → Demanda → Alocação → Engenharia → Finalizar → PDF</b>.</p>
        </x-card>

        <x-card id="cotacao" title="4. Cotações">
            <p class="text-sm text-gray-700 mb-2"><b>Onde:</b> menu → Cotações.</p>
            <ul class="text-sm text-gray-700 list-disc list-inside space-y-1">
                <li><b>Listagem:</b> mostra todas as cotações. Filtre por cliente ou busque pelo número.</li>
                <li><b>Nova Cotação:</b> preencha os dados do cabeçalho (cliente, escopo, datas, observações).</li>
                <li><b>Itens:</b> clique em <b>+ Adicionar Item</b> para incluir linhas. Cada item tem código MMV, NI, descrição, quantidade, unidade e material do cliente. Use o <b>×</b> para remover.</li>
                <li><b>Busca por NI:</b> ao sair do campo <b>NI</b> de um item, abre um modal com o histórico daquela peça em cotações/liberações anteriores.</li>
                <li><b>Anexos:</b> na tela de visualização (Ver), envie arquivos (PDF, e-mail, imagens).</li>
                <li><b>Ao salvar:</b> é criada automaticamente uma demanda do tipo “cotação”.</li>
            </ul>
        </x-card>

        <x-card id="liberacao" title="5. Liberações (PI)">
            <p class="text-sm text-gray-700 mb-2"><b>Onde:</b> menu → Liberações (PI).</p>
            <ul class="text-sm text-gray-700 list-disc list-inside space-y-1">
                <li><b>Novo PI:</b> informe número do PI/PC, cliente, escopo e datas.</li>
                <li><b>Itens:</b> além dos campos do item, cada um tem o <b>prazo de entrega (dias)</b>.</li>
                <li><b>Prazo do cabeçalho:</b> é calculado <b>automaticamente</b> como o maior prazo entre todos os itens.</li>
                <li><b>Anexos:</b> na tela de visualização há anexo <b>geral do PI</b> e anexo <b>por item</b> (ex.: desenho técnico do cliente).</li>
                <li><b>Ao salvar:</b> gera automaticamente uma demanda do tipo “liberação” com status <b>Aguardando</b>.</li>
            </ul>
        </x-card>

        <x-card id="demandas" title="6. Controle de Demandas">
            <p class="text-sm text-gray-700 mb-2"><b>Onde:</b> menu → Controle de Demandas. É a tela central do dia a dia.</p>
            <ul class="text-sm text-gray-700 list-disc list-inside space-y-1">
                <li><b>Grade:</b> lista todas as demandas com data de entrada, tipo, nº de referência, qtd. de itens, prazos, cliente, responsável, escopo, entrega da engenharia e status.</li>
                <li><b>Prazo Cotação:</b> dias entre a <b>data da cotação</b> e o <b>prazo de resposta</b>. Só aparece em demandas do tipo “cotação”; em PIs mostra “—”.</li>
                <li><b>Prazo Fabricação:</b> tempo estimado de produção em <b>dias úteis</b>, calculado pelo Gantt — é a duração do <b>item que demora mais</b> (caminho crítico). <b>Só aparece depois da alocação</b>, quando os itens já existem na Engenharia e têm etapas com duração; antes disso mostra “—”.</li>
                <li><b>Nova Demanda:</b> o botão no topo abre as duas origens possíveis — <b>Nova Cotação</b> ou <b>Nova Liberação (PI)</b>. A demanda nasce sempre a partir de uma delas (não se cria demanda “solta”).</li>
                <li><b>Expandir:</b> clique na seta (▸) à esquerda da linha para ver os itens daquele pedido.</li>
                <li><b>Filtros:</b> por responsável, status, tipo, ou busca pelo número.</li>
                <li><b>Alocar:</b> botão <b>Alocar</b> abre um modal para escolher o responsável. Ao confirmar, o sistema gera um registro de engenharia para <b>cada item</b> e muda o status para “Em andamento”.</li>
                <li><b>Atualização sozinha:</b> a tela reflete em <b>tempo real</b> mudanças feitas por outras pessoas (veja a seção 10).</li>
                <li><b>Perfil Consulta:</b> vê a grade, mas sem botões de ação.</li>
            </ul>
        </x-card>

        <x-card id="engenharia" title="7. Engenharia">
            <p class="text-sm text-gray-700 mb-2"><b>Onde:</b> menu → Engenharia. Lista os itens alocados (Engenharia vê os seus; Admin vê todos).</p>
            <ul class="text-sm text-gray-700 list-disc list-inside space-y-1">
                <li><b>Detalhar:</b> abre o item com seu cabeçalho, status e a lista de linhas.</li>
                <li><b>Adicionar/Editar Linha:</b> formulário com os <b>dropdowns encadeados</b> — escolha o <b>Componente</b> (matéria-prima / serviço / comercial) e o sistema carrega <b>Categoria → Tipo → Material</b> automaticamente. Preencha descrição, mão de obra, quantidade e fase.</li>
                <li><b>Duração (dias úteis):</b> cada linha tem o tempo que aquela etapa leva, em dias úteis (padrão 2). É esse valor que define o tamanho da barra no Gantt e alimenta o <b>Prazo de Fabricação</b> da demanda.</li>
                <li><b>Dependências:</b> no campo “Dependências” informe os números de outras linhas (ex.: <code>2,3</code>) que precisam ser feitas antes. A etapa só começa quando as suas dependências terminam.</li>
                <li><b>Arquivo da linha:</b> na coluna <b>Arquivo</b>, use <b>+ Anexar</b> para enviar um arquivo àquela etapa (ex.: desenho). Depois ele vira um link <b>📎</b> que abre em nova aba (preview) e tem o <b>✕</b> para remover.</li>
                <li><b>Editar/Excluir:</b> botões em cada linha da lista. A lista atualiza em tempo real.</li>
                <li><b>Gantt:</b> botão <b>📊 Gantt</b> mostra a sequência das etapas em um cronograma, respeitando as dependências e a duração de cada linha. O agendamento usa <b>dias úteis</b> (pula fins de semana; feriados não são considerados).</li>
                <li><b>Finalizar Item:</b> marca o item como concluído. Quando todos os itens do pedido estão finalizados, a demanda correspondente vira “Finalizado” automaticamente.</li>
            </ul>
        </x-card>

        <x-card id="output" title="8. Output — Documento PI (PDF)">
            <p class="text-sm text-gray-700 mb-2"><b>Onde:</b> dentro de um item de Engenharia, botões no topo.</p>
            <ul class="text-sm text-gray-700 list-disc list-inside space-y-1">
                <li><b>👁 Preview PI:</b> visualiza o documento em tela antes de gerar o arquivo.</li>
                <li><b>Gerar PDF:</b> cria o arquivo e guarda no histórico (mantém versões).</li>
                <li><b>📄 PDFs:</b> abre o histórico de PDFs gerados, com data e autor, e permite baixar.</li>
                <li>O documento é organizado em seções: <b>Matéria-prima</b>, <b>Mão de obra</b>, <b>Componentes comerciais</b> e <b>Insumos</b> — montadas a partir das linhas detalhadas na Engenharia.</li>
            </ul>
        </x-card>

        <x-card id="admin" title="9. Cadastros (Admin)">
            <p class="text-sm text-gray-700 mb-2"><b>Onde:</b> menu → Cadastros (Admin). Disponível para Admin (editar) e Engenharia (ver).</p>
            <ul class="text-sm text-gray-700 list-disc list-inside space-y-1">
                <li>Telas simples de cadastro para as tabelas de apoio: <b>Clientes, Escopos, Unidades, Categorias/Tipos/Materiais, Componentes comerciais, Processos, Status de engenharia e Perfis</b>.</li>
                <li>Todas seguem o mesmo padrão: lista com <b>+ Novo</b>, e em cada linha <b>Editar</b> e <b>Excluir</b>.</li>
                <li>A hierarquia <b>Categoria → Tipo → Material</b> é o que alimenta os dropdowns encadeados da Engenharia. Mantê-la correta deixa o detalhamento mais rápido.</li>
                <li><b>Status de engenharia</b> tem uma cor — ela aparece como etiqueta colorida nas telas.</li>
            </ul>
        </x-card>

        <x-card id="tempo-real" title="10. Atualização em tempo real">
            <p class="text-sm text-gray-700">
                As telas de <b>Controle de Demandas</b> e <b>Engenharia</b> atualizam sozinhas: se outra pessoa
                alocar, mudar um status ou editar uma linha, sua tela reflete a mudança <b>sem precisar recarregar</b>.
                Não é preciso ficar apertando F5. Se a conexão em tempo real estiver indisponível, o sistema continua
                funcionando normalmente — basta recarregar a página para ver as últimas mudanças.
            </p>
        </x-card>

        <x-card id="dicas" title="11. Dúvidas frequentes">
            <dl class="text-sm text-gray-700 space-y-3">
                <div><dt class="font-medium">Não vejo o botão de criar/editar.</dt>
                    <dd class="text-gray-600">Seu perfil provavelmente é “Ver/Consulta” naquela área. Fale com um Administrador.</dd></div>
                <div><dt class="font-medium">Criei um PI mas ele não aparece na Engenharia.</dt>
                    <dd class="text-gray-600">É preciso primeiro <b>Alocar</b> a demanda no Controle de Demandas. A alocação é que cria os itens na Engenharia.</dd></div>
                <div><dt class="font-medium">O prazo do PI veio diferente do que digitei.</dt>
                    <dd class="text-gray-600">O prazo do cabeçalho é o <b>maior</b> prazo entre os itens — é calculado automaticamente.</dd></div>
                <div><dt class="font-medium">A coluna “Prazo Fabricação” está vazia (“—”).</dt>
                    <dd class="text-gray-600">Esse prazo vem do Gantt, que só existe <b>depois de alocar</b> a demanda. Aloque um responsável e detalhe as etapas (com a sua duração) na Engenharia; o prazo aparece automaticamente como a duração do item mais demorado.</dd></div>
                <div><dt class="font-medium">A demanda não vira “Finalizado”.</dt>
                    <dd class="text-gray-600">Todos os itens daquele pedido precisam estar finalizados na Engenharia.</dd></div>
                <div><dt class="font-medium">O material não aparece na lista ao detalhar.</dt>
                    <dd class="text-gray-600">Verifique em Cadastros se o Material está vinculado ao Tipo/Categoria certos.</dd></div>
            </dl>
        </x-card>

    </div>
</div>
@endsection
