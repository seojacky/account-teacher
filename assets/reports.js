// assets/reports.js

/**
 * Менеджер звітів та статистики
 */
class ReportsManager {
    constructor() {
        this.isInitialized = false;
        this.statisticsData = null;
        this.updateInterval = null;
        
        // Bind методів
        this.handleExportAll = this.handleExportAll.bind(this);
        this.handleRefreshStats = this.handleRefreshStats.bind(this);
    }

    /**
     * Ініціалізація менеджера звітів
     */
    async init() {
        if (this.isInitialized) return;
        
        this.setupEventListeners();
        await this.loadStatistics();
        this.startAutoRefresh();
        
        this.isInitialized = true;
        console.log('[Reports] Менеджер звітів ініціалізовано');
    }

    /**
     * Налаштування обробників подій
     */
    setupEventListeners() {
        // Кнопка експорту всіх досягнень
        const exportAllBtn = document.getElementById('export-all-achievements');
        if (exportAllBtn) {
            exportAllBtn.addEventListener('click', this.handleExportAll);
        }

        // Кнопка оновлення статистики
        const refreshStatsBtn = document.getElementById('refresh-statistics');
        if (refreshStatsBtn) {
            refreshStatsBtn.addEventListener('click', this.handleRefreshStats);
        }

        console.log('[Reports] Обробники подій налаштовано');
    }

    /**
     * Завантаження статистики
     */
    async loadStatistics() {
        try {
            const response = await window.api.getStatistics();
            
            if (response.success) {
                this.statisticsData = response.statistics;
                this.renderStatistics();
                console.log('[Reports] Статистика завантажена');
            }

        } catch (error) {
            console.error('[Reports] Помилка завантаження статистики:', error);
            
            if (error instanceof ApiError) {
                window.toast.error(`Помилка завантаження статистики: ${error.message}`);
            } else {
                window.toast.error('Невідома помилка завантаження статистики');
            }
        }
    }

    /**
     * Відображення статистики
     */
    renderStatistics() {
        if (!this.statisticsData) return;

        const stats = this.statisticsData;

        // Основна статистика
        this.updateStatElement('total-users', stats.total_users || 0);
        this.updateStatElement('users-with-achievements', stats.users_with_achievements || 0);

        // Статистика по ролях
        this.renderRoleStatistics(stats.by_role || []);

        // Статистика по факультетах
        this.renderFacultyStatistics(stats.by_faculty || []);

        // Додаткова статистика
        this.renderAdditionalStats(stats);
    }

