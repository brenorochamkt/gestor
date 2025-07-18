<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexao.php';

// Simulações para o menu e dados do usuário (substitua pela sua lógica de sessão)
$usuario_logado_id = $_SESSION['usuario_id'] ?? 1; // Certifique-se de que este ID é do usuário correto
$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'gestor';
$nome_usuario = $_SESSION['usuario_nome'] ?? 'Usuário';
// $notificacoes_nao_lidas será buscado do banco mais abaixo

$mensagem_sucesso = $_SESSION['mensagem_sucesso'] ?? '';
$mensagem_erro = $_SESSION['mensagem_erro'] ?? '';
unset($_SESSION['mensagem_sucesso'], $_SESSION['mensagem_erro']);

// --- PROCESSAR AÇÕES (Marcar como lida) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao_notificacao = $_POST['acao_notificacao'] ?? '';

    try {
        if ($acao_notificacao === 'marcar_lida' && isset($_POST['notificacao_id'])) {
            $notificacao_id = filter_var($_POST['notificacao_id'], FILTER_VALIDATE_INT);
            if ($notificacao_id) {
                $sql_mark_read = "UPDATE notificacoes SET lida = 1, lida_em = NOW() 
                                  WHERE id = :notificacao_id AND usuario_id = :usuario_id AND lida = 0";
                $stmt_mark = $pdo->prepare($sql_mark_read);
                $stmt_mark->execute([':notificacao_id' => $notificacao_id, ':usuario_id' => $usuario_logado_id]);
                if ($stmt_mark->rowCount() > 0) {
                    $_SESSION['mensagem_sucesso'] = "Notificação marcada como lida.";
                }
            } else {
                $_SESSION['mensagem_erro'] = "ID da notificação inválido.";
            }
        } elseif ($acao_notificacao === 'marcar_todas_lidas') {
            $sql_mark_all_read = "UPDATE notificacoes SET lida = 1, lida_em = NOW() 
                                  WHERE usuario_id = :usuario_id AND lida = 0";
            $stmt_mark_all = $pdo->prepare($sql_mark_all_read);
            $stmt_mark_all->execute([':usuario_id' => $usuario_logado_id]);
            if ($stmt_mark_all->rowCount() > 0) {
                 $_SESSION['mensagem_sucesso'] = "Todas as notificações foram marcadas como lidas.";
            } else {
                 $_SESSION['mensagem_info'] = "Nenhuma notificação nova para marcar como lida."; // Usar uma classe de info
            }
        }
        header("Location: notificacoes.php");
        exit;
    } catch (PDOException $e) {
        $_SESSION['mensagem_erro'] = "Erro ao atualizar notificações: " . $e->getMessage();
        header("Location: notificacoes.php");
        exit;
    }
}


