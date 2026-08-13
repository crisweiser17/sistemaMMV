<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Migracao automatica no login
    |--------------------------------------------------------------------------
    |
    | Em hospedagem compartilhada nao ha acesso a linha de comando, entao um
    | deploy que sobe arquivos novos deixa o banco desatualizado e a aplicacao
    | quebra com erro 500 ate alguem rodar as migrations.
    |
    | Com isto ligado, o login de um usuario com perfil Administrador aplica as
    | migrations pendentes automaticamente. Nao roda para os demais perfis.
    |
    | Desligue com MIGRACAO_AUTOMATICA_NO_LOGIN=false quando houver CLI
    | disponivel e o deploy passar a rodar `php artisan migrate` sozinho.
    |
    */

    'migracao_automatica_no_login' => (bool) env('MIGRACAO_AUTOMATICA_NO_LOGIN', true),

    /*
    | Perfil autorizado a disparar a migracao automatica.
    */

    'perfil_migracao' => env('MMV_PERFIL_MIGRACAO', 'Administrador'),

];
