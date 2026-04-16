<?php
require_once __DIR__ . '/auth_utils.php';
auth_boot();

$currentUser = auth_current_user();
if ($currentUser) {
    header('Location: index.php');
    exit;
}

$mode = $_GET['mode'] ?? 'login';
if (!in_array($mode, ['login', 'register'], true)) {
    $mode = 'login';
}

$error = '';
$info = '';

if (!empty($_GET['logged_out'])) {
    $info = 'Vous etes deconnecte.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';

        $user = auth_validate_credentials($email, $password, $error);
        if ($user && auth_login_user($user['id'])) {
            header('Location: index.php');
            exit;
        }

        if ($error === '') {
            $error = 'Connexion impossible. Reessayez.';
        }
        $mode = 'login';
    }

    if ($action === 'register') {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirm'] ?? '';

        if ($password !== $confirm) {
            $error = 'Les mots de passe ne correspondent pas.';
        } else {
            $newUser = auth_register_user($username, $email, $password, $error);
            if ($newUser && auth_login_user($newUser['id'])) {
                header('Location: index.php');
                exit;
            }
            if ($error === '') {
                $error = 'Inscription impossible. Reessayez.';
            }
        }

        $mode = 'register';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RSSphere — Connexion</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/auth.css">
</head>
<body>

<div class="auth-shell">
  <div class="auth-bg-orb orb-a"></div>
  <div class="auth-bg-orb orb-b"></div>

  <main class="auth-card">
    <div class="auth-brand-row">
      <div class="brand-icon">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="5" cy="19" r="1.5" fill="currentColor" stroke="none"/>
          <path d="M4 11a8 8 0 018 8" stroke-linecap="round"/>
          <path d="M4 5a14 14 0 0114 14" stroke-linecap="round"/>
        </svg>
      </div>
      <span class="brand-name">RSSphere</span>
    </div>

    <h1 class="auth-title">Votre espace lecture</h1>
    <p class="auth-subtitle">Connectez-vous pour retrouver vos flux et vos articles sauvegardes.</p>

    <div class="auth-tabs">
      <a href="auth.php?mode=login" class="auth-tab <?php echo $mode === 'login' ? 'active' : ''; ?>">Connexion</a>
      <a href="auth.php?mode=register" class="auth-tab <?php echo $mode === 'register' ? 'active' : ''; ?>">Inscription</a>
    </div>

    <?php if ($info !== ''): ?>
      <div class="auth-alert info"><?php echo htmlspecialchars($info, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="auth-alert error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($mode === 'login'): ?>
      <form method="post" class="auth-form" autocomplete="on">
        <input type="hidden" name="action" value="login">

        <label class="auth-label" for="email">Email</label>
        <input class="auth-input" id="email" name="email" type="email" required placeholder="vous@email.com" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label class="auth-label" for="password">Mot de passe</label>
        <input class="auth-input" id="password" name="password" type="password" required placeholder="Votre mot de passe">

        <button class="auth-submit" type="submit">Se connecter</button>
      </form>
    <?php else: ?>
      <form method="post" class="auth-form" autocomplete="on">
        <input type="hidden" name="action" value="register">

        <label class="auth-label" for="username">Nom utilisateur</label>
        <input class="auth-input" id="username" name="username" type="text" required minlength="3" maxlength="40" placeholder="Ex: Vincent" value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label class="auth-label" for="register-email">Email</label>
        <input class="auth-input" id="register-email" name="email" type="email" required placeholder="vous@email.com" value="<?php echo htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

        <label class="auth-label" for="register-password">Mot de passe</label>
        <input class="auth-input" id="register-password" name="password" type="password" required minlength="8" placeholder="Minimum 8 caracteres">

        <label class="auth-label" for="password-confirm">Confirmer le mot de passe</label>
        <input class="auth-input" id="password-confirm" name="password_confirm" type="password" required minlength="8" placeholder="Retapez votre mot de passe">

        <button class="auth-submit" type="submit">Creer le compte</button>
      </form>
    <?php endif; ?>

    <p class="auth-note">Session persistante active: vous resterez connecte jusqu'a la deconnexion manuelle.</p>
  </main>
</div>

</body>
</html>
