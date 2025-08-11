// assets/app.js

/**
 * Головний клас додатка
 */
class App {
    constructor() {
        this.isInitialized = false;
        this.currentSection = 'achievements';
        
        // Bind методів
        this.handleNavigation = this.handleNavigation.bind(this);
    }

    /**
     * Ініціалізація додатка
     */
    async init() {
        if (this.isInitialized) return;
        
        console.log('[App] Ініціалізація додатка...');
        
        // Ініціалізуємо компоненти в правильному порядку
        await this.initializeComponents();
        
        // Налаштовуємо навігацію
        this.setupNavigation();
        
        // Ініціалізуємо авторизацію
        await window.auth.init();
        
        this.isInitialized = true;
        console.log('[App] Додаток ініціалізовано');
    }

    /**
     * Ініціалізація компонентів
     */
    async initializeComponents() {
        // Модальні вікна та toast
        window.modals.init();
        
        // Менеджери (ініціалізуються пізніше при необхідності)
        console.log('[App] Компоненти готові до ініціалізації');
    }

    /**
     * Налаштування навігації між секціями
     */
    setupNavigation() {
        const navButtons = document.querySelectorAll('.nav-btn');
        
        navButtons.forEach(button => {
            button.addEventListener('click', this.handleNavigation);
        });
        
        console.log('[App] Навігація налаштована');
    }

    /**
     * Обробка переключення між секціями
     */
    handleNavigation(event) {
        const sectionName = event.target.dataset.section;
        if (!sectionName) return;
        
        this.showSection(sectionName);
    }

    /**
     * Відображення секції
     */
    async showSection(sectionName) {
        // Ховаємо всі секції
        document.querySelectorAll('.content-section').forEach(section => {
            section.classList.remove('active');
        });
        
        // Оновлюємо навігацію
        document.querySelectorAll('.nav-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Показуємо вибрану секцію
        const targetSection = document.getElementById(`${sectionName}-section`);
        const targetNavBtn = document.querySelector(`[data-section="${sectionName}"]`);
        
        if (targetSection) {
            targetSection.classList.add('active');
        }
        
        if (targetNavBtn) {
            targetNavBtn.classList.add('active');
        }
        
        // Ініціалізуємо менеджер секції при необхідності
        await this.initializeSectionManager(sectionName);
        
        this.currentSection = sectionName;
    }

    /**
     * Ініціалізація менеджера секції
     */
    async initializeSectionManager(sectionName) {
        switch (sectionName) {
            case 'achievements':
                if (window.achievements && !window.achievements.isInitialized) {
                    await window.achievements.init();
                }
                break;
                
            case 'users':
                if (window.users && !window.users.isInitialized) {
                    await window.users.init();
                }
                break;
                
            case 'reports':
                if (window.reports && !window.reports.isInitialized) {
                    await window.reports.init();
                }
                break;
        }
    }

    /**
     * Відображення екрану авторизації
     */
    showLoginScreen() {
        const loadingScreen = document.getElementById('loading-screen');
        const loginScreen = document.getElementById('login-screen');
        const appScreen = document.getElementById('app');
        
        if (loadingScreen) loadingScreen.classList.add('hidden');
        if (loginScreen) loginScreen.classList.remove('hidden');
        if (appScreen) appScreen.classList.add('hidden');
        
        console.log('[App] Відображено екран авторизації');
    }

    /**
     * Відображення головного додатка
     */
    async showMainApp() {
        const loadingScreen = document.getElementById('loading-screen');
        const loginScreen = document.getElementById('login-screen');
        const appScreen = document.getElementById('app');
        
        if (loadingScreen) loadingScreen.classList.add('hidden');
        if (loginScreen) loginScreen.classList.add('hidden');
        if (appScreen) appScreen.classList.remove('hidden');
        
        // Ініціалізуємо поточну секцію
        await this.initializeSectionManager(this.currentSection);
        
        console.log('[App] Відображено головний додаток');
    }

    /**
     * Приховування екрану завантаження
     */
    hideLoadingScreen() {
        const loadingScreen = document.getElementById('loading-screen');
        if (loadingScreen) {
            loadingScreen.classList.add('hidden');
        }
    }
}

/**
 * Ініціалізація при завантаженні DOM
 */
document.addEventListener('DOMContentLoaded', async () => {
    try {
        // Створюємо екземпляр додатка
        window.app = new App();
        
        // Ініціалізуємо
        await window.app.init();
        
    } catch (error) {
        console.error('[App] Помилка ініціалізації:', error);
        
        // Показуємо помилку користувачу
        if (window.toast) {
            window.toast.error('Помилка ініціалізації додатка');
        }
    }
});