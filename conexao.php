<?php

// Configurações do banco de dados
define('DB_HOST', 'localhost');
define('DB_NAME', 'agencia');
define('DB_USER', 'agencia');
define('DB_PASS', '8VLI^Fh1-(g;');

// Conexão com PDO
try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,        // Lança exceções em caso de erro
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,     // Retorna resultados como objetos
            PDO::ATTR_EMULATE_PREPARES => false,                // Desabilita a emulação de prepared statements (melhor segurança)
            PDO::ATTR_PERSISTENT => false                       // Conexões não persistentes
        ]
    );
} catch (PDOException $e) {
    // Registra o erro no log do servidor para depuração
    error_log("Erro na conexão com o banco de dados: " . $e->getMessage());
    // Exibe uma mensagem genérica para o usuário por segurança
    die("Erro no sistema. Por favor, tente novamente mais tarde.");
}

// Função para gerar token seguro (se ainda não estiver definida)
// É uma boa prática ter funções auxiliares em um arquivo separado, mas para garantir
// a funcionalidade, mantemos aqui se não houver outra definição.
if (!function_exists('gerarToken')) {
    function gerarToken($tamanho = 32) {
        return bin2hex(random_bytes($tamanho));
    }
}

// Função para verificar autenticação em todas as páginas (exceto login)
function verificarAutenticacao() {
    // Acessa a variável global $pdo para usar a conexão com o banco de dados
    global $pdo;

    // Inicia a sessão se ainda não estiver iniciada.
    // Isso é crucial para que $_SESSION seja populada e verificada.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Verifica se a página atual NÃO é 'login.php'.
    // A lógica de autenticação só deve ser aplicada a páginas protegidas.
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        // Se o usuário não estiver logado via sessão
        if (!isset($_SESSION['usuario_id'])) {
            // Tenta verificar se há cookies de "Lembrar de mim"
            if (isset($_COOKIE['lembrar_token']) && isset($_COOKIE['lembrar_id'])) {
                $usuario_id = (int)$_COOKIE['lembrar_id'];
                $token = $_COOKIE['lembrar_token'];

                try {
                    // Prepara a consulta para verificar o token no banco de dados.
                    // CORREÇÃO: Alterado 't.expiracao' para 't.expira_em' para corresponder ao CREATE TABLE.
                    $stmt = $pdo->prepare("
                        SELECT t.*, u.*
                        FROM tokens_autenticacao t
                        JOIN usuarios u ON t.usuario_id = u.id
                        WHERE t.usuario_id = :usuario_id AND t.token = :token AND t.expira_em > NOW()
                    ");
                    $stmt->execute([':usuario_id' => $usuario_id, ':token' => $token]);
                    $token_valido = $stmt->fetch();

                    if ($token_valido) {
                        // Se o token for válido, loga o usuário na sessão
                        $_SESSION['usuario_id'] = $token_valido->usuario_id;
                        $_SESSION['usuario_nome'] = $token_valido->nome;
                        $_SESSION['usuario_nivel'] = $token_valido->nivel;
                        $_SESSION['usuario_email'] = $token_valido->email;

                        // Opcional: Renovar o token para estender a validade
                        // (similar à lógica no login.php, mas pode ser simplificado aqui)
                        /*
                        $novo_token = gerarToken();
                        $nova_expiracao_timestamp = time() + (60 * 60 * 24 * 30); // 30 dias
                        $nova_expiracao_db = date('Y-m-d H:i:s', $nova_expiracao_timestamp);

                        $stmt_update_token = $pdo->prepare("
                            UPDATE tokens_autenticacao
                            SET token = :novo_token, expira_em = :nova_expiracao
                            WHERE usuario_id = :usuario_id AND token = :token_antigo
                        ");
                        $stmt_update_token->execute([
                            ':novo_token' => $novo_token,
                            ':nova_expiracao' => $nova_expiracao_db,
                            ':usuario_id' => $usuario_id,
                            ':token_antigo' => $token
                        ]);

                        $cookie_path = '/'; // Ajuste se sua aplicação estiver em um subdiretório
                        setcookie('lembrar_token', $novo_token, $nova_expiracao_timestamp, $cookie_path, '', false, true);
                        setcookie('lembrar_id', $usuario_id, $nova_expiracao_timestamp, $cookie_path, '', false, true);
                        */
                        return; // Usuário autenticado, sai da função
                    }
                } catch (PDOException $e) {
                    // Erro ao verificar token no banco de dados (ex: tabela não encontrada, problema de conexão)
                    error_log("Erro PDO na verificação de autenticação via cookie: " . $e->getMessage());
                    // Não exibe o erro para o usuário, apenas limpa os cookies para forçar login manual
                    $cookie_path = '/'; // Ajuste se sua aplicação estiver em um subdiretório
                    setcookie('lembrar_token', '', time() - 3600, $cookie_path);
                    setcookie('lembrar_id', '', time() - 3600, $cookie_path);
                }
            }
            // Se não está logado na sessão e não há cookie válido (ou houve erro no cookie),
            // redireciona para a página de login.
            header('Location: login.php');
            exit;
        }
    }
}

// Chamar a verificação de autenticação
// Esta função será executada toda vez que conexao.php for incluído em uma página.
verificarAutenticacao();
?>
