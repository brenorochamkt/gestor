<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexao.php'; // Sua conexão PDO

// Verificar se o usuário está logado (adicione sua lógica de verificação de login aqui)
if (!isset($_SESSION['usuario_id'])) {
    // Exemplo: redirecionar para login se não estiver logado
    // header('Location: login.php');
    // exit;
    // Por enquanto, para desenvolvimento, vamos simular um usuário logado:
    // $_SESSION['usuario_id'] = 1; // Simulação
    // $_SESSION['usuario_nivel'] = 'gestor'; // Simulação ('gestor' ou 'editor')
}

$usuario_logado_id = $_SESSION['usuario_id'] ?? null;
$usuario_logado_nivel = $_SESSION['usuario_nivel'] ?? 'convidado'; // Default para convidado se não definido

// --- Variáveis Iniciais ---
$demanda_id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$demanda = null;
$historico_status = [];
$comentarios = [];
$modo_pagina = $demanda_id ? 'visualizar' : 'criar'; // 'criar', 'visualizar', 'editar' (controlado por JS também)
$mensagem_sucesso = $_SESSION['mensagem_sucesso'] ?? null;
$mensagem_erro = $_SESSION['mensagem_erro'] ?? null;
$mensagem_info = $_SESSION['mensagem_info'] ?? null;

unset($_SESSION['mensagem_sucesso'], $_SESSION['mensagem_erro'], $_SESSION['mensagem_info']);

// --- Funções Auxiliares (Exemplo - você pode ter um arquivo functions.php) ---
function verificar_senha_usuario($pdo_conn, $userId, $senhaFornecida) {
    // LÓGICA DE EXEMPLO - SUBSTITUA PELA SUA IMPLEMENTAÇÃO REAL
    // Esta função deve buscar o hash da senha do usuário no banco e usar password_verify()
    $stmt = $pdo_conn->prepare("SELECT senha FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user && password_verify($senhaFornecida, $user['senha'])) {
        return true;
    }
    return false; // Senha incorreta ou usuário não encontrado
}

// --- Carregar Dados Auxiliares para Formulários (Clientes, Usuários) ---
try {
    $stmt_clientes = $pdo->query("SELECT id, nome FROM clientes ORDER BY nome ASC");
    $clientes_options = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

    $stmt_usuarios = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC");
    $usuarios_options = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensagem_erro = "Erro ao carregar dados auxiliares: " . $e->getMessage();
    $clientes_options = [];
    $usuarios_options = [];
}

$prioridades_demanda = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];
$status_demanda_map = [
    'pendente' => 'Pendente',
    'em_andamento' => 'Em Andamento',
    'aguardando_material' => 'Aguardando Material',
    'material_com_problema' => 'Material com Problema',
    'necessita_nova_edicao' => 'Necessita Nova Edição',
    'enviado_para_cliente' => 'Enviado para Cliente',
    'cliente_solicitou_alteracao' => 'Cliente Solicitou Alteração',
    'cliente_aprovou' => 'Cliente Aprovou',
    'concluido' => 'Concluído',
    'video_publicado' => 'Vídeo Publicado'
];