    /**
     * Оновлення елемента статистики
     */
    updateStatElement(id, value) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = value;
        }
    }

    /**
     * Відображення статистики по ролях
     */
    renderRoleStatistics(roleStats) {
        const container = document.getElementById('role-statistics');
        if (!container) return;

        container.innerHTML = '';

        if (roleStats.length === 0) {
            container.innerHTML = '<p class="text-center">Немає даних</p>';
            return;
        }

        const list = document.createElement('div');
        list.className = 'stats-list';

        roleStats.forEach(role => {
            const item = document.createElement('div');
            item.className = 'stat-item';
            item.innerHTML = `
                <span class="stat-label">${role.display_name}:</span>
                <span class="stat-value">${role.count}</span>
            `;
            list.appendChild(item);
        });

        container.appendChild(list);
    }

    /**
     * Відображення статистики по факультетах
     */
    renderFacultyStatistics(facultyStats) {
        const container = document.getElementById('faculty-statistics');
        if (!container) return;

        container.innerHTML = '';

        if (facultyStats.length === 0) {
            container.innerHTML = '<p class="text-center">Немає даних</p>';
            return;
        }

        const list = document.createElement('div');
        list.className = 'stats-list';

        facultyStats.forEach(faculty => {
            const item = document.createElement('div');
            item.className = 'stat-item';
            item.innerHTML = `
                <span class="stat-label">${faculty.short_name}:</span>
                <span class="stat-value">${faculty.count}</span>
            `;
            list.appendChild(item);
        });

        container.appendChild(list);
    }

    /**
     * Відображення додаткової статистики
     */
    renderAdditionalStats(stats) {
        // Обчислюємо відсоток заповнення
        const fillPercentage = stats.total_users > 0 
            ? Math.round((stats.users_with_achievements / stats.total_users) * 100)
            : 0;

        this.updateStatElement('fill-percentage', `${fillPercentage}%`);

        // Відображаємо прогрес бар якщо є
        const progressBar = document.getElementById('achievements-progress');
        if (progressBar) {
            progressBar.style.width = `${fillPercentage}%`;
            progressBar.className = `progress-fill ${fillPercentage >= 80 ? 'success' : fillPercentage >= 50 ? 'warning' : 'info'}`;
        }
    }

    /**
     * Обробка експорту всіх досягнень
     */
    async handleExportAll() {
        const currentUser = window.auth.getCurrentUser();
        if (!currentUser) {
            window.toast.error('Користувач не авторизований');
            return;
        }

        // Перевіряємо права доступу
        if (!['admin', 'dekanat', 'zaviduvach'].includes(currentUser.role)) {
            window.toast.error('Недостатньо прав для експорту звітів');
            return;
        }

        const exportBtn = document.getElementById('export-all-achievements');
        const originalText = exportBtn.innerHTML;

        try {
            exportBtn.disabled = true;
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Експорт...';

            // Отримуємо фільтри (якщо є)
            const filters = this.getReportFilters();

            await window.api.exportReport(filters);
            window.toast.success('Звіт успішно завантажено!');

        } catch (error) {
            console.error('[Reports] Помилка експорту:', error);
            
            if (error instanceof ApiError) {
                window.toast.error(`Помилка експорту: ${error.message}`);
            } else {
                window.toast.error('Невідома помилка експорту');
            }
        } finally {
            exportBtn.disabled = false;
            exportBtn.innerHTML = originalText;
        }
    }

    /**
     * Отримання фільтрів звіту
     */
    getReportFilters() {
        const filters = {};

        // Фільтр по факультету
        const facultyFilter = document.getElementById('report-faculty-filter');
        if (facultyFilter && facultyFilter.value) {
            filters.faculty_id = facultyFilter.value;
        }

        // Фільтр по кафедрі
        const departmentFilter = document.getElementById('report-department-filter');
        if (departmentFilter && departmentFilter.value) {
            filters.department_id = departmentFilter.value;
        }

        // Фільтр по статусу заповнення
        const statusFilter = document.getElementById('report-status-filter');
        if (statusFilter && statusFilter.value) {
            filters.has_achievements = statusFilter.value === 'filled';
        }

        return filters;
    }

    /**
     * Обробка оновлення статистики
     */
    async handleRefreshStats() {
        await this.loadStatistics();
        window.toast.success('Статистику оновлено');
    }

    /**
     * Запуск автоматичного оновлення
     */
    startAutoRefresh() {
        // Оновлюємо статистику кожні 5 хвилин
        this.updateInterval = setInterval(() => {
            this.loadStatistics();
        }, 5 * 60 * 1000);
    }

    /**
     * Зупинка автоматичного оновлення
     */
    stopAutoRefresh() {
        if (this.updateInterval) {
            clearInterval(this.updateInterval);
            this.updateInterval = null;
        }
    }

    /**
     * Створення звіту з фільтрами
     */
    async generateFilteredReport(filters) {
        try {
            const response = await window.api.exportReport(filters);
            return response;
        } catch (error) {
            console.error('[Reports] Помилка створення звіту:', error);
            throw error;
        }
    }

    /**
     * Отримання топ викладачів по кількості досягнень
     */
    async getTopInstructors(limit = 10) {
        try {
            const response = await window.api.get('reports/top-instructors', { limit });
            return response;
        } catch (error) {
            console.error('[Reports] Помилка отримання топ викладачів:', error);
            return null;
        }
    }

    /**
     * Генерація звіту по кафедрі
     */
    async generateDepartmentReport(departmentId) {
        try {
            const filters = { department_id: departmentId };
            return await this.generateFilteredReport(filters);
        } catch (error) {
            console.error('[Reports] Помилка звіту по кафедрі:', error);
            throw error;
        }
    }

    /**
     * Генерація звіту по факультету
     */
    async generateFacultyReport(facultyId) {
        try {
            const filters = { faculty_id: facultyId };
            return await this.generateFilteredReport(filters);
        } catch (error) {
            console.error('[Reports] Помилка звіту по факультету:', error);
            throw error;
        }
    }

    /**
     * Очищення ресурсів
     */
    destroy() {
        this.stopAutoRefresh();
        
        const exportAllBtn = document.getElementById('export-all-achievements');
        if (exportAllBtn) {
            exportAllBtn.removeEventListener('click', this.handleExportAll);
        }
        
        console.log('[Reports] Менеджер звітів знищено');
    }
}

/**
 * Утиліти для роботи зі звітами
 */
class ReportsUtils {
    /**
     * Форматування великих чисел
     */
    static formatNumber(number) {
        if (number >= 1000000) {
            return (number / 1000000).toFixed(1) + 'М';
        } else if (number >= 1000) {
            return (number / 1000).toFixed(1) + 'К';
        }
        return number.toString();
    }

