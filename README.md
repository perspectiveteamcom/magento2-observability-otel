# Серверна observability для PHP 7.4-8.2

Ця гілка є Composer-метапакетом. Вона встановлює [Perspective_ObservabilityCompat](https://github.com/perspectiveteamcom/magento2-observability-compat), який залежить від базового модуля. Код Compat зберігається тільки у власному репозиторії.

Вимога `perspective/module-observability-otel:^1.0 || ^2.0` дозволяє Composer обрати 1.x на PHP 7.4-8.2 та нативний адаптер 2.x на PHP 8.3-8.5. Залежності Magento перевіряються разом із залежностями магазину. Інструкція додавання GitHub-репозиторіїв і встановлення є у [головному README](https://github.com/perspectiveteamcom/magento2-observability-otel/blob/main/README.md#встановлення).

Після встановлення 1.x увімкніть `Perspective_Observability` та `Perspective_ObservabilityCompat`. Модуль `Perspective_ObservabilityOtel` у цій лінійці не реєструється. Для розробки 1.x використовуйте `1.x-dev`, для розгортання використовуйте теги та збережений `composer.lock`.
