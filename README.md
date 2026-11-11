# Особистий бюджет

Laravel-проєкт із Docker / Sail, Filament `/admin`, ролями, REST API автентифікації через Sanctum, підтвердженням email, відновленням пароля та документацією OpenAPI. Реалізовано спільні категорії витрат, керування ними в Filament, API власних доходів і витрат та місячні бюджети з порівнянням плану й факту.

## Вимоги

Docker Desktop (запущений), Docker Compose та Git. PHP, Composer, Node, npm і MySQL на локальному компьютері не потрібні. Усі команди цих інструментів виконуються через контейнери.

## Наявне середовище

Якщо `.env`, ключ і volumes уже існують, використовуйте їх. Не перезаписуйте `.env` і не генеруйте повторно ключ. Перед командами запустіть Docker Desktop.

```sh
./vendor/bin/sail up -d
./vendor/bin/sail artisan migrate --seed
```

Створити власного адміністратора:

```sh
./vendor/bin/sail artisan app:create-admin
```

Або задати ім’я та email параметрами; пароль завжди вводиться приховано й має містити щонайменше 12 символів, не більше 72 байтів UTF-8, без NUL:

```sh
./vendor/bin/sail artisan app:create-admin --name="Admin" --email="admin@example.com"
```

Команда створює новий обліковий запис і призначає роль `admin` у guard `web`. Наявний email відхиляється. Команда Filament `make:filament-user` сама ролі не призначає, тому використовуйте `app:create-admin`. Демонстраційних облікових записів або стандартного пароля адміністратора немає.

## Відтворення після клонування без vendor

Цей розділ потрібен лише для нового checkout. Наявний `.env` не перезаписуйте.

```sh
cp .env.example .env
chmod 600 .env
docker build -t personal-budget-composer -f docker/bootstrap.Dockerfile .
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -e GIT_CONFIG_COUNT=1 \
  -e GIT_CONFIG_KEY_0=safe.directory \
  -e GIT_CONFIG_VALUE_0=/app \
  -v "$PWD:/app" -w /app \
  personal-budget-composer composer install --no-interaction
```

Bootstrap-образ містить PHP 8.5, Composer та необхідні розширення; перевірку platform requirements не вимикаємо. Базовий образ зафіксовано digest. Перед першим запуском MySQL задайте власні `DB_PASSWORD` і `MYSQL_ROOT_PASSWORD` у `.env`. Значення в `.env.example` — лише локальні приклади. Залиште `DB_DATABASE=personal_budget`, тестова база має фіксовану назву `personal_budget_testing`.

```sh
./vendor/bin/sail build
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail restart worker
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm ci
./vendor/bin/sail npm run build
./vendor/bin/sail artisan app:create-admin
```

Sail використовує runtime `vendor/laravel/sail/runtimes/8.5`. Sail, MySQL і Mailpit перевірені як `linux/arm64`; примусового `platform: linux/amd64` немає.

## Щоденна робота

```sh
# Запуск і стан
./vendor/bin/sail up -d
./vendor/bin/sail ps

# Зупинка зі збереженням даних MySQL
./vendor/bin/sail down

# Міграції й ролі (seed можна виконувати повторно)
./vendor/bin/sail artisan migrate --seed

# Frontend з HMR; залиште процес працювати в окремому терміналі
./vendor/bin/sail npm run dev

# Статична збірка frontend
./vendor/bin/sail npm run build

# Воркер листів запускається разом із середовищем; окремий запуск і зупинка
./vendor/bin/sail up -d worker
./vendor/bin/sail stop worker

# Після змін коду, .env або APP_KEY перезапустіть довготривалий процес
./vendor/bin/sail restart worker

# Логи воркера
./vendor/bin/sail logs -f worker

# Логи
./vendor/bin/sail logs -f

# Інструменти виключно в контейнері
./vendor/bin/sail php -v
./vendor/bin/sail composer install
./vendor/bin/sail artisan about
```

`./vendor/bin/sail composer setup` виконує міграції з ролями, `npm ci` і build усередині контейнера. `./vendor/bin/sail composer dev` запускає Vite; сам застосунок уже обслуговується Sail на порту 80.

## Адреси

