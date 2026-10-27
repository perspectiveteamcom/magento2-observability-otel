# Perspective_ObservabilityOtel

Серверні траси, метрики HTTP/cron/AMQP та копії Monolog для Magento 2. У лінійці 2.x модуль використовує нативне розширення OpenTelemetry й автоматичну інструментацію Magento, PDO та cURL. Базовий модуль установлюється як залежність і надає спільний профайлер Excimer.

## Версії та автоматичний вибір

| Пакет | Призначення | PHP |
| --- | --- | --- |
| `perspective/module-observability` 1.x | RUM і спільний CPU-профайлер | 7.4-8.5 |
| `perspective/module-observability-compat` 1.x | Траси через Magento plugins, без ext-opentelemetry | 7.4-8.5 |
| `perspective/module-observability-otel` 1.x | Метапакет, який підключає Compat для старого PHP | 7.4-8.2 |
| `perspective/module-observability-otel` 2.x | Нативні OTel hooks, автоматична інструментація Magento/PDO/cURL | 8.3-8.5 |

Усі модулі вимагають Magento 2.4 (`magento/framework` 103.x). Допустиму пару Magento/PHP додатково обмежують залежності самого магазину. Підтримка PHP 8.5 у модулі не робить старий Magento сумісним із PHP 8.5. Compat і нативний Otel одночасно не встановлюються: це перевіряє Composer через `conflict`.

Версія 1.x цього пакета має тип `metapackage`: вона містить тільки залежність від Compat, без другої копії його коду. Для PHP 7.4-8.2 Composer обирає її, для PHP 8.3-8.5 обирає 2.x. Вимога `^1.0 || ^2.0` дозволяє цей вибір. На новому PHP без потрібного розширення встановлення 2.x завершується помилкою, а не вимикає трасування непомітно.

## Встановлення

Команди виконуйте в корені Magento під тим самим PHP, що й застосунок. У Warden запускайте їх через `warden env exec -T php-fpm`; у Docker/Kubernetes використовуйте PHP-контейнер збірки. Не змінюйте файли робочого `vendor` вручну. Для команди зі змінною оточення синтаксис Warden такий: `warden env exec -T php-fpm env OTEL_PHP_AUTOLOAD_ENABLED=false composer require ...`.

Composer читає VCS-репозиторії лише з кореневого `composer.json`. Додайте їх командами нижче; вони зберігають решту репозиторіїв магазину. Тип `git` читає теги напряму через Git без запитів до GitHub API за метаданими цих пакетів.

```bash
composer config repositories.perspective-observability git https://github.com/perspectiveteamcom/magento2-observability.git
composer config repositories.perspective-observability-otel git https://github.com/perspectiveteamcom/magento2-observability-otel.git
composer config repositories.perspective-observability-compat git https://github.com/perspectiveteamcom/magento2-observability-compat.git
```
На PHP 8.3-8.5 спочатку встановіть `ext-opentelemetry` 1.4+ для FPM, CLI та consumers. `protobuf` прискорює серіалізацію; Excimer потрібен для профілів. CLI та FPM можуть читати різні `php.ini`: перевірте `php --ini` та конфігурацію свого FPM-бінарника. Composer перевіряє наявність розширень, але не встановлює їх.

Дозвольте Composer Patches застосувати REST-виправлення, потім установіть пакет:

```bash
composer config allow-plugins.cweagans/composer-patches true
OTEL_PHP_AUTOLOAD_ENABLED=false composer require 'perspective/module-observability-otel:^1.0 || ^2.0' --with-dependencies
composer check-platform-reqs
composer show 'perspective/*'
```

На PHP 7.4-8.2 результат містить `module-observability-otel` 1.x, `module-observability-compat` і базовий модуль. Увімкніть:

```bash
php bin/magento module:enable Perspective_Observability Perspective_ObservabilityCompat
php bin/magento setup:upgrade
```

На PHP 8.3-8.5 результат містить `module-observability-otel` 2.x і базовий модуль:

```bash
php bin/magento module:enable Perspective_Observability Perspective_ObservabilityOtel
php bin/magento setup:upgrade
```

