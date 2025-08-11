// assets/users.js

/**
 * Менеджер пользователей
 */
class UsersManager {
    constructor() {
        this.currentPage = 1;
        this.pageSize = 20;
        this.totalPages = 1;
        this.totalUsers = 0;
        this.currentFilters = {};
        this.isInitialized = false;
        this.users = [];
        this.roles = [];
        this.faculties = [];
        this.departments = [];
        
        // Bind методов
        this.handleAddUser = this.handleAddUser.bind(this);
        this.handleImportUsers = this.handleImportUsers.bind(this);
        this.handleSearch = this.handleSearch.bind(this);
        this.handleFilterChange = this.handleFilterChange.bind(this);
        // Убираем handlePageChange.bind - метод goToPage будет вызываться напрямую
    }

    /**
     * Инициализация менеджера пользователей
     */
    async init() {
        if (this.isInitialized) return;
        
        await this.loadReferenceData();
        this.setupEventListeners();
        this.setupFilters();
        await this.loadUsers();
        
        this.isInitialized = true;
        console.log('[Users] Менеджер пользователей инициализирован');
    }

    /**
     * Загрузка справочных данных
     */
    async loadReferenceData() {
        try {
            // Загружаем роли
            const rolesResponse = await window.api.getRoles();
            if (rolesResponse.success) {
                this.roles = rolesResponse.data;
            }

            // Загружаем факультеты
            const facultiesResponse = await window.api.getFaculties();
            if (facultiesResponse.success) {
                this.faculties = facultiesResponse.data;
            }

            // Загружаем кафедры
            const departmentsResponse = await window.api.getDepartments();
            if (departmentsResponse.success) {
                this.departments = departmentsResponse.data;
            }

            console.log('[Users] Справочные данные загружены');
        } catch (error) {
            console.error('[Users] Ошибка загрузки справочных данных:', error);
            window.toast.error('Помилка завантаження довідкових даних');
        }
    }

