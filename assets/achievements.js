// assets/achievements.js

/**
 * Менеджер достижений викладачів
 */
class AchievementsManager {
    constructor() {
        this.currentUserId = null;
        this.achievementsData = {};
        this.isFormDirty = false;
        this.autoSaveTimeout = null;
        this.isInitialized = false;
        
        // Определения групп достижений
        this.achievementGroups = [
            {
                number: 1,
                title: "наявність не менше п'яти публікацій у періодичних наукових виданнях, що включені до переліку фахових видань України, до наукометричних баз, зокрема Scopus, Web of Science Core Collection"
            },
            {
                number: 2,
                title: "наявність одного патенту на винахід або п'яти деклараційних патентів на винахід чи корисну модель, включаючи секретні, або наявність не менше п'яти свідоцтв про реєстрацію авторського права на твір"
            },
            {
                number: 3,
                title: "наявність виданого підручника чи навчального посібника (включаючи електронні) або монографії (загальним обсягом не менше 5 авторських аркушів), в тому числі видані у співавторстві (обсягом не менше 1,5 авторського аркуша на кожного співавтора)"
            },
            {
                number: 4,
                title: "наявність виданих навчально-методичних посібників/посібників для самостійної роботи здобувачів вищої освіти та дистанційного навчання, електронних курсів на освітніх платформах ліцензіатів, конспектів лекцій/практикумів/методичних вказівок/рекомендацій/ робочих програм, інших друкованих навчально-методичних праць загальною кількістю три найменування"
            },
            {
                number: 5,
                title: "захист дисертації на здобуття наукового ступеня"
            },
            {
                number: 6,
                title: "наукове керівництво (консультування) здобувача, який одержав документ про присудження наукового ступеня (прізвище, ім'я, по батькові дисертанта, здобутий науковий ступінь, спеціальність, назва дисертації, рік захисту, серія, номер, дата, ким виданий диплом)"
            },
            {
                number: 7,
                title: "участь в атестації наукових кадрів як офіційного опонента або члена постійної спеціалізованої вченої ради, або члена не менше трьох разових спеціалізованих вчених рад"
            },
            {
                number: 8,
                title: "виконання функцій (повноважень, обов'язків) наукового керівника або відповідального виконавця наукової теми (проекту), або головного редактора/члена редакційної колегії/експерта (рецензента) наукового видання, включеного до переліку фахових видань України, або іноземного наукового видання, що індексується в бібліографічних базах"
            },
            {
                number: 9,
                title: "робота у складі експертної ради з питань проведення експертизи дисертацій МОН або у складі галузевої експертної ради як експерта Національного агентства із забезпечення якості вищої освіти, або у складі Акредитаційної комісії, або міжгалузевої експертної ради з вищої освіти Акредитаційної комісії, або трьох експертних комісій МОН/зазначеного Агентства, або Науково-методичної ради/науково-методичних комісій (підкомісій) з вищої або фахової передвищої освіти МОН, наукових/науково-методичних/експертних рад органів державної влади та органів місцевого самоврядування, або у складі комісій Державної служби якості освіти із здійснення планових (позапланових) заходів державного нагляду (контролю)"
            },
            {
                number: 10,
                title: "участь у міжнародних наукових та/або освітніх проектах, залучення до міжнародної експертизи, наявність звання \"суддя міжнародної категорії\""
            },
            {
                number: 11,
                title: "наукове консультування підприємств, установ, організацій не менше трьох років, що здійснювалося на підставі договору із закладом вищої освіти (науковою установою)"
            },
            {
                number: 12,
                title: "наявність апробаційних та/або науково-популярних, та/або консультаційних (дорадчих), та/або науково-експертних публікацій з наукової або професійної тематики загальною кількістю не менше п'яти публікацій"
            },
            {
                number: 13,
                title: "проведення навчальних занять із спеціальних дисциплін іноземною мовою (крім дисциплін мовної підготовки) в обсязі не менше 50 аудиторних годин на навчальний рік"
            },
            {
                number: 14,
                title: "керівництво студентом, який зайняв призове місце на I або ІІ етапі Всеукраїнської студентської олімпіади (Всеукраїнського конкурсу студентських наукових робіт), або робота у складі організаційного комітету / журі Всеукраїнської студентської олімпіади (Всеукраїнського конкурсу студентських наукових робіт), або керівництво постійно діючим студентським науковим гуртком / проблемною групою; керівництво студентом, який став призером або лауреатом Міжнародних, Всеукраїнських мистецьких конкурсів, фестивалів та проектів, робота у складі організаційного комітету або у складі журі міжнародних, всеукраїнських мистецьких конкурсів, інших культурно-мистецьких проектів (для забезпечення провадження освітньої діяльності на третьому (освітньо-творчому) рівні); керівництво здобувачем, який став призером або лауреатом міжнародних мистецьких конкурсів, фестивалів, віднесених до Європейської або Всесвітньої (Світової) асоціації мистецьких конкурсів, фестивалів, робота у складі організаційного комітету або у складі журі зазначених мистецьких конкурсів, фестивалів); керівництво студентом, який брав участь в Олімпійських, Паралімпійських іграх, Всесвітній та Всеукраїнській Універсіаді, чемпіонаті світу, Європи, Європейських іграх, етапах Кубка світу та Європи, чемпіонаті України; виконання обов'язків тренера, помічника тренера національної збірної команди України з видів спорту; виконання обов'язків головного секретаря, головного судді, судді міжнародних та всеукраїнських змагань; керівництво спортивною делегацією; робота у складі організаційного комітету, суддівського корпусу"
            },
            {
                number: 15,
                title: "керівництво школярем, який зайняв призове місце III—IV етапу Всеукраїнських учнівських олімпіад з базових навчальних предметів, II—III етапу Всеукраїнських конкурсів-захистів науково-дослідницьких робіт учнів — членів Національного центру \"Мала академія наук України\"; участь у журі III—IV етапу Всеукраїнських учнівських олімпіад з базових навчальних предметів чи II—III етапу Всеукраїнських конкурсів-захистів науково-дослідницьких робіт учнів — членів Національного центру \"Мала академія наук України\" (крім третього (освітньо-наукового/освітньо-творчого) рівня)"
            },
            {
                number: 16,
                title: "наявність статусу учасника бойових дій (для вищих військових навчальних закладів, закладів вищої освіти із специфічними умовами навчання, військових навчальних підрозділів закладів вищої освіти)"
            },
            {
                number: 17,
                title: "участь у міжнародних операціях з підтримання миру і безпеки під егідою Організації Об'єднаних Націй (для вищих військових навчальних закладів, закладів вищої освіти із специфічними умовами навчання, військових навчальних підрозділів закладів вищої освіти)"
            },
            {
                number: 18,
                title: "участь у міжнародних військових навчаннях (тренуваннях) за участю збройних сил країн — членів НАТО (для вищих військових навчальних закладів, військових навчальних підрозділів закладів вищої освіти)"
            },
            {
                number: 19,
                title: "діяльність за спеціальністю у формі участі у професійних та/або громадських об'єднаннях"
            },
            {
                number: 20,
                title: "досвід практичної роботи за спеціальністю (спеціалізацією)/професією не менше п'яти років (крім педагогічної, науково-педагогічної, наукової діяльності) із зазначенням посади та строку роботи на цій посаді"
            }
        ];
        
        // Bind методов
        this.handleFormSubmit = this.handleFormSubmit.bind(this);
        this.handleTextareaChange = this.handleTextareaChange.bind(this);
        this.handleImport = this.handleImport.bind(this);
        this.handleExport = this.handleExport.bind(this);
        this.handleClearForm = this.handleClearForm.bind(this);
    }

