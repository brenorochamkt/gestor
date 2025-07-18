<?php
// Garante que session_start() seja chamado apenas uma vez.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexao.php'; // Conexão com o banco de dados

// Dados do usuário logado
$usuario_logado_id = $_SESSION['usuario_id'] ?? 1;
$usuario_logado_nivel = $_SESSION['usuario_nivel'] ?? 'gestor';

$mensagem_sucesso = '';
$mensagem_erro = '';
$mensagem_info = '';

// Verificar se há mensagens da sessão anterior
if (isset($_SESSION['mensagem_sucesso'])) {
    $mensagem_sucesso = $_SESSION['mensagem_sucesso'];
    unset($_SESSION['mensagem_sucesso']);
}
if (isset($_SESSION['mensagem_erro'])) {
    $mensagem_erro = $_SESSION['mensagem_erro'];
    unset($_SESSION['mensagem_erro']);
}
if (isset($_SESSION['mensagem_info'])) {
    $mensagem_info = $_SESSION['mensagem_info'];
    unset($_SESSION['mensagem_info']);
}

// --- BUSCAR DADOS AUXILIARES (Clientes e Usuários para Selects) ---
try {
    $stmt_clientes = $pdo->query("SELECT id, nome FROM clientes WHERE status = 'ativo' ORDER BY nome ASC");
    $clientes_options = $stmt_clientes->fetchAll(PDO::FETCH_ASSOC);

    $stmt_usuarios = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC");
    $usuarios_options = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $mensagem_erro = "Erro ao carregar dados auxiliares: " . $e->getMessage();
    $clientes_options = [];
    $usuarios_options = [];
}

// --- DEFINIÇÕES DE STATUS E PRIORIDADE ---
$prioridades_demanda = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];
$status_demanda_map = [
    'pendente' => 'Pendente',
    'necessita_nova_edicao' => 'Necessita Nova Edição',
    'concluido' => 'Concluído',
    'aguardando_material' => 'Aguardando Material',
    'em_andamento' => 'Em Andamento',
    'material_com_problema' => 'Material com Problema',
    'enviado_para_cliente' => 'Enviado para Cliente',
    'cliente_solicitou_alteracao' => 'Cliente Solicitou Alteração',
    'cliente_aprovou' => 'Cliente Aprovou',
    'video_publicado' => 'Vídeo Publicado'
];
$status_display_order = [
    'pendente', 'necessita_nova_edicao', 'concluido', 'aguardando_material',
    'em_andamento', 'material_com_problema', 'enviado_para_cliente',
    'cliente_solicitou_alteracao', 'cliente_aprovou', 'video_publicado'
];
$status_concluidos = ['concluido', 'cliente_aprovou', 'video_publicado'];