// --- Lógica de POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $usuario_logado_id) {
    $acao = $_POST['acao'] ?? '';
    $pdo->beginTransaction();
    try {
        if ($acao === 'criar_demanda' && $usuario_logado_nivel === 'gestor') {
            if (empty($_POST['titulo']) || empty($_POST['cliente_id']) || empty($_POST['data_entrega']) || empty($_POST['responsavel_id'])) {
                throw new Exception("Campos obrigatórios (Título, Cliente, Data de Entrega, Responsável) não preenchidos.");
            }
            $sql = "INSERT INTO demandas (titulo, cliente_id, descricao, material_bruto_url, data_captacao, hora_captacao_inicio, hora_captacao_fim, local_captacao, data_entrega, prioridade, status, responsavel_id, criado_por)
                    VALUES (:titulo, :cliente_id, :descricao, :material_bruto_url, :data_captacao, :hora_captacao_inicio, :hora_captacao_fim, :local_captacao, :data_entrega, :prioridade, :status, :responsavel_id, :criado_por)";
            $stmt = $pdo->prepare($sql);
            $status_inicial = $_POST['status'] ?? 'pendente';
            $stmt->execute([
                ':titulo' => $_POST['titulo'],
                ':cliente_id' => $_POST['cliente_id'],
                ':descricao' => !empty($_POST['descricao']) ? $_POST['descricao'] : null,
                ':material_bruto_url' => !empty($_POST['material_bruto_url']) ? $_POST['material_bruto_url'] : null,
                ':data_captacao' => !empty($_POST['data_captacao']) ? $_POST['data_captacao'] : null,
                ':hora_captacao_inicio' => !empty($_POST['hora_captacao_inicio']) ? $_POST['hora_captacao_inicio'] : null,
                ':hora_captacao_fim' => !empty($_POST['hora_captacao_fim']) ? $_POST['hora_captacao_fim'] : null,
                ':local_captacao' => !empty($_POST['local_captacao']) ? $_POST['local_captacao'] : null,
                ':data_entrega' => $_POST['data_entrega'],
                ':prioridade' => $_POST['prioridade'] ?? 'media',
                ':status' => $status_inicial,
                ':responsavel_id' => $_POST['responsavel_id'],
                ':criado_por' => $usuario_logado_id
            ]);
            $nova_demanda_id = $pdo->lastInsertId();

            $sql_hist = "INSERT INTO demandas_historico_status (demanda_id, usuario_id, novo_status, comentario) VALUES (:demanda_id, :usuario_id, :novo_status, :comentario)";
            $stmt_hist = $pdo->prepare($sql_hist);
            $stmt_hist->execute([
                ':demanda_id' => $nova_demanda_id,
                ':usuario_id' => $usuario_logado_id,
                ':novo_status' => $status_inicial,
                ':comentario' => 'Demanda criada.'
            ]);
            $_SESSION['mensagem_sucesso'] = "Demanda '{$_POST['titulo']}' criada com sucesso!";
            $pdo->commit();
            header("Location: criardemanda.php?id=" . $nova_demanda_id);
            exit;

        } elseif ($acao === 'editar_demanda' && $usuario_logado_nivel === 'gestor') {
            $demanda_id_edit = $_POST['demanda_id_hidden'] ?? null; // Usar um campo hidden para o ID
            if (!$demanda_id_edit) throw new Exception("ID da demanda não fornecido para edição.");
            if (empty($_POST['titulo']) || empty($_POST['cliente_id']) || empty($_POST['data_entrega']) || empty($_POST['responsavel_id'])) {
                throw new Exception("Campos obrigatórios para edição não preenchidos.");
            }

            $stmt_old = $pdo->prepare("SELECT status FROM demandas WHERE id = :id");
            $stmt_old->execute([':id' => $demanda_id_edit]);
            $status_anterior = $stmt_old->fetchColumn();
            $novo_status_edit = $_POST['status'] ?? $status_anterior;

            $sql = "UPDATE demandas SET titulo = :titulo, cliente_id = :cliente_id, descricao = :descricao, 
                    material_bruto_url = :material_bruto_url, data_captacao = :data_captacao, 
                    hora_captacao_inicio = :hora_captacao_inicio, hora_captacao_fim = :hora_captacao_fim, 
                    local_captacao = :local_captacao, data_entrega = :data_entrega, prioridade = :prioridade, 
                    status = :status, responsavel_id = :responsavel_id
                    WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id' => $demanda_id_edit,
                ':titulo' => $_POST['titulo'],
                ':cliente_id' => $_POST['cliente_id'],
                ':descricao' => !empty($_POST['descricao']) ? $_POST['descricao'] : null,
                ':material_bruto_url' => !empty($_POST['material_bruto_url']) ? $_POST['material_bruto_url'] : null,
                ':data_captacao' => !empty($_POST['data_captacao']) ? $_POST['data_captacao'] : null,
                ':hora_captacao_inicio' => !empty($_POST['hora_captacao_inicio']) ? $_POST['hora_captacao_inicio'] : null,
                ':hora_captacao_fim' => !empty($_POST['hora_captacao_fim']) ? $_POST['hora_captacao_fim'] : null,
                ':local_captacao' => !empty($_POST['local_captacao']) ? $_POST['local_captacao'] : null,
                ':data_entrega' => $_POST['data_entrega'],
                ':prioridade' => $_POST['prioridade'] ?? 'media',
                ':status' => $novo_status_edit,
                ':responsavel_id' => $_POST['responsavel_id']
            ]);

            if ($status_anterior != $novo_status_edit) {
                $sql_hist = "INSERT INTO demandas_historico_status (demanda_id, usuario_id, status_anterior, novo_status, comentario) VALUES (:demanda_id, :usuario_id, :status_anterior, :novo_status, :comentario)";
                $stmt_hist = $pdo->prepare($sql_hist);
                $stmt_hist->execute([
                    ':demanda_id' => $demanda_id_edit,
                    ':usuario_id' => $usuario_logado_id,
                    ':status_anterior' => $status_anterior,
                    ':novo_status' => $novo_status_edit,
                    ':comentario' => 'Status alterado durante a edição da demanda.'
                ]);
            }
            $_SESSION['mensagem_sucesso'] = "Demanda ID '{$demanda_id_edit}' atualizada com sucesso!";
            $pdo->commit();
            header("Location: criardemanda.php?id=" . $demanda_id_edit);
            exit;

        } elseif ($acao === 'excluir_demanda_confirmada' && $usuario_logado_nivel === 'gestor') {
            $demanda_id_excluir = $_POST['demanda_id_excluir'] ?? null;
            $senha_confirmacao = $_POST['senha_confirmacao_excluir'] ?? '';

            if (!$demanda_id_excluir) throw new Exception("ID da demanda não fornecido para exclusão.");
            
            // LÓGICA DE VERIFICAÇÃO DE SENHA - SUBSTITUA PELA SUA IMPLEMENTAÇÃO REAL
            if (!verificar_senha_usuario($pdo, $usuario_logado_id, $senha_confirmacao)) {
                 throw new Exception("Senha incorreta. A exclusão não foi realizada.");
            }

            // Excluir registros relacionados
            $stmt_del_hist = $pdo->prepare("DELETE FROM demandas_historico_status WHERE demanda_id = :id");
            $stmt_del_hist->execute([':id' => $demanda_id_excluir]);
            $stmt_del_com = $pdo->prepare("DELETE FROM demandas_comentarios WHERE demanda_id = :id");
            $stmt_del_com->execute([':id' => $demanda_id_excluir]);
            
            // Excluir demanda principal
            $sql_del = "DELETE FROM demandas WHERE id = :id";
            $stmt_del = $pdo->prepare($sql_del);
            $stmt_del->execute([':id' => $demanda_id_excluir]);

            $_SESSION['mensagem_sucesso'] = "Demanda ID '{$demanda_id_excluir}' e seus dados relacionados foram excluídos com sucesso!";
            $pdo->commit();
            header("Location: demandas.php"); // Redireciona para a lista/Kanban
            exit;

        } elseif ($acao === 'alterar_status_demanda_pagina') { // Permissão pode ser mais flexível aqui
             if (empty($_POST['status_demanda_id_pagina']) || empty($_POST['novo_status_pagina'])) {
                throw new Exception("ID da demanda e novo status são obrigatórios para alteração de status.");
            }
            $demanda_id_alt_status = $_POST['status_demanda_id_pagina'];
            $novo_status_alt = $_POST['novo_status_pagina'];

            $stmt_old_status = $pdo->prepare("SELECT status FROM demandas WHERE id = :id");
            $stmt_old_status->execute([':id' => $demanda_id_alt_status]);
            $status_anterior_alt = $stmt_old_status->fetchColumn();

            if ($status_anterior_alt == $novo_status_alt) {
                $_SESSION['mensagem_info'] = "O novo status é igual ao status atual. Nenhuma alteração feita.";
            } else {
                $sql_update_status = "UPDATE demandas SET status = :novo_status WHERE id = :id";
                $stmt_update = $pdo->prepare($sql_update_status);
                $stmt_update->execute([
                    ':novo_status' => $novo_status_alt,
                    ':id' => $demanda_id_alt_status
                ]);

                $sql_hist = "INSERT INTO demandas_historico_status (demanda_id, usuario_id, status_anterior, novo_status, comentario) VALUES (:demanda_id, :usuario_id, :status_anterior, :novo_status, :comentario)";
                $stmt_hist_alt = $pdo->prepare($sql_hist);
                $stmt_hist_alt->execute([
                    ':demanda_id' => $demanda_id_alt_status,
                    ':usuario_id' => $usuario_logado_id,
                    ':status_anterior' => $status_anterior_alt,
                    ':novo_status' => $novo_status_alt,
                    ':comentario' => !empty($_POST['status_comentario_pagina']) ? trim($_POST['status_comentario_pagina']) : 'Status alterado.'
                ]);
                $_SESSION['mensagem_sucesso'] = "Status da demanda ID '{$demanda_id_alt_status}' alterado com sucesso!";
            }
            $pdo->commit();
            header("Location: criardemanda.php?id=" . $demanda_id_alt_status);
            exit;
        
        } elseif ($acao === 'adicionar_comentario_pagina') {
            $demanda_id_com = $_POST['comentario_demanda_id_pagina'] ?? null;
            $texto_comentario = trim($_POST['comentario_texto_pagina'] ?? '');

            if (!$demanda_id_com) throw new Exception("ID da demanda não fornecido para adicionar comentário.");
            if (empty($texto_comentario)) throw new Exception("O texto do comentário não pode estar vazio.");
            
            $sql_com = "INSERT INTO demandas_comentarios (demanda_id, usuario_id, comentario) VALUES (:demanda_id, :usuario_id, :comentario)";
            $stmt_com = $pdo->prepare($sql_com);
            $stmt_com->execute([
                ':demanda_id' => $demanda_id_com,
                ':usuario_id' => $usuario_logado_id,
                ':comentario' => $texto_comentario
            ]);
            $_SESSION['mensagem_sucesso'] = "Comentário adicionado com sucesso!";
            $pdo->commit();
            header("Location: criardemanda.php?id=" . $demanda_id_com . "#timeline");
            exit;
        } else {
             if ($pdo->inTransaction()) $pdo->rollBack(); // Se nenhuma ação válida, mas transação iniciada
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['mensagem_erro'] = "Erro de Banco de Dados: " . $e->getMessage();
        $redirect_url = $demanda_id ? "criardemanda.php?id=" . $demanda_id : "criardemanda.php";
        header("Location: " . $redirect_url);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['mensagem_erro'] = "Erro: " . $e->getMessage();
        $redirect_url = $demanda_id ? "criardemanda.php?id=" . $demanda_id : "criardemanda.php";
        header("Location: " . $redirect_url);
        exit;
    }
}

