# Зміни

## 2.1.0

- Нормалізований SQL, операція й таблиця для PDO spans; сирий SQL і literal values не надсилаються в атрибутах.
- Redis command spans для Credis, phpredis/RedisCluster і Predis без ключів, значень та вкладених дублікатів драйвера.
- Event/observer/template spans типово вимкнені; їх можна ввімкнути окремо. Generated Proxy/Interceptor більше не дублюють detail spans.
- Попередній REST-патч збережено. При оновленні потрібно перебудувати patches.lock.json та перевстановити auto-magento2 у новій збірці, потім перевірити verify-patch.php.

## 2.0.1

Установлення через Git driver Composer, щоб читання тегів не залежало від ліміту GitHub API.

## 2.0.0

Перший окремий Composer-реліз модуля.