| Сервіс | Адреса з локального компьютера | Адреса в Docker-мережі |
| --- | --- | --- |
| Застосунок | http://localhost | `laravel.test:80` |
| Filament | http://localhost/admin | — |
| Перевірка стану | http://localhost/up | — |
| Mailpit UI | http://localhost:8025 | `mailpit:8025` |
| SMTP Mailpit | `127.0.0.1:1025` | `mailpit:1025` |
| MySQL | `127.0.0.1:3306` | `mysql:3306` |
| Redis | Не публікується на хост | `redis:6379` |
| Scramble UI | http://localhost/docs/api | — |
| OpenAPI JSON | http://localhost/docs/api.json | — |
| Vite (лише після `sail npm run dev`) | http://localhost:5173 | `laravel.test:5173` |

Порти опубліковані лише на `127.0.0.1`. Якщо порт зайнятий, змініть `APP_PORT`, `APP_URL`, `FORWARD_DB_PORT`, `FORWARD_MAILPIT_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT` або `VITE_PORT` у `.env` і повторіть `sail up -d`. `DB_HOST=mysql` та `DB_PORT=3306` усередині мережі залишаються такими самими.

Scramble за замовчуванням показує документацію лише в `APP_ENV=local`. OpenAPI описує 10 операцій автентифікації (включно з підтвердженням email), 9 операцій по будюжету, категоріям і транзакціям, параметри, відповіді, помилки та Bearer-авторизацію. Публічні endpoint’и позначені без вимоги токена. Для `/admin` залишається сесійна автентифікація через `web`; API приймає лише Bearer-токени, cookie адміністратора не авторизує API.

## Доступ до адміністративної панелі

Ролі `user` та `admin` створює `RoleSeeder`. `User::canAccessPanel()` дозволяє доступ лише ролі `admin` у guard `web`, зокрема в локальному середовищі. Гість перенаправляється на `/admin/login`; автентифікований `user` отримує 403; вхід через форму для нього також відхиляється. Реєстрацію явно вимкнено, `/admin/register` повертає 404.

## REST API

Базовий шлях — `/api/v1`. Передавайте `Accept: application/json` і для JSON-тіла `Content-Type: application/json`. Захищені маршрути приймають `Authorization: Bearer <token>`. Секретний токен повертається лише під час реєстрації або входу; збережіть його безпечно на клієнті. Паролі, хеші й `remember_token` у відповіді користувача відсутні.

| Метод і шлях | Поля JSON | Успіх | Bearer |
| --- | --- | --- | --- |
| `POST /auth/register` | `name`, `email`, `password`, `password_confirmation` | 201, користувач і токен | Ні |
| `POST /auth/login` | `email`, `password` | 200, користувач і токен | Ні |
| `POST /auth/logout` | — | 204 | Так |
| `GET /me` | — | 200, `data` з користувачем | Так |
| `POST /auth/forgot-password` | `email` | 202, нейтральне повідомлення | Ні |
| `POST /auth/reset-password` | `email`, `token`, `password`, `password_confirmation` | 204 | Ні |
| `PUT /auth/password` | `current_password`, `password`, `password_confirmation` | 204 | Так |
| `GET /auth/email/verify` | — | 200, `verified` та `message` | Так |
| `POST /auth/email/verify` | `email`, `code` (рядок із 6 цифр) | 200, `verified: true` та `message` | Ні |
| `POST /auth/email/verification-notification` | — | 202; 200, якщо email уже підтверджено | Так |

Email очищується від пробілів по краях та переводиться в нижній регістр перед валідацією, пошуком і збереженням. Новий пароль повинен містити щонайменше 12 символів, не більше 72 байтів UTF-8, не містити NUL-байта й збігатися з підтвердженням (обмеження додано через специфіку хешування bcript). Межа 72 байти перевіряється також під час входу API/Filament та перевірки current_password, щоб bcrypt не ігнорував суфікс. Реєстрація завжди призначає роль `user`; `role`, `roles`, `permissions`, `email_verified_at` та інші службові поля ігноруються.

Приклад реєстрації (замініть приклади паролів власними):

```sh
curl -i http://localhost/api/v1/auth/register \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"name":"Сергій","email":"sergiy@example.com","password":"example-long-password","password_confirmation":"example-long-password"}'
```

