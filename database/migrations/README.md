# Здесь не должно быть миграций

Схема базы данных проекта — **только в leget-db** (`ms/leget-db/database/migrations/`).

Каталог оставлен пустым намеренно: `php artisan make:migration` создаёт файл здесь, и без
напоминания копия схемы легко появляется снова. Ранее тут лежали дубли `users`, `cache`
и `jobs` — они не применялись, но вводили в заблуждение.

```bash
cd ms/leget-db
php artisan make:migration add_something_to_users_table
php artisan migrate
```