// --- LÓGICA PARA PROCESSAR AÇÕES (POST REQUESTS) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $pdo->beginTransaction();

    try {
        if ($acao === 'adicionar_demanda') {
            if ($usuario_logado_nivel !== 'gestor') {
                throw new Exception("Você não tem permissão para adicionar demandas.");
            }
            if (empty($_POST['titulo']) || empty($_POST['cliente_id']) || empty($_POST['data_entrega']) || empty($_POST['responsavel_id'])) {
                throw new Exception("Campos obrigatórios (Título, Cliente, Data de Entrega, Responsável) não preenchidos.");
            }
            $sql = "INSERT INTO demandas (titulo, cliente_id, descricao, material_bruto_url, data_captacao, hora_captacao_inicio, hora_captacao_fim, local_captacao, data_entrega, prioridade, status, responsavel_id, criado_por)
                     VALUES (:titulo, :cliente_id, :descricao, :material_bruto_url, :data_captacao, :hora_captacao_inicio, :hora_captacao_fim, :local_captacao, :data_entrega, :prioridade, :status, :responsavel_id, :criado_por)";
            $stmt = $pdo->prepare($sql);
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
                ':status' => $_POST['status'] ?? 'pendente',
                ':responsavel_id' => $_POST['responsavel_id'],
                ':criado_por' => $usuario_logado_id
            ]);
            $demanda_id = $pdo->lastInsertId();

            $sql_hist = "INSERT INTO demandas_historico_status (demanda_id, usuario_id, novo_status, comentario) VALUES (:demanda_id, :usuario_id, :novo_status, :comentario)";
            $stmt_hist = $pdo->prepare($sql_hist);
            $stmt_hist->execute([
                ':demanda_id' => $demanda_id,
                ':usuario_id' => $usuario_logado_id,
                ':novo_status' => $_POST['status'] ?? 'pendente',
                ':comentario' => 'Demanda criada.'
            ]);
            $_SESSION['mensagem_sucesso'] = "Demanda '{$_POST['titulo']}' adicionada com sucesso!";

        } elseif ($acao === 'alterar_status_demanda') {
            if (empty($_POST['status_demanda_id']) || empty($_POST['novo_status'])) {
                throw new Exception("ID da demanda e novo status são obrigatórios.");
            }
            $stmt_old_status = $pdo->prepare("SELECT status FROM demandas WHERE id = :id");
            $stmt_old_status->execute([':id' => $_POST['status_demanda_id']]);
            $status_anterior = $stmt_old_status->fetchColumn();

            if ($status_anterior == $_POST['novo_status']) {
                $_SESSION['mensagem_info'] = "O novo status é igual ao status atual. Nenhuma alteração feita.";
            } else {
                $sql_update_status = "UPDATE demandas SET status = :novo_status, atualizado_em = NOW() WHERE id = :id";
                $stmt_update = $pdo->prepare($sql_update_status);
                $stmt_update->execute([':novo_status' => $_POST['novo_status'], ':id' => $_POST['status_demanda_id']]);

                $sql_hist = "INSERT INTO demandas_historico_status (demanda_id, usuario_id, status_anterior, novo_status, comentario) VALUES (:demanda_id, :usuario_id, :status_anterior, :novo_status, :comentario)";
                $stmt_hist = $pdo->prepare($sql_hist);
                $stmt_hist->execute([
                    ':demanda_id' => $_POST['status_demanda_id'],
                    ':usuario_id' => $usuario_logado_id,
                    ':status_anterior' => $status_anterior,
                    ':novo_status' => $_POST['novo_status'],
                    ':comentario' => !empty($_POST['status_comentario']) ? $_POST['status_comentario'] : 'Status alterado.'
                ]);
                $_SESSION['mensagem_sucesso'] = "Status da demanda ID '{$_POST['status_demanda_id']}' alterado com sucesso!";
            }
        }

        $pdo->commit();
        header("Location: demandas.php");
        exit;
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['mensagem_erro'] = "Erro: " . $e->getMessage();
        header("Location: demandas.php");
        exit;
    }
}

// --- BUSCAR E FILTRAR DADOS DO BANCO PARA EXIBIÇÃO ---
$cliente_filtro = $_GET['cliente_id'] ?? '';
$busca_texto = $_GET['busca'] ?? '';

$where_clauses = [];
$params = [];

if (!empty($cliente_filtro)) {
    $where_clauses[] = "d.cliente_id = :cliente_id";
    $params[':cliente_id'] = $cliente_filtro;
}
if (!empty($busca_texto)) {
    $where_clauses[] = "(d.titulo LIKE :busca_texto OR d.descricao LIKE :busca_texto)";
    $params[':busca_texto'] = '%' . $busca_texto . '%';
}