Реєстрація і вхід повертають таку структуру:

```json
{
  "user": {
    "id": 1,
    "name": "Сергій",
    "email": "sergiy@example.com",
    "email_verified_at": null,
    "created_at": "2026-11-09T12:00:00.000000Z"
  },
  "token": "<одноразово показаний API-токен>",
  "token_type": "Bearer",
  "expires_at": "2026-11-10T12:00:00.000000Z"
}
```

Вхід, запит користувача й вихід (у `TOKEN` підставте отриманий токен; не додавайте його до Git):

```sh
curl -i http://localhost/api/v1/auth/login \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"sergiy@example.com","password":"example-long-password"}'

read -rs 'TOKEN?API-токен: '
curl -i http://localhost/api/v1/me \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -i -X POST http://localhost/api/v1/auth/logout \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
unset TOKEN
```

Токен діє 24 години. Змінна `SANCTUM_TOKEN_LIFETIME_MINUTES=1440` у `.env` задає строк для нових токенів, щонайменше одну хвилину; уже видані зберігають власне `expires_at`. Sanctum перевіряє цей строк на кожному запиті. Logout відкликає лише токен із поточного заголовка; інші сеанси залишаються чинними.

### Підтвердження email

Інструкція для Git: [EMAIL_VERIFICATION.md](EMAIL_VERIFICATION.md).

Після реєстрації API повертає 201 із користувачем та Bearer-токеном, а `email_verified_at` залишається `null`. Лист містить email облікового запису й виділений шестизначний код. Майбутня форма має надсилати два поля: `email` і `code`. Саму форму поки не реалізовано.

```json
{"email":"user@example.com","code":"048219"}
```

`POST /api/v1/auth/email/verify` (`verification.verify`) доступний без Bearer-токена. Email нормалізується: пробіли по краях прибираються, регістр знижується. `code` — JSON-рядок із рівно шести ASCII-цифр; початкові нулі потрібно зберігати. Успіх — 200:

```json
{"verified":true,"message":"Email підтверджено."}
```

Після успіху код споживається, `email_verified_at` заповнюється, а подія `Verified` генерується після commit лише один раз. Паралельні запити не можуть використати код повторно. Невідомий email, неправильний, прострочений, використаний або заблокований код мають однакову відповідь 422:

```json
{
  "message":"Код недійсний або строк його дії минув. Запросіть новий",
  "errors":{"code":["Код недійсний або строк його дії минув. Запросіть новий"]}
}
```

Код діє **10 хвилин від моменту створення**, а не від надсилання листа воркером. Налаштування: `EMAIL_VERIFICATION_EXPIRE_MINUTES=10` через `auth.verification.expire`, мінімум — одна хвилина. Після п’яти неправильних спроб код заблокований до нового надсилання. Лічильник зберігається в MySQL навіть при відповіді 422. Додаткові обмеження endpoint’а: 5 запитів за хвилину на нормалізований email незалежно від IP; 30 за хвилину на IP незалежно від email. Перевищення — JSON 429 із `Retry-After`.

`GET /api/v1/auth/email/verify` (`verification.notice`) повертає `verified` та повідомлення. `POST /api/v1/auth/email/verification-notification` (`verification.send`) створює новий код, скасовує попередній і скидає лічильник спроб. Ці два endpoint’и вимагають Bearer-токена, але не підтвердженого email. Resend доступний раз на 60 секунд на користувача незалежно від токена та IP: успіх — 202; повторний ранній запит — 429 із `Retry-After`. Підтвердженому користувачу повертається 200 без нового коду чи листа.

До підтвердження доступні login, `/me`, logout, зміна пароля, статус verification і resend. Фінансові API категорій, транзакцій та бюджетів повертають JSON 403. Після підтвердження той самий чинний токен отримує доступ із наявними дозволами. Доступ адміністратора до Filament залишається за роллю `admin` у guard `web` незалежно від verification. Forgot-password і reset-password залишаються публічними й не змінюють `email_verified_at`.

