# Тести модуля ReviewRequests

Тести модульні: сайт не піднімають, у базу не ходять, листів не надсилають.
Вони перевіряють правила черги, захист від потоку листів, заголовки відписки,
кабінет і позначку «Перевірена покупка», підставляючи заглушки замість сервісів
ядра.

## 1. Візьміть тести з репозиторію

В архів релізу тека `tests/` **не входить**. Взяти її можна з репозиторію:

```bash
git clone https://github.com/devSviat/ReviewRequests-OkayCMS.git
```

## 2. Покладіть їх у форк OkayCMS

```
{OkayCMS_root}/
├── Okay/Modules/Sviat/ReviewRequests/     ← модуль
└── tests/Modules/Sviat/ReviewRequests/     ← його тести
```

```bash
cd {OkayCMS_root}
mkdir -p tests/Modules/Sviat/ReviewRequests
cp -r ~/ReviewRequests-OkayCMS/tests/. tests/Modules/Sviat/ReviewRequests/
```

PHPUnit бере тільки те, що вказано в `phpunit.xml` рушія, а класи ядра, без яких
тести не працюють, приходять із його автозавантажувача.

## 3. Запустіть

```bash
cd {OkayCMS_root}
vendor/bin/phpunit tests/Modules/Sviat/ReviewRequests
```

Очікуваний результат — рядок `OK (N tests, M assertions)`.

**Немає `vendor/bin/phpunit`?** Залежності ставили без dev-пакетів:
`composer install`.

## Що перевіряє CI

На кожен PR і мердж у `main`:

1. `php -l` на всьому коді модуля під PHP 8.4 і 8.5;
2. модуль ставиться у [форк OkayCMS](https://github.com/devSviat/OkayCMS) і там
   запускається `phpunit`;
3. після мерджу, якщо версія в `Init/module.json` ще не має тега, ставиться тег
   і збирається архів релізу.
