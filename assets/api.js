// assets/api.js

/**
 * API клиент для работы с REST API системы ЄДЕБО
 */
class ApiClient {
    constructor() {
        this.baseUrl = '/api';
        this.sessionId = this.getSessionId();
        this.requestId = 0;
    }

    /**
     * Получение session ID из localStorage или cookie
     */
    getSessionId() {
        return localStorage.getItem('session_id') || this.getCookie('session_id');
    }

    /**
     * Установка session ID
     */
    setSessionId(sessionId) {
        this.sessionId = sessionId;
        localStorage.setItem('session_id', sessionId);
        
        // Устанавливаем cookie на 24 часа
        const expires = new Date();
        expires.setTime(expires.getTime() + (24 * 60 * 60 * 1000));
        document.cookie = `session_id=${sessionId}; expires=${expires.toUTCString()}; path=/`;
    }

    /**
     * Удаление session ID
     */
    clearSessionId() {
        this.sessionId = null;
        localStorage.removeItem('session_id');
        document.cookie = 'session_id=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    }

    /**
     * Получение cookie по имени
     */
    getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return parts.pop().split(';').shift();
        return null;
    }

    /**
     * Базовый метод для HTTP запросов
     */
    async request(endpoint, options = {}) {
        const url = `${this.baseUrl}/${endpoint.replace(/^\//, '')}`;
        const requestId = ++this.requestId;
        
        // Показываем индикатор загрузки для длительных запросов
        const loadingTimeout = setTimeout(() => {
            this.showGlobalLoading(true);
        }, 300);

        try {
            const config = {
                method: options.method || 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...options.headers
                }
            };

            // Добавляем авторизационный заголовок
            if (this.sessionId) {
                config.headers['Authorization'] = `Bearer ${this.sessionId}`;
            }

            // Добавляем тело запроса
            if (options.body) {
                if (options.body instanceof FormData) {
                    delete config.headers['Content-Type']; // Браузер сам установит
                    config.body = options.body;
                } else {
                    config.body = JSON.stringify(options.body);
                }
            }

            console.log(`[API] ${config.method} ${url}`, options.body);

            const response = await fetch(url, config);
            
            clearTimeout(loadingTimeout);
            this.showGlobalLoading(false);

            // Обрабатываем различные статусы ответа
            if (response.status === 401) {
                this.handleUnauthorized();
                throw new ApiError('Не авторизован', 401);
            }

            if (response.status === 403) {
                throw new ApiError('Доступ запрещен', 403);
            }

            if (response.status === 404) {
                throw new ApiError('Ресурс не найден', 404);
            }

            if (response.status >= 500) {
                throw new ApiError('Ошибка сервера', response.status);
            }

            // Проверяем тип контента
            const contentType = response.headers.get('content-type');
            
            if (contentType && contentType.includes('application/json')) {
                const data = await response.json();
                
                if (!response.ok) {
                    throw new ApiError(data.error || 'Ошибка API', response.status, data);
                }
                
                console.log(`[API] Response:`, data);
                return data;
            } else {
                // Для файлов и других типов контента
                if (!response.ok) {
                    const text = await response.text();
                    throw new ApiError(text || 'Ошибка API', response.status);
                }
                
                return response;
            }

        } catch (error) {
            clearTimeout(loadingTimeout);
            this.showGlobalLoading(false);
            
            if (error instanceof ApiError) {
                throw error;
            }
            
            // Ошибки сети
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                throw new ApiError('Ошибка сети. Проверьте соединение.', 0);
            }
            
