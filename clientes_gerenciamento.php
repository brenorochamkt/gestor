<?php
// Garante que a sessão seja iniciada antes de qualquer saída de HTML.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Inclui o arquivo de conexão e as funções de autenticação.
require_once 'conexao.php';

// --- INÍCIO DO BLOCO DE LÓGICA PHP (BACKEND) ---

// Define o fuso horário para garantir que as datas e horas estejam corretas.
date_default_timezone_set('America/Sao_Paulo');

// Obtém dados do usuário logado da sessão.
$usuario_logado_id = $_SESSION['usuario_id'] ?? null;
$usuario_logado_nome = $_SESSION['usuario_nome'] ?? 'Usuário';
$usuario_logado_nivel = $_SESSION['usuario_nivel'] ?? 'editor';

// --- FUNÇÕES AUXILIARES ---

/**
 * Formata uma data (Y-m-d ou Y-m-d H:i:s) para o padrão brasileiro (d/m/Y).
 * @param string|null $dateStr A string de data/timestamp.
 * @return string A data formatada ou 'N/A'.
 */
function formatarDataParaBR($dateStr) {
    if (empty($dateStr) || $dateStr === '0000-00-00' || strpos($dateStr, '0000-00-00') === 0) return 'N/A';
    try {
        return (new DateTime($dateStr))->format('d/m/Y');
    } catch (Exception $e) {
        return 'Data Inválida';
    }
}

/**
 * Cria e envia uma notificação para todos os usuários ativos do sistema.
 * @param PDO $pdo A instância da conexão PDO.
 * @param string $titulo O título da notificação.
 * @param string $mensagem O corpo da mensagem da notificação.
 * @param string $link O link de destino ao clicar na notificação.
 */
function notificarTodosUsuarios($pdo, $titulo, $mensagem, $link) {
    try {
        $stmt_users = $pdo->query("SELECT id FROM usuarios WHERE ativo = 1");
        $usuarios_ids = $stmt_users->fetchAll(PDO::FETCH_COLUMN);
        if (empty($usuarios_ids)) return;
        $sql_notif = "INSERT INTO notificacoes (usuario_id, titulo, mensagem, link) VALUES (:usuario_id, :titulo, :mensagem, :link)";
        $stmt_notif = $pdo->prepare($sql_notif);
        foreach ($usuarios_ids as $id_usuario) {
            $stmt_notif->execute([':usuario_id' => $id_usuario, ':titulo' => $titulo, ':mensagem' => $mensagem, ':link' => $link]);
        }
    } catch (PDOException $e) {
        error_log("Erro ao criar notificações em massa: " . $e->getMessage());
    }
}

/**
 * Obtém todos os dados relevantes de um cliente para os modais (Raio-X, etc.).
 * @param PDO $pdo A instância da conexão PDO.
 * @param int $cliente_id O ID do cliente a ser consultado.
 * @return array Os dados completos do cliente ou um array com uma chave de erro.
 */
