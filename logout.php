<?php
// Inicia o buffer de saída no início do script.
// Isso é uma prática recomendada para evitar o erro "headers already sent" (cabeçalhos já enviados),
// que ocorre quando qualquer saída (mesmo um espaço em branco) é enviada ao navegador antes
// das funções que manipulam cabeçalhos HTTP, como `session_start()` e `header()`.
// Ao usar ob_start(), todo o conteúdo é armazenado em um buffer e enviado de uma vez no final.
ob_start();

// Inclui o arquivo de conexão com o banco de dados.
// É crucial que este caminho esteja correto para que o script possa interagir com o banco de dados.
// Assumimos que 'conexao.php' está dentro do diretório 'inc' em relação a este script 'logout.php'.
require_once 'conexao.php'; // Verifique e ajuste este caminho se necessário.

// Inicia a sessão PHP.
// Esta função deve ser chamada antes de qualquer manipulação de variáveis de sessão.
// A verificação `session_status() === PHP_SESSION_NONE` garante que a sessão só seja iniciada uma vez.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// --- 1. Limpeza Completa da Sessão PHP ---

// Remove todas as variáveis de sessão.
// Isso esvazia o array `$_SESSION`, efetivamente limpando todos os dados
// associados à sessão atual do usuário no servidor.
$_SESSION = array();

// Se o PHP estiver configurado para usar cookies para gerenciar sessões (o que é o padrão),
// este bloco tenta apagar o cookie de sessão do navegador do usuário.
if (ini_get("session.use_cookies")) {
    // Obtém os parâmetros atuais do cookie de sessão (nome, tempo de vida, caminho, domínio, etc.).
    // Usar esses parâmetros é importante para garantir que o cookie correto seja alvo.
    $params = session_get_cookie_params();

    // Define o cookie de sessão com um tempo de expiração no passado.
    // Isso força o navegador a deletar o cookie imediatamente.
    setcookie(
        session_name(),    // O nome do cookie de sessão (ex: 'PHPSESSID')
        '',                // Define o valor do cookie como vazio
        time() - 42000,    // Define o tempo de expiração no passado (há 42000 segundos atrás)
        $params["path"],   // O caminho do cookie (deve ser o mesmo que foi definido ao criar a sessão)
        $params["domain"], // O domínio do cookie
        $params["secure"], // Indica se o cookie só deve ser enviado via HTTPS
        $params["httponly"]// Indica se o cookie só pode ser acessado via HTTP (não JavaScript)
    );
}

// Destrói os dados da sessão no servidor.
// Esta função remove o arquivo de sessão ou o registro da sessão do armazenamento do servidor.
// Após esta chamada, a sessão atual do usuário é completamente encerrada no lado do servidor.
session_destroy();

// --- 2. Limpeza Agressiva de Cookies de "Lembrar de mim" (Remember Me) ---
// O principal motivo para um logout falhar é que os cookies de "Lembrar de mim"
// (que mantêm o usuário logado mesmo após fechar o navegador) não são removidos.
// Isso pode acontecer se o cookie foi definido com um caminho ou domínio diferente do que
// o script de logout está tentando apagar. Esta seção tenta apagar os cookies
// em vários caminhos comuns para garantir a remoção.

// Nomes dos cookies de "Lembrar de mim" que precisam ser apagados.
$remember_me_cookies = ['lembrar_token', 'lembrar_id'];

// Lista de caminhos comuns onde os cookies podem ter sido definidos.
// Adicione aqui quaisquer outros subdiretórios específicos onde sua aplicação
// ou o script de login possa ter definido esses cookies.
$common_paths = [
    '/',                                // A raiz do domínio (o caminho mais comum)
    '/agencia/',                        // Exemplo: se sua aplicação está em /agencia/
    '/gestor/',                         // Exemplo: se sua aplicação está em /gestor/
    $_SERVER['REQUEST_URI'],            // O caminho da URL atual (pode ser útil em alguns casos)
    dirname($_SERVER['PHP_SELF']) . '/' // O diretório do script atual (ex: /gestor/ para logout.php)
];

// Garante que não haja caminhos duplicados na lista e os normaliza.
$unique_paths = array_unique($common_paths);

// Itera sobre todos os caminhos possíveis e tenta apagar cada cookie de "Lembrar de mim".
foreach ($unique_paths as $path) {
    foreach ($remember_me_cookies as $cookie_name) {
        // Verifica se o cookie existe antes de tentar apagá-lo.
        if (isset($_COOKIE[$cookie_name])) {
            // Apaga o cookie definindo seu valor como vazio e sua expiração no passado.
            setcookie($cookie_name, '', time() - 3600, $path);
            // Opcional: Para depuração, você pode descomentar a linha abaixo para logar
            // as tentativas de exclusão de cookies.
            // error_log("Tentando apagar cookie: {$cookie_name} no caminho: {$path}");
        }
    }
}

// --- 3. Exclusão do Token de Autenticação Persistente do Banco de Dados ---
// Se o sistema usa um token persistente (como "Lembrar de mim") armazenado no banco de dados,
// é fundamental remover esse token também. Isso invalida o token no servidor,
// mesmo que o cookie no navegador não seja apagado por algum motivo.
if (isset($_COOKIE['lembrar_token']) && isset($_COOKIE['lembrar_id'])) {
    try {
        // Prepara a query SQL para deletar o token da tabela `tokens_autenticacao`.
        // A tabela deve existir e estar acessível para esta operação.
        $stmt = $pdo->prepare("DELETE FROM tokens_autenticacao WHERE usuario_id = ? AND token = ?");
        // Executa a query com os valores do ID do usuário e do token obtidos dos cookies.
        $stmt->execute([$_COOKIE['lembrar_id'], $_COOKIE['lembrar_token']]);
    } catch (PDOException $e) {
        // Em caso de erro no banco de dados (ex: problema de conexão, tabela inacessível),
        // registra o erro no log do servidor. É importante que o processo de logout do lado
        // do cliente (limpeza de sessão e cookies) continue, mesmo que haja um erro no DB.
        error_log("Erro PDO ao deletar token de autenticação no logout: " . $e->getMessage());
        // Você pode adicionar uma mensagem de erro para o usuário aqui se for crítico,
        // mas geralmente para logout, é melhor que o processo finalize sem interrupção visível.
    }
}

// --- 4. Redirecionamento para a Página de Login ---
// Redireciona o navegador do usuário para a página de login após o logout.
// O `header('Location: ...')` deve ser chamado antes de qualquer saída para o navegador.
header('Location: login.php');
// O `exit;` é crucial para garantir que nenhum outro código PHP seja executado após o redirecionamento.
exit;

// Finaliza o buffer de saída e envia todo o conteúdo armazenado e os cabeçalhos HTTP para o navegador.
ob_end_flush();
?>