    /**
     * Настройка обработчиков событий
     */
    setupEventListeners() {
        // Кнопка добавления пользователя
        const addUserBtn = document.getElementById('add-user-btn');
        if (addUserBtn) {
            addUserBtn.addEventListener('click', this.handleAddUser);
        }

        // Кнопка импорта пользователей
        const importUsersBtn = document.getElementById('import-users-btn');
        if (importUsersBtn) {
            importUsersBtn.addEventListener('click', this.handleImportUsers);
        }

        // Поиск
        const searchInput = document.getElementById('users-search');
        if (searchInput) {
            searchInput.addEventListener('input', 
                ApiUtils.debounce(this.handleSearch, 500)
            );
        }

        // Фильтры
        const filterInputs = [
            'users-faculty-filter',
            'users-department-filter',
            'users-role-filter'
        ];

        filterInputs.forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', this.handleFilterChange);
            }
        });

        // Кнопка очистки фильтров
        const clearFiltersBtn = document.getElementById('clear-users-filters');
        if (clearFiltersBtn) {
            clearFiltersBtn.addEventListener('click', this.clearFilters.bind(this));
        }

        // Форма добавления пользователя
        const addUserForm = document.getElementById('add-user-form');
        if (addUserForm) {
            addUserForm.addEventListener('submit', this.handleUserFormSubmit.bind(this));
            
            // Обработчик изменения факультета
            const facultySelect = addUserForm.querySelector('select[name="faculty_id"]');
            if (facultySelect) {
                facultySelect.addEventListener('change', this.updateDepartments.bind(this));
            }
        }

        // Импорт файла пользователей
        const usersFileInput = document.getElementById('users-file-input');
        if (usersFileInput) {
            usersFileInput.addEventListener('change', this.handleFileImport.bind(this));
        }

        console.log('[Users] Обработчики событий настроены');
    }

    /**
     * Настройка фильтров
     */
    setupFilters() {
        this.populateRolesFilter();
        this.populateFacultiesFilter();
        this.populateDepartmentsFilter();
    }

    /**
     * Заполнение фильтра ролей
     */
    populateRolesFilter() {
        const roleFilter = document.getElementById('users-role-filter');
        if (!roleFilter) return;

        roleFilter.innerHTML = '<option value="">Всі ролі</option>';
        
        this.roles.forEach(role => {
            const option = document.createElement('option');
            option.value = role.id;
            option.textContent = role.display_name;
            roleFilter.appendChild(option);
        });
    }

    /**
     * Заполнение фильтра факультетов
     */
    populateFacultiesFilter() {
        const facultyFilter = document.getElementById('users-faculty-filter');
        if (!facultyFilter) return;

        facultyFilter.innerHTML = '<option value="">Всі факультети</option>';
        
        this.faculties.forEach(faculty => {
            const option = document.createElement('option');
            option.value = faculty.id;
            option.textContent = faculty.short_name;
            facultyFilter.appendChild(option);
        });
    }

    /**
     * Заполнение фильтра кафедр
     */
    populateDepartmentsFilter(facultyId = null) {
        const departmentFilter = document.getElementById('users-department-filter');
        if (!departmentFilter) return;

        departmentFilter.innerHTML = '<option value="">Всі кафедри</option>';
        
        const filteredDepartments = facultyId 
            ? this.departments.filter(dept => dept.faculty_id == facultyId)
            : this.departments;
        
        filteredDepartments.forEach(department => {
            const option = document.createElement('option');
            option.value = department.id;
            option.textContent = department.short_name;
            departmentFilter.appendChild(option);
        });
    }

    /**
     * Загрузка списка пользователей
     */
    async loadUsers() {
        try {
            const response = await window.api.getUsers(
                this.currentFilters,
                this.currentPage,
                this.pageSize
            );

            if (response.success) {
                this.users = response.data;
                this.totalPages = response.pagination.pages;
                this.totalUsers = response.pagination.total;
                
                this.renderUsersTable();
                this.renderPagination();
                
                console.log(`[Users] Загружено ${this.users.length} пользователей`);
            }

        } catch (error) {
            console.error('[Users] Ошибка загрузки пользователей:', error);
            
            if (error instanceof ApiError) {
                window.toast.error(`Помилка завантаження користувачів: ${error.message}`);
            } else {
                window.toast.error('Невідома помилка завантаження');
            }
        }
    }

    /**
     * Отрисовка таблицы пользователей
     */
    renderUsersTable() {
        const tableBody = document.getElementById('users-table-body');
        if (!tableBody) return;

        if (this.users.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center">
                        <div style="padding: 2rem; color: var(--gray-500);">
                            <i class="fas fa-users fa-2x" style="margin-bottom: 1rem;"></i>
                            <p>Користувачів не знайдено</p>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        tableBody.innerHTML = '';
        
        this.users.forEach(user => {
            const row = this.createUserRow(user);
            tableBody.appendChild(row);
        });
    }

    /**
     * Создание строки пользователя
     */
    createUserRow(user) {
        const row = document.createElement('tr');
        row.dataset.userId = user.id;
        
        const lastLogin = user.last_login 
            ? ApiUtils.formatDateTime(user.last_login)
            : 'Ніколи';

        const statusClass = user.is_active ? 'status-active' : 'status-inactive';
        const statusText = user.is_active ? 'Активний' : 'Неактивний';

        row.innerHTML = `
            <td>${ApiUtils.escapeHtml(user.employee_id || '-')}</td>
            <td>
                <div class="user-info">
                    <div class="user-name">${ApiUtils.escapeHtml(user.full_name)}</div>
                    <div class="user-email">${ApiUtils.escapeHtml(user.email || '')}</div>
                </div>
            </td>
            <td>${ApiUtils.escapeHtml(user.position || '-')}</td>
            <td>${ApiUtils.escapeHtml(user.faculty_name || '-')}</td>
            <td>${ApiUtils.escapeHtml(user.department_name || '-')}</td>
            <td>
                <span class="role-badge role-${user.role_name || 'unknown'}">
                    ${ApiUtils.escapeHtml(user.role_name || '-')}
                </span>
            </td>
            <td>
                <span class="status-badge ${statusClass}">${statusText}</span>
                <div class="last-login">${lastLogin}</div>
            </td>
            <td>
                <div class="action-buttons">
                    ${this.createActionButtons(user)}
                </div>
            </td>
        `;

        return row;
    }

    /**
     * Создание кнопок действий для пользователя
     */
    createActionButtons(user) {
        const currentUser = window.auth.getCurrentUser();
        if (!currentUser) return '';

        const buttons = [];

        // Кнопка просмотра достижений
        if (window.auth.canAccessUser(user.id)) {
            buttons.push(`
                <button class="btn btn-small btn-outline" 
                        onclick="window.users.viewUserAchievements(${user.id})"
                        title="Переглянути досягнення">
                    <i class="fas fa-trophy"></i>
                </button>
            `);
        }

        // Кнопка редактирования (только для админа)
        if (currentUser.role === 'admin') {
            buttons.push(`
                <button class="btn btn-small btn-outline" 
                        onclick="window.users.editUser(${user.id})"
                        title="Редагувати">
                    <i class="fas fa-edit"></i>
                </button>
            `);

            // Кнопка активации/деактивации
            if (user.is_active) {
                buttons.push(`
                    <button class="btn btn-small btn-danger" 
                            onclick="window.users.deactivateUser(${user.id})"
                            title="Деактивувати">
                        <i class="fas fa-ban"></i>
                    </button>
                `);
            } else {
                buttons.push(`
                    <button class="btn btn-small btn-success" 
                            onclick="window.users.activateUser(${user.id})"
                            title="Активувати">
                        <i class="fas fa-check"></i>
                    </button>
                `);
            }
        }

        return buttons.join('');
    }

    /**
     * Отрисовка пагинации
     */
    renderPagination() {
        const pagination = document.getElementById('users-pagination');
        if (!pagination) return;

        if (this.totalPages <= 1) {
            pagination.innerHTML = '';
            return;
        }

        const buttons = [];

        // Кнопка "Предыдущая"
        if (this.currentPage > 1) {
            buttons.push(`
                <button class="btn btn-outline" onclick="window.users.goToPage(${this.currentPage - 1})">
                    <i class="fas fa-chevron-left"></i>
                </button>
            `);
        }

        // Номера страниц
        const startPage = Math.max(1, this.currentPage - 2);
        const endPage = Math.min(this.totalPages, this.currentPage + 2);

        if (startPage > 1) {
            buttons.push(`<button class="btn btn-outline" onclick="window.users.goToPage(1)">1</button>`);
            if (startPage > 2) {
                buttons.push(`<span class="pagination-dots">...</span>`);
            }
        }

        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === this.currentPage ? 'active' : '';
            buttons.push(`
                <button class="btn btn-outline ${activeClass}" onclick="window.users.goToPage(${i})">
                    ${i}
                </button>
            `);
        }

        if (endPage < this.totalPages) {
            if (endPage < this.totalPages - 1) {
                buttons.push(`<span class="pagination-dots">...</span>`);
            }
            buttons.push(`
                <button class="btn btn-outline" onclick="window.users.goToPage(${this.totalPages})">
                    ${this.totalPages}
                </button>
            `);
        }

        // Кнопка "Следующая"
        if (this.currentPage < this.totalPages) {
            buttons.push(`
                <button class="btn btn-outline" onclick="window.users.goToPage(${this.currentPage + 1})">
                    <i class="fas fa-chevron-right"></i>
                </button>
            `);
        }

        pagination.innerHTML = `
            <div class="pagination-info">
                Показано ${this.users.length} з ${this.totalUsers} користувачів
            </div>
            <div class="pagination-buttons">
                ${buttons.join('')}
            </div>
        `;
    }

    /**
     * Обработка поиска
     */
    handleSearch(event) {
        const query = event.target.value.trim();
        
        if (query) {
            this.currentFilters.search = query;
        } else {
            delete this.currentFilters.search;
        }
        
        this.currentPage = 1;
        this.loadUsers();
    }

    /**
     * Обработка изменения фильтров
     */
    handleFilterChange(event) {
        const filterId = event.target.id;
        const value = event.target.value;

        switch (filterId) {
            case 'users-faculty-filter':
                if (value) {
                    this.currentFilters.faculty_id = value;
                } else {
                    delete this.currentFilters.faculty_id;
                }
                // Обновляем список кафедр
                this.populateDepartmentsFilter(value);
                // Сбрасываем фильтр кафедр
                delete this.currentFilters.department_id;
                document.getElementById('users-department-filter').value = '';
                break;

            case 'users-department-filter':
                if (value) {
                    this.currentFilters.department_id = value;
                } else {
                    delete this.currentFilters.department_id;
                }
                break;

            case 'users-role-filter':
                if (value) {
                    this.currentFilters.role_id = value;
                } else {
                    delete this.currentFilters.role_id;
                }
                break;
        }

        this.currentPage = 1;
        this.loadUsers();
    }

    /**
     * Очистка фильтров
     */
    clearFilters() {
        this.currentFilters = {};
        this.currentPage = 1;

        // Очищаем поля фильтров
        document.getElementById('users-search').value = '';
        document.getElementById('users-faculty-filter').value = '';
        document.getElementById('users-department-filter').value = '';
        document.getElementById('users-role-filter').value = '';

        // Восстанавливаем полный список кафедр
        this.populateDepartmentsFilter();

        this.loadUsers();
    }

    /**
     * Переход на страницу
     */
    goToPage(page) {
        if (page < 1 || page > this.totalPages) return;
        
        this.currentPage = page;
        this.loadUsers();
    }

    /**
     * Обработка добавления пользователя
     */
    handleAddUser() {
        // Заполняем селекты в модали
        this.populateAddUserForm();
        window.modals.show('add-user-modal');
    }

    /**
     * Заполнение формы добавления пользователя
     */
    populateAddUserForm() {
        const form = document.getElementById('add-user-form');
        if (!form) return;

        // Заполняем роли
        const roleSelect = form.querySelector('select[name="role_id"]');
        if (roleSelect) {
            roleSelect.innerHTML = '<option value="">Оберіть роль</option>';
            this.roles.forEach(role => {
                const option = document.createElement('option');
                option.value = role.id;
                option.textContent = role.display_name;
                roleSelect.appendChild(option);
            });
        }

        // Заполняем факультеты
        const facultySelect = form.querySelector('select[name="faculty_id"]');
        if (facultySelect) {
            facultySelect.innerHTML = '<option value="">Оберіть факультет</option>';
            this.faculties.forEach(faculty => {
                const option = document.createElement('option');
                option.value = faculty.id;
                option.textContent = faculty.short_name;
                facultySelect.appendChild(option);
            });
        }

        // Очищаем кафедры
        const departmentSelect = form.querySelector('select[name="department_id"]');
        if (departmentSelect) {
            departmentSelect.innerHTML = '<option value="">Оберіть кафедру</option>';
        }
    }

    /**
     * Обновление списка кафедр в форме
     */
    updateDepartments(event) {
        const facultyId = event.target.value;
        const form = event.target.closest('form');
        const departmentSelect = form.querySelector('select[name="department_id"]');
        
        if (!departmentSelect) return;

        departmentSelect.innerHTML = '<option value="">Оберіть кафедру</option>';
        
        if (facultyId) {
            const filteredDepartments = this.departments.filter(
                dept => dept.faculty_id == facultyId
            );
            
            filteredDepartments.forEach(department => {
                const option = document.createElement('option');
                option.value = department.id;
                option.textContent = department.short_name;
                departmentSelect.appendChild(option);
            });
        }
    }

    /**
     * Обработка отправки формы пользователя
     */
    async handleUserFormSubmit(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const userData = {};
        
        // Собираем данные формы
        for (const [key, value] of formData.entries()) {
            if (value.trim()) {
                userData[key] = value.trim();
            }
        }

        // Валидация
        const validation = this.validateUserData(userData);
        if (!validation.valid) {
            window.toast.error(validation.message);
            return;
        }

        const submitBtn = event.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        
        try {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Створення...';

            const response = await window.api.createUser(userData);
            
            if (response.success) {
                window.toast.success('Користувача успішно створено!');
                
                if (response.password) {
                    window.toast.info(`Згенерований пароль: ${response.password}`, 10000);
                }
                
                window.modals.hide('add-user-modal');
                event.target.reset();
                await this.loadUsers();
            }

        } catch (error) {
            console.error('[Users] Ошибка создания пользователя:', error);
            
            if (error instanceof ApiError) {
                window.toast.error(`Помилка створення: ${error.message}`);
            } else {
                window.toast.error('Невідома помилка створення користувача');
            }
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }

    /**
     * Валидация данных пользователя
     */
    validateUserData(userData) {
        if (!userData.employee_id) {
            return { valid: false, message: 'ID працівника обов\'язковий' };
        }

        if (!userData.full_name) {
            return { valid: false, message: 'ПІБ обов\'язкове' };
        }

        if (!userData.role_id) {
            return { valid: false, message: 'Роль обов\'язкова' };
        }

        if (userData.email && !ApiUtils.isValidEmail(userData.email)) {
            return { valid: false, message: 'Некоректний email' };
        }

        return { valid: true };
    }

    /**
     * Просмотр достижений пользователя
     */
    async viewUserAchievements(userId) {
        try {
            // Переключаемся на секцию достижений
            const achievementsSection = document.getElementById('achievements-section');
            const usersSection = document.getElementById('users-section');
            
            if (achievementsSection && usersSection) {
                usersSection.classList.remove('active');
                achievementsSection.classList.add('active');
                
                // Обновляем навигацию
                document.querySelectorAll('.nav-btn').forEach(btn => {
                    btn.classList.remove('active');
                });
                document.querySelector('.nav-btn[data-section="achievements"]').classList.add('active');
                
                // Загружаем достижения пользователя
                if (window.achievements) {
                    await window.achievements.loadUserAchievements(userId);
                }
            }

        } catch (error) {
            console.error('[Users] Ошибка просмотра достижений:', error);
            window.toast.error('Помилка завантаження досягнень користувача');
        }
    }

    /**
     * Редактирование пользователя
     */
    editUser(userId) {
        // TODO: Реализовать редактирование пользователя
        window.toast.info('Функція редагування буде реалізована');
    }

    /**
     * Деактивация пользователя
     */
    async deactivateUser(userId) {
        const user = this.users.find(u => u.id == userId);
        if (!user) return;

        window.modals.showConfirmation({
            title: 'Деактивувати користувача',
            message: `Ви впевнені, що хочете деактивувати користувача "${user.full_name}"?`,
            type: 'warning',
            confirmText: 'Деактивувати',
            cancelText: 'Скасувати',
            onConfirm: async () => {
                try {
                    await window.api.deleteUser(userId);
                    window.toast.success('Користувача деактивовано');
                    await this.loadUsers();
                } catch (error) {
                    console.error('[Users] Ошибка деактивации:', error);
                    window.toast.error('Помилка деактивації користувача');
                }
            }
        });
    }

    /**
     * Активация пользователя
     */
    async activateUser(userId) {
        const user = this.users.find(u => u.id == userId);
        if (!user) return;

        try {
            // Пока используем updateUser для активации
            await window.api.updateUser(userId, { is_active: 1 });
            window.toast.success('Користувача активовано');
            await this.loadUsers();
        } catch (error) {
            console.error('[Users] Ошибка активации:', error);
            window.toast.error('Помилка активації користувача');
        }
    }

    /**
     * Обработка импорта пользователей
     */
    handleImportUsers() {
        const fileInput = document.getElementById('users-file-input');
        if (fileInput) {
            fileInput.click();
        }
    }

    /**
     * Обработка выбора файла для импорта
     */
    async handleFileImport(event) {
        const file = event.target.files[0];
        if (!file) return;

        // Проверяем тип файла
        if (!file.name.toLowerCase().endsWith('.csv')) {
            window.toast.error('Підтримуються тільки CSV файли');
            return;
        }

        // Проверяем размер файла
        if (file.size > 10 * 1024 * 1024) {
            window.toast.error('Файл занадто великий (максимум 10MB)');
            return;
        }

        try {
            const response = await window.api.importUsers(file);
            
            if (response.success) {
                window.toast.success(
                    `Імпортовано ${response.imported} користувачів`
                );
                
                if (response.errors && response.errors.length > 0) {
                    console.warn('[Users] Ошибки импорта:', response.errors);
                    window.toast.warning(
                        `Помилок при імпорті: ${response.errors.length}. Перевірте консоль.`
                    );
                }
                
                await this.loadUsers();
            }

        } catch (error) {
            console.error('[Users] Ошибка импорта:', error);
            
            if (error instanceof ApiError) {
                window.toast.error(`Помилка імпорту: ${error.message}`);
            } else {
                window.toast.error('Невідома помилка імпорту');
            }
        } finally {
            // Очищаем input
            event.target.value = '';
        }
    }

    /**
     * Получение пользователя по ID
     */
    getUserById(userId) {
        return this.users.find(user => user.id == userId);
    }

    /**
     * Получение роли по ID
     */
    getRoleById(roleId) {
        return this.roles.find(role => role.id == roleId);
    }

    /**
     * Получение факультета по ID
     */
    getFacultyById(facultyId) {
        return this.faculties.find(faculty => faculty.id == facultyId);
    }

    /**
     * Получение кафедры по ID
     */
    getDepartmentById(departmentId) {
        return this.departments.find(department => department.id == departmentId);
    }

    /**
     * Cleanup при уничтожении
     */
    destroy() {
        console.log('[Users] Менеджер пользователей уничтожен');
    }
}

// Создаем глобальный экземпляр
window.users = new UsersManager();