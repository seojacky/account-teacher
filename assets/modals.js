// assets/modals.js

/**
 * Менеджер модальних вікон
 */
class ModalsManager {
    constructor() {
        this.isInitialized = false;
        this.currentModal = null;
        this.overlay = null;
        
        // Bind методів
        this.handleOverlayClick = this.handleOverlayClick.bind(this);
        this.handleEscapeKey = this.handleEscapeKey.bind(this);
        this.handleCloseClick = this.handleCloseClick.bind(this);
    }

    /**
     * Ініціалізація менеджера модальних вікон
     */
    init() {
        if (this.isInitialized) return;
        
        this.overlay = document.getElementById('modal-overlay');
        if (!this.overlay) {
            console.error('[Modals] Modal overlay не знайдено');
            return;
        }
        
        this.setupEventListeners();
        this.isInitialized = true;
        console.log('[Modals] Менеджер модальних вікон ініціалізовано');
    }

    /**
     * Налаштування обробників подій
     */
    setupEventListeners() {
        // Клік по overlay для закриття
        this.overlay.addEventListener('click', this.handleOverlayClick);
        
        // Клавіша Escape для закриття
        document.addEventListener('keydown', this.handleEscapeKey);
        
        // Кнопки закриття модальних вікон
        document.addEventListener('click', (event) => {
            if (event.target.matches('.modal-close, .modal-cancel')) {
                this.handleCloseClick(event);
            }
        });

        // User menu dropdown
        this.setupUserMenu();
    }

    /**
     * Налаштування меню користувача
     */
    setupUserMenu() {
        const userMenuBtn = document.getElementById('user-menu-btn');
        const userMenuDropdown = document.getElementById('user-menu-dropdown');

        if (userMenuBtn && userMenuDropdown) {
            userMenuBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                userMenuDropdown.classList.toggle('hidden');
            });

            // Закриваємо меню при кліку поза ним
            document.addEventListener('click', () => {
                userMenuDropdown.classList.add('hidden');
            });

            userMenuDropdown.addEventListener('click', (e) => {
                e.stopPropagation();
            });
        }
    }

    /**
     * Відображення модального вікна
     */
    show(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) {
            console.error(`[Modals] Модальне вікно ${modalId} не знайдено`);
            return;
        }

        // Ховаємо поточне модальне вікно якщо є
        if (this.currentModal) {
            this.currentModal.style.display = 'none';
        }

        this.currentModal = modal;
        modal.style.display = 'block';
        this.overlay.classList.remove('hidden');
        this.overlay.classList.add('show');

        // Автофокус на перше поле вводу
        const firstInput = modal.querySelector('input, select, textarea');
        if (firstInput) {
            setTimeout(() => firstInput.focus(), 100);
        }
    }

    /**
     * Приховування модального вікна
     */
    hide(modalId = null) {
        if (modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.style.display = 'none';
            }
        } else if (this.currentModal) {
            this.currentModal.style.display = 'none';
        }

        this.currentModal = null;
        this.overlay.classList.remove('show');
        
        setTimeout(() => {
            this.overlay.classList.add('hidden');
        }, 300);
    }

    /**
     * Відображення модального вікна підтвердження
     */
    showConfirmation(options) {
        const defaultOptions = {
            title: 'Підтвердження',
            message: 'Ви впевнені?',
            type: 'warning',
            confirmText: 'Підтвердити',
            cancelText: 'Скасувати',
            onConfirm: null,
            onCancel: null
        };

        const config = { ...defaultOptions, ...options };

        // Створюємо модальне вікно підтвердження
        const confirmModal = this.createConfirmationModal(config);
        document.body.appendChild(confirmModal);

        // Відображаємо
        this.show(confirmModal.id);

        // Видаляємо після закриття
        setTimeout(() => {
            if (confirmModal.parentNode) {
                confirmModal.parentNode.removeChild(confirmModal);
            }
        }, 5000);
    }

    /**
     * Створення модального вікна підтвердження
     */
    createConfirmationModal(config) {
        const modalId = 'confirmation-modal-' + Date.now();
        const iconClass = this.getConfirmationIcon(config.type);

        const modal = document.createElement('div');
        modal.id = modalId;
        modal.className = 'modal confirmation-modal';
        modal.innerHTML = `
            <div class="modal-header">
                <h3>${config.title}</h3>
                <button class="modal-close">&times;</button>
            </div>
            <div class="modal-content">
                <div class="confirmation-icon ${config.type}">
                    <i class="fas ${iconClass}"></i>
                </div>
                <div class="confirmation-message">${config.message}</div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-primary confirm-btn">
                    ${config.confirmText}
                </button>
                <button class="btn btn-secondary modal-cancel">
                    ${config.cancelText}
                </button>
            </div>
        `;

        // Обробники подій
        const confirmBtn = modal.querySelector('.confirm-btn');
        const cancelBtn = modal.querySelector('.modal-cancel');

        confirmBtn.addEventListener('click', () => {
            if (config.onConfirm) {
                config.onConfirm();
            }
            this.hide(modalId);
        });

        cancelBtn.addEventListener('click', () => {
            if (config.onCancel) {
                config.onCancel();
            }
            this.hide(modalId);
        });

        return modal;
    }

    /**
     * Отримання іконки для типу підтвердження
     */
    getConfirmationIcon(type) {
        const icons = {
            warning: 'fa-exclamation-triangle',
            danger: 'fa-exclamation-circle',
            info: 'fa-info-circle',
            success: 'fa-check-circle'
        };
        return icons[type] || icons.warning;
    }

    /**
     * Обробка кліку по overlay
     */
    handleOverlayClick(event) {
        if (event.target === this.overlay) {
            this.hide();
        }
    }

    /**
     * Обробка клавіші Escape
     */
    handleEscapeKey(event) {
        if (event.key === 'Escape' && this.currentModal) {
            this.hide();
        }
    }

    /**
     * Обробка кліку по кнопці закриття
     */
    handleCloseClick(event) {
        event.preventDefault();
        this.hide();
    }

    /**
     * Очищення ресурсів
     */
    destroy() {
        if (this.overlay) {
            this.overlay.removeEventListener('click', this.handleOverlayClick);
        }
        
        document.removeEventListener('keydown', this.handleEscapeKey);
        
        console.log('[Modals] Менеджер модальних вікон знищено');
    }
}

