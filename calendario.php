<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'conexao.php';

date_default_timezone_set('America/Sao_Paulo');
$usuario_logado_id = $_SESSION['usuario_id'] ?? null;
$usuario_logado_nivel = $_SESSION['usuario_nivel'] ?? 'editor';

if (!$usuario_logado_id) {
    header('Location: login.php');
    exit;
}

// --- LÓGICA DO CALENDÁRIO ---
$mes_atual = isset($_GET['mes']) ? (int)$_GET['mes'] : date('m');
$ano_atual = isset($_GET['ano']) ? (int)$_GET['ano'] : date('Y');

$primeiro_dia_mes_timestamp = mktime(0, 0, 0, $mes_atual, 1, $ano_atual);
$dias_no_mes = date('t', $primeiro_dia_mes_timestamp);
$primeiro_dia_semana = date('w', $primeiro_dia_mes_timestamp);

$mes_anterior = $mes_atual == 1 ? 12 : $mes_atual - 1;
$ano_anterior = $mes_atual == 1 ? $ano_atual - 1 : $ano_atual;
$mes_seguinte = $mes_atual == 12 ? 1 : $mes_atual + 1;
$ano_seguinte = $mes_atual == 12 ? $ano_atual + 1 : $ano_atual;

setlocale(LC_TIME, 'pt_BR', 'pt_BR.utf-8', 'portuguese');
$nome_mes_atual = strftime('%B de %Y', $primeiro_dia_mes_timestamp);