// --- BUSCAR NOTIFICAÇÕES DO USUÁRIO ---
$notificacoes = [];
$notificacoes_nao_lidas_count = 0; // Para o contador do menu
try {
    $stmt_notif = $pdo->prepare("SELECT id, titulo, mensagem, lida, link, criado_em, lida_em 
                                 FROM notificacoes 
                                 WHERE usuario_id = :usuario_id 
                                 ORDER BY criado_em DESC");
    $stmt_notif->execute([':usuario_id' => $usuario_logado_id]);
    $notificacoes = $stmt_notif->fetchAll(PDO::FETCH_ASSOC);

    // Contar não lidas para o menu
    $stmt_unread_count = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = :usuario_id AND lida = 0");
    $stmt_unread_count->execute([':usuario_id' => $usuario_logado_id]);
    $notificacoes_nao_lidas = $stmt_unread_count->fetchColumn(); // Atualiza a variável do menu

} catch (PDOException $e) {
    if (empty($mensagem_erro)) {
        $mensagem_erro = "Erro ao carregar notificações: " . $e->getMessage();
    }
}
if (isset($_SESSION['mensagem_info'])) { // Para a mensagem de "nenhuma nova"
    $mensagem_info = $_SESSION['mensagem_info'];
    unset($_SESSION['mensagem_info']);
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minhas Notificações - AgênciaSys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }
        .navbar-brand i { font-size: 1.5rem; }
        .navbar .nav-link { font-size: 0.95rem; }
        .navbar .nav-link.active { font-weight: 600; }
        .navbar .dropdown-menu { font-size: 0.95rem; }
        .navbar .dropdown-item i { width: 20px; }

        .main-container {
            padding-top: 20px;
            padding-bottom: 30px;
        }
        .page-title {
            font-weight: 700;
            color: #343a40;
        }
        .notification-list .list-group-item {
            border-radius: 0.375rem;
            margin-bottom: 10px;
            border-left-width: 5px;
            transition: background-color 0.3s ease;
        }
        .notification-list .list-group-item.unread {
            background-color: #e9f5ff; /* Um azul bem claro para não lidas */
            border-left-color: #0d6efd; /* Azul primário */
        }
        .notification-list .list-group-item.read {
            background-color: #f8f9fa; /* Um cinza bem claro para lidas */
            border-left-color: #6c757d; /* Cinza secundário */
            opacity: 0.85;
        }
        .notification-list .list-group-item:hover {
            background-color: #e2e6ea;
        }
        .notification-title {
            font-weight: 600;
            color: #212529;
        }
        .notification-message {
            font-size: 0.95rem;
            color: #495057;
        }
        .notification-date {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .notification-actions .btn {
            font-size: 0.8rem;
            padding: 0.25rem 0.5rem;
        }
        .notification-link {
            font-weight: 500;
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">
                <i class="fas fa-bullhorn me-2"></i>AgênciaSys
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNavDropdown">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="fas fa-tachometer-alt me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'clientes.php' ? 'active' : ''; ?>" href="clientes.php">
                            <i class="fas fa-users me-1"></i>Clientes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'demandas.php' ? 'active' : ''; ?>" href="demandas.php">
                            <i class="fas fa-tasks me-1"></i>Demandas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'calendario.php' ? 'active' : ''; ?>" href="calendario.php">
                            <i class="fas fa-calendar-alt me-1"></i>Calendário
                        </a>
                    </li>
                    <?php if ($nivel_usuario === 'gestor'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'usuarios.php' ? 'active' : ''; ?>" href="usuarios.php">
                            <i class="fas fa-user-shield me-1"></i>Usuários
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link position-relative <?php echo basename($_SERVER['PHP_SELF']) == 'notificacoes.php' ? 'active' : ''; ?>" href="notificacoes.php" title="Notificações">
                            <i class="fas fa-bell"></i>
                            <?php if ($notificacoes_nao_lidas > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    <?php echo $notificacoes_nao_lidas; ?>
                                    <span class="visually-hidden">notificações não lidas</span>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle <?php echo basename($_SERVER['PHP_SELF']) == 'perfil.php' ? 'active' : ''; ?>" href="#" id="navbarDropdownUserMenu" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-circle me-1"></i><?php echo htmlspecialchars($nome_usuario); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarDropdownUserMenu">
                            <li><a class="dropdown-item" href="perfil.php"><i class="fas fa-user-edit me-2"></i>Meu Perfil</a></li>
                            <li><a class="dropdown-item" href="configuracoes.php"><i class="fas fa-cog me-2"></i>Configurações</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Sair</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container main-container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h2 page-title">Minhas Notificações</h1>
            <?php if ($notificacoes_nao_lidas > 0): ?>
            <form action="notificacoes.php" method="POST" class="d-inline">
                <input type="hidden" name="acao_notificacao" value="marcar_todas_lidas">
                <button type="submit" class="btn btn-sm btn-outline-primary">
                    <i class="fas fa-check-double me-1"></i>Marcar todas como lidas
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php if ($mensagem_sucesso): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($mensagem_sucesso); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($mensagem_info)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($mensagem_info); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($mensagem_erro): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($mensagem_erro); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="notification-list">
            <?php if (empty($notificacoes)): ?>
                <div class="alert alert-light text-center" role="alert">
                    <i class="fas fa-bell-slash fa-3x mb-3 text-muted"></i>
                    <p class="mb-0">Você não tem nenhuma notificação no momento.</p>
                </div>
            <?php else: ?>
                <ul class="list-group">
                    <?php foreach ($notificacoes as $notificacao): ?>
                        <li class="list-group-item <?php echo $notificacao['lida'] ? 'read' : 'unread'; ?>">
                            <div class="d-flex w-100 justify-content-between">
                                <div>
                                    <h5 class="mb-1 notification-title">
                                        <?php if (!$notificacao['lida']): ?>
                                            <i class="fas fa-circle text-primary me-2" style="font-size: 0.6em; vertical-align: middle;"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($notificacao['titulo']); ?>
                                    </h5>
                                    <p class="mb-1 notification-message"><?php echo nl2br(htmlspecialchars($notificacao['mensagem'])); ?></p>
                                    <small class="notification-date">
                                        <i class="far fa-clock"></i> <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($notificacao['criado_em']))); ?>
                                        <?php if ($notificacao['lida'] && $notificacao['lida_em']): ?>
                                            | Lida em: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($notificacao['lida_em']))); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="notification-actions text-end align-self-center">
                                    <?php if ($notificacao['link']): ?>
                                        <a href="<?php echo htmlspecialchars($notificacao['link']); ?>" class="btn btn-outline-secondary btn-sm mb-1 notification-link" title="Ver Detalhes">
                                            <i class="fas fa-external-link-alt"></i> Ver
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!$notificacao['lida']): ?>
                                        <form action="notificacoes.php" method="POST" class="d-inline">
                                            <input type="hidden" name="acao_notificacao" value="marcar_lida">
                                            <input type="hidden" name="notificacao_id" value="<?php echo $notificacao['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary" title="Marcar como lida">
                                                <i class="fas fa-check"></i> Lida
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
