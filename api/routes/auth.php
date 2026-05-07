<?php

function handle_login(): void {
    rate_limit('admin_login', 30, 1800);
    $body = read_json_body();
    $u = $body['username'] ?? '';
    $p = $body['password'] ?? '';

    $isAdmin  = ($u === ADMIN_USERNAME  && $p === ADMIN_PASSWORD);
    $isViewer = ($u === VIEWER_USERNAME && $p === VIEWER_PASSWORD);

    if ($isAdmin || $isViewer) {
        start_session_safe();
        $_SESSION['is_admin']  = true;
        $_SESSION['is_viewer'] = $isViewer;
        Logger::info($isViewer ? 'Viewer login' : 'Admin login');
        send_json(['success' => true, 'csrf_token' => csrf_token(), 'is_viewer' => $isViewer]);
    } else {
        Logger::warn('Admin login failed', ['username' => $u]);
        send_json(['error' => 'اسم المستخدم أو كلمة المرور غير صحيحة'], 401);
    }
}

function handle_logout(): void {
    start_session_safe();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'], $params['secure'], $params['httponly']
        );
    }
    session_destroy();
    send_json(['success' => true]);
}

function handle_me(): void {
    start_session_safe();
    $isAdmin   = !empty($_SESSION['is_admin']);
    $isViewer  = !empty($_SESSION['is_viewer']);
    $studentId = $_SESSION['student_id'] ?? null;

    $extra = $isAdmin ? ['csrf_token' => csrf_token(), 'is_viewer' => $isViewer] : [];

    if ($studentId) {
        $stmt = db()->prepare("SELECT id, name, grade_level, class_name, total_points FROM users WHERE id = ?");
        $stmt->execute([$studentId]);
        $u = $stmt->fetch();
        send_json(array_merge(['isAdmin' => $isAdmin, 'user' => $u ? [
            'id'           => (int)$u['id'],
            'name'         => $u['name'],
            'grade_level'  => $u['grade_level'],
            'class_name'   => $u['class_name'] ?? null,
            'total_points' => (int)$u['total_points'],
        ] : null], $extra));
    }
    send_json(array_merge(['isAdmin' => $isAdmin, 'user' => null], $extra));
}

function handle_register(): void {
    rate_limit('register', 5, 3600);
    $b          = read_json_body();
    $name       = trim($b['name'] ?? '');
    $username   = strtolower(trim($b['username'] ?? ''));
    $password   = $b['password'] ?? '';
    $grade      = $b['grade_level'] ?? '';
    $class_name = trim($b['class_name'] ?? '');

    if (!$name || !$username || !$password || !$grade || !$class_name) {
        send_json(['error' => 'جميع الحقول مطلوبة'], 400);
    }
    if (!in_array($grade, ['first', 'second'])) {
        send_json(['error' => 'المرحلة الدراسية غير صحيحة'], 400);
    }
    if (mb_strlen($password) < 6) {
        send_json(['error' => 'كلمة المرور يجب أن تكون ٦ أحرف على الأقل'], 400);
    }

    $check = db()->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        send_json(['error' => 'اسم المستخدم مستخدم بالفعل، جرّب اسماً آخر'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare(
        "INSERT INTO users (name, username, password_hash, grade_level, class_name) VALUES (?,?,?,?,?)"
    );
    $stmt->execute([$name, $username, $hash, $grade, $class_name]);
    $id = (int)db()->lastInsertId();

    start_session_safe();
    $_SESSION['student_id']    = $id;
    $_SESSION['student_name']  = $name;
    $_SESSION['student_grade'] = $grade;

    Logger::info('Student registered', ['id' => $id, 'grade' => $grade, 'class' => $class_name]);
    send_json(['success' => true, 'user' => [
        'id'           => $id,
        'name'         => $name,
        'grade_level'  => $grade,
        'class_name'   => $class_name,
        'total_points' => 0,
    ]], 201);
}

function handle_student_login(): void {
    rate_limit('student_login', 30, 1800);
    $b        = read_json_body();
    $username = strtolower(trim($b['username'] ?? ''));
    $password = $b['password'] ?? '';

    if (!$username || !$password) {
        send_json(['error' => 'اسم المستخدم وكلمة المرور مطلوبان'], 400);
    }

    $stmt = db()->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($password, $u['password_hash'])) {
        Logger::warn('Student login failed', ['username' => $username]);
        send_json(['error' => 'اسم المستخدم أو كلمة المرور غير صحيحة'], 401);
    }

    start_session_safe();
    $_SESSION['student_id']    = (int)$u['id'];
    $_SESSION['student_name']  = $u['name'];
    $_SESSION['student_grade'] = $u['grade_level'];

    Logger::info('Student login success', ['id' => $u['id']]);
    send_json(['success' => true, 'user' => [
        'id'           => (int)$u['id'],
        'name'         => $u['name'],
        'grade_level'  => $u['grade_level'],
        'total_points' => (int)$u['total_points'],
    ]]);
}
