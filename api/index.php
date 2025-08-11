<?php
// api/index.php - Безопасная версия с реальной проверкой токенов

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обрабатываем preflight запросы
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Подключаем только database.php (как в оригинале)
try {
    require_once '../config/database.php';
} catch (Exception $e) {
    sendError('Database config not found: ' . $e->getMessage(), 500);
    exit;
}

try {
    // Отримуємо шлях (как в оригинале)
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $path = trim($path, '/');
    $parts = explode('/', $path);
    
    // Убираем 'api' из пути если есть
    if (count($parts) > 0 && $parts[0] === 'api') {
        array_shift($parts);
    }
    
    $parts = array_filter($parts);
    $parts = array_values($parts);
    $method = $_SERVER['REQUEST_METHOD'];
    
    // Простой роутинг (как в оригинале)
    if (count($parts) >= 2 && $parts[0] === 'auth') {
        if ($parts[1] === 'login' && $method === 'POST') {
            handleLogin();
        } elseif ($parts[1] === 'me' && $method === 'GET') {
            handleGetMe();
        } elseif ($parts[1] === 'logout' && $method === 'POST') {
            handleLogout();
        } else {
            sendError('Invalid auth endpoint', 404);
        }
    } elseif (count($parts) >= 1 && $parts[0] === 'users') {
        handleUsersAPI($parts, $method);
    } elseif (count($parts) >= 1 && $parts[0] === 'achievements') {
        handleAchievementsAPI($parts, $method);
    } elseif (count($parts) >= 1 && $parts[0] === 'reports') {
        handleReportsAPI($parts, $method);
    } else {
        sendError('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    Database::getInstance()->writeLog("API Router error: " . $e->getMessage(), 'error');
    sendError('Server error: ' . $e->getMessage(), 500);
}

// ============ УЛУЧШЕННЫЕ ФУНКЦИИ AUTH ============

function handleLogin() {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (empty($data['employee_id']) || empty($data['password'])) {
            sendError('Employee ID and password are required', 400);
            return;
        }
        
        // Прямое подключение к БД (как в оригинале)
        $db = Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, r.display_name as role_display_name, r.permissions, 
                   f.short_name as faculty_name, d.short_name as department_name
            FROM users u 
            JOIN roles r ON u.role_id = r.id 
            LEFT JOIN faculties f ON u.faculty_id = f.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.employee_id = ? AND u.is_active = 1
        ");
        
        $stmt->execute([$data['employee_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            sendError('Invalid credentials', 401);
            return;
        }
        
        // Обновляем время последнего входа
        $updateStmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $updateStmt->execute([$user['id']]);
        
        // Создаем РЕАЛЬНУЮ сессию
        $sessionId = bin2hex(random_bytes(32));
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Сохраняем сессию в базе данных
        $sessionStmt = $db->prepare("
            INSERT INTO sessions (id, user_id, ip_address, user_agent, created_at, last_activity) 
            VALUES (?, ?, ?, ?, NOW(), NOW())
        ");
        $sessionStmt->execute([$sessionId, $user['id'], $ipAddress, $userAgent]);
        
        sendSuccess([
            'user' => [
                'id' => $user['id'],
                'employee_id' => $user['employee_id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'position' => $user['position'],
                'role' => $user['role_name'],
                'role_display' => $user['role_display_name'],
                'permissions' => json_decode($user['permissions'] ?? '{}', true),
                'faculty_id' => $user['faculty_id'],
                'department_id' => $user['department_id'],
                'faculty_name' => $user['faculty_name'],
                'department_name' => $user['department_name']
            ],
            'session_id' => $sessionId
        ]);
        
    } catch (Exception $e) {
        Database::getInstance()->writeLog("Login error: " . $e->getMessage(), 'error');
        sendError('Login error: ' . $e->getMessage(), 500);
    }
}

function handleGetMe() {
    try {
        $user = getCurrentUser();
        if ($user) {
            sendSuccess(['user' => $user]);
        } else {
            sendError('Not authenticated', 401);
        }
        
    } catch (Exception $e) {
        Database::getInstance()->writeLog("Get me error: " . $e->getMessage(), 'error');
        sendError('Authentication error', 401);
    }
}

function handleLogout() {
    try {
        // Получаем токен из заголовка
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? null;
        
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $sessionId = substr($authHeader, 7);
            
            // Удаляем сессию из базы данных
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
        }
        
        sendSuccess(['message' => 'Logged out successfully']);
    } catch (Exception $e) {
        Database::getInstance()->writeLog("Logout error: " . $e->getMessage(), 'error');
        sendError('Logout error', 500);
    }
}

// ============ ЗАГЛУШКИ ДЛЯ ДОПОЛНИТЕЛЬНЫХ API ============

function handleUsersAPI($parts, $method) {
    // Проверяем авторизацию для пользователей
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        sendError('Authentication required', 401);
        return;
    }
    
    // Заглушки для API пользователей
    if ($method === 'GET') {
        sendSuccess([
            'data' => [],
            'pagination' => ['page' => 1, 'total' => 0]
        ]);
    } else {
        sendSuccess(['message' => 'Users API endpoint (stub)']);
    }
}

function handleAchievementsAPI($parts, $method) {
    // Проверяем авторизацию для достижений
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        sendError('Authentication required', 401);
        return;
    }
    
    // Заглушки для API достижений
    if ($method === 'GET') {
        sendSuccess([
            'data' => [
                'user_id' => $parts[1] ?? 1,
                'full_name' => $currentUser['full_name'],
                'achievement_1' => null,
                'achievement_2' => null,
            ]
        ]);
    } else {
        sendSuccess(['message' => 'Achievements API endpoint (stub)']);
    }
}

function handleReportsAPI($parts, $method) {
    // Проверяем авторизацию для отчетов
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        sendError('Authentication required', 401);
        return;
    }
    
    // Заглушки для API отчетов
    if (count($parts) >= 2 && $parts[1] === 'statistics') {
        sendSuccess([
            'statistics' => [
                'total_users' => 0,
                'users_with_achievements' => 0,
                'by_role' => [],
                'by_faculty' => []
            ]
        ]);
    } else {
        sendSuccess(['message' => 'Reports API endpoint (stub)']);
    }
}

// ============ БЕЗОПАСНАЯ ФУНКЦИЯ ПРОВЕРКИ ПОЛЬЗОВАТЕЛЯ ============

/**
 * Получение текущего пользователя из РЕАЛЬНОГО токена
 */
function getCurrentUser() {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? null;
    
    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        return null;
    }
    
    $sessionId = substr($authHeader, 7);
    
    if (empty($sessionId) || strlen($sessionId) !== 64) {
        return null;
    }
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Проверяем сессию в базе данных
        $stmt = $db->prepare("
            SELECT u.*, r.name as role_name, r.display_name as role_display_name, r.permissions,
                   f.short_name as faculty_name, d.short_name as department_name,
                   s.last_activity
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            JOIN roles r ON u.role_id = r.id
            LEFT JOIN faculties f ON u.faculty_id = f.id
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE s.id = ? AND u.is_active = 1
        ");
        
        $stmt->execute([$sessionId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return null;
        }
        
        // Проверяем, не истекла ли сессия (24 часа)
        $lastActivity = strtotime($result['last_activity']);
        $now = time();
        $timeDiff = $now - $lastActivity;
        
        if ($timeDiff > 24 * 60 * 60) { // 24 часа в секундах
            // Сессия истекла, удаляем её
            $deleteStmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
            $deleteStmt->execute([$sessionId]);
            return null;
        }
        
        // Обновляем время последней активности
        $updateStmt = $db->prepare("UPDATE sessions SET last_activity = NOW() WHERE id = ?");
        $updateStmt->execute([$sessionId]);
        
        // Возвращаем данные пользователя
        return [
            'id' => $result['id'],
            'employee_id' => $result['employee_id'],
            'full_name' => $result['full_name'],
            'email' => $result['email'],
            'position' => $result['position'],
            'role' => $result['role_name'],
            'role_display' => $result['role_display_name'],
            'permissions' => json_decode($result['permissions'] ?? '{}', true),
            'faculty_id' => $result['faculty_id'],
            'department_id' => $result['department_id'],
            'faculty_name' => $result['faculty_name'],
            'department_name' => $result['department_name']
        ];
        
    } catch (Exception $e) {
        Database::getInstance()->writeLog("getCurrentUser error: " . $e->getMessage(), 'error');
        return null;
    }
}

// ============ ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ============

function sendSuccess($data, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true] + $data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
?>