Код генерується криптографічним генератором. У `users` зберігаються лише хеш, прив’язаний до нормалізованого email, строк дії та лічильник помилок. Службові поля приховані, не приймаються під час реєстрації та не повертаються API. Зміна й споживання коду виконуються в транзакціях із блокуванням рядка користувача.

`Registered`, `MustVerifyEmail` і штатний listener Laravel збережені. Власна `VerifyEmailCode` використовує зашифроване завдання `SendQueuedNotifications` у Redis-черзі `mail` з `afterCommit`. Rollback скасовує зміну коду та публікацію завдання. Воркер пропускає листи із заміненими, простроченими чи заблокованими кодами, зміненим email та вже підтвердженим обліковим записом. Код не повертається у відповіді API та не додається до логів.

Для переходу зі старої версії застосуйте міграцію та перезапустіть воркер:

```sh
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan config:clear
./vendor/bin/sail restart worker
```

Старий GET-маршрут із `{id}/{hash}` видалено: підписані посилання більше не діють. Старі verification-завдання залишаються сумісними з десеріалізацією, але пропускаються без надсилання; інші листи та черга не очищаються. Наявні користувачі з непідтвердженим email входять і запитують новий код. Міграція не змінює наявний статус підтвердження.

Ручна перевірка: зареєструйте користувача, збережіть токен із відповіді та відкрийте [Mailpit](http://localhost:8025). У листі «Підтвердження email — Особистий бюджет» скопіюйте код і виконайте запит без авторизації (приклади email і коду замініть власними):

```sh
curl -i -X POST http://localhost/api/v1/auth/email/verify \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","code":"048219"}'
```

До підтвердження запит категорій повертає 403; після успіху — 200, а `/me` містить дату підтвердження:

```sh
read -rs 'TOKEN?API-токен із реєстрації: '
curl -i http://localhost/api/v1/categories \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -i http://localhost/api/v1/me \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
unset TOKEN
```

Новий код до підтвердження можна запросити так:

```sh
read -rs 'TOKEN?API-токен: '
curl -i -X POST http://localhost/api/v1/auth/email/verification-notification \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
unset TOKEN
```

Не публікуйте код або токен у логах чи звітах. Автоматичні й наскрізні тестові сценарії виконуйте лише на `personal_budget_testing`.

### Відновлення та зміна пароля

Відновлення пароля:

```sh
curl -i http://localhost/api/v1/auth/forgot-password \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"sergiy@example.com"}'
```

Відповідь для будь-якого валідного email — 202 із повідомленням «Якщо акаунт існує, ми надішлемо лист із токеном для відновлення пароля». Тіло відповіді містить лише `message`; reset-токен надходить тільки на email.

Відкрийте [Mailpit](http://localhost:8025), скопіюйте токен із тексту листа та передайте його в API. Лист містить токен окремим рядком моноширинним шрифтом, строк його дії та назву endpoint’а. Скидання відбувається повністю через API; публічних сторінок і web-маршрутів скидання немає. Обидва endpoint’и forgot-password та reset-password доступні без Bearer-токена.

Скидання пароля (підставте скопійований токен із листа):

```sh
curl -i http://localhost/api/v1/auth/reset-password \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"email":"sergiy@example.com","token":"<токен із листа>","password":"new-example-password","password_confirmation":"new-example-password"}'
```

Успішний reset-password повертає HTTP 204 без тіла відповіді. Reset-токен генерує штатний Laravel password broker, у БД зберігається лише його хеш. Строк дії визначає `auth.passwords.<назва broker>.expire` у хвилинах (типово 60), а мінімальний інтервал між листами — `throttle` у секундах (типово 60) у `config/auth.php`. Broker вибирається через `auth.defaults.passwords`. Власна Notification отримує початковий токен через `User::sendPasswordResetNotification()`, показує його і строк дії з конфігурації; залежності від маршруту `password.reset` немає. Токен одноразовий. Раніший повторний запит листа також отримує нейтральну відповідь 202.

Листи відновлення надсилає сервіс `worker` через SMTP Mailpit. `ResetPasswordToken` реалізує `ShouldQueue` і `ShouldBeEncrypted`: Laravel створює штатне завдання `SendQueuedNotifications` у черзі `mail`, шифруючи його вміст ключем `APP_KEY`. Постановка відбувається після commit, якщо виклик здійснюється в DB-транзакції; при rollback завдання не публікується. HTTP-запит не очікує SMTP.

У `.env` та `.env.example`: `QUEUE_CONNECTION=redis`, `REDIS_HOST=redis`, `REDIS_PORT=6379`, `REDIS_QUEUE_RETRY_AFTER=90`. Redis зберігає дані в Docker volume з AOF. Воркер використовує той самий образ Sail, змонтований код і `.env` з тим самим `APP_KEY`, що й застосунок. Не змінюйте ключ, поки в черзі є зашифровані завдання. Команда контейнера: `php artisan queue:work redis --queue=mail --tries=3 --timeout=30 --backoff=10`. `retry_after=90` перевищує `timeout=30`; після трьох невдалих спроб завдання потрапляє до `failed_jobs`. Перегляд стану — `./vendor/bin/sail artisan queue:failed`; повторна спроба — `./vendor/bin/sail artisan queue:retry <uuid>`.

Перевірити доступність Redis із Sail:

```sh
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan tinker --execute="dump(Illuminate\Support\Facades\Redis::connection()->ping());"
```

Для ручної перевірки черги зупиніть `worker`, викличте forgot-password для свого облікового запису й перевірте `202`. Кількість завдань без виведення payload: `./vendor/bin/sail artisan tinker --execute="dump(Illuminate\Support\Facades\Queue::connection('redis')->size('mail'));"`. Запустіть `worker`, дочекайтеся листа в Mailpit і виконайте reset-password за прикладом вище. Токени й паролі передавайте лише у відповідному API-запиті; не виводьте в логи, звіти або Redis payload. Автоматичні перевірки й наскрізні тестові сценарії запускайте лише з БД `personal_budget_testing`.

Зміна пароля авторизованим користувачем:

```sh
read -rs 'TOKEN?API-токен: '
curl -i -X PUT http://localhost/api/v1/auth/password \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"current_password":"example-long-password","password":"new-example-password","password_confirmation":"new-example-password"}'
unset TOKEN
```

Після скидання або зміни пароля всі API-токени цього користувача відкликаються, `remember_token` оновлюється, попередні записи сесій видаляються. Наявний middleware Filament також перевіряє хеш пароля сесії. Потрібний повторний вхід; інші користувачі зберігають свої токени й сесії. Збереження пароля й відкликання токенів відбуваються в одній транзакції; блокування користувача також узгоджує вхід і споживання reset-токена.

Помилки: `401` — відсутній, неправильний, прострочений або відкликаний Bearer-токен чи невдалі облікові дані; `403` — email не підтверджено для фінансової транзакції, недостатньо дозволів; `422` — валідація, неправильний `current_password` або недійсний reset-токен чи verification-код; `429` — перевищення частоти із заголовком `Retry-After` у секундах. Невідомий email і неправильний пароль на вході мають однакові статус та тіло відповіді. Помилки API завжди JSON, навіть без `Accept`.

Обмеження за хвилину: реєстрація — 5 запитів з IP; вхід — 5 для нормалізованого email разом з IP і додатково 30 з IP для всіх email; відновлення — спільні 5 з IP для forgot/reset/change. Враховуються також невалідні спроби. Ліміти зберігаються в налаштованому cache store (локально — MySQL).

## Категорії та транзакції

Усі маршрути вимагають Bearer-токен Sanctum і підтверджений email. Категорії — спільний довідник; керування доступне через `/admin/categories`. Лише `admin` має `categories.manage`. Обидві ролі мають `categories.view` та `transactions.view/create/update/delete`. Policies перевіряють дозволи й власника: навіть адміністратор бачить і змінює лише власні фінансові транзакції. Вхідний `user_id` ігнорується, власника визначає зв’язок авторизованого користувача.

| Метод і шлях після `/api/v1` | Призначення | Успіх |
| --- | --- | --- |
| `GET /categories` | Активні категорії; `include_inactive=true` додає неактивні | 200 |
| `GET /transactions` | Власні транзакції з фільтрами й пагінацією | 200 |
| `POST /transactions` | Нова транзакція | 201 |
| `GET /transactions/{id}` | Власна транзакція | 200 |
| `PATCH /transactions/{id}` | Часткове редагування власної транзакції | 200 |
| `DELETE /transactions/{id}` | Видалення власної транзакції | 204 |

Валюта — лише `UAH`. `amount_minor` — **ціле JSON-число від 1 до 999999999999 копійок включно** (максимум 9 999 999 999,99 грн). Наприклад, 12345 означає 123,45 грн. У БД це `BIGINT UNSIGNED`, у PHP — integer. Межа нижча за PHP_INT_MAX і максимальне безпечне ціле JavaScript. API відхиляє дроби, JSON-числа з дробовою частиною, boolean і рядки сум. Не множте float на 100: якщо клієнт отримує десятковий текст, розділіть гривні й копійки як рядки, перевірте не більше двох десяткових знаків і складіть цілу кількість копійок. API приймає вже готове ціле значення.

`type` — `income` або `expense`. Для витрати обов’язкова активна `category_id`; для доходу категорія відсутня (`null`). `occurred_on` — календарна дата `YYYY-MM-DD` без часу й часової зони; поточна валідація операцій дозволяє дати від 2026-01-01. `description` — необов’язковий текст до 2000 символів або `null`.

```sh
read -rs 'TOKEN?API-токен: '

./vendor/bin/sail exec -T laravel.test curl -s http://localhost/api/v1/categories \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Replace category_id with an active category id from the catalogue.
./vendor/bin/sail exec -T laravel.test curl -i http://localhost/api/v1/transactions \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"type":"expense","category_id":1,"amount_minor":12345,"occurred_on":"2026-11-10","description":"Продукти"}'

./vendor/bin/sail exec -T laravel.test curl -i http://localhost/api/v1/transactions \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"type":"income","amount_minor":1000000,"occurred_on":"2026-11-10"}'

./vendor/bin/sail exec -T laravel.test curl -s \
  'http://localhost/api/v1/transactions?date_from=2026-11-01&date_to=2026-11-30&type=expense&category_id=1&per_page=20&page=1' \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# Replace 1 below with an id of your own transaction.
./vendor/bin/sail exec -T laravel.test curl -s http://localhost/api/v1/transactions/1 \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

./vendor/bin/sail exec -T laravel.test curl -i -X PATCH http://localhost/api/v1/transactions/1 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -d '{"amount_minor":15000,"description":null}'

./vendor/bin/sail exec -T laravel.test curl -i -X DELETE http://localhost/api/v1/transactions/1 \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
unset TOKEN
```

Відповідь однієї транзакції:

```json
{
  "data": {
    "id": 1,
    "type": "expense",
    "amount_minor": 12345,
    "currency": "UAH",
    "occurred_on": "2026-11-10",
    "description": "Продукти",
    "category": {"id": 1, "name": "Продукти", "is_active": true},
    "created_at": "2026-11-10T12:00:00.000000Z",
    "updated_at": "2026-11-10T12:00:00.000000Z"
  }
}
```

Список містить `data`, `links`, `meta`. Дати фільтрів включні, `date_to` не може бути раніше за `date_from`. `type` і `category_id` комбінуються з датами. Типово 20 записів, `per_page` — 1–100; порядок `occurred_on DESC, id DESC`. Категорії завантажуються одним запитом на сторінку. `include_inactive` приймає `true`, `false`, `1`, `0`; неправильне значення дає 422.

PATCH перевіряє кінцевий стан з урахуванням збережених полів. Перехід `income → expense` вимагає активної категорії. Перехід `expense → income` без `category_id` або з `null` очищає попередню категорію; явно передана ненульова категорія для доходу дає 422. Неактивну категорію старої витрати можна зберігати під час редагування інших полів або повторно передати її id, але призначати її іншій транзакції не можна.

Filament підтримує пошук, фільтр активності, створення, редагування, деактивацію та повторну активацію категорій. Видалення дозволено лише для невикористаних категорій; для використаних показано пояснення й доступна деактивація. Policy захищає також прямі виклики Actions, зовнішній ключ БД додатково забороняє видалення використаної категорії. Деактивація зберігає зв’язок і назву в історії операцій.

Помилки: 401 — немає чинного Bearer-токена; 403 — email не підтверджено або немає потрібного дозволу; 404 — чужий або відсутній запис; 422 — невалідна сума, дата, категорія, фільтри або перехід типу.

`CategorySeeder` додає Продукти, Транспорт, Житло, Здоров’я, Дозвілля, Інше. Повторне `sail artisan db:seed` не дублює категорії, не активує деактивовані й не скидає наявні ролі або додаткові дозволи. Демонстраційні транзакції не створюються; для тестів є factories.

## Місячні бюджети

Усі три маршрути доступні лише через REST API з Bearer-токеном Sanctum і підтвердженим email. `user` та `admin` мають `budgets.view` і `budgets.manage`; кожен працює тільки зі своїми бюджетами. Місяць у URL — строго `YYYY-MM`, роки 2026–9999; неправильне значення повертає 422. У БД зберігається перший день місяця, унікальний разом із `user_id`.

| Метод і шлях після `/api/v1` | Призначення | Успіх |
| --- | --- | --- |
| `GET /budgets/{month}` | Власний план із категоріями, UAH і загальною сумою | 200; 404, якщо бюджету немає |
| `PUT /budgets/{month}` | Створення або повна заміна плану | 201 при створенні, 200 при заміні |
| `GET /budgets/{month}/summary` | План, фактичні витрати, доходи й сальдо | 200, також без бюджету |

PUT приймає `{"items":[{"category_id":1,"amount_minor":150000}]}`. `items` обов’язковий і має бути JSON-списком; ідентифікатори категорій — без повторів. Суми — цілі JSON-числа **0–999999999999 копійок включно**, без рядків, boolean або дробів. Валюта — `UAH`. `user_id` визначає поточний користувач, `month` — URL; однойменні поля тіла їх не змінюють.

Кожен PUT **повністю замінює** категорії плану. Пропущені категорії прибираються, `{"items":[]}` зберігає порожній бюджет, повторення запиту не створює дублікатів. Перевищення плану дозволене. Збереження бюджету та рядків виконується в одній транзакції; блокування рядка користувача працює також до першого створення бюджету. Унікальні індекси MySQL додатково захищають місяць і категорії. **Останнє успішне збереження замінює попередній план**, конкурентні PUT не змішують рядки. Порядок надходження запитів або отримання відповідей клієнтом не визначає порядок збереження.

До плану можна додавати активні категорії. Уже включену неактивну категорію можна зберігати, змінювати її суму або прибрати; після прибирання додати її знову можна лише після активації. В інший бюджет така категорія не додається. GET і summary повертають неактивні історичні категорії разом з `is_active`. Категорію, що використовується хоча б в транзакції або бюджеті, фізично видалити не можна; деактивація доступна.

Summary повертає `data.items` — об’єднання категорій плану й фактичних витрат, впорядковане за id категорії. Кожен рядок містить `category` (`id`, `name`, `is_active`), `is_planned`, `planned_minor`, `spent_minor`, `remaining_minor`, `overspent_minor`, `used_percent`:

- `remaining_minor = planned_minor − spent_minor`;
- `overspent_minor = max(spent_minor − planned_minor, 0)`;
- `used_percent = round(spent_minor / planned_minor × 100, 2)`, а за нульового плану — `null`;
- без плану для категорії: `is_planned=false`, `planned_minor=0`; нульовий запланований рядок має `is_planned=true`;
- від’ємний залишок і використання понад 100% допустимі; категорії плану без витрат також включаються.

Загальні поля: `month`, `has_budget`, `currency`, `planned_total_minor`, `spent_total_minor`, `remaining_total_minor`, `overspent_total_minor`, `unplanned_spent_minor`, `income_total_minor`, `net_total_minor`. Залишок — загальний план мінус усі витрати; загальна перевитрата — `max(spent_total_minor − planned_total_minor, 0)`, **а не сума перевитрат категорій**. `unplanned_spent_minor` включає тільки витрати категорій, яких немає в плані. Доходи не зменшують витрати: вони показуються окремо, `net_total_minor = income_total_minor − spent_total_minor`.

За відсутності бюджету summary повертає 200, `has_budget=false`, нульові планові суми та всі фактичні транзакції за тими самими формулами. Порожній збережений бюджет має `has_budget=true`. Факт обчислюється SQL-агрегацією за `occurred_on`, за індексованим діапазоном від першого дня місяця включно до першого дня наступного невключно; PHP отримує лише агрегати, а грошові обчислення використовують цілі копійки. Для останнього місяця MySQL DATE, 9999-12, використовується еквівалентна верхня межа `9999-12-31 23:59:59`. Редагування, перенесення, зміна типу або видалення транзакції одразу впливають на наступний summary; кешування факту немає.

```sh
read -rs 'TOKEN?API-токен: '

# Replace category_id with an active category id.
./vendor/bin/sail exec -T laravel.test curl -i -X PUT http://localhost/api/v1/budgets/2026-11 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"items":[{"category_id":1,"amount_minor":150000}]}'

./vendor/bin/sail exec -T laravel.test curl -s http://localhost/api/v1/budgets/2026-11 \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

./vendor/bin/sail exec -T laravel.test curl -s http://localhost/api/v1/budgets/2026-11/summary \
  -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
unset TOKEN
```

## Бази та тести

Pest використовує PHPUnit як транзитивну залежність. Файл `phpunit.xml` залишається конфігурацією запуску Pest.

- Основна база: `personal_budget`.
- Окрема тестова база: `personal_budget_testing`, той самий MySQL-сервіс і локальний користувач із `.env`.
- Init-скрипт `docker/mysql/10-create-testing-database.sh` створює тестову базу й надає права під час першої ініціалізації MySQL volume.
- `phpunit.xml` примусово задає MySQL і назву тестової бази. `Tests\TestCase` додатково перевіряє конфігурацію **перед** запуском міграцій тестів і зупиняє запуск на іншій базі.
- Feature-тести використовують `RefreshDatabase`; дані тестової бази можуть бути очищені. Основну базу тести не змінюють.

```sh
docker compose config --quiet
./vendor/bin/sail artisan migrate:status
./vendor/bin/sail test
./vendor/bin/sail pint --test
./vendor/bin/sail composer validate --strict
./vendor/bin/sail composer check-platform-reqs
./vendor/bin/sail composer audit
```

Форматування з виправленням:

```sh
./vendor/bin/sail pint
```

Якщо MySQL volume уже існував без тестової бази, створіть її init-скриптом (наявні бази він не видаляє):

```sh
docker compose exec mysql bash /docker-entrypoint-initdb.d/10-create-testing-database.sh
```

`down` зберігає MySQL volume. Не додавайте `-v`, якщо дані потрібні. Зміна пароля в `.env` після ініціалізації volume сама пароль у MySQL не змінює.

## Git та секрети

Локальний Git-репозиторій ініціалізований, гілка `main`. Комітів автоматично не створено. `.gitignore` виключає `.env`, інші локальні env-файли, ключі, `vendor`, `node_modules`, кеші й згенеровані assets. `composer.lock`, `package-lock.json` та `.env.example` призначені для Git. Assets Filament відновлюються Composer-скриптом `filament:upgrade`.

## Версії пакетів

| Компонент | Встановлено |
| --- | --- |
| Laravel | 13.40.0 |
| PHP | 8.5.12 |
| MySQL LTS | 8.4.13 |
| Filament | 5.11.2 |
| Laravel Sail | 1.69.0 |
| Laravel Sanctum | 4.3.3 |
| Spatie Laravel Permission | 8.5.0 |
| Scramble | 0.13.53 |
| Pest / Laravel plugin | 4.7.8 / 4.1.0 |
| Laravel Pint | 1.32.1 |
| Mailpit | 1.33.1 |
| Composer | 2.11.1 |
| Node / npm (Sail) | 24.22.2 / 12.2.2 |

Пакети встановлені зі стабільних релізів, PHP-залежності та npm-залежності зафіксовані lock-файлами.

Офіційна документація: [Laravel Sail](https://github.com/laravel/docs/blob/13.x/sail.md), [Filament 5](https://filamentphp.com/docs/5.x/introduction/installation), [сумісність Spatie](https://spatie.be/docs/laravel-permission/v8/prerequisites), [Scramble](https://scramble.dedoc.co/installation).