function obterDadosCompletosCliente($pdo, $cliente_id) {
    // 1. Dados básicos do cliente
    $stmt_cliente = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
    $stmt_cliente->execute([':id' => $cliente_id]);
    $cliente_data = $stmt_cliente->fetch(PDO::FETCH_ASSOC);
    if (!$cliente_data) return ['error' => 'Cliente não encontrado.'];

    // 2. Resumo de status das demandas
    $stmt_resumo = $pdo->prepare("SELECT status, COUNT(*) as count FROM demandas WHERE cliente_id = :cliente_id GROUP BY status");
    $stmt_resumo->execute([':cliente_id' => $cliente_id]);
    $resumo_raw = $stmt_resumo->fetchAll(PDO::FETCH_KEY_PAIR);
    $status_em_edicao = ['em_andamento', 'necessita_nova_edicao', 'aguardando_material', 'material_com_problema', 'enviado_para_cliente', 'cliente_solicitou_alteracao'];
    $em_edicao_count = 0;
    foreach ($status_em_edicao as $status) $em_edicao_count += $resumo_raw[$status] ?? 0;

    // 3. Marcos e Históricos
    $stmt_marcos = $pdo->prepare("
        SELECT 
            (SELECT MAX(data_captacao) FROM demandas WHERE cliente_id = :id1 AND data_captacao IS NOT NULL AND data_captacao <> '0000-00-00') as ultima_gravacao,
            (SELECT MAX(atualizado_em) FROM demandas WHERE cliente_id = :id2 AND status = 'video_publicado') as ultimo_video_postado
    ");
    $stmt_marcos->execute([':id1' => $cliente_id, ':id2' => $cliente_id]);
    $marcos = $stmt_marcos->fetch(PDO::FETCH_ASSOC);

    $stmt_trabalhos = $pdo->prepare("SELECT d.id, d.titulo, d.data_entrega, d.status, d.prioridade, u.nome as responsavel_nome FROM demandas d LEFT JOIN usuarios u ON d.responsavel_id = u.id WHERE d.cliente_id = :cliente_id ORDER BY d.criado_em DESC");
    $stmt_trabalhos->execute([':cliente_id' => $cliente_id]);
    $historico_trabalhos = $stmt_trabalhos->fetchAll(PDO::FETCH_ASSOC);

    $stmt_captacoes = $pdo->prepare("SELECT id, titulo, data_captacao, local_captacao FROM demandas WHERE cliente_id = :cliente_id AND data_captacao IS NOT NULL AND data_captacao <> '0000-00-00' ORDER BY data_captacao DESC");
    $stmt_captacoes->execute([':cliente_id' => $cliente_id]);
    $historico_captacoes = $stmt_captacoes->fetchAll(PDO::FETCH_ASSOC);

    return array_merge($cliente_data, [
        'resumo_status' => ['total' => array_sum($resumo_raw), 'pendente' => $resumo_raw['pendente'] ?? 0, 'em_edicao' => $em_edicao_count, 'concluido' => ($resumo_raw['concluido'] ?? 0) + ($resumo_raw['cliente_aprovou'] ?? 0), 'publicado' => $resumo_raw['video_publicado'] ?? 0],
        'marcos_importantes' => ['ultima_gravacao' => $marcos['ultima_gravacao'], 'ultimo_video_postado' => $marcos['ultimo_video_postado']],
        'historico_trabalhos' => $historico_trabalhos,
        'historico_captacoes' => $historico_captacoes
    ]);
}

// --- ROTEAMENTO DE REQUISIÇÕES (AJAX E POST) ---

if (isset($_REQUEST['acao'])) {
    // Bloco para Requisições AJAX
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        ob_start();
        $response = ['success' => false, 'error' => 'Ação inválida ou não permitida.'];

        try {
            if (!$usuario_logado_id) throw new Exception('Acesso negado. Por favor, faça o login novamente.');
            $acao_ajax = $_REQUEST['acao'];

            switch($acao_ajax) {
                case 'obter_dados_cliente':
                    $cliente_id_ajax = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
                    if (!$cliente_id_ajax) throw new Exception('ID do cliente inválido.');
                    $dados = obterDadosCompletosCliente($pdo, $cliente_id_ajax);
                    if (isset($dados['error'])) throw new Exception($dados['error']);
                    $response = ['success' => true, 'data' => $dados];
                    break;
                case 'verificar_senha':
                    if($usuario_logado_nivel !== 'gestor') throw new Exception('Permissão negada.');
                    $senha_fornecida = $_POST['senha'] ?? '';
                    if(empty($senha_fornecida)) throw new Exception('A senha é obrigatória.');
                    $stmt_senha = $pdo->prepare("SELECT senha FROM usuarios WHERE id = :id");
                    $stmt_senha->execute([':id' => $usuario_logado_id]);
                    $hash_senha = $stmt_senha->fetchColumn();
                    if ($hash_senha && password_verify($senha_fornecida, $hash_senha)) {
                        $response = ['success' => true];
                    } else {
                        throw new Exception('Senha incorreta.');
                    }
                    break;
            }
        } catch (Throwable $e) {
            $response['error'] = $e->getMessage();
            http_response_code(500);
        }
        ob_end_clean();
        echo json_encode($response);
        exit;
    }

    // Bloco para Submissões de Formulário (POST síncrono)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($usuario_logado_nivel !== 'gestor') {
            $_SESSION['mensagem_erro'] = "Você não tem permissão para realizar esta ação.";
            header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'clientes_gerenciamento.php'));
            exit;
        }
        $acao = $_POST['acao'] ?? '';
        $pdo->beginTransaction();
        try {
            switch ($acao) {
                case 'adicionar_cliente':
                    if (empty($_POST['nome']) || empty($_POST['email'])) throw new Exception("Nome e Email são obrigatórios.");
                    $sql_add = "INSERT INTO clientes (nome, email, cnpj, telefone, endereco, cidade, estado, cep, informacoes_contrato, observacoes, status) VALUES (:nome, :email, :cnpj, :telefone, :endereco, :cidade, :estado, :cep, :informacoes_contrato, :observacoes, :status)";
                    $stmt_add = $pdo->prepare($sql_add);
                    $stmt_add->execute([':nome' => trim($_POST['nome']), ':email' => trim($_POST['email']), ':cnpj' => $_POST['cnpj'] ?: null, ':telefone' => $_POST['telefone'] ?: null, ':endereco' => $_POST['endereco'] ?: null, ':cidade' => $_POST['cidade'] ?: null, ':estado' => $_POST['estado'] ?: null, ':cep' => $_POST['cep'] ?: null, ':informacoes_contrato' => $_POST['informacoes_contrato'] ?: null, ':observacoes' => $_POST['observacoes'] ?: null, ':status' => $_POST['status'] ?? 'ativo']);
                    $_SESSION['mensagem_sucesso'] = "Cliente adicionado com sucesso!";
                    break;
                case 'editar_cliente':
                    $cliente_id_edit = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                    if (!$cliente_id_edit || empty($_POST['nome']) || empty($_POST['email'])) throw new Exception("Dados insuficientes para editar.");
                    $sql_edit = "UPDATE clientes SET nome = :nome, email = :email, cnpj = :cnpj, telefone = :telefone, endereco = :endereco, cidade = :cidade, estado = :estado, cep = :cep, informacoes_contrato = :informacoes_contrato, observacoes = :observacoes, status = :status WHERE id = :id";
                    $stmt_edit = $pdo->prepare($sql_edit);
                    $stmt_edit->execute([':id' => $cliente_id_edit, ':nome' => trim($_POST['nome']), ':email' => trim($_POST['email']), ':cnpj' => $_POST['cnpj'] ?: null, ':telefone' => $_POST['telefone'] ?: null, ':endereco' => $_POST['endereco'] ?: null, ':cidade' => $_POST['cidade'] ?: null, ':estado' => $_POST['estado'] ?: null, ':cep' => $_POST['cep'] ?: null, ':informacoes_contrato' => $_POST['informacoes_contrato'] ?: null, ':observacoes' => $_POST['observacoes'] ?: null, ':status' => $_POST['status'] ?? 'ativo']);
                    $_SESSION['mensagem_sucesso'] = "Cliente atualizado com sucesso!";
                    break;
                case 'toggle_status':
                    $cliente_id_toggle = filter_input(INPUT_POST, 'cliente_id', FILTER_VALIDATE_INT);
                    if (!$cliente_id_toggle) throw new Exception("ID de cliente inválido.");
                    $stmt_status_atual = $pdo->prepare("SELECT status, nome FROM clientes WHERE id = :id");
                    $stmt_status_atual->execute([':id' => $cliente_id_toggle]);
                    $cliente_atual = $stmt_status_atual->fetch(PDO::FETCH_ASSOC);
                    if (!$cliente_atual) throw new Exception("Cliente não encontrado.");
                    $novo_status = ($cliente_atual['status'] === 'ativo') ? 'desativado' : 'ativo';
                    $stmt_update = $pdo->prepare("UPDATE clientes SET status = :status WHERE id = :id");
                    $stmt_update->execute([':status' => $novo_status, ':id' => $cliente_id_toggle]);
                    $_SESSION['mensagem_sucesso'] = "Status do cliente '" . htmlspecialchars($cliente_atual['nome']) . "' alterado para {$novo_status}.";
                    break;
            }
            $pdo->commit();
        } catch (PDOException | Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['mensagem_erro'] = "Erro na operação: " . $e->getMessage();
        }
        header("Location: " . ($_SERVER['HTTP_REFERER'] ?? 'clientes_gerenciamento.php'));
        exit;
    }
}

