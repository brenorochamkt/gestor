<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexao.php';

// --- CONFIGURAÇÕES E DADOS DO USUÁRIO ---
date_default_timezone_set('America/Sao_Paulo');
$usuario_logado_id = $_SESSION['usuario_id'] ?? 1;
$nome_usuario = $_SESSION['usuario_nome'] ?? 'Usuário';

$mensagem_sucesso = $_SESSION['mensagem_sucesso'] ?? '';
$mensagem_erro = $_SESSION['mensagem_erro'] ?? '';
unset($_SESSION['mensagem_sucesso'], $_SESSION['mensagem_erro']);

// --- FUNÇÃO AUXILIAR PARA TEMPO RELATIVO ---
function time_ago($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = ['y' => 'ano', 'm' => 'mês', 'w' => 'semana', 'd' => 'dia', 'h' => 'hora', 'i' => 'minuto', 's' => 'segundo'];
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? 'há ' . implode(', ', $string) : 'agora mesmo';
}

// --- COLETA DE DADOS PARA O DASHBOARD ---
$kpis = [];
$demandas_atrasadas = [];
$demandas_necessita_edicao = [];
$atividade_recente = [];
$demandas_por_dia_chart = ['labels' => [], 'data' => []];
$hoje_data = date('Y-m-d');
$amanha_data = date('Y-m-d', strtotime('+1 day'));
$status_finalizados = ['concluido', 'video_publicado', 'cliente_aprovou'];