// --- BUSCA DE DADOS PARA FILTROS E EVENTOS ---
$php_error_message = null;
$eventos_por_dia = [];
try {
    $clientes_filtro = $pdo->query("SELECT id, nome FROM clientes WHERE status = 'ativo' ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);
    $responsaveis_filtro = $pdo->query("SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC")->fetchAll(PDO::FETCH_ASSOC);

    $cliente_id_filtro = filter_input(INPUT_GET, 'cliente_id', FILTER_VALIDATE_INT);
    $responsavel_id_filtro = filter_input(INPUT_GET, 'responsavel_id', FILTER_VALIDATE_INT);

    $sql_base = "SELECT id, titulo, data_entrega, data_captacao, prioridade FROM demandas";
    $conditions = [];
    $params = [];
    
    if ($cliente_id_filtro) {
        $conditions[] = "cliente_id = :cliente_id";
        $params[':cliente_id'] = $cliente_id_filtro;
    }
    if ($responsavel_id_filtro) {
        $conditions[] = "responsavel_id = :responsavel_id";
        $params[':responsavel_id'] = $responsavel_id_filtro;
    }
     if ($usuario_logado_nivel === 'editor') {
        $conditions[] = "responsavel_id = :usuario_logado_id";
        $params[':usuario_logado_id'] = $usuario_logado_id;
    }

    if (!empty($conditions)) {
        $sql_base .= " WHERE " . implode(" AND ", $conditions);
    }

    $stmt = $pdo->prepare($sql_base);
    $stmt->execute($params);
    $demandas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($demandas as $demanda) {
        if (!empty($demanda['data_entrega']) && $demanda['data_entrega'] != '0000-00-00') {
            $eventos_por_dia[$demanda['data_entrega']][] = ['tipo' => 'prazo', 'titulo' => $demanda['titulo'], 'prioridade' => $demanda['prioridade'], 'id' => $demanda['id']];
        }
        if (!empty($demanda['data_captacao']) && $demanda['data_captacao'] != '0000-00-00') {
            $eventos_por_dia[$demanda['data_captacao']][] = ['tipo' => 'captacao', 'titulo' => $demanda['titulo'], 'id' => $demanda['id']];
        }
    }

} catch (PDOException $e) {
    $php_error_message = "Erro ao carregar dados: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendário Manual de Atividades</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { 
            --primary-color: #6f42c1; --light-bg: #f4f6f9; --card-border-color: #e9ecef;
            --text-primary-color: #212529; --text-secondary-color: #6c757d;
        }
        body { font-family: 'Inter', sans-serif; background-color: var(--light-bg); }
        .main-container { padding-top: 20px; padding-bottom: 40px; }
        .calendar-wrapper { display: flex; flex-wrap: wrap; gap: 1.5rem; }
        .calendar-main { flex: 1; min-width: 70%; }
        .calendar-sidebar { width: 100%; max-width: 280px; flex-shrink: 0; }
        .card { background-color: #fff; border: none; border-radius: .75rem; box-shadow: 0 4px 30px rgba(0,0,0,.08); }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; background-color: #fff; border-bottom: 1px solid var(--card-border-color); }
        .calendar-title { font-size: 1.5rem; font-weight: 700; color: var(--primary-color); text-transform: capitalize; }
        .calendar-nav a { color: var(--primary-color); text-decoration: none; font-weight: 500; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); }
        .calendar-weekday { background-color: #fafafa; text-align: center; padding: .75rem .5rem; font-weight: 600; font-size: 0.8rem; color: var(--text-secondary-color); text-transform: uppercase; border-bottom: 1px solid var(--card-border-color); }
        .calendar-day { min-height: 120px; border-right: 1px solid var(--card-border-color); border-bottom: 1px solid var(--card-border-color); padding: 8px; font-size: 0.85rem; transition: background-color .2s; }
        .calendar-day:nth-child(7n) { border-right: none; }
        .calendar-day-number { font-weight: 600; }
        .day-other-month { background-color: #f8f9fa; color: #ccc; }
        .day-today .calendar-day-number { color: white; background-color: var(--primary-color); border-radius: 50%; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center; }
        .event-list { margin-top: 8px; list-style: none; padding-left: 0; }
        .event-item { font-size: 0.8rem; padding: 3px 6px; margin-bottom: 4px; border-radius: .25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .event-item a { color: inherit; text-decoration: none; }
        .event-prazo-urgente { background-color: rgba(220, 53, 69, 0.2); color: #842029; }
        .event-prazo-alta { background-color: rgba(253, 126, 20, 0.2); color: #9A520A; }
        .event-prazo-media { background-color: rgba(255, 193, 7, 0.2); color: #806502; }
        .event-prazo-baixa { background-color: rgba(25, 135, 84, 0.2); color: #145A32; }
        .event-captacao { background-color: rgba(13, 110, 253, 0.2); color: #032895; }
    </style>
</head>
<body>
    <?php require_once 'menu.php'; ?>
    <div class="container-fluid main-container">
        <h1 class="h3 page-title mb-4 font-weight-bold">Calendário de Atividades</h1>
        <?php if ($php_error_message): ?>
            <div class="alert alert-danger"><strong>Erro Crítico:</strong> <?php echo htmlspecialchars($php_error_message); ?></div>
        <?php endif; ?>

        <div class="calendar-wrapper">
            <div class="calendar-main card">
                <div class="calendar-header">
                    <a href="?mes=<?php echo $mes_anterior; ?>&ano=<?php echo $ano_anterior; ?>"><i class="fas fa-chevron-left"></i></a>
                    <div class="calendar-title"><?php echo $nome_mes_atual; ?></div>
                    <a href="?mes=<?php echo $mes_seguinte; ?>&ano=<?php echo $ano_seguinte; ?>"><i class="fas fa-chevron-right"></i></a>
                </div>
                <div class="calendar-grid">
                    <div class="calendar-weekday">Dom</div><div class="calendar-weekday">Seg</div><div class="calendar-weekday">Ter</div>
                    <div class="calendar-weekday">Qua</div><div class="calendar-weekday">Qui</div><div class="calendar-weekday">Sex</div><div class="calendar-weekday">Sáb</div>
                    
                    <?php for ($i = 0; $i < $primeiro_dia_semana; $i++): ?><div class="calendar-day day-other-month"></div><?php endfor; ?>
                    <?php for ($dia = 1; $dia <= $dias_no_mes; $dia++): ?>
                        <?php
                        $data_completa = $ano_atual . '-' . str_pad($mes_atual, 2, '0', STR_PAD_LEFT) . '-' . str_pad($dia, 2, '0', STR_PAD_LEFT);
                        $classe_hoje = (date('Y-m-d') == $data_completa) ? 'day-today' : '';
                        ?>
                        <div class="calendar-day <?php echo $classe_hoje; ?>">
                            <div class="calendar-day-number"><?php echo $dia; ?></div>
                            <?php if (isset($eventos_por_dia[$data_completa])): ?>
                                <ul class="event-list">
                                    <?php foreach ($eventos_por_dia[$data_completa] as $evento): ?>
                                        <?php
                                        $classe_evento = 'event-' . $evento['tipo'];
                                        if ($evento['tipo'] == 'prazo') {
                                            $classe_evento .= '-' . $evento['prioridade'];
                                        }
                                        $icone = $evento['tipo'] == 'prazo' ? 'fa-flag-checkered' : 'fa-video';
                                        ?>
                                        <li class="event-item <?php echo $classe_evento; ?>">
                                            <a href="criardemanda.php?id=<?php echo $evento['id']; ?>" title="<?php echo htmlspecialchars($evento['titulo']); ?>">
                                                <i class="fas <?php echo $icone; ?> me-1"></i><?php echo htmlspecialchars($evento['titulo']); ?>
                                            </a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endfor; ?>
                    <?php 
                    $total_celulas = $primeiro_dia_semana + $dias_no_mes;
                    $celulas_restantes = (7 - ($total_celulas % 7)) % 7;
                    for ($i = 0; $i < $celulas_restantes; $i++): ?>
                        <div class="calendar-day day-other-month"></div>
                    <?php endfor; ?>
                </div>
            </div>
            <div class="calendar-sidebar">
                <div class="card">
                    <div class="card-body">
                        <h5><i class="fas fa-filter me-2"></i>Filtros</h5>
                        <form id="filter-form" method="GET" action="calendario.php">
                            <input type="hidden" name="mes" value="<?php echo $mes_atual; ?>">
                            <input type="hidden" name="ano" value="<?php echo $ano_atual; ?>">
                            <div class="mb-3">
                                <label for="cliente_filtro" class="form-label small fw-bold">Cliente</label>
                                <select id="cliente_filtro" name="cliente_id" class="form-select form-select-sm">
                                    <option value="">Todos os Clientes</option>
                                    <?php foreach($clientes_filtro as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php if (($cliente_id_filtro ?? '') == $c['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($c['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="responsavel_filtro" class="form-label small fw-bold">Responsável</label>
                                <select id="responsavel_filtro" name="responsavel_id" class="form-select form-select-sm" <?php if($usuario_logado_nivel === 'editor') echo 'disabled'; ?>>
                                    <option value="">Todos</option>
                                    <?php foreach($responsaveis_filtro as $r): ?>
                                        <option value="<?php echo $r['id']; ?>" <?php if (($responsavel_id_filtro ?? ($usuario_logado_nivel === 'editor' ? $usuario_logado_id : '')) == $r['id']) echo 'selected'; ?>>
                                            <?php echo htmlspecialchars($r['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 mb-2">Aplicar Filtros</button>
                            <a href="calendario.php?mes=<?php echo date('m'); ?>&ano=<?php echo date('Y'); ?>" class="btn btn-sm btn-outline-secondary w-100">Limpar e Voltar para Hoje</a>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
