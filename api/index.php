<?php
// api/index.php - Исправленная версия с полной поддержкой достижений

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Обрабатываем preflight запросы
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Полифилл для PHP 7.4
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return strpos($haystack, $needle) === 0;
    }
}

// Полифилл для getallheaders
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

// Подключаем необходимые файлы
try {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../classes/Auth.php';
    require_once __DIR__ . '/../classes/AchievementsManager.php';
    require_once __DIR__ . '/../classes/UserManager.php';
} catch (Exception $e) {
    sendError('Помилка ініціалізації: ' . $e->getMessage(), 500);
    exit;
}

try {
    // Отримуємо шлях
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
    
    // Роутинг
    if (count($parts) >= 2 && $parts[0] === 'auth') {
        handleAuth($parts, $method);
    } elseif (count($parts) >= 1 && $parts[0] === 'users') {
        // Проверяем справочники
        if (count($parts) >= 2 && in_array($parts[1], ['roles', 'faculties', 'departments'])) {
            handleUsersReferences($parts, $method);
        } else {
            handleUsersAPI($parts, $method);
        }
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

// ============ ФУНКЦИИ АВТОРИЗАЦИИ ============

function handleAuth($parts, $method) {
    $auth = new Auth();
    
    if ($parts[1] === 'login' && $method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['employee_id']) || empty($input['password'])) {
            sendError('Employee ID and password are required', 400);
            return;
        }
        
        $result = $auth->login($input['employee_id'], $input['password']);
        
        if ($result) {
            sendSuccess($result);
        } else {
            sendError('Invalid credentials', 401);
        }
        
    } elseif ($parts[1] === 'me' && $method === 'GET') {
        $user = getCurrentUser();
        if ($user) {
            sendSuccess(['user' => $user]);
        } else {
            sendError('Not authenticated', 401);
        }
        
    } elseif ($parts[1] === 'logout' && $method === 'POST') {
        // Получаем токен из заголовка
        $authHeader = null;
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
        } else {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? null;
        }
        
        if ($authHeader && strpos($authHeader, 'Bearer ') === 0) {
            $sessionId = substr($authHeader, 7);
            
            // Удаляем сессию из базы данных
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
        }
        
        sendSuccess(['message' => 'Logged out successfully']);
        
    } elseif ($parts[1] === 'change-password' && $method === 'POST') {
        $user = getCurrentUser();
        if (!$user) {
            sendError('Not authenticated', 401);
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['current_password']) || empty($input['new_password'])) {
            sendError('Current and new passwords are required', 400);
            return;
        }
        
        // Проверяем текущий пароль
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $userData = $stmt->fetch();
        
        if (!$userData || !password_verify($input['current_password'], $userData['password_hash'])) {
            sendError('Current password is incorrect', 400);
            return;
        }
        
        // Обновляем пароль
        $newPasswordHash = password_hash($input['new_password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$newPasswordHash, $user['id']]);
        
        sendSuccess(['message' => 'Password changed successfully']);
        
    } else {
        sendError('Invalid auth endpoint', 404);
    }
}

// ============ ФУНКЦИИ ДОСТИЖЕНИЙ ============

function handleAchievementsAPI($parts, $method) {
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        sendError('Authentication required', 401);
        return;
    }
    
    $achievementsManager = new AchievementsManager();
    
    if (count($parts) >= 2) {
        $userId = intval($parts[1]);
        
        if ($method === 'GET') {
            // Получение достижений пользователя
            $result = $achievementsManager->getAchievements($userId, $currentUser);
            
            if (isset($result['error'])) {
                sendError($result['error'], $result['code']);
            } else {
                sendSuccess($result);
            }
            
        } elseif ($method === 'PUT') {
            // Обновление достижений
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                sendError('Invalid JSON data', 400);
                return;
            }
            
            $result = $achievementsManager->updateAchievements($userId, $input, $currentUser);
            
            if (isset($result['error'])) {
                sendError($result['error'], $result['code']);
            } else {
                sendSuccess($result);
            }
            
        } elseif (count($parts) >= 3 && $parts[2] === 'export' && $method === 'GET') {
            // Экспорт достижений в CSV
            $encoding = $_GET['encoding'] ?? 'utf8bom';
            $includeEmpty = isset($_GET['include_empty']) && $_GET['include_empty'] === 'true';
            
            $result = $achievementsManager->generateCSV($userId, $currentUser, $encoding, $includeEmpty);
            
            if (isset($result['error'])) {
                sendError($result['error'], $result['code']);
            } else {
                // Возвращаем файл для скачивания
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                
                if ($result['encoding'] === 'utf8bom') {
                    header('Content-Type: text/csv; charset=utf-8');
                } elseif ($result['encoding'] === 'windows1251') {
                    header('Content-Type: text/csv; charset=windows-1251');
                }
                
                echo $result['content'];
                exit;
            }
            
        } elseif (count($parts) >= 3 && $parts[2] === 'import' && $method === 'POST') {
            // Импорт достижений из CSV
            if (!isset($_FILES['file'])) {
                sendError('No file uploaded', 400);
                return;
            }
            
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                sendError('File upload error', 400);
                return;
            }
            
            $csvContent = file_get_contents($file['tmp_name']);
            $result = $achievementsManager->importFromCSV($userId, $csvContent, $currentUser);
            
            if (isset($result['error'])) {
                sendError($result['error'], $result['code']);
            } else {
                sendSuccess($result);
            }
            
        } else {
            sendError('Invalid achievements endpoint', 404);
        }
    } else {
        sendError('User ID required', 400);
    }
}

// ============ ФУНКЦИИ ПОЛЬЗОВАТЕЛЕЙ ============