/**
 * Менеджер Toast повідомлень
 */
class ToastManager {
    constructor() {
        this.container = null;
        this.toasts = new Map();
        this.init();
    }

    init() {
        // Створюємо контейнер якщо не існує
        this.container = document.getElementById('toast-container');
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.className = 'toast-container';
            document.body.appendChild(this.container);
        }
    }

    /**
     * Відображення toast повідомлення
     */
    show(message, type = 'info', duration = 5000) {
        const toastId = 'toast-' + Date.now();
        const toast = this.createToast(toastId, message, type);
        
        this.container.appendChild(toast);
        this.toasts.set(toastId, toast);

        // Автоматичне видалення
        setTimeout(() => {
            this.hide(toastId);
        }, duration);

        return toastId;
    }

    /**
     * Створення toast елемента
     */
    createToast(id, message, type) {
        const toast = document.createElement('div');
        toast.id = id;
        toast.className = `toast ${type}`;
        
        const iconClass = this.getToastIcon(type);
        
        toast.innerHTML = `
            <div class="toast-content">
                <div class="toast-icon">
                    <i class="fas ${iconClass}"></i>
                </div>
                <div class="toast-message">${message}</div>
            </div>
        `;

        // Кнопка закриття
        const closeBtn = document.createElement('button');
        closeBtn.className = 'toast-close';
        closeBtn.innerHTML = '&times;';
        closeBtn.addEventListener('click', () => this.hide(id));
        toast.appendChild(closeBtn);

        return toast;
    }

    /**
     * Приховування toast повідомлення
     */
    hide(toastId) {
        const toast = this.toasts.get(toastId);
        if (toast) {
            toast.style.animation = 'slideOut 0.3s ease-in';
            setTimeout(() => {
                if (toast.parentNode) {
                    toast.parentNode.removeChild(toast);
                }
                this.toasts.delete(toastId);
            }, 300);
        }
    }

    /**
     * Отримання іконки для типу toast
     */
    getToastIcon(type) {
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        return icons[type] || icons.info;
    }

    /**
     * Швидкі методи для різних типів повідомлень
     */
    success(message, duration = 5000) {
        return this.show(message, 'success', duration);
    }

    error(message, duration = 8000) {
        return this.show(message, 'error', duration);
    }

    warning(message, duration = 6000) {
        return this.show(message, 'warning', duration);
    }

    info(message, duration = 5000) {
        return this.show(message, 'info', duration);
    }
}

// Створюємо глобальні екземпляри
window.modals = new ModalsManager();
window.toast = new ToastManager();