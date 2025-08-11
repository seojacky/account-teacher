// assets/auth.js - Полная исправленная версия

/**
 * Менеджер авторизации и управления пользователями
 */
class AuthManager {
    constructor() {
        this.currentUser = null;
        this.loginForm = null;
        this.isInitialized = false;
        this.sessionCheckInterval = null;
        
        // Bind методы для корректного контекста
        this.handleLogin = this.handleLogin.bind(this);
        this.handleLogout = this.handleLogout.bind(this);
        this.checkAuthStatus = this.checkAuthStatus.bind(this);
    }

    /**
     * Инициализация менеджера авторизации
     */
    async init() {
        if (this.isInitialized) return;
        
        this.setupLoginForm();
        this.setupLogoutHandler();
        
        // ОТЛОЖЕННАЯ настройка кнопки смены пароля
        setTimeout(() => {
            this.setupPasswordChangeHandler();
        }, 500);
        
        // Проверяем статус авторизации при загрузке
        await this.checkAuthStatus();
        
        this.isInitialized = true;
        console.log('[Auth] Менеджер авторизации инициализирован');
    }

    /**
     * Настройка формы авторизации
     */
    setupLoginForm() {
        this.loginForm = document.getElementById('login-form');
        if (!this.loginForm) return;

        this.loginForm.addEventListener('submit', this.handleLogin);
        
        // Автофокус на первое поле
        const firstInput = this.loginForm.querySelector('input');
        if (firstInput) {
            firstInput.focus();
        }

        // Enter на поле пароля
        const passwordInput = document.getElementById('password');
        if (passwordInput) {
            passwordInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.handleLogin(e);
                }
            });
        }
    }

    /**
     * Настройка обработчика выхода
     */
    setupLogoutHandler() {
        const logoutBtn = document.getElementById('logout-btn');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', this.handleLogout);
        }
    }

    /**
     * Настройка обработчика смены пароля - ИСПРАВЛЕННАЯ ВЕРСИЯ
     */
    setupPasswordChangeHandler() {
        const changePasswordBtn = document.getElementById('change-password-btn');
        
        if (changePasswordBtn) {
            console.log('[Auth] Настраиваем обработчик смены пароля');
            
            // ПОЛНОСТЬЮ очищаем все обработчики
            const newBtn = changePasswordBtn.cloneNode(true);
            changePasswordBtn.parentNode.replaceChild(newBtn, changePasswordBtn);
            
            // Добавляем ЕДИНСТВЕННЫЙ обработчик
            newBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                
                console.log('[Auth] Клік по кнопці зміни пароля');
                
                // Закрываем выпадающее меню
                const userMenuDropdown = document.getElementById('user-menu-dropdown');
                if (userMenuDropdown) {
                    userMenuDropdown.classList.add('hidden');
                }
                
                // Показываем модаль через небольшую задержку
                setTimeout(() => {
                    if (window.modals) {
                        console.log('[Auth] Открываем модаль смены пароля');
                        window.modals.show('change-password-modal');
                    }
                }, 100);
            });
            
            console.log('[Auth] Обработчик смены пароля установлен');
        }

        // Настройка формы смены пароля
        const changePasswordForm = document.getElementById('change-password-form');
        if (changePasswordForm) {
            changePasswordForm.addEventListener('submit', this.handlePasswordChange.bind(this));
        }
    }

    /**
     * Обработка авторизации
     */
    async handleLogin(event) {
        event.preventDefault();
        
        const formData = new FormData(this.loginForm);
        const employeeId = formData.get('employee_id')?.trim();
        const password = formData.get('password');
        
        // Валидация
        if (!employeeId || !password) {
            this.showLoginError('Заповніть всі поля');
            return;
        }

        const submitBtn = this.loginForm.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        try {
            // Показываем индикатор загрузки
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Входжу...';
            this.hideLoginError();

            // Отправляем запрос авторизации
            const response = await window.api.login(employeeId, password);
            
            if (response.user) {
                this.currentUser = response.user;
                this.onLoginSuccess();
                
                // Показываем приложение
                if (window.app && window.app.showMainApp) {
                    window.app.showMainApp();
                }
                
                window.toast.success('Успішно авторизовано!');
            }

        } catch (error) {
            if (error instanceof ApiError) {
                if (error.status === 401) {
                    this.showLoginError('Неправильний ID працівника або пароль');
                } else if (error.status === 0) {
                    this.showLoginError('Помилка з\'єднання. Перевірте інтернет.');
                } else {
                    this.showLoginError(error.message || 'Помилка авторизації');
                }
            } else {
                this.showLoginError('Невідома помилка авторизації');
            }
        } finally {
            // Восстанавливаем кнопку
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    /**
     * Обработка выхода из системы
     */
    async handleLogout() {
        try {
            await window.api.logout();
        } catch (error) {
            // Игнорируем ошибки выхода
        } finally {
            this.onLogoutSuccess();
        }
    }

    /**
     * Обработка смены пароля
     */
    async handlePasswordChange(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const currentPassword = formData.get('current_password');
        const newPassword = formData.get('new_password');
        const confirmPassword = formData.get('confirm_password');
        
        // Валидация
        if (!currentPassword || !newPassword || !confirmPassword) {
            window.toast.error('Заповніть всі поля');
            return;
        }

        if (newPassword !== confirmPassword) {
            window.toast.error('Нові паролі не співпадають');
            return;
        }

        if (newPassword.length < 6) {
            window.toast.error('Пароль повинен містити мінімум 6 символів');
            return;
        }

        const submitBtn = event.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        try {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Збереження...';

            await window.api.changePassword(currentPassword, newPassword);
            
            window.toast.success('Пароль успішно змінено');
            window.modals.hide('change-password-modal');
            event.target.reset();

        } catch (error) {
            if (error instanceof ApiError) {
                window.toast.error(error.message || 'Помилка зміни паролю');
            } else {
                window.toast.error('Невідома помилка');
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    /**
     * Проверка статуса авторизации
     */
    async checkAuthStatus() {
        const sessionId = window.api.getSessionId();
        
        if (!sessionId) {
            this.onLogoutSuccess();
            return false;
        }

        try {
            const response = await window.api.getCurrentUser();
            
            if (response.user) {
                this.currentUser = response.user;
                this.onLoginSuccess();
                
                // Показываем приложение
                if (window.app && window.app.showMainApp) {
                    window.app.showMainApp();
                }
                
                return true;
            }

        } catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                // Сессия истекла
                this.onLogoutSuccess();
            }
        }
        
        return false;
    }

    /**
     * Успешная авторизация
     */
    onLoginSuccess() {
        // Обновляем информацию о пользователе в интерфейсе
        this.updateUserInfo();
        
        // Настраиваем права доступа в интерфейсе
        this.setupUserPermissions();
        
        // Сохраняем информацию о последнем входе
        localStorage.setItem('last_login', new Date().toISOString());
        
        // Запускаем периодическую проверку сессии
        this.startSessionCheck();
        
        // ВАЖНО: Перенастраиваем обработчик смены пароля после входа
        setTimeout(() => {
            this.setupPasswordChangeHandler();
        }, 1000);
    }

    /**
     * Успешный выход
     */
    onLogoutSuccess() {
        this.currentUser = null;
        window.api.clearSessionId();
        
        // Останавливаем проверку сессии
        this.stopSessionCheck();
        
        // Очищаем локальное хранилище
        localStorage.removeItem('last_login');
        
        // Показываем экран авторизации
        if (window.app && window.app.showLoginScreen) {
            window.app.showLoginScreen();
        }
    }

    /**
     * Обновление информации о пользователе в интерфейсе
     */
    updateUserInfo() {
        if (!this.currentUser) return;

        // Обновляем имя пользователя
        const userNameElement = document.getElementById('user-name');
        if (userNameElement) {
            userNameElement.textContent = this.currentUser.full_name;
        }

        // Обновляем информацию о викладаче в форме достижений
        const instructorNameElement = document.getElementById('instructor-name');
        const instructorPositionElement = document.getElementById('instructor-position');
        const instructorFacultyElement = document.getElementById('instructor-faculty');
        const instructorDepartmentElement = document.getElementById('instructor-department');

        if (instructorNameElement) {
            instructorNameElement.textContent = this.currentUser.full_name || '-';
        }
        if (instructorPositionElement) {
            instructorPositionElement.textContent = this.currentUser.position || '-';
        }
        if (instructorFacultyElement) {
            instructorFacultyElement.textContent = this.currentUser.faculty_name || '-';
        }
        if (instructorDepartmentElement) {
            instructorDepartmentElement.textContent = this.currentUser.department_name || '-';
        }
    }

    /**
     * Настройка прав доступа в интерфейсе
     */
    setupUserPermissions() {
        if (!this.currentUser) return;

        const role = this.currentUser.role;
        
        // Скрываем/показываем элементы навигации в зависимости от роли
        const usersNav = document.getElementById('users-nav');
        const reportsNav = document.getElementById('reports-nav');
        const adminNav = document.getElementById('admin-nav');

        // Пользователи - для админа, деканата, завкафедры
        if (usersNav) {
            if (['admin', 'dekanat', 'zaviduvach'].includes(role)) {
                usersNav.style.display = 'block';
            } else {
                usersNav.style.display = 'none';
            }
        }

        // Отчеты - для админа, деканата, завкафедры
        if (reportsNav) {
            if (['admin', 'dekanat', 'zaviduvach'].includes(role)) {
                reportsNav.style.display = 'block';
            } else {
                reportsNav.style.display = 'none';
            }
        }

        // Администрирование - только для админа
        if (adminNav) {
            if (role === 'admin') {
                adminNav.style.display = 'block';
            } else {
                adminNav.style.display = 'none';
            }
        }
    }

    /**
     * Запуск периодической проверки сессии
     */
    startSessionCheck() {
        // Проверяем сессию каждые 5 минут
        this.sessionCheckInterval = setInterval(async () => {
            try {
                await window.api.getCurrentUser();
            } catch (error) {
                if (error instanceof ApiError && error.status === 401) {
                    this.onLogoutSuccess();
                    window.toast.warning('Сесія завершена. Увійдіть знову.');
                }
            }
        }, 5 * 60 * 1000); // 5 минут
    }

    /**
     * Остановка проверки сессии
     */
    stopSessionCheck() {
        if (this.sessionCheckInterval) {
            clearInterval(this.sessionCheckInterval);
            this.sessionCheckInterval = null;
        }
    }

    /**
     * Показ ошибки авторизации
     */
    showLoginError(message) {
        const errorElement = document.getElementById('login-error');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.classList.remove('hidden');
        }
    }

    /**
     * Скрытие ошибки авторизации
     */
    hideLoginError() {
        const errorElement = document.getElementById('login-error');
        if (errorElement) {
            errorElement.classList.add('hidden');
        }
    }

    /**
     * Получение текущего пользователя
     */
    getCurrentUser() {
        return this.currentUser;
    }

    /**
     * Проверка роли пользователя
     */
    hasRole(role) {
        return this.currentUser && this.currentUser.role === role;
    }

    /**
     * Проверка прав доступа
     */
    hasPermission(permission, resource = null) {
        if (!this.currentUser || !this.currentUser.permissions) {
            return false;
        }

        const permissions = this.currentUser.permissions;
        
        // Админ может все
        if (this.currentUser.role === 'admin') {
            return true;
        }

        // Проверяем конкретные права
        if (resource && permissions[resource]) {
            return permissions[resource].includes(permission);
        }

        return false;
    }

    /**
     * Проверка прав доступа к пользователю
     */
    canAccessUser(targetUserId) {
        if (!this.currentUser) return false;

        const role = this.currentUser.role;
        const currentUserId = this.currentUser.id;

        // Админ может видеть всех
        if (role === 'admin') return true;

        // Викладач может видеть только себя
        if (role === 'vykladach') {
            return currentUserId == targetUserId;
        }

        // Для деканата и завкафедры проверяем принадлежность к подразделению
        // Эта логика будет реализована при загрузке конкретного пользователя
        return true;
    }

    /**
     * Форматирование отображения роли
     */
    getRoleDisplayName(role) {
        const roleNames = {
            'admin': 'Адміністратор',
            'dekanat': 'Деканат',
            'zaviduvach': 'Завідувач кафедри',
            'vykladach': 'Викладач'
        };
        
        return roleNames[role] || role;
    }

    /**
     * Проверка активности пользователя
     */
    checkUserActivity() {
        let lastActivity = Date.now();
        const activityTimeout = 30 * 60 * 1000; // 30 минут

        const updateActivity = () => {
            lastActivity = Date.now();
        };

        // Отслеживаем активность
        ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'].forEach(event => {
            document.addEventListener(event, updateActivity, true);
        });

        // Проверяем неактивность каждую минуту
        setInterval(() => {
            if (Date.now() - lastActivity > activityTimeout) {
                this.handleLogout();
                window.toast.warning('Автоматичний вихід через неактивність');
            }
        }, 60 * 1000);
    }

    /**
     * Cleanup при уничтожении
     */
    destroy() {
        this.stopSessionCheck();
        
        if (this.loginForm) {
            this.loginForm.removeEventListener('submit', this.handleLogin);
        }
        
        console.log('[Auth] Менеджер авторизации уничтожен');
    }
}

