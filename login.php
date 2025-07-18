<?php
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'conexao.php';

if (!function_exists('gerarToken')) {
    function gerarToken($length = 32) {
        return bin2hex(random_bytes($length));
    }
}

if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

$erro_login = '';
$email_persistido = '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_COOKIE['lembrar_token_pixel']) && isset($_COOKIE['lembrar_id_pixel'])) {
    $usuario_id_cookie = filter_var($_COOKIE['lembrar_id_pixel'], FILTER_VALIDATE_INT);
    $token_cookie = $_COOKIE['lembrar_token_pixel'];

    if ($usuario_id_cookie && $token_cookie) {
        try {
            $stmt_cookie = $pdo->prepare("
                SELECT t.usuario_id, t.token, t.expira_em, u.nome, u.nivel, u.email
                FROM tokens_autenticacao t
                JOIN usuarios u ON t.usuario_id = u.id
                WHERE t.usuario_id = :usuario_id AND t.token = :token AND t.expira_em > NOW()
            ");
            $stmt_cookie->execute([':usuario_id' => $usuario_id_cookie, ':token' => $token_cookie]);
            $token_valido = $stmt_cookie->fetch(PDO::FETCH_OBJ);

            if ($token_valido) {
                $_SESSION['usuario_id'] = $token_valido->usuario_id;
                $_SESSION['usuario_nome'] = $token_valido->nome;
                $_SESSION['usuario_nivel'] = $token_valido->nivel;
                $_SESSION['usuario_email'] = $token_valido->email;

                // Opcional: Renovar token "Lembrar de mim"
                $novo_token_renovado = gerarToken();
                $nova_expiracao_ts_renovado = time() + (60 * 60 * 24 * 30); // 30 dias
                $nova_expiracao_db_renovado = date('Y-m-d H:i:s', $nova_expiracao_ts_renovado);

                $stmt_update_token = $pdo->prepare("UPDATE tokens_autenticacao SET token = :novo_token, expira_em = :nova_expiracao WHERE usuario_id = :usuario_id AND token = :token_antigo");
                $stmt_update_token->execute([
                    ':novo_token' => $novo_token_renovado,
                    ':nova_expiracao' => $nova_expiracao_db_renovado,
                    ':usuario_id' => $usuario_id_cookie,
                    ':token_antigo' => $token_cookie
                ]);
                
                $cookie_path = '/'; // Ajuste se necessário
                $is_secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'; // True se HTTPS
                setcookie('lembrar_token_pixel', $novo_token_renovado, $nova_expiracao_ts_renovado, $cookie_path, '', $is_secure, true);
                setcookie('lembrar_id_pixel', (string)$usuario_id_cookie, $nova_expiracao_ts_renovado, $cookie_path, '', $is_secure, true);


                header('Location: dashboard.php');
                exit;
            } else {
                $cookie_path = '/';
                setcookie('lembrar_token_pixel', '', time() - 3600, $cookie_path);
                setcookie('lembrar_id_pixel', '', time() - 3600, $cookie_path);
            }
        } catch (PDOException $e) {
            error_log("Erro PDO na verificação do token de login: " . $e->getMessage());
            $erro_login = 'Ocorreu um erro. Tente novamente.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email_form = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $senha_form = $_POST['senha'] ?? '';
    $lembrar_form = isset($_POST['lembrar']);
    $email_persistido = $email_form;

    if (empty($email_form) || empty($senha_form)) {
        $erro_login = 'Por favor, preencha e-mail e senha.';
    } else {
        try {
            $stmt_login = $pdo->prepare("SELECT id, nome, email, senha, nivel, ativo FROM usuarios WHERE email = :email");
            $stmt_login->execute([':email' => $email_form]);
            $usuario = $stmt_login->fetch(PDO::FETCH_OBJ);

            if ($usuario && $usuario->ativo == 1 && password_verify($senha_form, $usuario->senha)) {
                $_SESSION['usuario_id'] = $usuario->id;
                $_SESSION['usuario_nome'] = $usuario->nome;
                $_SESSION['usuario_nivel'] = $usuario->nivel;
                $_SESSION['usuario_email'] = $usuario->email;

                $cookie_path = '/'; // Ajuste se sua aplicação estiver em um subdiretório
                $is_secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';


                if ($lembrar_form) {
                    $token_lembrar = gerarToken();
                    $expiracao_ts_lembrar = time() + (60 * 60 * 24 * 30); // 30 dias
                    $expiracao_db_lembrar = date('Y-m-d H:i:s', $expiracao_ts_lembrar);

                    $stmt_delete_old_tokens = $pdo->prepare("DELETE FROM tokens_autenticacao WHERE usuario_id = :usuario_id");
                    $stmt_delete_old_tokens->execute([':usuario_id' => $usuario->id]);

                    $stmt_insert_token = $pdo->prepare("INSERT INTO tokens_autenticacao (usuario_id, token, expira_em) VALUES (:usuario_id, :token, :expira_em)");
                    $stmt_insert_token->execute([
                        ':usuario_id' => $usuario->id,
                        ':token' => $token_lembrar,
                        ':expira_em' => $expiracao_db_lembrar
                    ]);
                    
                    setcookie('lembrar_token_pixel', $token_lembrar, $expiracao_ts_lembrar, $cookie_path, '', $is_secure, true);
                    setcookie('lembrar_id_pixel', (string)$usuario->id, $expiracao_ts_lembrar, $cookie_path, '', $is_secure, true);
                } else {
                    if (isset($_COOKIE['lembrar_token_pixel'])) {
                        setcookie('lembrar_token_pixel', '', time() - 3600, $cookie_path);
                    }
                    if (isset($_COOKIE['lembrar_id_pixel'])) {
                        setcookie('lembrar_id_pixel', '', time() - 3600, $cookie_path);
                    }
                    $stmt_delete_tokens = $pdo->prepare("DELETE FROM tokens_autenticacao WHERE usuario_id = :usuario_id");
                    $stmt_delete_tokens->execute([':usuario_id' => $usuario->id]);
                }

                header('Location: dashboard.php');
                exit;
            } elseif ($usuario && $usuario->ativo == 0) {
                $erro_login = 'Sua conta está desativada. Contate o administrador.';
            } else {
                $erro_login = 'E-mail ou senha incorretos.';
            }
        } catch (PDOException $e) {
            error_log("Erro PDO no processo de login: " . $e->getMessage());
            $erro_login = 'Ocorreu um erro no sistema. Tente novamente mais tarde.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - PIXEL Agência</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6f42c1; /* Roxo PIXEL */
            --primary-color-dark: #5a35a2;
            --secondary-color: #f8f9fa;
            --text-color: #333;
            --input-bg-color: #f0f0f0;
            --input-border-color: #ced4da;
            --card-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            --animation-duration: 0.4s;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #8e44ad 0%, #3498db 100%); /* Gradiente roxo/azul */
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden; /* Para evitar barras de rolagem desnecessárias com animações */
        }

        .login-wrapper {
            position: relative;
            max-width: 450px;
            width: 100%;
        }

        .login-card {
            background: #ffffff;
            padding: 40px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            animation: fadeInScale var(--animation-duration) ease-out forwards;
            opacity: 0;
            transform: scale(0.95);
        }

        @keyframes fadeInScale {
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .login-header .logo-icon {
            font-size: 4rem; /* Ícone maior */
            color: var(--primary-color);
            margin-bottom: 15px;
            display: inline-block;
            animation: iconBounce 0.8s ease-out;
        }
        
        @keyframes iconBounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .login-header h1 {
            font-weight: 700;
            font-size: 2rem;
            color: var(--text-color);
            letter-spacing: -0.5px;
        }
        .login-header h1 .pixel-text {
            color: var(--primary-color);
        }

        .form-floating > .form-control {
            height: calc(3.5rem + 2px);
            line-height: 1.25;
            padding: 1rem 0.75rem;
            background-color: var(--input-bg-color);
            border: 1px solid var(--input-border-color);
            border-radius: 8px;
            transition: all 0.2s ease-in-out;
        }
        .form-floating > .form-control:focus {
            background-color: #fff;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(111, 66, 193, 0.25);
        }
        .form-floating > label {
            padding: 1rem 0.75rem;
            color: #6c757d;
        }

        .btn-login {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            padding: 0.75rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: 8px;
            transition: background-color 0.2s ease-in-out, transform 0.1s ease;
        }
        .btn-login:hover {
            background-color: var(--primary-color-dark);
            border-color: var(--primary-color-dark);
            transform: translateY(-2px);
        }
        .btn-login:active {
            transform: translateY(0);
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        .form-check-label {
            font-size: 0.9rem;
            color: #555;
        }
        .extra-links {
            text-align: center;
            margin-top: 20px;
        }
        .extra-links a {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.85rem;
            transition: color 0.2s ease;
        }
        .extra-links a:hover {
            color: var(--primary-color-dark);
            text-decoration: underline;
        }
        .alert-danger {
            background-color: #f8d7da;
            border-color: #f5c2c7;
            color: #842029;
            padding: 0.8rem 1rem;
            font-size: 0.9rem;
            border-radius: 8px;
        }
        /* Animação para os campos de formulário ao carregar */
        .form-floating {
            opacity: 0;
            transform: translateY(10px);
            animation: fadeInUp var(--animation-duration) ease-out forwards;
        }
        .form-floating:nth-child(1) { animation-delay: 0.1s; }
        .form-floating:nth-child(2) { animation-delay: 0.2s; }
        .form-check, .btn-login, .extra-links {
            opacity: 0;
            transform: translateY(10px);
            animation: fadeInUp var(--animation-duration) ease-out forwards;
        }
        .form-check { animation-delay: 0.3s; }
        .btn-login { animation-delay: 0.4s; }
        .extra-links { animation-delay: 0.5s; }

        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Partículas no fundo (opcional) */
        #particles-js {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            z-index: -1; /* Para ficar atrás do conteúdo */
        }

    </style>
</head>
<body>
    <div id="particles-js"></div>

    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-rocket logo-icon"></i> <h1>Bem-vindo à <span class="pixel-text">PIXEL</span></h1>
            </div>

            <?php if ($erro_login): ?>
                <div class="alert alert-danger py-2 mb-3"><?php echo htmlspecialchars($erro_login); ?></div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <div class="form-floating mb-3">
                    <input type="email" class="form-control" id="email" name="email" placeholder="seu@email.com" value="<?php echo htmlspecialchars($email_persistido); ?>" required autofocus>
                    <label for="email">E-mail</label>
                </div>
                <div class="form-floating mb-3">
                    <input type="password" class="form-control" id="senha" name="senha" placeholder="Sua Senha" required>
                    <label for="senha">Senha</label>
                </div>
                <div class="mb-3 form-check">
                    <input type="checkbox" class="form-check-input" id="lembrar" name="lembrar">
                    <label class="form-check-label" for="lembrar">Lembrar de mim</label>
                </div>
                <button type="submit" class="btn btn-login w-100">
                    <i class="fas fa-sign-in-alt me-2"></i>Entrar
                </button>
            </form>
            <div class="extra-links">
                <a href="esqueci_senha.php">Esqueceu a senha?</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/particles.js/2.0.0/particles.min.js"></script>
    <script>
        // Configuração do particles.js (opcional)
        if (document.getElementById('particles-js')) {
            particlesJS("particles-js", {
                "particles": {
                    "number": {
                        "value": 80, // Densidade das partículas
                        "density": {
                            "enable": true,
                            "value_area": 800
                        }
                    },
                    "color": {
                        "value": "#ffffff" // Cor das partículas
                    },
                    "shape": {
                        "type": "circle", // Formato (circle, edge, triangle, polygon, star, image)
                    },
                    "opacity": {
                        "value": 0.3, // Opacidade
                        "random": false,
                        "anim": {
                            "enable": false,
                            "speed": 1,
                            "opacity_min": 0.1,
                            "sync": false
                        }
                    },
                    "size": {
                        "value": 3, // Tamanho
                        "random": true,
                        "anim": {
                            "enable": false,
                            "speed": 40,
                            "size_min": 0.1,
                            "sync": false
                        }
                    },
                    "line_linked": {
                        "enable": true,
                        "distance": 150, // Distância para ligar as partículas
                        "color": "#ffffff",
                        "opacity": 0.2, // Opacidade das linhas
                        "width": 1
                    },
                    "move": {
                        "enable": true,
                        "speed": 2, // Velocidade do movimento
                        "direction": "none",
                        "random": false,
                        "straight": false,
                        "out_mode": "out", // Comportamento ao sair da tela (out, bounce)
                        "bounce": false,
                    }
                },
                "interactivity": {
                    "detect_on": "canvas",
                    "events": {
                        "onhover": {
                            "enable": true,
                            "mode": "grab" // Efeito ao passar o mouse (grab, repulse, bubble)
                        },
                        "onclick": {
                            "enable": true,
                            "mode": "push" // Efeito ao clicar (push, remove, bubble)
                        },
                        "resize": true
                    },
                    "modes": {
                        "grab": {
                            "distance": 140,
                            "line_linked": {
                                "opacity": 0.7
                            }
                        },
                        "bubble": {
                            "distance": 400,
                            "size": 40,
                            "duration": 2,
                            "opacity": 8,
                            "speed": 3
                        },
                        "repulse": {
                            "distance": 200,
                            "duration": 0.4
                        },
                        "push": {
                            "particles_nb": 4 // Quantidade de partículas ao clicar
                        },
                        "remove": {
                            "particles_nb": 2
                        }
                    }
                },
                "retina_detect": true
            });
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
