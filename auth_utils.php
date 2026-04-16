<?php

const AUTH_USERS_FILE = __DIR__ . '/data/users.json';
const AUTH_COOKIE_NAME = 'rssphere_auth';
const AUTH_COOKIE_TTL = 315360000; // 10 years

function auth_cookie_options($expires) {
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function auth_boot() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params([
            'lifetime' => AUTH_COOKIE_TTL,
            'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    auth_ensure_users_file();

    if (!empty($_SESSION['auth_user_id'])) {
        $user = auth_find_user_by_id($_SESSION['auth_user_id']);
        if ($user) {
            return;
        }
        unset($_SESSION['auth_user_id']);
    }

    $token = $_COOKIE[AUTH_COOKIE_NAME] ?? '';
    if ($token !== '') {
        $user = auth_find_user_by_token($token);
        if ($user) {
            $_SESSION['auth_user_id'] = $user['id'];
            return;
        }
        auth_clear_auth_cookie();
    }
}

function auth_ensure_users_file() {
    if (file_exists(AUTH_USERS_FILE)) {
        return;
    }

    $default = ['users' => []];
    file_put_contents(
        AUTH_USERS_FILE,
        json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function auth_load_users() {
    auth_ensure_users_file();
    $raw = @file_get_contents(AUTH_USERS_FILE);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return ['users' => []];
    }
    if (!isset($data['users']) || !is_array($data['users'])) {
        $data['users'] = [];
    }
    return $data;
}

function auth_save_users($data) {
    file_put_contents(
        AUTH_USERS_FILE,
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

function auth_normalize_email($email) {
    return strtolower(trim((string)$email));
}

function auth_public_user($user) {
    if (!$user || !is_array($user)) {
        return null;
    }
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'createdAt' => $user['createdAt'] ?? null,
        'lastLoginAt' => $user['lastLoginAt'] ?? null,
    ];
}

function auth_find_user_by_email($email) {
    $target = auth_normalize_email($email);
    if ($target === '') {
        return null;
    }

    $data = auth_load_users();
    foreach ($data['users'] as $user) {
        if (auth_normalize_email($user['email'] ?? '') === $target) {
            return $user;
        }
    }
    return null;
}

function auth_find_user_by_id($userId) {
    $id = (string)$userId;
    if ($id === '') {
        return null;
    }

    $data = auth_load_users();
    foreach ($data['users'] as $user) {
        if ((string)($user['id'] ?? '') === $id) {
            return $user;
        }
    }
    return null;
}

function auth_find_user_by_token($token) {
    $token = trim((string)$token);
    if ($token === '') {
        return null;
    }

    $targetHash = hash('sha256', $token);
    $data = auth_load_users();

    foreach ($data['users'] as $user) {
        $storedHash = (string)($user['rememberTokenHash'] ?? '');
        if ($storedHash !== '' && hash_equals($storedHash, $targetHash)) {
            return $user;
        }
    }

    return null;
}

function auth_register_user($username, $email, $password, &$error = null) {
    $username = trim((string)$username);
    $email = auth_normalize_email($email);
    $password = (string)$password;

    if (mb_strlen($username) < 3 || mb_strlen($username) > 40) {
        $error = 'Le nom utilisateur doit contenir entre 3 et 40 caracteres.';
        return null;
    }
    if (!preg_match('/^[\p{L}\p{N} ._\-]+$/u', $username)) {
        $error = 'Le nom utilisateur contient des caracteres non autorises.';
        return null;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
        return null;
    }
    if (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caracteres.';
        return null;
    }

    $data = auth_load_users();

    foreach ($data['users'] as $user) {
        if (auth_normalize_email($user['email'] ?? '') === $email) {
            $error = 'Un compte existe deja avec cet email.';
            return null;
        }
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if (!$passwordHash) {
        $error = 'Impossible de securiser le mot de passe.';
        return null;
    }

    $newUser = [
        'id' => bin2hex(random_bytes(12)),
        'username' => $username,
        'email' => $email,
        'passwordHash' => $passwordHash,
        'rememberTokenHash' => null,
        'createdAt' => gmdate('c'),
        'lastLoginAt' => null,
    ];

    $data['users'][] = $newUser;
    auth_save_users($data);

    return $newUser;
}

function auth_validate_credentials($email, $password, &$error = null) {
    $email = auth_normalize_email($email);
    $password = (string)$password;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse email invalide.';
        return null;
    }

    $user = auth_find_user_by_email($email);
    if (!$user) {
        $error = 'Identifiants invalides.';
        return null;
    }

    if (!password_verify($password, $user['passwordHash'] ?? '')) {
        $error = 'Identifiants invalides.';
        return null;
    }

    return $user;
}

function auth_login_user($userId) {
    $id = (string)$userId;
    if ($id === '') {
        return false;
    }

    $data = auth_load_users();
    $targetIndex = -1;

    foreach ($data['users'] as $i => $user) {
        if ((string)($user['id'] ?? '') === $id) {
            $targetIndex = $i;
            break;
        }
    }

    if ($targetIndex < 0) {
        return false;
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    $data['users'][$targetIndex]['rememberTokenHash'] = $tokenHash;
    $data['users'][$targetIndex]['lastLoginAt'] = gmdate('c');
    auth_save_users($data);

    session_regenerate_id(true);
    $_SESSION['auth_user_id'] = $id;

    setcookie(AUTH_COOKIE_NAME, $token, auth_cookie_options(time() + AUTH_COOKIE_TTL));
    return true;
}

function auth_clear_auth_cookie() {
    setcookie(AUTH_COOKIE_NAME, '', auth_cookie_options(time() - 3600));
}

function auth_logout() {
    $userId = $_SESSION['auth_user_id'] ?? null;

    if ($userId) {
        $data = auth_load_users();
        foreach ($data['users'] as $i => $user) {
            if ((string)($user['id'] ?? '') === (string)$userId) {
                $data['users'][$i]['rememberTokenHash'] = null;
                break;
            }
        }
        auth_save_users($data);
    }

    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }

    auth_clear_auth_cookie();
}

function auth_current_user() {
    $userId = $_SESSION['auth_user_id'] ?? null;
    if (!$userId) {
        return null;
    }

    $user = auth_find_user_by_id($userId);
    if (!$user) {
        return null;
    }

    return auth_public_user($user);
}

function auth_require_login_redirect($redirectTo = 'auth.php') {
    if (!auth_current_user()) {
        header('Location: ' . $redirectTo);
        exit;
    }
}

function auth_require_login_json() {
    $user = auth_current_user();
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Authentification requise']);
        exit;
    }
    return $user;
}