/**
 * Утилиты для работы с авторизацией
 */
class AuthUtils {
    /**
     * Валидация пароля
     */
    static validatePassword(password) {
        const errors = [];
        
        if (!password) {
            errors.push('Пароль обов\'язковий');
            return errors;
        }
        
        if (password.length < 6) {
            errors.push('Пароль повинен містити мінімум 6 символів');
        }
        
        if (!/[a-zA-Z]/.test(password)) {
            errors.push('Пароль повинен містити літери');
        }
        
        if (!/[0-9]/.test(password)) {
            errors.push('Пароль повинен містити цифри');
        }
        
        return errors;
    }

    /**
     * Генерация безопасного пароля
     */
    static generateSecurePassword(length = 12) {
        const lowercase = 'abcdefghijklmnopqrstuvwxyz';
        const uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        const numbers = '0123456789';
        const symbols = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        
        const allChars = lowercase + uppercase + numbers + symbols;
        let password = '';
        
        // Обеспечиваем наличие каждого типа символов
        password += lowercase[Math.floor(Math.random() * lowercase.length)];
        password += uppercase[Math.floor(Math.random() * uppercase.length)];
        password += numbers[Math.floor(Math.random() * numbers.length)];
        password += symbols[Math.floor(Math.random() * symbols.length)];
        
        // Заполняем остальные позиции
        for (let i = 4; i < length; i++) {
            password += allChars[Math.floor(Math.random() * allChars.length)];
        }
        
        // Перемешиваем символы
        return password.split('').sort(() => 0.5 - Math.random()).join('');
    }