// --- LÓGICA DE EXIBIÇÃO DA PÁGINA (GET REQUEST) ---
$filtro_status = $_GET['filtro_status'] ?? 'ativo';
$where_clause = ($filtro_status !== 'todos') ? "WHERE c.status = :status" : "";
$params = ($filtro_status !== 'todos') ? [':status' => $filtro_status] : [];

try {
    $sql_select = "
        SELECT 
            c.id, c.nome, c.status,
            (SELECT MAX(data_captacao) FROM demandas WHERE cliente_id = c.id AND data_captacao IS NOT NULL AND data_captacao <> '0000-00-00') as ultima_captacao,
            (SELECT MAX(atualizado_em) FROM demandas WHERE cliente_id = c.id AND status = 'video_publicado') as ultimo_video_publicado
        FROM clientes c {$where_clause} ORDER BY c.nome ASC";
    $stmt_select = $pdo->prepare($sql_select);
    $stmt_select->execute($params);
    $clientes = $stmt_select->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clientes = []; $mensagem_erro_display = "Erro ao buscar clientes: " . $e->getMessage(); 
}

$mensagem_sucesso = $_SESSION['mensagem_sucesso'] ?? null;
$mensagem_erro = $_SESSION['mensagem_erro'] ?? null;
unset($_SESSION['mensagem_sucesso'], $_SESSION['mensagem_erro']);