Збережіть `composer.json` і `composer.lock` у репозиторії магазину. Наступні збірки виконують `composer install` із lock-файла. `composer check-platform-reqs` перевіряє фактичний PHP та розширення; `config.platform.php` впливає на вибір залежностей, але не встановлює PHP. Не використовуйте `--ignore-platform-reqs` для розгортання.

Пакети встановлюються в `vendor/perspective/`. Перед переходом із `app/code` приберіть старі каталоги `Perspective/Observability*` у новій збірці, зберігши проєктні правки окремо. Дві копії з однаковою Magento-реєстрацією призведуть до помилки. Файли робочого релізу замінюйте штатним deployment.

Підключіть `<agent_root>/php.env` до FPM, cron та consumers за [покроковою інструкцією](docs/php-environment.md). Вона також постачається з агентами. Виконайте звичайний deployment Magento з DI, статичними файлами, кешами та перезапуском PHP-процесів.

### REST-патч у 2.x

Пакет вимагає стабільний `open-telemetry/opentelemetry-auto-magento2` 0.1.0. Його REST-виправлення оголошено в `composer.json` цього пакета: Composer Patches завантажує файл із зафіксованого тега, перевіряє SHA-256 та застосовує його. Вручну копіювати патч або додавати його до кореневого `extra.patches` не потрібно. У 1.x патча й залежності Composer Patches немає.

Для проєкту, де вже є `patches.lock.json`, після додавання пакета виконайте у збірці:

```bash
OTEL_PHP_AUTOLOAD_ENABLED=false composer patches-relock
OTEL_PHP_AUTOLOAD_ENABLED=false composer patches-repatch
```

Ці команди оновлюють перелік патчів і перевстановлюють пакети з патчами. Збережіть `patches.lock.json` разом із `composer.lock`. Не вимикайте dependency patches для цього пакета. Якщо проєкт використовує Composer Patches 1.x, перехід на 2.x потребує перевірки всіх його патчів.

Перевірте застосовані файли командою з кореня Magento:

```bash
php vendor/perspective/module-observability-otel/verify-patch.php
```

Вона має завершитися повідомленням `PASS Magento REST instrumentation patch`. Перед робочим запуском перевірте REST JSON і статуси 200/401/404/500, зокрема відповіді `Magento\Framework\Webapi\Rest\Response\Proxy`.

## Налаштування

| Змінна | Призначення |
| --- | --- |
| `OTEL_PHP_AUTOLOAD_ENABLED=true` | Вмикає SDK і запуск інструментації; також потрібен увімкнений Magento-модуль |
| `OTEL_SERVICE_NAME`, `OTEL_RESOURCE_ATTRIBUTES` | Сервіс, проєкт, середовище та унікальний `service.instance.id` |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | Приватний локальний Collector |
| `OTEL_TRACES_SAMPLER_ARG=0.1` | Частка нових трас; `parentbased_traceidratio` зберігає рішення батьківської траси |
| `OTEL_LOGS_EXPORTER=otlp` | Експорт Monolog; `none` вимикає цей канал. Читання файлів налаштовується в Collector |
| `OBSERVABILITY_PROFILING_ENABLED=false` | Увімкніть лише з Excimer і локальним шлюзом профілів |
| `OBSERVABILITY_PROFILING_RATIO=0.1` | Частка вебзапитів для профілів; CLI профілюється весь час роботи |
| `OBSERVABILITY_PROFILING_ENDPOINT` | Адреса локального шлюзу профілів із `php.env` |
| `OTEL_SDK_DISABLED=true` | Вимикає SDK; перезапустіть PHP-процеси після зміни |

Окрема група cron `observability` записує час останнього запуску й кількість станів із `cron_schedule` за добу. Якщо планувальник проєкту запускає лише перелічені групи, додайте `bin/magento cron:run --group=observability` до його розкладу. Не додавайте другий запуск, якщо штатний cron уже обробляє цю групу.

## Що спостерігає модуль