            console.error(`[API] Error:`, error);
            throw new ApiError('Неизвестная ошибка', 0, error);
        }
    }

    /**
     * GET запрос
     */
    async get(endpoint, params = {}) {
        const queryString = new URLSearchParams(params).toString();
        const url = queryString ? `${endpoint}?${queryString}` : endpoint;
        
        return this.request(url, { method: 'GET' });
    }

    /**
     * POST запрос
     */
    async post(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'POST',
            body: data
        });
    }

    /**
     * PUT запрос
     */
    async put(endpoint, data = {}) {
        return this.request(endpoint, {
            method: 'PUT',
            body: data
        });
    }

    /**
     * DELETE запрос
     */
    async delete(endpoint) {
        return this.request(endpoint, { method: 'DELETE' });
    }

    /**
     * Загрузка файла
     */
    async uploadFile(endpoint, formData) {
        return this.request(endpoint, {
            method: 'POST',
            body: formData
        });
    }

    /**
     * Скачивание файла
     */
    async downloadFile(endpoint, params = {}) {
        const queryString = new URLSearchParams(params).toString();
        const url = queryString ? `${endpoint}?${queryString}` : endpoint;
        
        const response = await this.request(url, { method: 'GET' });
        
        if (response instanceof Response) {
            const blob = await response.blob();
            const filename = this.getFilenameFromResponse(response);
            this.downloadBlob(blob, filename);
            return { success: true, filename };
        }
        
        return response;
    }

    /**
     * Получение имени файла из ответа
     */
    getFilenameFromResponse(response) {
        const disposition = response.headers.get('Content-Disposition');
        if (disposition) {
            const matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
            if (matches != null && matches[1]) {
                return matches[1].replace(/['"]/g, '');
            }
        }
        return 'download.csv';
    }

    /**
     * Скачивание blob как файл
     */
    downloadBlob(blob, filename) {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    /**
     * Обработка неавторизованного доступа
     */
    handleUnauthorized() {
        this.clearSessionId();
        
        // Показываем экран авторизации
        if (window.app && window.app.showLoginScreen) {
            window.app.showLoginScreen();
        } else {
            // Перезагружаем страницу как fallback
            window.location.reload();
        }
    }

    /**
     * Показ/скрытие глобального индикатора загрузки
     */
    showGlobalLoading(show) {
        const loadingScreen = document.getElementById('loading-screen');
        if (loadingScreen) {
            if (show) {
                loadingScreen.classList.remove('hidden');
            } else {
                loadingScreen.classList.add('hidden');
            }
        }
    }

    // ============ МЕТОДЫ ДЛЯ АВТОРИЗАЦИИ ============

    /**
     * Авторизация
     */
    async login(employeeId, password) {
        const response = await this.post('auth/login', {
			//const response = await this.post('debug_login', { //временная замена для отладки
			//const response = await this.post('simple_login', {
            employee_id: employeeId,
            password: password
        });
        
        if (response.session_id) {
            this.setSessionId(response.session_id);
        }
        
        return response;
    }

    /**
     * Выход из системы
     */
    async logout() {
        try {
            await this.post('auth/logout');
        } finally {
            this.clearSessionId();
        }
    }

    /**
     * Смена пароля
     */
    async changePassword(currentPassword, newPassword) {
        return this.post('auth/change-password', {
            current_password: currentPassword,
            new_password: newPassword
        });
    }

    /**
     * Получение текущего пользователя
     */
    async getCurrentUser() {
        return this.get('auth/me');
    }

    // ============ МЕТОДЫ ДЛЯ ПОЛЬЗОВАТЕЛЕЙ ============

    /**
     * Получение списка пользователей
     */
    async getUsers(filters = {}, page = 1, limit = 50) {
        const params = { ...filters, page, limit };
        return this.get('users', params);
    }

    /**
     * Получение пользователя по ID
     */
    async getUser(userId) {
        return this.get(`users/${userId}`);
    }

    /**
     * Создание пользователя
     */
    async createUser(userData) {
        return this.post('users', userData);
    }

    /**
     * Обновление пользователя
     */
    async updateUser(userId, userData) {
        return this.put(`users/${userId}`, userData);
    }

    /**
     * Удаление пользователя
     */
    async deleteUser(userId) {
        return this.delete(`users/${userId}`);
    }

    /**
     * Импорт пользователей из CSV
     */
    async importUsers(file) {
        const formData = new FormData();
        formData.append('file', file);
        return this.uploadFile('users/import', formData);
    }

    /**
     * Получение ролей
     */
    async getRoles() {
        return this.get('users/roles');
    }

    /**
     * Получение факультетов
     */
    async getFaculties() {
        return this.get('users/faculties');
    }

    /**
     * Получение кафедр
     */
    async getDepartments(facultyId = null) {
        const params = facultyId ? { faculty_id: facultyId } : {};
        return this.get('users/departments', params);
    }

    // ============ МЕТОДЫ ДЛЯ ДОСТИЖЕНИЙ ============

    /**
     * Получение достижений пользователя
     */
    async getAchievements(userId) {
        return this.get(`achievements/${userId}`);
    }

    /**
     * Обновление достижений
     */
    async updateAchievements(userId, achievementsData) {
        return this.put(`achievements/${userId}`, achievementsData);
    }

    /**
     * Экспорт достижений в CSV
     */
    async exportAchievements(userId, options = {}) {
        return this.downloadFile(`achievements/${userId}/export`, options);
    }

    /**
     * Импорт достижений из CSV
     */
    async importAchievements(userId, file) {
        const formData = new FormData();
        formData.append('file', file);
        return this.uploadFile(`achievements/${userId}/import`, formData);
    }

    // ============ МЕТОДЫ ДЛЯ ОТЧЕТОВ ============

    /**
     * Экспорт отчета по всем пользователям
     */
    async exportReport(filters = {}) {
        return this.downloadFile('reports/export', filters);
    }

    /**
     * Получение статистики
     */
    async getStatistics() {
        return this.get('reports/statistics');
    }

    // ============ МЕТОДЫ ДЛЯ АДМИНИСТРИРОВАНИЯ ============

    /**
     * Получение системных логов
     */
    async getSystemLogs(page = 1, limit = 50) {
        return this.get('system/logs', { page, limit });
    }

    /**
     * Получение статуса системы
     */
    async getSystemStatus() {
        return this.get('system/status');
    }
}

/**
 * Класс для API ошибок
 */
class ApiError extends Error {
    constructor(message, status = 0, data = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

/**
 * Утилиты для работы с API
 */
class ApiUtils {
    /**
     * Форматирование даты для API
     */
    static formatDate(date) {
        if (!date) return null;
        if (typeof date === 'string') return date;
        return date.toISOString().split('T')[0];
    }

    /**
     * Форматирование даты и времени для отображения
     */
    static formatDateTime(dateString) {
        if (!dateString) return '-';
        
        const date = new Date(dateString);
        const now = new Date();
        const diffMs = now - date;
        const diffHours = diffMs / (1000 * 60 * 60);
        const diffDays = diffMs / (1000 * 60 * 60 * 24);

        // Если менее часа назад
        if (diffHours < 1) {
            const minutes = Math.floor(diffMs / (1000 * 60));
            return `${minutes} хв. тому`;
        }

        // Если менее суток назад
        if (diffDays < 1) {
            const hours = Math.floor(diffHours);
            return `${hours} год. тому`;
        }

        // Если менее недели назад
        if (diffDays < 7) {
            const days = Math.floor(diffDays);
            return `${days} дн. тому`;
        }

        // Иначе показываем дату
        return date.toLocaleDateString('uk-UA', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    /**
     * Валидация email
     */
    static isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }

    /**
     * Генерация случайного пароля
     */
    static generatePassword(length = 8) {
        const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        let result = '';
        for (let i = 0; i < length; i++) {
            result += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        return result;
    }

    /**
     * Дебаунс функции
     */
    static debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Троттлинг функции
     */
    static throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }

    /**
     * Безопасное получение вложенного свойства объекта
     */
    static safeGet(obj, path, defaultValue = null) {
        const keys = path.split('.');
        let result = obj;
        
        for (const key of keys) {
            if (result == null || typeof result !== 'object') {
                return defaultValue;
            }
            result = result[key];
        }
        
        return result !== undefined ? result : defaultValue;
    }

    /**
     * Экранирование HTML
     */
    static escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
}

// Создаем глобальный экземпляр API клиента
window.api = new ApiClient();
window.ApiError = ApiError;
window.ApiUtils = ApiUtils;