function handleUsersAPI($parts, $method) {
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        sendError('Authentication required', 401);
        return;
    }
    
    $userManager = new UserManager();
    
    if ($method === 'GET') {
        if (count($parts) >= 2) {
            // Получение конкретного пользователя
            $userId = intval($parts[1]);
            $user = $userManager->getUserById($userId, $currentUser);
            
            if ($user) {
                sendSuccess(['data' => $user]);
            } else {
                sendError('User not found', 404);
            }
        } else {
            // Получение списка пользователей
            $filters = $_GET;
            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 50);
            
            $result = $userManager->getUsersList($currentUser, $filters, $page, $limit);
            
            if (isset($result['error'])) {
                sendError($result['error'], $result['code']);
            } else {
                sendSuccess($result);
            }
        }
        
    } elseif ($method === 'POST') {
        // Создание пользователя
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON data', 400);
            return;
        }
        
        $result = $userManager->createUser($input, $currentUser);
        
        if (isset($result['error'])) {
            sendError($result['error'], $result['code']);
        } else {
            sendSuccess($result);
        }
        
    } elseif ($method === 'PUT' && count($parts) >= 2) {
        // Обновление пользователя
        $userId = intval($parts[1]);
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON data', 400);
            return;
        }
        
        $result = $userManager->updateUser($userId, $input, $currentUser);
        
        if (isset($result['error'])) {
            sendError($result['error'], $result['code']);
        } else {
            sendSuccess($result);
        }
        
    } elseif ($method === 'DELETE' && count($parts) >= 2) {
        // Деактивация пользователя
        $userId = intval($parts[1]);
        $result = $userManager->deactivateUser($userId, $currentUser);
        
        if (isset($result['error'])) {
            sendError($result['error'], $result['code']);
        } else {
            sendSuccess($result);
        }
        
    } else {
        sendError('Invalid users endpoint', 404);
    }
}

// Дополнительные endpoints для справочников
function handleUsersReferences($parts, $method) {
    if (!getCurrentUser()) {
        sendError('Authentication required', 401);
        return;
    }
    
    $userManager = new UserManager();
    
    if ($parts[1] === 'roles') {
        $result = $userManager->getRoles();
        sendResponse($result);
    } elseif ($parts[1] === 'faculties') {
        $result = $userManager->getFaculties();
        sendResponse($result);
    } elseif ($parts[1] === 'departments') {
        $facultyId = $_GET['faculty_id'] ?? null;
        $result = $userManager->getDepartments($facultyId);
        sendResponse($result);
    } else {
        sendError('Invalid reference endpoint', 404);
    }
}

// ============ ФУНКЦИИ ОТЧЕТОВ ============

function handleReportsAPI($parts, $method) {
    $currentUser = getCurrentUser();
    if (!$currentUser) {
        sendError('Authentication required', 401);
        return;
    }
    
    $achievementsManager = new AchievementsManager();
    
    if (count($parts) >= 2 && $parts[1] === 'statistics' && $method === 'GET') {
        // Получение статистики
        $db = Database::getInstance()->getConnection();
        
        try {
            // Общая статистика
            $stmt = $db->prepare("SELECT COUNT(*) as total_users FROM users WHERE is_active = 1");
            $stmt->execute();
            $totalUsers = $stmt->fetch()['total_users'];
            
            $stmt = $db->prepare("SELECT COUNT(*) as users_with_achievements FROM achievements a JOIN users u ON a.user_id = u.id WHERE u.is_active = 1");
            $stmt->execute();
            $usersWithAchievements = $stmt->fetch()['users_with_achievements'];
            
            // Статистика по ролям
            $stmt = $db->prepare("
                SELECT r.display_name, COUNT(u.id) as count 
                FROM roles r 
                LEFT JOIN users u ON r.id = u.role_id AND u.is_active = 1 
                GROUP BY r.id, r.display_name
            ");
            $stmt->execute();
            $byRole = $stmt->fetchAll();
            
            // Статистика по факультетам
            $stmt = $db->prepare("
                SELECT f.short_name, COUNT(u.id) as count 
                FROM faculties f 
                LEFT JOIN users u ON f.id = u.faculty_id AND u.is_active = 1 
                GROUP BY f.id, f.short_name
            ");
            $stmt->execute();
            $byFaculty = $stmt->fetchAll();
            
            sendSuccess([
                'statistics' => [
                    'total_users' => $totalUsers,
                    'users_with_achievements' => $usersWithAchievements,
                    'by_role' => $byRole,
                    'by_faculty' => $byFaculty
                ]
            ]);
            
        } catch (Exception $e) {
            Database::getInstance()->writeLog("Statistics error: " . $e->getMessage(), 'error');
            sendError('Failed to get statistics', 500);
        }
        
    } elseif (count($parts) >= 2 && $parts[1] === 'export' && $method === 'GET') {
        // Экспорт отчета
        $filters = $_GET;
        $result = $achievementsManager->exportReport($currentUser, $filters);
        
        if (isset($result['error'])) {
            sendError($result['error'], $result['code']);
        } else {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
            echo $result['content'];
            exit;
        }
        
    } else {
        sendError('Invalid reports endpoint', 404);
    }
}

// ============ ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ ============

/**
 * Получение текущего пользователя из РЕАЛЬНОГО токена
 */
function getCurrentUser() {
    // Более надежное получение заголовка Authorization
    $authHeader = null;
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    } else {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? null;
    }
    
    if (!$authHeader || strpos($authHeader, 'Bearer ') !== 0) {
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

function sendResponse($result) {
    if (isset($result['error'])) {
        sendError($result['error'], $result['code']);
    } else {
        sendSuccess($result);
    }
}
?>