try {
    // KPIs
    $kpis['clientes_ativos'] = $pdo->query("SELECT COUNT(*) FROM clientes WHERE status = 'ativo'")->fetchColumn();
    $kpis['demandas_pendentes'] = $pdo->query("SELECT COUNT(*) FROM demandas WHERE status = 'pendente'")->fetchColumn();
    
    // Entregas para Hoje (Contagem e Lista)
    $stmt_hoje_count = $pdo->prepare("SELECT COUNT(*) FROM demandas WHERE DATE(data_entrega) = ? AND status NOT IN ('".implode("','", $status_finalizados)."')");
    $stmt_hoje_count->execute([$hoje_data]);
    $kpis['entregar_hoje'] = $stmt_hoje_count->fetchColumn();
    $stmt_hoje_lista = $pdo->prepare("SELECT id, titulo FROM demandas WHERE DATE(data_entrega) = ? AND status NOT IN ('".implode("','", $status_finalizados)."') ORDER BY prioridade DESC, id DESC LIMIT 3");
    $stmt_hoje_lista->execute([$hoje_data]);
    $kpis['lista_entregar_hoje'] = $stmt_hoje_lista->fetchAll(PDO::FETCH_ASSOC);

    // Entregas para Amanhã (Contagem e Lista)
    $stmt_amanha_count = $pdo->prepare("SELECT COUNT(*) FROM demandas WHERE DATE(data_entrega) = ? AND status NOT IN ('".implode("','", $status_finalizados)."')");
    $stmt_amanha_count->execute([$amanha_data]);
    $kpis['entregar_amanha'] = $stmt_amanha_count->fetchColumn();
    $stmt_amanha_lista = $pdo->prepare("SELECT id, titulo FROM demandas WHERE DATE(data_entrega) = ? AND status NOT IN ('".implode("','", $status_finalizados)."') ORDER BY prioridade DESC, id DESC LIMIT 3");
    $stmt_amanha_lista->execute([$amanha_data]);
    $kpis['lista_entregar_amanha'] = $stmt_amanha_lista->fetchAll(PDO::FETCH_ASSOC);


    // Feed de Atividade Recente
    $sql_atividade = "
        (SELECT 'comentario' as tipo, d.id as demanda_id, d.titulo as demanda_titulo, u.nome as usuario_nome, c.criado_em as data_evento, c.comentario as detalhe
         FROM demandas_comentarios c
         JOIN usuarios u ON c.usuario_id = u.id
         JOIN demandas d ON c.demanda_id = d.id)
        UNION ALL
        (SELECT 'historico' as tipo, d.id as demanda_id, d.titulo as demanda_titulo, u.nome as usuario_nome, h.criado_em as data_evento, h.comentario as detalhe
         FROM demandas_historico_status h
         JOIN usuarios u ON h.usuario_id = u.id
         JOIN demandas d ON h.demanda_id = d.id)
        ORDER BY data_evento DESC
        LIMIT 7
    ";
    $atividade_recente = $pdo->query($sql_atividade)->fetchAll(PDO::FETCH_ASSOC);

    // Demandas em Atraso (APENAS STATUS 'pendente')
    $sql_atrasadas = "SELECT c.nome as nome_cliente, d.id as demanda_id, d.titulo as titulo_demanda, d.data_entrega 
                      FROM demandas d JOIN clientes c ON d.cliente_id = c.id 
                      WHERE d.data_entrega < ? AND d.status = 'pendente'
                      ORDER BY d.data_entrega ASC LIMIT 5";
    $stmt_atrasadas = $pdo->prepare($sql_atrasadas);
    $stmt_atrasadas->execute([$hoje_data]);
    $demandas_atrasadas = $stmt_atrasadas->fetchAll(PDO::FETCH_ASSOC);

    // Demandas que necessitam de nova edição
    $sql_edicao = "SELECT d.id, d.titulo, c.nome as nome_cliente FROM demandas d JOIN clientes c ON d.cliente_id = c.id WHERE d.status = 'necessita_nova_edicao' ORDER BY d.atualizado_em ASC LIMIT 5";
    $demandas_necessita_edicao = $pdo->query($sql_edicao)->fetchAll(PDO::FETCH_ASSOC);
    
    // Gráfico de Demandas Criadas por Dia
    $primeiro_dia_mes = date('Y-m-01');
    $ultimo_dia_mes = date('Y-m-t');
    $stmt_chart = $pdo->prepare("SELECT DATE_FORMAT(criado_em, '%d/%m') as dia, COUNT(*) as total FROM demandas WHERE criado_em BETWEEN ? AND ? GROUP BY dia ORDER BY criado_em ASC");
    $stmt_chart->execute([$primeiro_dia_mes . " 00:00:00", $ultimo_dia_mes . " 23:59:59"]);
    $chart_data_raw = $stmt_chart->fetchAll(PDO::FETCH_ASSOC);
    foreach($chart_data_raw as $row) {
        $demandas_por_dia_chart['labels'][] = $row['dia'];
        $demandas_por_dia_chart['data'][] = $row['total'];
    }

} catch (PDOException $e) {
    $mensagem_erro = "Erro ao carregar dados do dashboard: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Agência</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        :root { 
            --primary-color: #6f42c1; --light-bg: #f8f9fa; --card-border-color: #e9ecef;
            --text-primary: #212529; --text-secondary: #6c757d;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--light-bg); }
        .main-container { padding-top: 20px; padding-bottom: 40px; }
        .page-header h1 { font-weight: 800; }
        .kpi-card-link { text-decoration: none; }
        .kpi-card { background: #fff; border: 1px solid var(--card-border-color); border-radius: .75rem; padding: 1.5rem; transition: all .3s ease; display: flex; flex-direction: column; height: 100%; }
        .kpi-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,.08); }
        .kpi-main-content { display: flex; align-items: center; gap: 1rem; }
        .kpi-icon { font-size: 1.75rem; width: 60px; height: 60px; border-radius: .5rem; display: flex; align-items: center; justify-content: center; }
        .kpi-value { font-size: 2.25rem; font-weight: 800; line-height: 1; color: var(--text-primary); }
        .kpi-label { font-size: .9rem; font-weight: 500; color: var(--text-secondary); }
        .kpi-details { margin-top: 1rem; font-size: 0.8rem; color: var(--text-secondary); border-top: 1px solid var(--card-border-color); padding-top: .75rem; }
        .kpi-details ul { padding-left: 0; list-style: none; margin-bottom: 0; }
        .kpi-details li { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .card { border: none; border-radius: .75rem; box-shadow: 0 4px 30px rgba(0,0,0,.05); }
        .card-header { background-color: #fff; border-bottom: 1px solid var(--card-border-color); font-weight: 700; color: var(--text-primary); }
        .list-group-item { border-color: var(--card-border-color); }
        .activity-feed .list-group-item { display: flex; align-items: center; gap: 1rem; }
        .activity-avatar { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600; color: #fff; }
        .activity-text { flex: 1; font-size: 0.9rem; }
        .activity-time { font-size: 0.8rem; color: var(--text-secondary); }
    </style>
</head>
<body>
    <?php require_once 'menu.php'; ?>
    <div class="container-fluid main-container">
        <div class="page-header mb-4 d-flex justify-content-between align-items-center">
            <h1><span class="fw-normal">Bem-vindo,</span> <?php echo htmlspecialchars($nome_usuario); ?>!</h1>
            <div id="real-time-clock" class="text-end text-muted fw-light"></div>
        </div>

        <?php if ($mensagem_sucesso): ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_sucesso); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($mensagem_erro): ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($mensagem_erro); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        
        <div class="row g-4 mb-4">
            <div class="col-xl-3 col-md-6"><a href="clientes_gerenciamento.php" class="kpi-card-link"><div class="kpi-card"><div class="kpi-main-content"><div class="kpi-icon bg-primary-subtle text-primary"><i class="fas fa-users"></i></div><div><div class="kpi-value"><?= $kpis['clientes_ativos'] ?? 0 ?></div><div class="kpi-label">Clientes Ativos</div></div></div></div></a></div>
            <div class="col-xl-3 col-md-6"><a href="demandas.php" class="kpi-card-link"><div class="kpi-card"><div class="kpi-main-content"><div class="kpi-icon bg-warning-subtle text-warning"><i class="fas fa-hourglass-half"></i></div><div><div class="kpi-value"><?= $kpis['demandas_pendentes'] ?? 0 ?></div><div class="kpi-label">Demandas Pendentes</div></div></div></div></a></div>
            <div class="col-xl-3 col-md-6"><a href="demandas.php" class="kpi-card-link"><div class="kpi-card"><div class="kpi-main-content"><div class="kpi-icon bg-danger-subtle text-danger"><i class="fas fa-calendar-day"></i></div><div><div class="kpi-value"><?= $kpis['entregar_hoje'] ?? 0 ?></div><div class="kpi-label">Entregas para Hoje</div></div></div><?php if(!empty($kpis['lista_entregar_hoje'])): ?><div class="kpi-details"><ul><?php foreach($kpis['lista_entregar_hoje'] as $d): ?><li><i class="fas fa-caret-right fa-xs"></i> <?= htmlspecialchars($d['titulo']) ?></li><?php endforeach; ?></ul></div><?php endif; ?></div></a></div>
            <div class="col-xl-3 col-md-6"><a href="demandas.php" class="kpi-card-link"><div class="kpi-card"><div class="kpi-main-content"><div class="kpi-icon bg-info-subtle text-info"><i class="fas fa-calendar-week"></i></div><div><div class="kpi-value"><?= $kpis['entregar_amanha'] ?? 0 ?></div><div class="kpi-label">Entregas para Amanhã</div></div></div><?php if(!empty($kpis['lista_entregar_amanha'])): ?><div class="kpi-details"><ul><?php foreach($kpis['lista_entregar_amanha'] as $d): ?><li><i class="fas fa-caret-right fa-xs"></i> <?= htmlspecialchars($d['titulo']) ?></li><?php endforeach; ?></ul></div><?php endif; ?></div></a></div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header"><i class="fas fa-stream me-2"></i>Atividade Recente</div>
                    <div class="list-group list-group-flush activity-feed">
                        <?php if (empty($atividade_recente)): ?>
                            <div class="list-group-item text-center p-4 text-muted">Nenhuma atividade recente.</div>
                        <?php else: foreach ($atividade_recente as $item): ?>
                            <?php 
                                $avatar_color = 'bg-secondary';
                                if ($item['tipo'] == 'comentario') $avatar_color = 'bg-primary';
                                if ($item['tipo'] == 'historico') $avatar_color = 'bg-info';
                            ?>
                            <a href="criardemanda.php?id=<?= $item['demanda_id'] ?>" class="list-group-item list-group-item-action">
                                <div class="activity-avatar <?= $avatar_color ?>"><i class="fas <?= $item['tipo'] == 'comentario' ? 'fa-comment-dots' : 'fa-pencil-alt'?> fa-sm"></i></div>
                                <div class="activity-text">
                                    <strong><?= htmlspecialchars($item['usuario_nome']) ?></strong> 
                                    <?= $item['tipo'] == 'comentario' ? 'comentou em' : 'atualizou' ?>
                                    <strong>"<?= htmlspecialchars($item['demanda_titulo']) ?>"</strong>
                                </div>
                                <div class="activity-time text-nowrap"><?= time_ago($item['data_evento']) ?></div>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card mb-4">
                    <div class="card-header"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>Demandas em Atraso (Status Pendente)</div>
                     <div class="list-group list-group-flush">
                        <?php if (empty($demandas_atrasadas)): ?>
                            <div class="list-group-item text-center p-4 text-muted"><i class="fas fa-check-circle text-success fa-2x mb-2"></i><br>Nenhuma demanda pendente em atraso.</div>
                        <?php else: foreach ($demandas_atrasadas as $item): ?>
                            <a href="criardemanda.php?id=<?= $item['demanda_id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold"><?= htmlspecialchars($item['titulo_demanda']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($item['nome_cliente']) ?></small>
                                </div>
                                <span class="badge bg-danger-subtle text-danger-emphasis rounded-pill">Vencida em <?= date('d/m/Y', strtotime($item['data_entrega'])) ?></span>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
                 <div class="card">
                    <div class="card-header"><i class="fas fa-edit me-2 text-warning"></i>Demandas "Necessita Nova Edição"</div>
                    <div class="list-group list-group-flush">
                         <?php if (empty($demandas_necessita_edicao)): ?>
                            <div class="list-group-item text-center p-4 text-muted"><i class="fas fa-check-circle text-success fa-2x mb-2"></i><br>Nenhuma demanda aguardando edição.</div>
                        <?php else: foreach ($demandas_necessita_edicao as $item): ?>
                            <a href="criardemanda.php?id=<?= $item['id'] ?>" class="list-group-item list-group-item-action">
                                <div class="fw-bold"><?= htmlspecialchars($item['titulo']) ?></div>
                                <small class="text-muted"><?= htmlspecialchars($item['nome_cliente']) ?></small>
                            </a>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row g-4 mt-2">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><i class="fas fa-chart-bar me-2"></i>Demandas Criadas por Dia (Mês Atual)</div>
                    <div class="card-body p-4">
                        <canvas id="demandasPorDiaChart" style="min-height: 250px;"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Real-time Clock
            const clockElement = document.getElementById('real-time-clock');
            if(clockElement) {
                const updateClock = () => {
                    const now = new Date();
                    const months = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
                    const day = now.getDate();
                    const month = months[now.getMonth()];
                    const year = now.getFullYear();
                    const hours = String(now.getHours()).padStart(2, '0');
                    const minutes = String(now.getMinutes()).padStart(2, '0');
                    const seconds = String(now.getSeconds()).padStart(2, '0');
                    clockElement.textContent = `${day} de ${month} de ${year} às ${hours}:${minutes}:${seconds}`;
                };
                setInterval(updateClock, 1000);
                updateClock();
            }

            // Chart
            const chartLabels = <?php echo json_encode($demandas_por_dia_chart['labels']); ?>;
            const chartData = <?php echo json_encode($demandas_por_dia_chart['data']); ?>;
            const chartCtx = document.getElementById('demandasPorDiaChart');
            if (chartCtx && chartData.length > 0) {
                new Chart(chartCtx, {
                    type: 'bar',
                    data: {
                        labels: chartLabels,
                        datasets: [{
                            label: 'Demandas Criadas',
                            data: chartData,
                            backgroundColor: 'rgba(111, 66, 193, 0.6)',
                            borderColor: 'rgba(111, 66, 193, 1)',
                            borderWidth: 1,
                            borderRadius: 4,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        });
    </script>
</body>
</html>
