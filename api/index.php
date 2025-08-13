<?php
// api/index.php - Рефакторована версія з AuthMiddleware

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
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../classes/Auth.php';
    require_once __DIR__ . '/../classes/AuthMiddleware.php';
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
    
    // Создаем экземпляр middleware
    $middleware = new AuthMiddleware();
    
    // Роутинг с middleware
    if (count($parts) >= 2 && $parts[0] === 'auth') {
        // Auth endpoints - публичные, без middleware
        handleAuth($parts, $method);
        
    } elseif (count($parts) >= 1 && $parts[0] === 'users') {
        // Users endpoints - только для керівництва та адміна
        if (count($parts) >= 2 && in_array($parts[1], ['roles', 'faculties', 'departments'])) {
            // Справочники - для всех авторизованных
            $middleware->handle(function($user) use ($parts, $method) {
                handleUsersReferences($parts, $method, $user);
            });
        } else {
            // Управление пользователями - только для керівництва
            $middleware->requireRole(['admin', 'dekanat', 'zaviduvach'], function($user) use ($parts, $method) {
                handleUsersAPI($parts, $method, $user);
            });
        }
        
    } elseif (count($parts) >= 1 && $parts[0] === 'achievements') {
        // Achievements endpoints - для всех авторизованных с проверкой доступа к пользователю
        if (count($parts) >= 2) {
            $targetUserId = intval($parts[1]);
            $middleware->requireUserAccess($targetUserId, function($user) use ($parts, $method) {
                handleAchievementsAPI($parts, $method, $user);
            });
        } else {
            $middleware->handle(function($user) use ($parts, $method) {
                handleAchievementsAPI($parts, $method, $user);
            });
        }
        
    } elseif (count($parts) >= 1 && $parts[0] === 'reports') {
        // Reports endpoints - только для керівництва та адміна
        $middleware->requireRole(['admin', 'dekanat', 'zaviduvach'], function($user) use ($parts, $method) {
            handleReportsAPI($parts, $method, $user);
        });
        
    } elseif (count($parts) >= 1 && $parts[0] === 'system') {
        // System endpoints - публичные или для админа
        handleSystemAPI($parts, $method);
        
    } else {
        sendError('Endpoint not found', 404);
    }
    
} catch (Exception $e) {
    Database::getInstance()->writeLog("API Router error: " . $e->getMessage(), 'error');
    sendError('Server error: ' . $e->getMessage(), 500);
}

// ============ ФУНКЦИИ АВТОРИЗАЦИИ (без изменений) ============

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
        // Используем middleware для получения текущего пользователя
        $middleware = new AuthMiddleware();
        $user = $middleware->authenticate();
        
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
        // Используем middleware для проверки авторизации
        $middleware = new AuthMiddleware();
        $middleware->handle(function($user) {
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
        });
        
    } else {
        sendError('Invalid auth endpoint', 404);
    }
}

// ============ ФУНКЦИИ ДОСТИЖЕНИЙ (обновленные) ============

function handleAchievementsAPI($parts, $method, $user) {
    $achievementsManager = new AchievementsManager();
    
    if (count($parts) >= 2) {
        $userId = intval($parts[1]);
        
        if ($method === 'GET') {
            // Получение достижений пользователя
            $result = $achievementsManager->getAchievements($userId, $user);
            sendResponse($result);
            
        } elseif ($method === 'PUT') {
            // Обновление достижений
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                sendError('Invalid JSON data', 400);
                return;
            }
            
            $result = $achievementsManager->updateAchievements($userId, $input, $user);
            sendResponse($result);
            
        } elseif (count($parts) >= 3 && $parts[2] === 'export' && $method === 'GET') {
            // Экспорт достижений
            $result = $achievementsManager->getAchievements($userId, $user);
            
            if (isset($result['error'])) {
                sendError($result['error'], $result['code']);
            } else {
                // Добавляем метаданные для экспорта
                $exportData = [
                    'instructor_name' => $result['data']['full_name'],
                    'instructor_info' => [
                        'full_name' => $result['data']['full_name'],
                        'employee_id' => $result['data']['employee_id'],
                        'position' => $result['data']['position'],
                        'faculty_name' => $result['data']['faculty_name'],
                        'department_name' => $result['data']['department_name']
                    ],
                    'achievements' => []
                ];
                
                // Собираем достижения
                $includeEmpty = isset($_GET['include_empty']) && $_GET['include_empty'] === 'true';
                
                for ($i = 1; $i <= 20; $i++) {
                    $achievement = $result['data']["achievement_$i"] ?? null;
                    
                    if ($achievement || $includeEmpty) {
                        $exportData['achievements'][$i] = $achievement ?? '';
                    }
                }
                
                sendSuccess([
                    'data' => $exportData,
                    'export_settings' => [
                        'encoding' => $_GET['encoding'] ?? 'utf8bom',
                        'include_empty' => $includeEmpty,
                        'timestamp' => date('Y-m-d H:i:s')
                    ]
                ]);
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
            $result = $achievementsManager->importFromCSV($userId, $csvContent, $user);
            sendResponse($result);
            
        } else {
            sendError('Invalid achievements endpoint', 404);
        }
    } else {
        sendError('User ID required', 400);
    }
}

