<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginLog;
use App\Services\MigracaoAutomaticaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

class LoginController extends Controller
{
    public function __construct(private MigracaoAutomaticaService $migracao) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // Apenas usuarios ativos podem autenticar.
        $credentials['ativo'] = true;

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withInput($request->only('email'))
                ->with('error', 'Credenciais invalidas ou usuario inativo.');
        }

        $request->session()->regenerate();

        $destino = redirect()->intended(route('demandas.index'));

        // Hospedagem compartilhada nao tem CLI: o administrador que entra aplica
        // as migrations pendentes do deploy. Falha aqui nao impede o login.
        //
        // Vem ANTES do LoginLog de proposito: se um deploy futuro mexer em
        // login_logs, gravar o log falharia com o schema velho e o administrador
        // nunca chegaria na migracao que conserta justamente isso.
        if ($this->migracao->deveExecutar($request->user())) {
            $resultado = $this->migracao->executar();
            $destino->with($resultado['ok'] ? 'success' : 'error', $resultado['mensagem']);

            // Qual banco o servidor usa e onde ficou o backup. Vai em chave
            // separada para o toast poder mostrar como segunda linha, sem
            // espremer tudo numa frase que some da tela em segundos.
            if ($resultado['detalhe'] !== '') {
                $destino->with('toast_detalhe', $resultado['detalhe']);
            }
        }

        $this->registrarLog($request, 'login');

        return $destino;
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->registrarLog($request, 'logout');

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Registra o evento de autenticacao sem nunca derrubar o fluxo.
     *
     * Auditoria e importante, mas nao ao ponto de trancar o cliente para fora do
     * sistema quando a tabela `login_logs` estiver defasada por um deploy.
     */
    private function registrarLog(Request $request, string $evento): void
    {
        try {
            LoginLog::create([
                'user_id' => Auth::id(),
                'evento' => $evento,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Nao foi possivel registrar o evento de '.$evento.': '.$e->getMessage());
        }
    }
}
