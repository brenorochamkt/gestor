<?php
if(!isset($pdo) && file_exists('conexao.php')){
    require_once 'conexao.php';
}

$nivel_usuario = $_SESSION['usuario_nivel'] ?? 'editor'; 
$nome_usuario = $_SESSION['usuario_nome'] ?? 'Usuário';
$avatar_letras = strtoupper(substr($nome_usuario, 0, 2));

function is_active_page($page_name) {
    return basename($_SERVER['PHP_SELF']) == $page_name;
}
?>
<style>
    :root {
        --primary-color: #6f42c1;
        --primary-color-light: rgba(111, 66, 193, 0.1);
        --text-primary: #212529;
        --text-secondary: #6c757d;
        --border-color: #e9ecef;
        --navbar-height: 70px;
    }
    body {
        padding-top: var(--navbar-height);
        background-color: #f4f6f9;
    }
    .main-navbar {
        height: var(--navbar-height);
        width: 100%;
        background-color: #ffffff;
        border-bottom: 1px solid var(--border-color);
        padding: 0 2rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: fixed;
        top: 0;
        left: 0;
        z-index: 1030;
        box-shadow: 0 4px 15px rgba(0,0,0,.05);
    }
    .navbar-brand-custom {
        font-size: 1.6rem;
        font-weight: 800;
        color: var(--primary-color);
        text-decoration: none;
        display: flex;
        align-items: center;
    }
    .navbar-brand-custom i {
        margin-right: 0.75rem;
        transition: transform 0.3s ease;
    }
    .navbar-brand-custom:hover i {
        transform: rotate(15deg) scale(1.1);
    }
    .navbar-nav-container {
        flex-grow: 1;
        display: flex;
        justify-content: center;
    }
    .navbar-nav-main .nav-item {
        margin: 0 0.25rem;
    }
    .navbar-nav-main .nav-link {
        color: var(--text-secondary);
        font-weight: 600;
        font-size: 0.95rem;
        padding: .75rem 1.25rem;
        border-radius: .5rem;
        display: flex;
        align-items: center;
        position: relative;
        transition: color .2s ease, background-color .2s ease;
    }
     .navbar-nav-main .nav-link::after {
        content: '';
        position: absolute;
        bottom: 5px;
        left: 50%;
        transform: translateX(-50%);
        width: 0;
        height: 3px;
        background-color: var(--primary-color);
        border-radius: 3px;
        transition: width 0.3s ease;
    }
    .navbar-nav-main .nav-link:hover {
        color: var(--primary-color);
    }
    .navbar-nav-main .nav-link.active {
        color: var(--primary-color);
    }
     .navbar-nav-main .nav-link.active::after {
        width: 40%;
    }
    .navbar-nav-main .nav-link i {
        margin-right: 0.6rem;
        width: 20px;
        text-align: center;
    }
    .user-profile-dropdown .dropdown-toggle::after {
        display: none;
    }
    .user-profile-avatar {
        width: 42px;
        height: 42px;
        border-radius: 50%;
        background-color: var(--primary-color);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        cursor: pointer;
        border: 2px solid #fff;
        box-shadow: 0 0 10px rgba(0,0,0,.1);
        transition: transform 0.2s ease, box-shadow .2s ease;
    }
     .user-profile-avatar:hover {
        transform: scale(1.1);
        box-shadow: 0 0 15px rgba(111, 66, 193, .5);
    }
    .profile-dropdown .dropdown-item {
        font-weight: 500;
        padding: .5rem 1rem;
    }
    .profile-dropdown .dropdown-item i {
        width: 24px;
        opacity: 0.7;
    }
</style>

<nav class="main-navbar">
    <div class="navbar-brand-container">
        <a href="dashboard.php" class="navbar-brand-custom">
            <i class="fas fa-rocket"></i>PIXEL
        </a>
    </div>

    <div class="navbar-nav-container">
        <ul class="nav navbar-nav-main">
            <li class="nav-item">
                <a class="nav-link <?php if(is_active_page('dashboard.php')) echo 'active'; ?>" href="dashboard.php">
                    <i class="fas fa-tachometer-alt fa-fw"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if(is_active_page('clientes_gerenciamento.php')) echo 'active'; ?>" href="clientes_gerenciamento.php">
                    <i class="fas fa-users fa-fw"></i> Clientes
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if(is_active_page('demandas.php')) echo 'active'; ?>" href="demandas.php">
                    <i class="fas fa-tasks fa-fw"></i> Demandas
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php if(is_active_page('calendario.php')) echo 'active'; ?>" href="calendario.php">
                    <i class="fas fa-calendar-alt fa-fw"></i> Calendário
                </a>
            </li>
        </ul>
    </div>

    <div class="dropdown user-profile-dropdown">
        <a href="#" class="d-block text-decoration-none dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <div class="user-profile-avatar" title="<?= htmlspecialchars($nome_usuario) ?>">
                <?= $avatar_letras ?>
            </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end profile-dropdown">
             <li><h6 class="dropdown-header">Olá, <?= htmlspecialchars($nome_usuario) ?></h6></li>
             <li><a class="dropdown-item" href="#"><i class="fas fa-user-edit fa-fw text-muted"></i> Meu Perfil</a></li>
             <li><a class="dropdown-item" href="#"><i class="fas fa-cog fa-fw text-muted"></i> Configurações</a></li>
             <li><hr class="dropdown-divider"></li>
             <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-sign-out-alt fa-fw"></i> Sair</a></li>
        </ul>
    </div>
</nav>

<script>
    if (typeof bootstrap === 'undefined') {
        var script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js';
        document.body.appendChild(script);
    }
</script>