$where_sql = count($where_clauses) > 0 ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    // Query principal modificada para buscar a data da última mudança de status relevante
    $sql_select_demandas = "
        SELECT 
            d.*, 
            c.nome as nome_cliente, 
            u.nome as nome_responsavel, 
            uc.nome as nome_criador,
            (SELECT dhs.criado_em 
             FROM demandas_historico_status dhs 
             WHERE dhs.demanda_id = d.id AND dhs.novo_status = d.status 
             ORDER BY dhs.criado_em DESC 
             LIMIT 1) as data_mudanca_status
        FROM demandas d
        LEFT JOIN clientes c ON d.cliente_id = c.id
        LEFT JOIN usuarios u ON d.responsavel_id = u.id
        LEFT JOIN usuarios uc ON d.criado_por = uc.id
        {$where_sql}
        ORDER BY FIELD(d.prioridade, 'urgente', 'alta', 'media', 'baixa'), d.data_entrega ASC, d.id DESC
    ";
    $stmt_select = $pdo->prepare($sql_select_demandas);
    $stmt_select->execute($params);
    $demandas_brutas = $stmt_select->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar demandas por status para as colunas
    $demandas_por_status = [];
    foreach ($status_demanda_map as $key => $value) {
        $demandas_por_status[$key] = [];
    }

    foreach ($demandas_brutas as $demanda) {
        if (array_key_exists($demanda['status'], $demandas_por_status)) {
            $demandas_por_status[$demanda['status']][] = $demanda;
        }
    }

    // Aplicar o limite de 10 para 'Vídeo Publicado'
    if (isset($demandas_por_status['video_publicado'])) {
        // Ordena pela data da última atualização (que reflete a entrada no status)
        usort($demandas_por_status['video_publicado'], function($a, $b) {
            return strtotime($b['atualizado_em']) - strtotime($a['atualizado_em']);
        });
        $demandas_por_status['video_publicado'] = array_slice($demandas_por_status['video_publicado'], 0, 10);
    }

} catch (PDOException $e) {
    $demandas_por_status = [];
    if(empty($mensagem_erro)) $mensagem_erro = "Erro ao buscar demandas: " . $e->getMessage();
}

/**
 * Função para gerar a etiqueta de tempo no status.
 * @param string|null $data_mudanca_status A data em que a demanda entrou no status.
 * @return array Contendo 'texto' e 'classe_cor' da etiqueta.
 */