| Точка Magento | Поведінка |
| --- | --- |
| `Http::launch`, `FrontController::dispatch`, `ResponseInterface::sendResponse` | Вимірює тривалість, маршрут і остаточний HTTP-статус; повертає застосунку його початкову відповідь |
| `ProcessCronQueueObserver::_runJob` | Створює span завдання, враховує успіх або виняток |
| `Amqp\Exchange::enqueue` | Спостерігає публікацію; зберігає початковий envelope, тіло та заголовки |
| `MessageQueue\Consumer::dispatchMessage` | Спостерігає обробку; читає trace context, якщо він уже є у вхідних заголовках |
| `Monolog\Logger::addRecord` | Експортує очищену копію тексту; залишає початкові аргументи та обробники журналу незмінними |
| `Cron\Heartbeat` | Виконує `SELECT` агрегованих станів `cron_schedule` і записує метрики |

AMQP producer і consumer можуть мати окремі траси: модуль не додає trace context до повідомлення. Зв'язок між ними з'являється лише за наявності контексту від іншої інструментації. Власних таблиць, змін бізнес-даних, заміни результатів чи винятків у модулі немає. Сам heartbeat планується стандартним Magento cron і має звичайні записи розкладу. Стороння інструментація cURL може додавати стандартні заголовки trace context до вихідних HTTP-запитів.

## Точки розширення

Власні бізнес-операції інструментуйте у проєктному модулі через OpenTelemetry API. Умовою встановлення такого модуля є наявність SDK. Приклад тіла `around`-плагіна для методу без аргументів: збір не має переривати виклик `$proceed()`, а помилки завершення не повинні замінювати його виняток.

```php
$span = $scope = null;
try {
    $span = \OpenTelemetry\API\Globals::tracerProvider()
        ->getTracer('vendor.project')->spanBuilder('erp.sync')->startSpan();
    $scope = $span->activate();
} catch (\Throwable $telemetryError) {
    // Збір необов'язковий для виконання операції.
}
try {
    return $proceed();
} finally {
    try {
        if ($scope !== null) { $scope->detach(); }
    } catch (\Throwable $telemetryError) {}
    try {
        if ($span !== null) { $span->end(); }
    } catch (\Throwable $telemetryError) {}
}
```

Назви операцій і міток мають бути обмеженим набором. Не записуйте email, номер замовлення, SKU, повний URL або вміст повідомлень у мітки метрик. Нестандартні обробники черг можуть обходити `Consumer::dispatchMessage`; їх інструментуйте у проєктному модулі.

`Hook.php` перехоплює винятки власних обробників і відкидає їхні результати, які OpenTelemetry інакше міг би використати для заміни аргументів або відповіді. Захист поширюється також на помилки журналювання. Профайлер окремо захищає callback таймера, запуск і завершення процесу. `LocalTransport*` обмежує спробу відправлення до 250 мс без повторів і на 5 секунд призупиняє відправлення після синхронної або асинхронної помилки. Повторну доставку прийнятих даних виконує Collector. Зміни цих класів перевіряйте разом із HTTP, ізоляцією винятків і транспортом; не копіюйте в них бізнес-логіку магазину.

## Межі та вимкнення

Захист власних обробників не гарантує безпомилковості сторонніх бібліотек або PHP-розширення. Перевіряйте HTTP 200/401/404/500, JSON, cron, черги й недоступний Collector. Один запит може створювати тисячі вкладених операцій; виміряйте навантаження свого магазину.

Модуль маскує частину секретів у тексті Monolog, але довільний журнал може містити інші персональні дані. Файловий збір і Monolog можуть дублювати повідомлення. Оберіть потрібні джерела.

Для повного аварійного вимкнення задайте `OTEL_SDK_DISABLED=true`, `OTEL_PHP_AUTOLOAD_ENABLED=false`, `OBSERVABILITY_PROFILING_ENABLED=false` та за потреби `OTEL_PHP_DISABLED_INSTRUMENTATIONS=magento2,pdo,curl`. Перезапустіть усі PHP-процеси. `module:disable Perspective_ObservabilityOtel` вимикає власну частину; сторонні бібліотеки реєструють свої обробники незалежно від Magento-модуля.

Для видалення вимкніть модуль, приберіть лише його окремий запуск cron, видаліть каталог у наступній збірці. Видаляйте Composer-залежності й патч лише після перевірки, що інші модулі їх не використовують.