    /**
     * Инициализация менеджера достижений
     */
    async init() {
        if (this.isInitialized) return;
        
        await this.setupForm();
        this.setupEventListeners();
        this.setupAutoSave();
        
        // Загружаем достижения текущего пользователя
        const currentUser = window.auth.getCurrentUser();
        if (currentUser) {
            this.currentUserId = currentUser.id;
            await this.loadAchievements();
        }
        
        this.isInitialized = true;
    }

    /**
     * Настройка формы достижений
     */
    async setupForm() {
        const container = document.querySelector('.achievements-groups');
        if (!container) return;

        container.innerHTML = '';

        this.achievementGroups.forEach(group => {
            const groupElement = this.createAchievementGroup(group);
            container.appendChild(groupElement);
        });
    }

    /**
     * Создание элемента группы достижений
     */
    createAchievementGroup(group) {
        const groupDiv = document.createElement('div');
        groupDiv.className = 'achievement-group';
        
        groupDiv.innerHTML = `
            <div class="achievement-header" data-group="${group.number}">
                <div class="achievement-number">${group.number})</div>
                <div class="achievement-title">${group.title}</div>
                <button type="button" class="achievement-toggle">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            <div class="achievement-content">
                <textarea 
                    name="achievement_${group.number}" 
                    class="achievement-textarea"
                    placeholder="Наприклад: 1. Наукова публікація у періодичному виданні..."
                    rows="4"
                ></textarea>
            </div>
        `;

        // Обработчик переключения раскрытия группы
        const header = groupDiv.querySelector('.achievement-header');
        header.addEventListener('click', () => {
            groupDiv.classList.toggle('expanded');
        });

        return groupDiv;
    }