function getEtiquetaTempoStatus($data_mudanca_status) {
    if (!$data_mudanca_status) {
        return ['texto' => 'Desde a criação', 'classe_cor' => 'bg-secondary'];
    }
    $hoje = new DateTime();
    $data_status = new DateTime($data_mudanca_status);
    $diff = $hoje->diff($data_status)->days;

    if ($diff == 0) {
        $texto = "Desde HOJE aqui";
    } elseif ($diff == 1) {
        $texto = "Chegou ONTEM aqui";
    } else {
        $texto = "Está a {$diff} dias aqui";
    }

    if ($diff <= 2) {
        $classe_cor = 'bg-success';
    } elseif ($diff <= 4) {
        $classe_cor = 'bg-warning text-dark';
    } else {
        $classe_cor = 'bg-danger';
    }

    return ['texto' => $texto, 'classe_cor' => $classe_cor];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciamento de Demandas</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f0f2f5; }
        .main-container { margin-top: 20px; margin-bottom: 30px; }
        .page-title { font-weight: 700; color: #343a40; }
        .board-container { display: flex; overflow-x: auto; gap: 15px; padding-bottom: 15px; }
        .board-column { background-color: #e9ecef; border-radius: 12px; padding: 15px; width: 320px; flex-shrink: 0; box-shadow: 0 4px 10px rgba(0,0,0,0.1); display: flex; flex-direction: column; }
        .board-column-header { font-weight: 600; font-size: 1.1rem; padding-bottom: 10px; border-bottom: 1px solid #dee2e6; margin-bottom: 15px; color: #495057; text-align: center; }
        .demand-card-list { flex-grow: 1; min-height: 100px; }
        .demand-card { background-color: #ffffff; border-radius: 8px; padding: 15px; margin-bottom: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.07); border-left: 5px solid; transition: all 0.2s ease-in-out; }
        .demand-card:hover { transform: translateY(-3px); box-shadow: 0 5px 10px rgba(0,0,0,0.1); }
        .demand-card-title { font-weight: 600; font-size: 1rem; margin-bottom: 10px; color: #343a40; }
        .demand-card-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 15px; padding-top: 10px; border-top: 1px solid #f1f3f5; }
        .demand-card .badge { font-size: 0.75rem; padding: 0.4em 0.7em; border-radius: 0.375rem; font-weight: 500; }
        .tag-cliente { background-color: #6f42c1 !important; color: white; }
        .tag-entrega { background-color: #0dcaf0 !important; color: white; }
        .tag-tempo-status { font-size: 0.7rem; padding: 0.4em 0.6em; }
        .card-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }

        .demand-card.priority-baixa { border-left-color: #28a745; }
        .demand-card.priority-media { border-left-color: #ffc107; }
        .demand-card.priority-alta { border-left-color: #fd7e14; }
        .demand-card.priority-urgente { border-left-color: #dc3545; }
        
        .badge-prioridade-baixa { background-color: #28a745; }
        .badge-prioridade-media { background-color: #ffc107; color: #000 !important; }
        .badge-prioridade-alta { background-color: #fd7e14; }
        .badge-prioridade-urgente { background-color: #dc3545; }
        
        .modal-header { background-color: #6f42c1; color: white; }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    </style>
</head>
<body>
<?php require_once 'menu.php'; ?>
    <div class="container-fluid main-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0 page-title">Quadro de Demandas</h1>
            <div class="d-flex align-items-center gap-3">
                <form method="GET" action="demandas.php" class="d-flex align-items-center gap-3 m-0 p-0">
                    <select class="form-select" id="cliente_id_filtro" name="cliente_id" onchange="this.form.submit()">
                        <option value="">Todos os Clientes</option>
                        <?php foreach ($clientes_options as $cliente): ?>
                            <option value="<?php echo $cliente['id']; ?>" <?= ($cliente_filtro == $cliente['id']) ? 'selected' : '' ?>><?php echo htmlspecialchars($cliente['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($usuario_logado_nivel === 'gestor'): ?>
                <button type="button" class="btn btn-primary shadow-sm" style="background-color: #6f42c1; border-color: #6f42c1;" data-bs-toggle="modal" data-bs-target="#addDemandaModal">
                    <i class="fas fa-plus fa-sm"></i> Nova Demanda
                </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mensagem_sucesso): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_sucesso); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($mensagem_info): ?><div class="alert alert-info alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_info); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($mensagem_erro): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_erro); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <div class="board-container">
            <?php
            foreach ($status_display_order as $status_key):
                if (empty($demandas_por_status[$status_key]) && $status_key !== 'video_publicado') { continue; }
            ?>
                <div class="board-column">
                    <h4 class="board-column-header"><?php echo htmlspecialchars($status_demanda_map[$status_key]); ?> (<?php echo count($demandas_por_status[$status_key] ?? []); ?>)</h4>
                    <div class="demand-card-list">
                        <?php if(!empty($demandas_por_status[$status_key])): ?>
                        <?php foreach ($demandas_por_status[$status_key] as $demanda): ?>
                            <?php
                                $prioridade_class = 'priority-' . $demanda['prioridade'];
                                $etiqueta_tempo = getEtiquetaTempoStatus($demanda['data_mudanca_status'] ?? $demanda['atualizado_em']);
                            ?>
                            <div class="demand-card <?= $prioridade_class ?>" id="demanda-<?php echo $demanda['id']; ?>">
                                <p class="demand-card-title"><?php echo htmlspecialchars($demanda['titulo']); ?></p>
                                
                                <div class="card-tags">
                                    <span class="badge tag-cliente"><?php echo htmlspecialchars($demanda['nome_cliente'] ?? 'N/A'); ?></span>
                                    
                                    <?php if ($demanda['status'] === 'pendente'): ?>
                                        <span class="badge tag-entrega">
                                            <i class="fas fa-calendar-alt fa-xs"></i> Para entrega em: <?php echo date('d/m/Y', strtotime($demanda['data_entrega'])); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if ($demanda['status'] === 'necessita_nova_edicao'): ?>
                                         <span class="badge bg-warning text-dark">NECESSITA NOVA EDIÇÃO</span>
                                    <?php endif; ?>
                                </div>

                                <div class="mt-2">
                                    <span class="badge rounded-pill tag-tempo-status <?php echo $etiqueta_tempo['classe_cor']; ?>">
                                        <i class="fas fa-clock fa-xs"></i> <?php echo $etiqueta_tempo['texto']; ?>
                                    </span>
                                </div>

                                <div class="demand-card-footer">
                                    <span class="badge <?php echo 'badge-prioridade-' . $demanda['prioridade']; ?>">
                                        <?php echo htmlspecialchars($prioridades_demanda[$demanda['prioridade']] ?? ucfirst($demanda['prioridade'])); ?>
                                    </span>
                                    <div class="action-buttons">
                                        <a href="criardemanda.php?id=<?php echo htmlspecialchars($demanda['id']); ?>" class="btn btn-outline-secondary btn-sm" title="Visualizar Detalhes"><i class="fas fa-eye"></i></a>
                                        <button type="button" class="btn btn-outline-primary btn-sm status-demanda-btn" title="Alterar Status" data-bs-toggle="modal" data-bs-target="#statusDemandaModal" data-id="<?php echo htmlspecialchars($demanda['id']); ?>" data-titulo="<?php echo htmlspecialchars($demanda['titulo']); ?>" data-status-atual="<?php echo htmlspecialchars($demanda['status']); ?>"><i class="fas fa-sync-alt"></i></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($demandas_por_status[$status_key])): ?>
                    <a href="todasdemandas.php?status=<?php echo htmlspecialchars($status_key); ?>" class="btn btn-outline-secondary btn-sm w-100 mt-auto">Ver Todas</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modais (Add, Status Change) -->
    <?php if ($usuario_logado_nivel === 'gestor'): ?>
    <div class="modal fade" id="addDemandaModal" tabindex="-1" aria-labelledby="addDemandaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <form action="demandas.php" method="POST">
                    <input type="hidden" name="acao" value="adicionar_demanda">
                    <div class="modal-header"><h5 class="modal-title" id="addDemandaModalLabel">Adicionar Nova Demanda</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                         <div class="row"><div class="col-md-12 mb-3"><label for="titulo" class="form-label">Título <span class="text-danger">*</span></label><input type="text" class="form-control" id="titulo" name="titulo" required></div></div>
                        <div class="row">
                            <div class="col-md-6 mb-3"><label for="cliente_id" class="form-label">Cliente <span class="text-danger">*</span></label><select class="form-select" id="cliente_id" name="cliente_id" required><option value="">Selecione</option><?php foreach ($clientes_options as $cliente): ?><option value="<?php echo $cliente['id']; ?>"><?php echo htmlspecialchars($cliente['nome']); ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-6 mb-3"><label for="responsavel_id" class="form-label">Responsável <span class="text-danger">*</span></label><select class="form-select" id="responsavel_id" name="responsavel_id" required><option value="">Selecione</option><?php foreach ($usuarios_options as $usuario): ?><option value="<?php echo $usuario['id']; ?>"><?php echo htmlspecialchars($usuario['nome']); ?></option><?php endforeach; ?></select></div>
                        </div>
                        <div class="mb-3"><label for="descricao" class="form-label">Descrição</label><textarea class="form-control" id="descricao" name="descricao" rows="3"></textarea></div>
                        <div class="row">
                            <div class="col-md-4 mb-3"><label for="data_entrega" class="form-label">Entrega <span class="text-danger">*</span></label><input type="date" class="form-control" id="data_entrega" name="data_entrega" required></div>
                            <div class="col-md-4 mb-3"><label for="prioridade" class="form-label">Prioridade</label><select class="form-select" id="prioridade" name="prioridade"><?php foreach($prioridades_demanda as $key => $value): ?><option value="<?php echo $key; ?>" <?php echo ($key == 'media' ? 'selected' : ''); ?>><?php echo $value; ?></option><?php endforeach; ?></select></div>
                            <div class="col-md-4 mb-3"><label for="status" class="form-label">Status</label><select class="form-select" id="status" name="status"><?php foreach($status_demanda_map as $key => $value): ?><option value="<?php echo $key; ?>" <?php echo ($key == 'pendente' ? 'selected' : ''); ?>><?php echo $value; ?></option><?php endforeach; ?></select></div>
                        </div>
                        <hr><h6 class="mb-3">Captação (Opcional)</h6>
                        <div class="row">
                            <div class="col-md-4 mb-3"><label for="data_captacao" class="form-label">Data</label><input type="date" class="form-control" id="data_captacao" name="data_captacao"></div>
                            <div class="col-md-2 mb-3"><label for="hora_captacao_inicio" class="form-label">Início</label><input type="time" class="form-control" id="hora_captacao_inicio" name="hora_captacao_inicio"></div>
                            <div class="col-md-2 mb-3"><label for="hora_captacao_fim" class="form-label">Fim</label><input type="time" class="form-control" id="hora_captacao_fim" name="hora_captacao_fim"></div>
                            <div class="col-md-4 mb-3"><label for="local_captacao" class="form-label">Local</label><input type="text" class="form-control" id="local_captacao" name="local_captacao"></div>
                        </div>
                        <div class="mb-3"><label for="material_bruto_url" class="form-label">Link Material Bruto</label><input type="url" class="form-control" id="material_bruto_url" name="material_bruto_url" placeholder="https://"></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button><button type="submit" class="btn btn-primary">Salvar Demanda</button></div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="statusDemandaModal" tabindex="-1" aria-labelledby="statusDemandaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-md modal-dialog-centered">
            <div class="modal-content">
                <form action="demandas.php" method="POST">
                    <input type="hidden" name="acao" value="alterar_status_demanda">
                    <input type="hidden" name="status_demanda_id" id="status_demanda_id">
                    <div class="modal-header"><h5 class="modal-title" id="statusDemandaModalLabel">Alterar Status da Demanda</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                         <p>Demanda: <strong id="status_demanda_titulo"></strong></p>
                        <p>Status Atual: <span id="status_demanda_atual_texto" class="fw-bold"></span></p>
                        <div class="mb-3"><label for="novo_status" class="form-label">Novo Status <span class="text-danger">*</span></label><select class="form-select" id="novo_status" name="novo_status" required><option value="">Selecione</option><?php foreach($status_demanda_map as $key => $value): ?><option value="<?php echo $key; ?>"><?php echo $value; ?></option><?php endforeach; ?></select></div>
                        <div class="mb-3"><label for="status_comentario" class="form-label">Comentário (Opcional)</label><textarea class="form-control" id="status_comentario" name="status_comentario" rows="2" placeholder="Ex: Material recebido."></textarea></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success">Salvar Novo Status</button></div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const statusDemandaJS = <?php echo json_encode($status_demanda_map); ?>;
        document.addEventListener('DOMContentLoaded', function () {
            var statusDemandaModal = document.getElementById('statusDemandaModal');
            if (statusDemandaModal) {
                statusDemandaModal.addEventListener('show.bs.modal', function (event) {
                    var button = event.relatedTarget;
                    document.getElementById('status_demanda_id').value = button.getAttribute('data-id');
                    document.getElementById('status_demanda_titulo').textContent = button.getAttribute('data-titulo');
                    let statusAtual = button.getAttribute('data-status-atual');
                    document.getElementById('status_demanda_atual_texto').textContent = statusDemandaJS[statusAtual] || statusAtual;
                    var novoStatusSelect = document.getElementById('novo_status');
                    for (var i = 0; i < novoStatusSelect.options.length; i++) {
                         novoStatusSelect.options[i].disabled = (novoStatusSelect.options[i].value === statusAtual);
                    }
                    novoStatusSelect.value = '';
                });
            }
        });
    </script>
</body>
</html>
