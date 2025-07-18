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
}

$usuario_logado_id = $_SESSION['usuario_id'] ?? null;
$usuario_logado_nivel = $_SESSION['usuario_nivel'] ?? 'convidado';

$status_solicitado = isset($_GET['status']) ? trim($_GET['status']) : null;
$demandas_listadas = [];
$nome_status_display = 'Status Desconhecido';

$mensagem_sucesso = $_SESSION['mensagem_sucesso'] ?? null;
$mensagem_erro = $_SESSION['mensagem_erro'] ?? null;
$mensagem_info = $_SESSION['mensagem_info'] ?? null;

unset($_SESSION['mensagem_sucesso'], $_SESSION['mensagem_erro'], $_SESSION['mensagem_info']);

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
$status_para_verificar_atraso = ['pendente', 'em_andamento'];
$hoje = date('Y-m-d');

// --- Lógica de POST para Alterar Status (se o modal for submetido nesta página) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao']) && $_POST['acao'] === 'alterar_status_demanda_lista') {
    if ($usuario_logado_id) {
        $pdo->beginTransaction();
        try {
            $demanda_id_alt_status = $_POST['status_demanda_id_lista'] ?? null;
            $novo_status_alt = $_POST['novo_status_lista'] ?? null;

            if (!$demanda_id_alt_status || !$novo_status_alt) {
                throw new Exception("Dados insuficientes para alterar o status.");
            }

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
                    ':comentario' => !empty($_POST['status_comentario_lista']) ? trim($_POST['status_comentario_lista']) : 'Status alterado via lista.'
                ]);
                $_SESSION['mensagem_sucesso'] = "Status da demanda ID '{$demanda_id_alt_status}' alterado com sucesso!";
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['mensagem_erro'] = "Erro de Banco de Dados: " . $e->getMessage();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['mensagem_erro'] = "Erro: " . $e->getMessage();
        }
        // Redireciona de volta para a mesma página de lista de status
        header("Location: todasdemandas.php?status=" . urlencode($status_solicitado));
        exit;
    } else {
        $_SESSION['mensagem_erro'] = "Ação não permitida ou usuário não logado.";
        header("Location: todasdemandas.php?status=" . urlencode($status_solicitado));
        exit;
    }
}


// --- Validar e Buscar Demandas ---
if ($status_solicitado && array_key_exists($status_solicitado, $status_demanda_map)) {
    $nome_status_display = $status_demanda_map[$status_solicitado];
    try {
        $sql = "SELECT d.id, d.titulo, d.cliente_id, d.data_entrega, d.responsavel_id, d.prioridade, d.status, 
                       c.nome as nome_cliente, u.nome as nome_responsavel
                FROM demandas d
                LEFT JOIN clientes c ON d.cliente_id = c.id
                LEFT JOIN usuarios u ON d.responsavel_id = u.id
                WHERE d.status = :status
                ORDER BY FIELD(d.prioridade, 'urgente', 'alta', 'media', 'baixa'), d.data_entrega ASC, d.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':status' => $status_solicitado]);
        $demandas_listadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $mensagem_erro = "Erro ao buscar demandas: " . $e->getMessage();
    }
} elseif ($status_solicitado === 'nao_mapeado') { // Caso especial para status não mapeados
    $nome_status_display = 'Status Não Mapeado';
     try {
        // Coleta todos os status conhecidos para excluí-los da busca por "não mapeado"
        $known_statuses_placeholders = implode(',', array_fill(0, count($status_demanda_map), '?'));
        $sql = "SELECT d.id, d.titulo, d.cliente_id, d.data_entrega, d.responsavel_id, d.prioridade, d.status, 
                       c.nome as nome_cliente, u.nome as nome_responsavel
                FROM demandas d
                LEFT JOIN clientes c ON d.cliente_id = c.id
                LEFT JOIN usuarios u ON d.responsavel_id = u.id
                WHERE d.status NOT IN ($known_statuses_placeholders) OR d.status IS NULL
                ORDER BY FIELD(d.prioridade, 'urgente', 'alta', 'media', 'baixa'), d.data_entrega ASC, d.id DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_keys($status_demanda_map));
        $demandas_listadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $mensagem_erro = "Erro ao buscar demandas com status não mapeado: " . $e->getMessage();
    }
} else {
    if ($status_solicitado) { // Se um status foi fornecido mas não é válido
        $mensagem_erro = "O status solicitado ('" . htmlspecialchars($status_solicitado) . "') não é válido.";
    } else { // Se nenhum status foi fornecido
        $mensagem_erro = "Nenhum status foi especificado para listar as demandas.";
    }
    // Não define $nome_status_display aqui, pois já tem o default "Status Desconhecido"
}