    /**
     * Оценка надежности пароля
     */
    static getPasswordStrength(password) {
        if (!password) return { score: 0, text: 'Дуже слабкий', color: '#dc2626' };
        
        let score = 0;
        
        // Длина
        if (password.length >= 6) score += 1;
        if (password.length >= 8) score += 1;
        if (password.length >= 12) score += 1;
        
        // Типы символов
        if (/[a-z]/.test(password)) score += 1;
        if (/[A-Z]/.test(password)) score += 1;
        if (/[0-9]/.test(password)) score += 1;
        if (/[^a-zA-Z0-9]/.test(password)) score += 1;
        
        // Разнообразие
        const uniqueChars = new Set(password).size;
        if (uniqueChars >= password.length * 0.6) score += 1;
        
        const strength = {
            0: { text: 'Дуже слабкий', color: '#dc2626' },
            1: { text: 'Слабкий', color: '#ea580c' },
            2: { text: 'Слабкий', color: '#ea580c' },
            3: { text: 'Середній', color: '#d97706' },
            4: { text: 'Середній', color: '#d97706' },
            5: { text: 'Хороший', color: '#65a30d' },
            6: { text: 'Хороший', color: '#65a30d' },
            7: { text: 'Відмінний', color: '#059669' },
            8: { text: 'Відмінний', color: '#059669' }
        };
        
        return { score, ...strength[Math.min(score, 8)] };
    }

    /**
     * Форматирование времени последнего входа
     */
    static formatLastLogin(dateString) {
        if (!dateString) return 'Ніколи';
        
        return ApiUtils.formatDateTime(dateString);
    }
}

// Создаем глобальный экземпляр менеджера авторизации
window.auth = new AuthManager();
window.AuthUtils = AuthUtils;