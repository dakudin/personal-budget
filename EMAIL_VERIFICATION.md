# Підтвердження email одноразовим кодом

Базовий шлях REST API — `/api/v1`. Усі PHP, Composer, Artisan, Node та npm-команди виконуються лише через Docker / Sail.

Після реєстрації API повертає 201 із користувачем та Bearer-токеном, а `email_verified_at` залишається `null`. Лист містить email облікового запису й виділений шестизначний код. Майбутня форма має надсилати два поля: `email` і `code`.

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

## Автоматична перевірка

Тести працюють лише на MySQL-базі `personal_budget_testing`. Черга перевіряється через тестову таблицю `jobs`, а доставка — транспортом `array`; робочі Redis-завдання не споживаються. Конкурентне споживання перевіряється двома процесами з незалежними MySQL-з’єднаннями до тестової бази.

```sh
docker compose config --quiet
./vendor/bin/sail test
./vendor/bin/sail pint --test
./vendor/bin/sail artisan route:list --name=verification -v
```