    /**
     * Настройка обработчиков событий
     */
    setupEventListeners() {
        // Обработчик отправки формы
        const form = document.getElementById('achievements-form');
        if (form) {
            form.addEventListener('submit', this.handleFormSubmit);
        }

        // Обработчики для кнопок
        const exportBtn = document.getElementById('export-achievements-btn');
        const importBtn = document.getElementById('import-achievements-btn');
        const clearBtn = document.getElementById('clear-form-btn');

        if (exportBtn) {
            exportBtn.addEventListener('click', this.handleExport);
        }

        if (importBtn) {
            importBtn.addEventListener('click', this.handleImport);
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', this.handleClearForm);
        }

        // Обработчик изменений в textarea
        document.addEventListener('input', (e) => {
            if (e.target.matches('.achievement-textarea')) {
                this.handleTextareaChange(e);
            }
        });

        // Обработчик импорта файла
        const csvFileInput = document.getElementById('csv-file-input');
        if (csvFileInput) {
            csvFileInput.addEventListener('change', this.handleFileImport.bind(this));
        }
    }

    /**
     * Настройка автосохранения
     */
    setupAutoSave() {
        // Автосохранение каждые 30 секунд при наличии изменений
        setInterval(() => {
            if (this.isFormDirty && this.currentUserId) {
                this.saveAchievements(true); // true = silent save
            }
        }, 30000);
    }

    /**
     * Загрузка достижений пользователя
     */
    async loadAchievements(userId = null) {
        const targetUserId = userId || this.currentUserId;
        if (!targetUserId) return;

        try {
            const response = await window.api.getAchievements(targetUserId);
            
            if (response.success && response.data) {
                this.achievementsData = response.data;
                this.fillForm(response.data);
                this.isFormDirty = false;
            }

        } catch (error) {
            if (error instanceof ApiError) {
                window.toast.error(`Помилка завантаження досягнень: ${error.message}`);
            } else {
                window.toast.error('Невідома помилка завантаження досягнень');
            }
        }
    }

    /**
     * Заполнение формы данными
     */
    fillForm(data) {
        for (let i = 1; i <= 20; i++) {
            const textarea = document.querySelector(`textarea[name="achievement_${i}"]`);
            if (textarea) {
                textarea.value = data[`achievement_${i}`] || '';
            }
        }

        // Обновляем информацию о викладаче
        this.updateInstructorInfo(data);
    }