// --- Carregar Dados da Demanda (se estiver visualizando/editando) ---
if ($demanda_id) {
    try {
        $stmt = $pdo->prepare("SELECT d.*, c.nome as nome_cliente, u.nome as nome_responsavel, uc.nome as nome_criador 
                               FROM demandas d
                               LEFT JOIN clientes c ON d.cliente_id = c.id
                               LEFT JOIN usuarios u ON d.responsavel_id = u.id
                               LEFT JOIN usuarios uc ON d.criado_por = uc.id
                               WHERE d.id = :id");
        $stmt->execute([':id' => $demanda_id]);
        $demanda = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$demanda) {
            $_SESSION['mensagem_erro'] = "Demanda não encontrada.";
            header("Location: demandas.php"); // ou uma página de erro 404
            exit;
        }
        $modo_pagina = 'visualizar'; // Confirma o modo

        // Carregar Histórico de Status
        $stmt_hist = $pdo->prepare("SELECT dhs.*, u.nome as nome_usuario_alteracao 
                                    FROM demandas_historico_status dhs
                                    LEFT JOIN usuarios u ON dhs.usuario_id = u.id
                                    WHERE dhs.demanda_id = :demanda_id ORDER BY dhs.criado_em DESC");
        $stmt_hist->execute([':demanda_id' => $demanda_id]);
        $historico_status = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

        // Carregar Comentários
        $stmt_com = $pdo->prepare("SELECT dc.*, u.nome as nome_usuario_comentario 
                                   FROM demandas_comentarios dc
                                   LEFT JOIN usuarios u ON dc.usuario_id = u.id
                                   WHERE dc.demanda_id = :demanda_id ORDER BY dc.criado_em ASC");
        $stmt_com->execute([':demanda_id' => $demanda_id]);
        $comentarios = $stmt_com->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $mensagem_erro = "Erro ao carregar dados da demanda: " . $e->getMessage();
        $demanda = null; // Garante que a página não tente renderizar dados inexistentes
    }
}

// --- Definir título da página dinamicamente ---
$titulo_pagina = "Gerenciar Demanda";
if ($modo_pagina === 'criar') {
    $titulo_pagina = "Criar Nova Demanda";
} elseif ($demanda && $demanda_id) {
    $titulo_pagina = "Demanda #{$demanda['id']}: " . htmlspecialchars($demanda['titulo']);
}

// --- Valores do formulário (para preenchimento ou se houver erro de POST e precisar repopular) ---
// Se estiver criando ou se houve um erro no POST e os dados antigos foram enviados
$form_data = $_POST; // Tenta usar dados do POST em caso de erro de submissão
if ($modo_pagina === 'visualizar' && $demanda) {
    $form_data = $demanda; // Se visualizando, usa dados do banco
} elseif ($modo_pagina === 'criar' && empty($_POST)) { // Se criando e não é um re-POST com erro
    $form_data = [ // Valores padrão para criação
        'titulo' => '', 'cliente_id' => '', 'descricao' => '', 'material_bruto_url' => '',
        'data_captacao' => '', 'hora_captacao_inicio' => '', 'hora_captacao_fim' => '', 'local_captacao' => '',
        'data_entrega' => '', 'prioridade' => 'media', 'status' => 'pendente', 'responsavel_id' => ''
    ];
}
// Garante que todos os índices esperados existam em $form_data para evitar notices no HTML
$default_keys = [
    'titulo' => '', 'cliente_id' => '', 'descricao' => '', 'material_bruto_url' => '',
    'data_captacao' => '', 'hora_captacao_inicio' => '', 'hora_captacao_fim' => '', 'local_captacao' => '',
    'data_entrega' => '', 'prioridade' => 'media', 'status' => 'pendente', 'responsavel_id' => ''
];
$form_data = array_merge($default_keys, $form_data);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($titulo_pagina); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f5f6f8;
            color: #2d3748;
        }
        .main-container {
            margin-top: 2rem;
            margin-bottom: 4rem;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            background: #ffffff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.1);
        }
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #1a202c;
        }
        .form-label {
            font-weight: 500;
            color: #4a5568;
            margin-bottom: 0.5rem;
        }
        .form-control, .form-select {
            border-radius: 8px;
            padding: 0.75rem;
            font-size: 0.95rem;
            border: 1px solid #e2e8f0;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #6b46c1;
            box-shadow: 0 0 0 3px rgba(107, 70, 193, 0.1);
        }
        .disabled-look {
            background-color: #edf2f7;
            opacity: 0.7;
            cursor: not-allowed;
        }
        .btn-view-mode, .btn-edit-mode {
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-view-mode { display: inline-block; }
        .btn-edit-mode { display: none; }
        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 1.5rem;
            position: relative;
            padding-bottom: 0.5rem;
        }
        .section-title::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 50px;
            height: 3px;
            background: #6b46c1;
            border-radius: 2px;
        }
        .timeline {
            position: relative;
            padding-left: 2rem;
            margin-left: 1rem;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 0.5rem;
            top: 0;
            bottom: 0;
            width: 4px;
            background: #e2e8f0;
            border-radius: 2px;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            transition: transform 0.2s ease;
        }
        .timeline-item:hover {
            transform: translateX(5px);
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -2.35rem;
            top: 1.5rem;
            width: 12px;
            height: 12px;
            background: #6b46c1;
            border-radius: 50%;
            border: 2px solid #ffffff;
        }
        .timeline-item.status::before {
            background: #38a169;
        }
        .timeline-item.comment::before {
            background: #3182ce;
        }
        .timeline-item p {
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }
        .timeline-item small {
            color: #718096;
            font-size: 0.85rem;
        }
        .form-actions-bar {
            background: #f7fafc;
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            border-radius: 0 0 8px 8px;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }
        .alert {
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .modal-content {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        .modal-header {
            border-bottom: 1px solid #e2e8f0;
        }
        .modal-footer {
            border-top: 1px solid #e2e8f0;
        }
        .btn-primary {
            background: #6b46c1;
            border-color: #6b46c1;
        }
        .btn-primary:hover {
            background: #553c9a;
            border-color: #553c9a;
        }
        .btn-danger {
            background: #e53e3e;
            border-color: #e53e3e;
        }
        .btn-danger:hover {
            background: #c53030;
            border-color: #c53030;
        }
        @media (max-width: 992px) {
            .edit-mode .col-lg-6 {
                margin-bottom: 2rem;
            }
            .timeline {
                padding-left: 1.5rem;
            }
            .timeline::before {
                left: 0.25rem;
            }
            .timeline-item::before {
                left: -1.85rem;
            }
        }
    </style>
</head>
<body>
<?php include_once 'menu.php'; // Inclui o menu ?>

<div class="container main-container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="page-title"><?php echo htmlspecialchars($titulo_pagina); ?></h1>
        <a href="demandas.php" class="btn btn-outline-secondary btn-view-mode">
            <i class="fas fa-arrow-left me-1"></i> Voltar
        </a>
    </div>

    <?php if ($mensagem_sucesso): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensagem_sucesso); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($mensagem_erro): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensagem_erro); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($mensagem_info): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($mensagem_info); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body p-4">
                    <form id="formDemanda" method="POST" action="criardemanda.php<?php echo $demanda_id ? '?id='.$demanda_id : ''; ?>">
                        <input type="hidden" name="demanda_id_hidden" id="demanda_id_hidden" value="<?php echo $demanda_id ?? ''; ?>">
                        <input type="hidden" name="acao" id="form_acao" value="<?php echo ($modo_pagina === 'criar') ? 'criar_demanda' : ''; ?>">

                        <h5 class="section-title">Informações Principais</h5>
                        <div class="mb-3">
                            <label for="titulo" class="form-label">Título da Demanda <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="titulo" name="titulo" value="<?php echo htmlspecialchars($form_data['titulo']); ?>" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="cliente_id" class="form-label">Cliente <span class="text-danger">*</span></label>
                                <select class="form-select" id="cliente_id" name="cliente_id" required>
                                    <option value="">Selecione um cliente</option>
                                    <?php foreach ($clientes_options as $cliente_opt): ?>
                                        <option value="<?php echo $cliente_opt['id']; ?>" <?php echo ($form_data['cliente_id'] == $cliente_opt['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cliente_opt['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="responsavel_id" class="form-label">Responsável <span class="text-danger">*</span></label>
                                <select class="form-select" id="responsavel_id" name="responsavel_id" required>
                                    <option value="">Selecione um responsável</option>
                                    <?php foreach ($usuarios_options as $usuario_opt): ?>
                                        <option value="<?php echo $usuario_opt['id']; ?>" <?php echo ($form_data['responsavel_id'] == $usuario_opt['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($usuario_opt['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="descricao" class="form-label">Descrição</label>
                            <textarea class="form-control" id="descricao" name="descricao" rows="4"><?php echo htmlspecialchars($form_data['descricao']); ?></textarea>
                        </div>

                        <hr class="my-4">
                        <h5 class="section-title">Prazos e Prioridade</h5>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="data_entrega" class="form-label">Data de Entrega <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="data_entrega" name="data_entrega" value="<?php echo htmlspecialchars($form_data['data_entrega']); ?>" required>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="prioridade" class="form-label">Prioridade</label>
                                <select class="form-select" id="prioridade" name="prioridade">
                                    <?php foreach ($prioridades_demanda as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" <?php echo ($form_data['prioridade'] == $key) ? 'selected' : ''; ?>><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach ($status_demanda_map as $key => $value): ?>
                                        <option value="<?php echo $key; ?>" <?php echo ($form_data['status'] == $key) ? 'selected' : ''; ?>><?php echo $value; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($modo_pagina === 'visualizar' && $demanda): ?>
                                    <small class="form-text text-muted">Status atual: <strong><?php echo htmlspecialchars($status_demanda_map[$demanda['status']] ?? $demanda['status']); ?></strong>. Use o botão "Atualizar Status" para mudar.</small>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h5 class="section-title">Detalhes da Captação (Opcional)</h5>
                        <div class="row">
                            <div class="col-md-3 mb-3">
                                <label for="data_captacao" class="form-label">Data da Captação</label>
                                <input type="date" class="form-control" id="data_captacao" name="data_captacao" value="<?php echo htmlspecialchars($form_data['data_captacao']); ?>">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label for="hora_captacao_inicio" class="form-label">Início</label>
                                <input type="time" class="form-control" id="hora_captacao_inicio" name="hora_captacao_inicio" value="<?php echo htmlspecialchars($form_data['hora_captacao_inicio']); ?>">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label for="hora_captacao_fim" class="form-label">Fim</label>
                                <input type="time" class="form-control" id="hora_captacao_fim" name="hora_captacao_fim" value="<?php echo htmlspecialchars($form_data['hora_captacao_fim']); ?>">
                            </div>
                            <div class="col-md-5 mb-3">
                                <label for="local_captacao" class="form-label">Local da Captação</label>
                                <input type="text" class="form-control" id="local_captacao" name="local_captacao" value="<?php echo htmlspecialchars($form_data['local_captacao']); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="material_bruto_url" class="form-label">Link do Material Bruto (URL)</label>
                            <input type="url" class="form-control" id="material_bruto_url" name="material_bruto_url" placeholder="https://..." value="<?php echo htmlspecialchars($form_data['material_bruto_url']); ?>">
                        </div>

                        <?php if ($modo_pagina === 'visualizar' && $demanda): ?>
                        <hr class="my-4">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <p class="mb-1"><strong>Criado por:</strong> <?php echo htmlspecialchars($demanda['nome_criador'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="col-md-6 mb-3">
                                <p class="mb-1"><strong>Criado em:</strong> <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($demanda['criado_em']))); ?></p>
                            </div>
                            <?php if (isset($demanda['atualizado_em']) && $demanda['atualizado_em']): ?>
                            <div class="col-md-12 mb-3">
                                <p class="mb-1"><strong>Última Atualização:</strong> <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($demanda['atualizado_em']))); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="form-actions-bar">
                            <?php if ($modo_pagina === 'criar'): ?>
                                <button type="submit" class="btn btn-success btn-lg"><i class="fas fa-save me-2"></i>Salvar Nova Demanda</button>
                            <?php elseif ($modo_pagina === 'visualizar' && $demanda_id && $usuario_logado_nivel === 'gestor'): ?>
                                <button type="button" id="btn_iniciar_edicao" class="btn btn-warning btn-view-mode"><i class="fas fa-edit me-2"></i>Editar Demanda</button>
                                <button type="button" class="btn btn-info btn-view-mode" data-bs-toggle="modal" data-bs-target="#modalAtualizarStatusPagina">
                                    <i class="fas fa-sync-alt me-2"></i>Atualizar Status
                                </button>
                                <button type="button" class="btn btn-danger btn-view-mode" data-bs-toggle="modal" data-bs-target="#modalConfirmarExclusao">
                                    <i class="fas fa-trash me-2"></i>Apagar Demanda
                                </button>
                                <button type="submit" id="btn_salvar_edicao" class="btn btn-success btn-edit-mode"><i class="fas fa-save me-2"></i>Salvar Alterações</button>
                                <button type="button" id="btn_cancelar_edicao" class="btn btn-secondary btn-edit-mode"><i class="fas fa-times me-2"></i>Cancelar Edição</button>
                            <?php elseif ($modo_pagina === 'visualizar' && $demanda_id): ?>
                                <button type="button" class="btn btn-info btn-view-mode" data-bs-toggle="modal" data-bs-target="#modalAtualizarStatusPagina">
                                    <i class="fas fa-sync-alt me-2"></i>Atualizar Status
                                </button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($demanda_id && $demanda): ?>
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body p-4">
                    <h5 class="section-title">Linha do Tempo</h5>
                    <div class="timeline" id="timeline">
                        <?php
                        // Combinar histórico e comentários em uma única linha do tempo
                        $timeline_items = [];
                        foreach ($historico_status as $hist) {
                            $timeline_items[] = [
                                'type' => 'status',
                                'timestamp' => strtotime($hist['criado_em']),
                                'data' => $hist
                            ];
                        }
                        foreach ($comentarios as $com) {
                            $timeline_items[] = [
                                'type' => 'comment',
                                'timestamp' => strtotime($com['criado_em']),
                                'data' => $com
                            ];
                        }
                        // Ordenar por timestamp (mais recente primeiro)
                        usort($timeline_items, function($a, $b) {
                            return $b['timestamp'] - $a['timestamp'];
                        });
                        ?>
                        <?php if (empty($timeline_items)): ?>
                            <p class="text-muted">Nenhuma atividade registrada.</p>
                        <?php else: ?>
                            <?php foreach ($timeline_items as $item): ?>
                                <?php if ($item['type'] === 'status'): ?>
                                    <div class="timeline-item status">
                                        <p>
                                            <strong>Status Alterado</strong>: 
                                            <?php echo htmlspecialchars($status_demanda_map[$item['data']['novo_status']] ?? $item['data']['novo_status']); ?>
                                            <?php if ($item['data']['status_anterior']): ?>
                                                (de <?php echo htmlspecialchars($status_demanda_map[$item['data']['status_anterior']] ?? $item['data']['status_anterior']); ?>)
                                            <?php endif; ?>
                                        </p>
                                        <p><small>Por: <?php echo htmlspecialchars($item['data']['nome_usuario_alteracao'] ?? 'Sistema'); ?></small></p>
                                        <?php if (!empty($item['data']['comentario'])): ?>
                                            <p><small><em><?php echo nl2br(htmlspecialchars($item['data']['comentario'])); ?></em></small></p>
                                        <?php endif; ?>
                                        <p><small><?php echo date('d/m/Y H:i', $item['timestamp']); ?></small></p>
                                    </div>
                                <?php else: ?>
                                    <div class="timeline-item comment">
                                        <p><strong>Comentário</strong>: <?php echo htmlspecialchars($item['data']['nome_usuario_comentario'] ?? 'Usuário'); ?>:</p>
                                        <p><?php echo nl2br(htmlspecialchars($item['data']['comentario'])); ?></p>
                                        <p><small><?php echo date('d/m/Y H:i', $item['timestamp']); ?></small></p>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <hr class="my-4">
                    <h6 class="mb-3">Adicionar Novo Comentário</h6>
                    <form method="POST" action="criardemanda.php?id=<?php echo $demanda_id; ?>#timeline">
                        <input type="hidden" name="acao" value="adicionar_comentario_pagina">
                        <input type="hidden" name="comentario_demanda_id_pagina" value="<?php echo $demanda_id; ?>">
                        <div class="mb-3">
                            <textarea class="form-control" name="comentario_texto_pagina" rows="3" placeholder="Digite seu comentário aqui..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Enviar Comentário</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($demanda_id): ?>
<div class="modal fade" id="modalAtualizarStatusPagina" tabindex="-1" aria-labelledby="modalAtualizarStatusLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="criardemanda.php?id=<?php echo $demanda_id; ?>">
                <input type="hidden" name="acao" value="alterar_status_demanda_pagina">
                <input type="hidden" name="status_demanda_id_pagina" value="<?php echo $demanda_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalAtualizarStatusLabel">Atualizar Status da Demanda</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Demanda: <strong>#<?php echo $demanda['id'] . ' - ' . htmlspecialchars($demanda['titulo']); ?></strong></p>
                    <p>Status Atual: <strong id="statusAtualModalDisplay"><?php echo htmlspecialchars($status_demanda_map[$demanda['status']] ?? $demanda['status']); ?></strong></p>
                    <div class="mb-3">
                        <label for="novo_status_pagina" class="form-label">Novo Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="novo_status_pagina" name="novo_status_pagina" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($status_demanda_map as $key => $value): ?>
                                <option value="<?php echo $key; ?>" <?php echo ($demanda['status'] == $key) ? 'disabled' : ''; ?>><?php echo $value; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="status_comentario_pagina" class="form-label">Comentário (Opcional)</label>
                        <textarea class="form-control" id="status_comentario_pagina" name="status_comentario_pagina" rows="2" placeholder="Motivo da alteração, se necessário..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar Novo Status</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($demanda_id && $usuario_logado_nivel === 'gestor'): ?>
<div class="modal fade" id="modalConfirmarExclusao" tabindex="-1" aria-labelledby="modalConfirmarExclusaoLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="formExcluirDemanda" method="POST" action="criardemanda.php?id=<?php echo $demanda_id; ?>">
                <input type="hidden" name="acao" value="excluir_demanda_confirmada">
                <input type="hidden" name="demanda_id_excluir" value="<?php echo $demanda_id; ?>">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title" id="modalConfirmarExclusaoLabel"><i class="fas fa-exclamation-triangle me-2"></i>Confirmar Exclusão</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Tem certeza que deseja excluir permanentemente a demanda <strong>#<?php echo $demanda['id'] . ' - ' . htmlspecialchars($demanda['titulo']); ?></strong>?</p>
                    <p>Todos os históricos e comentários associados também serão apagados. <strong>Esta ação é irreversível.</strong></p>
                    <hr>
                    <div class="mb-3">
                        <label for="senha_confirmacao_excluir" class="form-label">Para confirmar, digite sua senha: <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="senha_confirmacao_excluir" name="senha_confirmacao_excluir" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-2"></i>Confirmar Exclusão</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modoPagina = '<?php echo $modo_pagina; ?>';
    const demandaId = <?php echo $demanda_id ? $demanda_id : 'null'; ?>;
    const formDemanda = document.getElementById('formDemanda');
    const formFields = formDemanda.querySelectorAll('input:not([type="hidden"]), select, textarea');
    const btnIniciarEdicao = document.getElementById('btn_iniciar_edicao');
    const btnSalvarEdicao = document.getElementById('btn_salvar_edicao');
    const btnCancelarEdicao = document.getElementById('btn_cancelar_edicao');
    const btnViewMode = document.querySelectorAll('.btn-view-mode');
    const formAcaoInput = document.getElementById('form_acao');
    const selectStatus = document.getElementById('status');
    const container = document.querySelector('.row');

    let originalFormValues = {};

    function toggleEditMode(enable) {
        formFields.forEach(field => {
            if (field.id === 'status' && modoPagina === 'visualizar' && !enable) {
                field.disabled = true;
            } else {
                field.disabled = !enable;
            }
            if (!enable) {
                field.classList.add('disabled-look');
            } else {
                field.classList.remove('disabled-look');
            }
        });

        btnViewMode.forEach(btn => btn.style.display = enable ? 'none' : 'inline-block');
        if (btnIniciarEdicao) btnIniciarEdicao.style.display = enable ? 'none' : 'inline-block';
        if (btnSalvarEdicao) btnSalvarEdicao.style.display = enable ? 'inline-block' : 'none';
        if (btnCancelarEdicao) btnCancelarEdicao.style.display = enable ? 'inline-block' : 'none';

        if (enable) {
            container.classList.add('edit-mode');
            if (formAcaoInput && demandaId) formAcaoInput.value = 'editar_demanda';
            formFields.forEach(field => {
                originalFormValues[field.name] = field.type === 'checkbox' ? field.checked : field.value;
            });
        } else {
            container.classList.remove('edit-mode');
            if (formAcaoInput && demandaId) formAcaoInput.value = '';
        }
    }

    if (modoPagina === 'criar') {
        toggleEditMode(true);
        if (formAcaoInput) formAcaoInput.value = 'criar_demanda';
    } else if (modoPagina === 'visualizar') {
        toggleEditMode(false);
        if (selectStatus) selectStatus.disabled = true;
    }

    if (btnIniciarEdicao) {
        btnIniciarEdicao.addEventListener('click', function () {
            toggleEditMode(true);
            if (selectStatus && modoPagina === 'visualizar') selectStatus.disabled = false;
        });
    }

    if (btnCancelarEdicao) {
        btnCancelarEdicao.addEventListener('click', function () {
            formFields.forEach(field => {
                if (originalFormValues.hasOwnProperty(field.name)) {
                    if (field.type === 'checkbox') {
                        field.checked = originalFormValues[field.name];
                    } else {
                        field.value = originalFormValues[field.name];
                    }
                }
            });
            toggleEditMode(false);
            if (selectStatus && modoPagina === 'visualizar') selectStatus.disabled = true;
        });
    }
});
</script>
</body>
</html>