$status_map_raiox = ['pendente' => ['text' => 'Pendente', 'color' => 'secondary'], 'em_andamento' => ['text' => 'Em Andamento', 'color' => 'info'], 'aguardando_material' => ['text' => 'Aguardando Material', 'color' => 'warning'], 'material_com_problema' => ['text' => 'Material c/ Problema', 'color' => 'danger'], 'necessita_nova_edicao' => ['text' => 'Nova Edição', 'color' => 'warning'], 'enviado_para_cliente' => ['text' => 'Enviado p/ Cliente', 'color' => 'primary'], 'cliente_solicitou_alteracao' => ['text' => 'Alteração Solicitada', 'color' => 'warning'], 'cliente_aprovou' => ['text' => 'Aprovado', 'color' => 'success'], 'concluido' => ['text' => 'Concluído', 'color' => 'success'], 'video_publicado' => ['text' => 'Publicado', 'color' => 'success']];
$prioridade_map_raiox = ['baixa' => ['text' => 'Baixa', 'color' => 'success'], 'media' => ['text' => 'Média', 'color' => 'info'], 'alta' => ['text' => 'Alta', 'color' => 'warning'], 'urgente' => ['text' => 'Urgente', 'color' => 'danger']];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Clientes - Agência</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary-color: #6f42c1; --light-bg: #f4f6f9; --card-border-color: #e9ecef;
            --text-primary-color: #212529; --text-secondary-color: #6c757d;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--light-bg); }
        .main-container { padding-top: 20px; padding-bottom: 40px; }
        .card { border: none; border-radius: .75rem; box-shadow: 0 4px 25px rgba(0,0,0,.07); }
        .table { border-collapse: separate; border-spacing: 0 .5rem; margin-top: -0.5rem; }
        .table th, .table td { vertical-align: middle; font-size: 0.9rem; padding: 1rem; }
        .table thead th { background-color: transparent; border: none; color: var(--text-secondary-color); font-weight: 600; text-transform: uppercase; font-size: 0.75rem; }
        .table tbody tr { background-color: #fff; border-radius: .5rem; box-shadow: 0 2px 10px rgba(0,0,0,.03); transition: transform .2s ease, box-shadow .2s ease; }
        .table tbody tr:hover { transform: translateY(-3px); box-shadow: 0 4px 15px rgba(0,0,0,.08); }
        .action-buttons .btn { margin: 0 3px; border-radius: .5rem; }
        .modal-header { background-color: var(--primary-color); color: white; border-bottom: none; }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        .btn-raio-x { background-color: #fd7e14; color: white; border-color: #fd7e14; }
        .btn-raio-x:hover { background-color: #e66a01; border-color: #e66a01; }
        .loading-spinner-container { text-align: center; padding: 40px; }
        .raiox-section h6 { font-weight: 600; color: var(--primary-color); border-bottom: 2px solid #eee; padding-bottom: 0.3rem; margin-top: 1.2rem; margin-bottom: 1rem; display: flex; align-items: center; }
        .raiox-section h6 i { margin-right: 8px; }
        .modal-raiox { max-width: 950px; }
        #form-content-edit, #raioXContent { display: none; }
        .badge { font-weight: 500; font-size: 0.8rem; padding: 0.4em 0.7em; }
        .table-scroll-container { max-height: 280px; overflow-y: auto; border: 1px solid var(--card-border-color); border-radius: .375rem; }
        .pagination-controls { display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; padding-top: 10px; }
    </style>
</head>
<body>

    <?php require_once 'menu.php'; ?>

    <div class="container-fluid main-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0 text-primary-color">Gerenciamento de Clientes</h1>
            <?php if ($usuario_logado_nivel === 'gestor'): ?>
            <button type="button" class="btn btn-primary shadow-sm" data-bs-toggle="modal" data-bs-target="#addClienteModal"><i class="fas fa-user-plus fa-sm me-2"></i> Adicionar Cliente</button>
            <?php endif; ?>
        </div>
        
        <?php if ($mensagem_sucesso): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_sucesso); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if (isset($mensagem_erro_display)): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($mensagem_erro_display); ?></div><?php endif; ?>
        <?php if ($mensagem_erro): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_erro); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <div class="card">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center py-3">
                <h5 class="mb-0 card-title">Lista de Clientes</h5>
                <form method="GET" class="d-flex align-items-center mt-2 mt-md-0">
                    <label for="filtro_status" class="form-label me-2 mb-0 fw-bold text-secondary-color">Mostrar:</label>
                    <select class="form-select form-select-sm" name="filtro_status" id="filtro_status" onchange="this.form.submit()">
                        <option value="ativo" <?php echo ($filtro_status === 'ativo') ? 'selected' : ''; ?>>Ativos</option>
                        <option value="desativado" <?php echo ($filtro_status === 'desativado') ? 'selected' : ''; ?>>Desativados</option>
                        <option value="todos" <?php echo ($filtro_status === 'todos') ? 'selected' : ''; ?>>Todos</option>
                    </select>
                </form>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead><tr><th>Nome</th><th class="text-center">Última Captação</th><th class="text-center">Último Vídeo Publicado</th><th class="text-center">Status</th><th class="text-center">Ações</th></tr></thead>
                        <tbody>
                            <?php if (empty($clientes)): ?>
                                <tr><td colspan="5" class="text-center py-5 text-muted"><h4><i class="fas fa-search-minus me-2"></i></h4> Nenhum cliente encontrado.</td></tr>
                            <?php else: foreach ($clientes as $cliente): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($cliente['nome']); ?></strong></td>
                                    <td class="text-center"><?php echo formatarDataParaBR($cliente['ultima_captacao']); ?></td>
                                    <td class="text-center"><?php echo formatarDataParaBR($cliente['ultimo_video_publicado']); ?></td>
                                    <td class="text-center"><span class="badge rounded-pill bg-<?php echo ($cliente['status'] === 'ativo') ? 'success' : 'secondary'; ?>"><?php echo ucfirst($cliente['status']); ?></span></td>
                                    <td class="text-center action-buttons">
                                        <?php if ($usuario_logado_nivel === 'gestor'): ?>
                                            <button class="btn btn-outline-warning btn-sm" title="Editar" data-bs-toggle="modal" data-bs-target="#editClienteModal" data-cliente-id="<?php echo $cliente['id']; ?>"><i class="fas fa-edit"></i></button>
                                            <?php if ($cliente['status'] === 'ativo'): ?>
                                                <button class="btn btn-outline-danger btn-sm" title="Desativar" data-bs-toggle="modal" data-bs-target="#confirmarDesativacaoModal" data-cliente-id="<?php echo $cliente['id']; ?>" data-cliente-nome="<?php echo htmlspecialchars($cliente['nome']); ?>"><i class="fas fa-power-off"></i></button>
                                            <?php else: ?>
                                                <form method="POST" action="clientes_gerenciamento.php" class="d-inline">
                                                    <input type="hidden" name="acao" value="toggle_status">
                                                    <input type="hidden" name="cliente_id" value="<?php echo $cliente['id']; ?>">
                                                    <button type="submit" class="btn btn-outline-success btn-sm" title="Ativar"><i class="fas fa-power-off"></i></button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <button class="btn btn-raio-x btn-sm" title="Raio X" data-bs-toggle="modal" data-bs-target="#raioXClienteModal" data-cliente-id="<?php echo $cliente['id']; ?>"><i class="fas fa-bolt"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <?php if ($usuario_logado_nivel === 'gestor'): ?>
    <div class="modal fade" id="addClienteModal" tabindex="-1" aria-labelledby="addClienteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form action="clientes_gerenciamento.php" method="POST">
                    <input type="hidden" name="acao" value="adicionar_cliente">
                    <div class="modal-header"><h5 class="modal-title" id="addClienteModalLabel">Adicionar Novo Cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-8 mb-3"><label class="form-label">Nome/Razão Social <span class="text-danger">*</span></label><input type="text" class="form-control" name="nome" required></div>
                            <div class="col-md-4 mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="ativo" selected>Ativo</option><option value="desativado">Desativado</option></select></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" name="email" required></div>
                            <div class="col-md-6 mb-3"><label class="form-label">Telefone</label><input type="text" class="form-control" name="telefone"></div>
                        </div>
                        <div class="mb-3"><label class="form-label">Info. Contrato</label><textarea class="form-control" name="informacoes_contrato" rows="3"></textarea></div>
                        <div><label class="form-label">Observações</label><textarea class="form-control" name="observacoes" rows="3"></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><button type="submit" class="btn btn-primary">Salvar</button></div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="editClienteModal" tabindex="-1" aria-labelledby="editClienteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="editClienteModalLabel">Editar Cliente</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="loading-spinner-container text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Carregando...</span></div></div>
                    <div id="form-content-edit"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="confirmarDesativacaoModal" tabindex="-1" aria-labelledby="confirmarDesativacaoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="confirmarDesativacaoModalLabel">Confirmar Desativação</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja desativar o cliente <strong id="nome-cliente-desativar"></strong>?</p>
                    <p class="text-danger">Para confirmar, por favor, digite sua senha de acesso.</p>
                    <div class="mb-3">
                        <label for="senha-confirmacao" class="form-label fw-bold">Sua Senha</label>
                        <input type="password" class="form-control" id="senha-confirmacao" required>
                    </div>
                    <div id="desativacao-alert" class="alert alert-danger" style="display:none;" role="alert"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-danger" id="btn-confirmar-desativacao">Desativar Cliente</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="raioXClienteModal" tabindex="-1" aria-labelledby="raioXClienteModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-raiox modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="raioXClienteModalLabel"><i class="fas fa-bolt me-2"></i>Raio X: <span></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="loading-spinner-container text-center py-5"><div class="spinner-border text-primary" style="width: 3rem; height: 3rem;"></div><p class="mt-2">Carregando Raio-X completo...</p></div>
                    <div id="raioXContent"></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const statusMap = <?php echo json_encode($status_map_raiox); ?>;
        const prioridadeMap = <?php echo json_encode($prioridade_map_raiox); ?>;

        const showLoading = (modal) => {
            const spinner = modal.querySelector('.loading-spinner-container');
            const content = modal.querySelector('#form-content-edit, #raioXContent');
            if(spinner) spinner.style.display = 'block';
            if(content) { content.style.display = 'none'; content.innerHTML = ''; }
        };

        const hideLoading = (modal) => {
            const spinner = modal.querySelector('.loading-spinner-container');
            const content = modal.querySelector('#form-content-edit, #raioXContent');
            if(spinner) spinner.style.display = 'none';
            if(content) content.style.display = 'block';
        };

        const handleAjaxError = (modal, error) => {
            const contentAreaId = modal.id === 'editClienteModal' ? '#form-content-edit' : '#raioXContent';
            const content = modal.querySelector(contentAreaId);
            hideLoading(modal);
            if(content) content.innerHTML = `<div class="alert alert-danger m-3"><strong>Erro:</strong> ${error.message}</div>`;
        };

        const fetchData = (clienteId, callback, errorCallback) => {
            fetch(`clientes_gerenciamento.php?acao=obter_dados_cliente&id=${clienteId}`, { headers: {'X-Requested-With': 'XMLHttpRequest'} })
                .then(response => {
                    if (!response.ok) throw new Error(`Erro de rede: ${response.statusText}`);
                    return response.json();
                })
                .then(res => {
                    if (!res.success) throw new Error(res.error || 'Não foi possível carregar os dados.');
                    callback(res.data);
                })
                .catch(err => errorCallback(err));
        };

        document.querySelectorAll('[data-bs-target="#editClienteModal"]').forEach(btn => {
            btn.addEventListener('click', e => {
                const clienteId = e.currentTarget.dataset.clienteId;
                const modalEl = document.getElementById('editClienteModal');
                showLoading(modalEl);
                fetchData(clienteId, data => {
                    populateEditModal(modalEl, data);
                    hideLoading(modalEl);
                }, err => handleAjaxError(modalEl, err));
            });
        });

        document.querySelectorAll('[data-bs-target="#raioXClienteModal"]').forEach(btn => {
            btn.addEventListener('click', e => {
                const clienteId = e.currentTarget.dataset.clienteId;
                const modalEl = document.getElementById('raioXClienteModal');
                showLoading(modalEl);
                fetchData(clienteId, data => {
                    populateRaioXModal(modalEl, data);
                    hideLoading(modalEl);
                }, err => handleAjaxError(modalEl, err));
            });
        });

        const desativacaoModalEl = document.getElementById('confirmarDesativacaoModal');
        if (desativacaoModalEl) {
            let clienteIdParaDesativar = null;
            desativacaoModalEl.addEventListener('show.bs.modal', function (event) {
                const button = event.relatedTarget;
                clienteIdParaDesativar = button.dataset.clienteId;
                document.getElementById('nome-cliente-desativar').textContent = button.dataset.clienteNome;
                const senhaInput = document.getElementById('senha-confirmacao');
                const alertDiv = document.getElementById('desativacao-alert');
                senhaInput.value = '';
                alertDiv.style.display = 'none';
                senhaInput.focus();
            });

            document.getElementById('btn-confirmar-desativacao').addEventListener('click', function() {
                const senha = document.getElementById('senha-confirmacao').value;
                const alertDiv = document.getElementById('desativacao-alert');
                const formData = new FormData();
                formData.append('senha', senha);

                fetch('clientes_gerenciamento.php?acao=verificar_senha', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = 'clientes_gerenciamento.php';
                            form.innerHTML = `<input type="hidden" name="acao" value="toggle_status"><input type="hidden" name="cliente_id" value="${clienteIdParaDesativar}">`;
                            document.body.appendChild(form);
                            form.submit();
                        } else {
                            alertDiv.textContent = data.error || 'Senha incorreta.';
                            alertDiv.style.display = 'block';
                        }
                    }).catch(err => {
                        alertDiv.textContent = 'Erro de comunicação. Tente novamente.';
                        alertDiv.style.display = 'block';
                    });
            });
        }
        
        function populateEditModal(modal, data) {
            const contentDiv = modal.querySelector('#form-content-edit');
            if (modal.querySelector('.modal-title')) modal.querySelector('.modal-title').textContent = `Editar Cliente: ${data.nome}`;
            contentDiv.innerHTML = `
                <form action="clientes_gerenciamento.php" method="POST">
                    <input type="hidden" name="acao" value="editar_cliente">
                    <input type="hidden" name="id" value="${data.id || ''}">
                    <div class="row">
                        <div class="col-md-8 mb-3"><label class="form-label">Nome/Razão Social <span class="text-danger">*</span></label><input type="text" class="form-control" name="nome" value="${data.nome || ''}" required></div>
                        <div class="col-md-4 mb-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="ativo" ${data.status === 'ativo' ? 'selected' : ''}>Ativo</option><option value="desativado" ${data.status === 'desativado' ? 'selected' : ''}>Desativado</option></select></div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Email <span class="text-danger">*</span></label><input type="email" class="form-control" name="email" value="${data.email || ''}" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Telefone</label><input type="text" class="form-control" name="telefone" value="${data.telefone || ''}"></div>
                    </div>
                    <div class="mb-3"><label class="form-label">Informações do Contrato</label><textarea class="form-control" name="informacoes_contrato" rows="3">${data.informacoes_contrato || ''}</textarea></div>
                    <div class="mb-3"><label class="form-label">Observações</label><textarea class="form-control" name="observacoes" rows="3">${data.observacoes || ''}</textarea></div>
                    <div class="modal-footer border-0 p-0 pt-3"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><button type="submit" class="btn btn-primary">Salvar Alterações</button></div>
                </form>`;
        }
        
        function renderPaginatedTable(container, data, renderRow, itemsPerPage = 10) {
            let currentPage = 1;
            const totalPages = data.length > 0 ? Math.ceil(data.length / itemsPerPage) : 1;
            function render() {
                const start = (currentPage - 1) * itemsPerPage;
                const end = start + itemsPerPage;
                const paginatedItems = data.slice(start, end);
                const tableRows = paginatedItems.map(renderRow).join('');
                container.querySelector('tbody').innerHTML = tableRows;
                container.querySelector('.page-info').textContent = `Página ${currentPage} de ${totalPages}`;
                container.querySelector('.prev-page').disabled = currentPage === 1;
                container.querySelector('.next-page').disabled = currentPage === totalPages;
            }
            container.addEventListener('click', e => {
                if (e.target.matches('.prev-page') && currentPage > 1) { currentPage--; render(); }
                if (e.target.matches('.next-page') && currentPage < totalPages) { currentPage++; render(); }
            });
            render();
        }

        function populateRaioXModal(modal, data) {
            const contentDiv = modal.querySelector('#raioXContent');
            if (modal.querySelector('.modal-title span')) modal.querySelector('.modal-title span').textContent = data.nome;

            const formatDate = (d) => {
                if (!d || d.startsWith('0000-00-00')) return 'N/A';
                try { return new Date(d.includes(' ') ? d : d + 'T00:00:00').toLocaleDateString('pt-BR'); } catch (e) { return 'Inválida'; }
            };
            
            const marcosHtml = `<div class="row"><div class="col-md-6"><p class="mb-0"><strong>Última Captação:</strong> <span class="badge bg-info">${formatDate(data.marcos_importantes.ultima_gravacao)}</span></p></div><div class="col-md-6"><p class="mb-0"><strong>Último Vídeo Publicado:</strong> <span class="badge bg-success">${formatDate(data.marcos_importantes.ultimo_video_postado)}</span></p></div></div>`;
            const resumoHtml = `<div class="d-flex justify-content-around text-center mt-3 p-3 bg-light rounded"><div><span class="fs-4 fw-bold">${data.resumo_status.total||0}</span><br><small>Total</small></div><div><span class="fs-4 fw-bold">${data.resumo_status.pendente||0}</span><br><small>Pendentes</small></div><div><span class="fs-4 fw-bold">${data.resumo_status.em_edicao||0}</span><br><small>Em Edição</small></div><div><span class="fs-4 fw-bold">${data.resumo_status.concluido||0}</span><br><small>Concluídas</small></div><div><span class="fs-4 fw-bold">${data.resumo_status.publicado||0}</span><br><small>Publicadas</small></div></div>`;

            const trabalhosContainerId = `trabalhos-container-${data.id}`;
            const captacoesContainerId = `captacoes-container-${data.id}`;

            contentDiv.innerHTML = `<div class="raiox-section"><h6><i class="fas fa-star"></i>Marcos Importantes</h6>${marcosHtml}</div><div class="raiox-section"><h6><i class="fas fa-chart-pie"></i>Resumo de Demandas</h6>${resumoHtml}</div><div class="raiox-section" id="${trabalhosContainerId}"><h6><i class="fas fa-history"></i>Histórico de Trabalhos</h6></div><div class="raiox-section" id="${captacoesContainerId}"><h6><i class="fas fa-video"></i>Histórico de Captações</h6></div>`;

            if (data.historico_trabalhos && data.historico_trabalhos.length > 0) {
                const container = document.getElementById(trabalhosContainerId);
                container.innerHTML += `<div class="table-scroll-container"><table class="table table-sm table-striped"><thead><tr><th>ID</th><th>Título</th><th>Entrega</th><th>Responsável</th><th>Status</th><th>Prioridade</th></tr></thead><tbody></tbody></table></div><div class="pagination-controls"><button class="btn btn-sm btn-outline-secondary prev-page">Anterior</button><span class="page-info"></span><button class="btn btn-sm btn-outline-secondary next-page">Próximo</button></div>`;
                renderPaginatedTable(container, data.historico_trabalhos, job => `<tr><td>${job.id}</td><td><a href="criardemanda.php?id=${job.id}" target="_blank">${job.titulo || ''}</a></td><td>${formatDate(job.data_entrega)}</td><td>${job.responsavel_nome || 'N/A'}</td><td><span class="badge bg-${statusMap[job.status]?.color||'secondary'}">${statusMap[job.status]?.text||job.status}</span></td><td><span class="badge bg-${prioridadeMap[job.prioridade]?.color||'secondary'}">${prioridadeMap[job.prioridade]?.text||job.prioridade}</span></td></tr>`);
            } else { document.getElementById(trabalhosContainerId).innerHTML += '<p class="text-muted">Nenhum trabalho encontrado.</p>'; }

            if (data.historico_captacoes && data.historico_captacoes.length > 0) {
                const container = document.getElementById(captacoesContainerId);
                container.innerHTML += `<div class="table-scroll-container"><table class="table table-sm table-striped"><thead><tr><th>Data</th><th>Título da Demanda</th><th>Local</th></tr></thead><tbody></tbody></table></div><div class="pagination-controls"><button class="btn btn-sm btn-outline-secondary prev-page">Anterior</button><span class="page-info"></span><button class="btn btn-sm btn-outline-secondary next-page">Próximo</button></div>`;
                renderPaginatedTable(container, data.historico_captacoes, cap => `<tr><td>${formatDate(cap.data_captacao)}</td><td><a href="criardemanda.php?id=${cap.id}" target="_blank">${cap.titulo||''}</a></td><td>${cap.local_captacao||'N/A'}</td></tr>`);
            } else { document.getElementById(captacoesContainerId).innerHTML += '<p class="text-muted">Nenhuma captação registrada.</p>'; }
        }
    });
    </script>
</body>
</html>