    /**
     * Обновление информации о викладаче
     */
    updateInstructorInfo(data) {
        const elements = {
            'instructor-name': data.full_name,
            'instructor-position': data.position,
            'instructor-faculty': data.faculty_name,
            'instructor-department': data.department_name
        };

        Object.entries(elements).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                element.textContent = value || '-';
            }
        });
    }

    /**
     * Обработка отправки формы
     */
    async handleFormSubmit(event) {
        event.preventDefault();
        await this.saveAchievements();
    }

    /**
     * Сохранение достижений
     */
    async saveAchievements(silent = false) {
        if (!this.currentUserId) {
            if (!silent) {
                window.toast.error('Користувач не визначений');
            }
            return;
        }

        const formData = this.getFormData();
        const submitBtn = document.querySelector('#achievements-form button[type="submit"]');
        
        try {
            if (!silent && submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Збереження...';
            }

            const response = await window.api.updateAchievements(this.currentUserId, formData);
            
            if (response.success) {
                this.isFormDirty = false;
                
                if (!silent) {
                    window.toast.success('Досягнення успішно збережено!');
                }
            }

        } catch (error) {
            if (!silent) {
                if (error instanceof ApiError) {
                    window.toast.error(`Помилка збереження: ${error.message}`);
                } else {
                    window.toast.error('Невідома помилка збереження');
                }
            }
        } finally {
            if (!silent && submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Зберегти досягнення';
            }
        }
    }

    /**
     * Получение данных формы
     */
    getFormData() {
        const data = {};
        
        for (let i = 1; i <= 20; i++) {
            const textarea = document.querySelector(`textarea[name="achievement_${i}"]`);
            if (textarea) {
                data[`achievement_${i}`] = textarea.value.trim();
            }
        }
        
        return data;
    }

    /**
     * Обработка изменений в textarea
     */
    handleTextareaChange(event) {
        this.isFormDirty = true;
        
        // Автоматическое изменение высоты textarea
        const textarea = event.target;
        textarea.style.height = 'auto';
        textarea.style.height = textarea.scrollHeight + 'px';
        
        // Отменяем предыдущий таймер автосохранения
        if (this.autoSaveTimeout) {
            clearTimeout(this.autoSaveTimeout);
        }
        
        // Устанавливаем новый таймер автосохранения (5 секунд после прекращения ввода)
        this.autoSaveTimeout = setTimeout(() => {
            if (this.isFormDirty) {
                this.saveAchievements(true);
            }
        }, 5000);
    }

    /**
     * Обработка экспорта
     */
    async handleExport() {
        if (!this.currentUserId) {
            window.toast.error('Користувач не визначений');
            return;
        }

        // Показываем модаль настроек экспорта
        window.modals.show('export-settings-modal');
    }

    /**
     * Экспорт с настройками
     */
    async exportWithSettings(settings) {
        try {
            await window.api.exportAchievements(this.currentUserId, settings);
            window.toast.success('Файл успішно завантажено!');
            
        } catch (error) {
            if (error instanceof ApiError) {
                window.toast.error(`Помилка експорту: ${error.message}`);
            } else {
                window.toast.error('Невідома помилка експорту');
            }
        }
    }

    /**
     * Обработка импорта
     */
    handleImport() {
        const fileInput = document.getElementById('csv-file-input');
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

        // Проверяем размер файла (максимум 10MB)
        if (file.size > 10 * 1024 * 1024) {
            window.toast.error('Файл занадто великий (максимум 10MB)');
            return;
        }

        try {
            const response = await window.api.importAchievements(this.currentUserId, file);
            
            if (response.success) {
                // Перезагружаем достижения
                await this.loadAchievements();
                window.toast.success('Досягнення успішно імпортовано!');
            }

        } catch (error) {
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
     * Очистка формы
     */
    handleClearForm() {
        if (this.isFormDirty || this.hasAnyData()) {
            // Показываем подтверждение
            window.modals.showConfirmation({
                title: 'Очистити форму',
                message: 'Ви впевнені, що хочете очистити всі поля? Незбережені зміни будуть втрачені.',
                type: 'warning',
                confirmText: 'Очистити',
                cancelText: 'Скасувати',
                onConfirm: () => {
                    this.clearAllFields();
                }
            });
        } else {
            this.clearAllFields();
        }
    }

    /**
     * Проверка наличия данных в форме
     */
    hasAnyData() {
        for (let i = 1; i <= 20; i++) {
            const textarea = document.querySelector(`textarea[name="achievement_${i}"]`);
            if (textarea && textarea.value.trim()) {
                return true;
            }
        }
        return false;
    }

    /**
     * Очистка всех полей
     */
    clearAllFields() {
        for (let i = 1; i <= 20; i++) {
            const textarea = document.querySelector(`textarea[name="achievement_${i}"]`);
            if (textarea) {
                textarea.value = '';
                textarea.style.height = 'auto';
            }
        }

        // Сворачиваем все группы
        document.querySelectorAll('.achievement-group.expanded').forEach(group => {
            group.classList.remove('expanded');
        });

        this.isFormDirty = true;
        window.toast.info('Форма очищена');
    }

    /**
     * Загрузка достижений другого пользователя (для администраторов)
     */
    async loadUserAchievements(userId) {
        const currentUser = window.auth.getCurrentUser();
        
        // Проверяем права доступа
        if (!currentUser || !window.auth.canAccessUser(userId)) {
            window.toast.error('Недостатньо прав для перегляду досягнень цього користувача');
            return;
        }

        this.currentUserId = userId;
        await this.loadAchievements(userId);
    }

    /**
     * Cleanup при уничтожении
     */
    destroy() {
        if (this.autoSaveTimeout) {
            clearTimeout(this.autoSaveTimeout);
        }
        
        const form = document.getElementById('achievements-form');
        if (form) {
            form.removeEventListener('submit', this.handleFormSubmit);
        }
    }
}

/**
 * Помощник для работы с экспортом настроек
 */
class ExportSettingsHelper {
    static init() {
        const exportSettingsForm = document.getElementById('export-settings-form');
        if (exportSettingsForm) {
            exportSettingsForm.addEventListener('submit', this.handleExportSubmit.bind(this));
        }

        // Загружаем сохранённые настройки
        this.loadSavedSettings();
    }

    static async handleExportSubmit(event) {
        event.preventDefault();
        
        const formData = new FormData(event.target);
        const settings = {
            encoding: formData.get('encoding') || 'utf8bom',
            include_empty: formData.has('include_empty')
        };

        // Сохраняем настройки
        this.saveSettings(settings);

        // Закрываем модаль
        window.modals.hide('export-settings-modal');

        // Выполняем экспорт
        if (window.achievements) {
            await window.achievements.exportWithSettings(settings);
        }
    }

    static saveSettings(settings) {
        localStorage.setItem('export_settings', JSON.stringify(settings));
    }

    static loadSavedSettings() {
        try {
            const saved = localStorage.getItem('export_settings');
            if (saved) {
                const settings = JSON.parse(saved);
                
                // Применяем настройки к форме
                const form = document.getElementById('export-settings-form');
                if (form) {
                    const encodingRadio = form.querySelector(`input[name="encoding"][value="${settings.encoding}"]`);
                    if (encodingRadio) {
                        encodingRadio.checked = true;
                    }
                    
                    const includeEmptyCheckbox = form.querySelector('input[name="include_empty"]');
                    if (includeEmptyCheckbox) {
                        includeEmptyCheckbox.checked = settings.include_empty || false;
                    }
                }
            }
        } catch (error) {
            // Игнорируем ошибки загрузки настроек
        }
    }
}

// Инициализация при загрузке DOM
document.addEventListener('DOMContentLoaded', () => {
    // Инициализируем помощник настроек экспорта
    ExportSettingsHelper.init();
});

// Создаем глобальные экземпляры
window.achievements = new AchievementsManager();
window.ExportSettingsHelper = ExportSettingsHelper;