    /**
     * Обчислення відсотка
     */
    static calculatePercentage(value, total) {
        if (total === 0) return 0;
        return Math.round((value / total) * 100);
    }

    /**
     * Створення CSV з даних
     */
    static createCSV(headers, data) {
        const csvContent = [
            headers.join(';'),
            ...data.map(row => 
                row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(';')
            )
        ].join('\n');

        return '\ufeff' + csvContent; // Додаємо BOM для коректного відображення у Excel
    }

    /**
     * Завантаження CSV файлу
     */
    static downloadCSV(filename, content) {
        const blob = new Blob([content], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        
        link.setAttribute('href', url);
        link.setAttribute('download', filename);
        link.style.visibility = 'hidden';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    /**
     * Генерація кольорів для графіків
     */
    static generateColors(count) {
        const colors = [
            '#2563eb', '#dc2626', '#059669', '#d97706', '#7c3aed',
            '#0284c7', '#65a30d', '#c2410c', '#be123c', '#0891b2'
        ];
        
        const result = [];
        for (let i = 0; i < count; i++) {
            result.push(colors[i % colors.length]);
        }
        
        return result;
    }

    /**
     * Валідація даних для звіту
     */
    static validateReportData(data) {
        if (!data || !Array.isArray(data)) {
            return { valid: false, message: 'Некоректні дані для звіту' };
        }

        if (data.length === 0) {
            return { valid: false, message: 'Немає даних для звіту' };
        }

        return { valid: true };
    }

    /**
     * Групування даних по полю
     */
    static groupBy(data, field) {
        return data.reduce((groups, item) => {
            const key = item[field] || 'Не вказано';
            if (!groups[key]) {
                groups[key] = [];
            }
            groups[key].push(item);
            return groups;
        }, {});
    }

    /**
     * Сортування статистики
     */
    static sortStatistics(stats, sortBy = 'count', direction = 'desc') {
        return stats.sort((a, b) => {
            const valueA = a[sortBy] || 0;
            const valueB = b[sortBy] || 0;
            
            if (direction === 'desc') {
                return valueB - valueA;
            } else {
                return valueA - valueB;
            }
        });
    }

    /**
     * Фільтрація даних за датою
     */
    static filterByDate(data, dateField, startDate, endDate) {
        return data.filter(item => {
            const itemDate = new Date(item[dateField]);
            return itemDate >= startDate && itemDate <= endDate;
        });
    }

    /**
     * Розрахунок тренду
     */
    static calculateTrend(currentValue, previousValue) {
        if (previousValue === 0) {
            return currentValue > 0 ? 100 : 0;
        }
        
        return Math.round(((currentValue - previousValue) / previousValue) * 100);
    }

    /**
     * Форматування тренду для відображення
     */
    static formatTrend(trend) {
        const sign = trend > 0 ? '+' : '';
        const color = trend > 0 ? 'success' : trend < 0 ? 'danger' : 'secondary';
        const icon = trend > 0 ? 'fa-arrow-up' : trend < 0 ? 'fa-arrow-down' : 'fa-minus';
        
        return {
            text: `${sign}${trend}%`,
            color,
            icon
        };
    }
}

/**
 * Компонент для відображення статистичних карток
 */
class StatisticsCard {
    constructor(container, options = {}) {
        this.container = container;
        this.options = {
            title: 'Статистика',
            icon: 'fas fa-chart-bar',
            value: 0,
            trend: null,
            description: '',
            ...options
        };
        
        this.render();
    }

    render() {
        if (!this.container) return;

        const trendHtml = this.options.trend 
            ? `<div class="card-trend trend-${this.options.trend.color}">
                 <i class="fas ${this.options.trend.icon}"></i>
                 ${this.options.trend.text}
               </div>`
            : '';

        this.container.innerHTML = `
            <div class="statistics-card">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="${this.options.icon}"></i>
                    </div>
                    <div class="card-title">${this.options.title}</div>
                </div>
                <div class="card-body">
                    <div class="card-value">${this.options.value}</div>
                    ${trendHtml}
                    <div class="card-description">${this.options.description}</div>
                </div>
            </div>
        `;
    }

    update(newOptions) {
        this.options = { ...this.options, ...newOptions };
        this.render();
    }
}

// Створюємо глобальні екземпляри
window.reports = new ReportsManager();
window.ReportsUtils = ReportsUtils;
window.StatisticsCard = StatisticsCard;