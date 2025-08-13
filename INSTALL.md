# Инструкция по установке системы ЄДЕБО

## 1. Подготовка окружения

### Требования:
- PHP 7.4+ с расширениями: PDO, PDO_MySQL, mbstring
- MySQL 5.7+ или MariaDB 10.2+
- Web-сервер (Apache/Nginx)

## 2. Установка

### Шаг 1: Скачивание файлов
Разместите все файлы в корневой директории веб-сайта.

### Шаг 2: Настройка конфигурации
```bash
# Скопируйте файл примера конфигурации
cp .env.example .env

# Отредактируйте .env файл с вашими настройками
nano .env
```

### Шаг 3: Настройка .env файла
Отредактируйте файл `.env` и укажите:

```env
# Database Configuration
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_username
DB_PASS=your_password
DB_CHARSET=utf8mb4

# Application Configuration
APP_ENV=production
APP_DEBUG=false
```

### Шаг 4: Создание базы данных
```sql
-- Подключитесь к MySQL и выполните:
CREATE DATABASE kalinsky_edebo_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Шаг 5: Инициализация базы данных
```sql
-- Выполните скрипт инициализации:
mysql -u your_username -p kalinsky_edebo_system < start/init.sql
```

### Шаг 6: Настройка прав доступа
```bash
# Создайте директории для логов
mkdir logs
chmod 755 logs

# Убедитесь что веб-сервер может писать в директорию логов
chown www-data:www-data logs  # для Apache
# или
chown nginx:nginx logs        # для Nginx
```

### Шаг 7: Настройка веб-сервера

#### Apache (.htaccess уже настроен)
Убедитесь что mod_rewrite включен:
```bash
a2enmod rewrite
systemctl reload apache2
```

#### Nginx
Добавьте в конфигурацию:
```nginx
location /api/ {
    try_files $uri $uri/ /api/index.php?$query_string;
}

location ~ \.php$ {
    fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
}
```

## 3. Первый запуск

### Создание администратора
После установки создайте первого пользователя администратора напрямую в базе данных:

```sql
-- Создание администратора (пароль: admin123)
INSERT INTO users (employee_id, role_id, full_name, password_hash, is_active) 
VALUES ('ADMIN', 1, 'Системний адміністратор', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);
```

### Вход в систему
1. Откройте сайт в браузере
2. Войдите используя:
   - ID працівника: `ADMIN`
   - Пароль: `admin123`

## 4. Безопасность

### Обязательные шаги после установки:
1. Смените пароль администратора
2. Убедитесь что `.env` файл не доступен через веб
3. Настройте резервное копирование базы данных
4. Включите HTTPS

### Настройка веб-сервера для защиты .env:

#### Apache
```apache
<Files ".env*">
    Order allow,deny
    Deny from all
</Files>
```

#### Nginx
```nginx
location ~ /\.env {
    deny all;
    return 404;
}
```

## 5. Обслуживание

### Очистка логов
Система автоматически очищает старые логи, но вы можете настроить cron для дополнительной очистки:

```bash
# Добавьте в crontab:
0 2 * * * find /path/to/logs -name "*.log" -mtime +30 -delete
```

### Резервное копирование
```bash
# Создание бекапа базы данных
mysqldump -u username -p kalinsky_edebo_system > backup_$(date +%Y%m%d).sql
```

## 6. Устранение неполадок

### Проблемы с подключением к БД
1. Проверьте настройки в `.env`
2. Убедитесь что база данных создана
3. Проверьте права пользователя MySQL

### Проблемы с правами доступа
```bash
# Проверьте права на файлы
ls -la logs/
# Должно быть: drwxr-xr-x www-data www-data
```

### Проблемы с API
1. Проверьте что mod_rewrite включен (Apache)
2. Проверьте логи ошибок веб-сервера
3. Убедитесь что PHP может писать в директорию логов

## Поддержка

При возникновении проблем:
1. Проверьте логи в директории `logs/`
2. Убедитесь что все требования выполнены
3. Проверьте настройки веб-сервера