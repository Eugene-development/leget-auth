#!/bin/sh

set -e

echo "🚀 Starting Laravel application..."

# Создаем необходимые директории
echo "📁 Creating directories..."
mkdir -p /var/www/storage/logs
mkdir -p /var/www/storage/framework/sessions
mkdir -p /var/www/storage/framework/views
mkdir -p /var/www/storage/framework/cache
mkdir -p /var/www/bootstrap/cache

# Устанавливаем правильные права
echo "🔒 Setting permissions..."
# Используем www-data для совместимости с nginx
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
chmod -R 775 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

# Очищаем bootstrap cache принудительно
echo "🧹 Clearing bootstrap cache..."
rm -rf /var/www/bootstrap/cache/*

# Переключаемся на пользователя www-data для выполнения artisan команд
echo "🧹 Clearing Laravel cache..."
su-exec www-data php artisan config:clear 2>/dev/null || echo "⚠️  Config clear failed, continuing..."
su-exec www-data php artisan cache:clear 2>/dev/null || echo "⚠️  Cache clear failed, continuing..."
su-exec www-data php artisan route:clear 2>/dev/null || echo "⚠️  Route clear failed, continuing..."
su-exec www-data php artisan view:clear 2>/dev/null || echo "⚠️  View clear failed, continuing..."

# Создаем .env файл если его нет
if [ ! -f /var/www/.env ]; then
    echo "📝 Creating .env file..."
    su-exec www-data cp /var/www/.env.example /var/www/.env 2>/dev/null || echo "⚠️  No .env.example found"
fi

# Генерируем ключ приложения если его нет
echo "🔑 Checking application key..."
su-exec www-data php artisan key:generate --force 2>/dev/null || echo "⚠️  Key generation failed"

# Генерируем JWT_SECRET для валидации JWT токенов
echo "🔑 Setting JWT_SECRET..."
if [ -f "/run/secrets/jwt_secret" ]; then
    JWT_SECRET=$(cat /run/secrets/jwt_secret)
    echo "✅ JWT_SECRET loaded from secret"
else
    JWT_SECRET=$(openssl rand -base64 64 | tr -d '\n')
    echo "✅ JWT_SECRET generated"
fi
export JWT_SECRET

# Write JWT_SECRET to .env file so PHP-FPM can access it
if [ -f "/var/www/.env" ]; then
    # Remove existing JWT_SECRET line if present
    sed -i '/^JWT_SECRET=/d' /var/www/.env
    # Add new JWT_SECRET
    echo "JWT_SECRET=${JWT_SECRET}" >> /var/www/.env
    echo "✅ JWT_SECRET written to .env"
fi

echo "✅ Laravel initialization complete!"

# Запуск php-fpm от root пользователя чтобы избежать проблем с логированием
echo "🏃 Starting PHP-FPM..."
exec php-fpm