$titulo_pagina = "Demandas: " . htmlspecialchars($nome_status_display);

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo_pagina; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --kanban-bg-color: #f4f7f9; 
            --card-border-color: #e3e8ee;
            --text-color-primary: #343a40;
            --text-color-secondary: #566573;
            --color-atrasada-border: #e74c3c; 
            --color-atrasada-text: #c0392b;
            --color-pendente-border: #3498db;
            --prioridade-urgente: #e74c3c; 
            --prioridade-alta: #f39c12;    
            --prioridade-media: #3498db;   
            --prioridade-baixa: #2ecc71;   
        }
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: var(--kanban-bg-color);
        }
        .main-header { 
            background-color: #ffffff; 
            padding: 0.8rem 1.5rem; 
            border-bottom: 1px solid var(--card-border-color);
            margin-bottom: 20px; 
        }
        .page-title { 
            font-weight: 600; 
            color: var(--text-color-primary); 
            font-size: 1.4rem; 
        }
        .main-container {
            padding: 0 15px 20px 15px;
        }
        .table th, .table td {
            vertical-align: middle;
            font-size: 0.9rem;
        }
        .table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--text-color-primary);
        }
        .table-hover tbody tr:hover {
            background-color: #f1f5f9;
        }
        .action-buttons .btn {
            margin-right: 5px;
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }
        .badge { 
            font-size: 0.8em; 
            padding: 0.35em 0.6em; 
            border-radius: 0.25rem;
            font-weight: 500;
        } 
        .badge-prioridade-urgente { background-color: var(--prioridade-urgente); color: white; }
        .badge-prioridade-alta { background-color: var(--prioridade-alta); color: white; }
        .badge-prioridade-media { background-color: var(--prioridade-media); color: white; }
        .badge-prioridade-baixa { background-color: var(--prioridade-baixa); color: white; }

        .demanda-atrasada td {
            /* background-color: #fff0f0; Opcional: fundo sutil para a linha toda */
        }
        .demanda-atrasada .data-entrega-display,
        .demanda-atrasada .titulo-demanda-display {
            color: var(--color-atrasada-text) !important;
            font-weight: bold;
        }
        .demanda-pendente .titulo-demanda-display {
            /* color: var(--color-pendente-border); Opcional */
        }
        .modal-header { background-color: #6f42c1; color: white; }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
    </style>
</head>
<body>

    <?php include_once 'menu.php'; ?>

    <div class="main-header">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0 page-title"><?php echo $titulo_pagina; ?></h1>
            <a href="demandas.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-1"></i> Voltar ao Kanban
            </a>
        </div>
    </div>

    <div class="container-fluid main-container">
        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_sucesso); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
        <?php if ($mensagem_erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_erro); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>
        <?php if ($mensagem_info): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_info); ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php endif; ?>

        <?php if (!empty($demandas_listadas)): ?>
            <div class="card shadow-sm">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Título</th>
                                    <th>Cliente</th>
                                    <th>Entrega</th>
                                    <th>Responsável</th>
                                    <th class="text-center">Prioridade</th>
                                    <th class="text-center">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($demandas_listadas as $demanda): ?>
                                    <?php
                                        $atrasada = false;
                                        if ($demanda['data_entrega'] < $hoje && in_array($demanda['status'], $status_para_verificar_atraso)) {
                                            $atrasada = true;
                                        }
                                        $row_class = $atrasada ? 'demanda-atrasada' : '';
                                        if (!$atrasada && $demanda['status'] === 'pendente') {
                                            $row_class .= ' demanda-pendente'; // Pode acumular com outras classes se necessário
                                        }
                                    ?>
                                    <tr class="<?php echo trim($row_class); ?>">
                                        <td><?php echo $demanda['id']; ?></td>
                                        <td class="titulo-demanda-display">
                                            <?php if ($atrasada): ?>
                                                <i class="fas fa-exclamation-triangle text-danger me-1" title="Demanda Atrasada"></i>
                                            <?php elseif ($demanda['status'] === 'pendente'): ?>
                                                <i class="fas fa-hourglass-half me-1" title="Demanda Pendente" style="color: var(--color-pendente-border);"></i>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($demanda['titulo']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($demanda['nome_cliente'] ?? 'N/A'); ?></td>
                                        <td class="data-entrega-display">
                                            <?php echo htmlspecialchars(date('d/m/Y', strtotime($demanda['data_entrega']))); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($demanda['nome_responsavel'] ?? 'N/A'); ?></td>
                                        <td class="text-center">
                                            <?php
                                                $prioridade_class_name = 'badge-prioridade-media'; // Default
                                                if ($demanda['prioridade'] === 'baixa') $prioridade_class_name = 'badge-prioridade-baixa';
                                                elseif ($demanda['prioridade'] === 'alta') $prioridade_class_name = 'badge-prioridade-alta';
                                                elseif ($demanda['prioridade'] === 'urgente') $prioridade_class_name = 'badge-prioridade-urgente';
                                            ?>
                                            <span class="badge <?php echo $prioridade_class_name; ?>">
                                                <?php echo htmlspecialchars($prioridades_demanda[$demanda['prioridade']] ?? ucfirst($demanda['prioridade'])); ?>
                                            </span>
                                        </td>
                                        <td class="text-center action-buttons">
                                            <a href="criardemanda.php?id=<?php echo $demanda['id']; ?>" class="btn btn-outline-primary btn-sm" title="Visualizar/Editar Detalhes">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-secondary btn-sm status-demanda-btn-lista" title="Alterar Status"
                                                data-bs-toggle="modal" data-bs-target="#modalAlterarStatusLista"
                                                data-id="<?php echo htmlspecialchars($demanda['id']); ?>"
                                                data-titulo="<?php echo htmlspecialchars($demanda['titulo']); ?>"
                                                data-status-atual="<?php echo htmlspecialchars($demanda['status']); ?>">
                                                <i class="fas fa-sync-alt"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php elseif (empty($mensagem_erro)): // Se não há erro, mas a lista está vazia ?>
            <div class="alert alert-info text-center" role="alert">
                Nenhuma demanda encontrada para o status "<?php echo htmlspecialchars($nome_status_display); ?>".
            </div>
        <?php endif; ?>
    </div>

    <div class="modal fade" id="modalAlterarStatusLista" tabindex="-1" aria-labelledby="modalAlterarStatusListaLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="todasdemandas.php?status=<?php echo urlencode($status_solicitado ?? ''); ?>">
                    <input type="hidden" name="acao" value="alterar_status_demanda_lista">
                    <input type="hidden" name="status_demanda_id_lista" id="status_demanda_id_lista_modal">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalAlterarStatusListaLabel">Alterar Status da Demanda</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Demanda: <strong id="status_demanda_titulo_lista_modal"></strong></p>
                        <p>Status Atual: <strong id="status_demanda_atual_texto_lista_modal"></strong></p>
                        <div class="mb-3">
                            <label for="novo_status_lista_modal" class="form-label">Novo Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="novo_status_lista_modal" name="novo_status_lista" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($status_demanda_map as $key => $value): ?>
                                    <option value="<?php echo $key; ?>"><?php echo $value; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="status_comentario_lista_modal" class="form-label">Comentário (Opcional)</label>
                            <textarea class="form-control" id="status_comentario_lista_modal" name="status_comentario_lista" rows="2" placeholder="Motivo da alteração, se necessário..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" style="background-color: #6f42c1; border-color: #6f42c1;">Salvar Novo Status</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const statusDemandaJSMap = <?php echo json_encode($status_demanda_map); ?>;

        document.addEventListener('DOMContentLoaded', function () {
            const modalAlterarStatusLista = document.getElementById('modalAlterarStatusLista');
            if (modalAlterarStatusLista) {
                modalAlterarStatusLista.addEventListener('show.bs.modal', function (event) {
                    const button = event.relatedTarget;
                    const demandaId = button.getAttribute('data-id');
                    const demandaTitulo = button.getAttribute('data-titulo');
                    const statusAtualKey = button.getAttribute('data-status-atual');
                    
                    const modalIdInput = modalAlterarStatusLista.querySelector('#status_demanda_id_lista_modal');
                    const modalTituloStrong = modalAlterarStatusLista.querySelector('#status_demanda_titulo_lista_modal');
                    const modalStatusAtualStrong = modalAlterarStatusLista.querySelector('#status_demanda_atual_texto_lista_modal');
                    const modalNovoStatusSelect = modalAlterarStatusLista.querySelector('#novo_status_lista_modal');
                    const modalComentarioTextarea = modalAlterarStatusLista.querySelector('#status_comentario_lista_modal');

                    modalIdInput.value = demandaId;
                    modalTituloStrong.textContent = demandaTitulo;
                    modalStatusAtualStrong.textContent = statusDemandaJSMap[statusAtualKey] || statusAtualKey;
                    modalComentarioTextarea.value = ''; // Limpa comentário anterior
                    
                    // Desabilitar a opção do status atual no select
                    Array.from(modalNovoStatusSelect.options).forEach(option => {
                        option.disabled = (option.value === statusAtualKey);
                    });
                    modalNovoStatusSelect.value = ''; // Reseta a seleção
                });
            }
        });
    </script>
</body>
</html>