// ============ ФУНКЦИИ ПОЛЬЗОВАТЕЛЕЙ (обновленные) ============

function handleUsersAPI($parts, $method, $user) {
    $userManager = new UserManager();
    
    if ($method === 'GET') {
        if (count($parts) >= 2) {
            // Получение конкретного пользователя
            $userId = intval($parts[1]);
            $userData = $userManager->getUserById($userId, $user);
            
            if ($userData) {
                sendSuccess(['data' => $userData]);
            } else {
                sendError('User not found', 404);
            }
        } else {
            // Получение списка пользователей
            $filters = $_GET;
            $page = intval($_GET['page'] ?? 1);
            $limit = intval($_GET['limit'] ?? 50);
            
            $result = $userManager->getUsersList($user, $filters, $page, $limit);
            sendResponse($result);
        }
        
    } elseif ($method === 'POST') {
        // Создание пользователя
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON data', 400);
            return;
        }
        
        $result = $userManager->createUser($input, $user);
        sendResponse($result);
        
    } elseif ($method === 'PUT' && count($parts) >= 2) {
        // Обновление пользователя
        $userId = intval($parts[1]);
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            sendError('Invalid JSON data', 400);
            return;
        }
        
        $result = $userManager->updateUser($userId, $input, $user);
        sendResponse($result);
        
    } elseif ($method === 'DELETE' && count($parts) >= 2) {
        // Деактивация пользователя
        $userId = intval($parts[1]);
        $result = $userManager->deactivateUser($userId, $user);
        sendResponse($result);
        
    } else {
        sendError('Invalid users endpoint', 404);
    }
}

// Дополнительные endpoints для справочников (обновленные)
function handleUsersReferences($parts, $method, $user) {
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

// ============ ФУНКЦИИ ОТЧЕТОВ (обновленные) ============

function handleReportsAPI($parts, $method, $user) {
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
        $result = $achievementsManager->exportReport($user, $filters);
        
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

// ============ ФУНКЦИИ СИСТЕМЫ (новые) ============

function handleSystemAPI($parts, $method) {
    if (count($parts) >= 2 && $parts[1] === 'version' && $method === 'GET') {
        // Получение версии системы - публичный endpoint
        try {
            $versionInfo = Config::getVersionInfo();
            sendSuccess($versionInfo);
        } catch (Exception $e) {
            sendError('Failed to get version info', 500);
        }
        
    } elseif (count($parts) >= 2 && $parts[1] === 'status' && $method === 'GET') {
        // Системный статус - только для админа
        $middleware = new AuthMiddleware();
        $middleware->requireRole(['admin'], function($user) {
            try {
                $db = Database::getInstance();
                
                $status = [
                    'version' => Config::getVersion(),
                    'environment' => Config::getEnvironment(),
                    'database' => [
                        'connected' => $db->isConnected(),
                        'info' => $db->getDatabaseInfo()
                    ],
                    'php_version' => phpversion(),
                    'memory_usage' => memory_get_usage(true),
                    'uptime' => time() - $_SERVER['REQUEST_TIME']
                ];
                
                sendSuccess(['status' => $status]);
            } catch (Exception $e) {
                sendError('Failed to get system status', 500);
            }
        });
        
    } else {
        sendError('Invalid system endpoint', 404);
    }
}

// ============ ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ (без изменений) ============

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