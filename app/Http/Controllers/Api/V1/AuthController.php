<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\ChangePassword;
use App\Actions\Auth\LoginUser;
use App\Actions\Auth\RegisterUser;
use App\Actions\Auth\ResetPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\EmailRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\AuthTokenResource;
use App\Http\Resources\UserResource;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Password;

#[Group('Автентифікація')]
class AuthController extends Controller
{
    /** @unauthenticated */
    #[Endpoint(title: 'Реєстрація', description: 'Створює користувача з роллю user і видає Bearer-токен. Email нормалізується; службові поля ігноруються. Пароль має містити щонайменше 12 символів, password_confirmation має збігатися з password.')]
    #[ApiResponse(201, type: AuthTokenResource::class)]
    #[ApiResponse(429, description: 'Перевищено 5 запитів за хвилину з IP. Заголовок Retry-After містить час очікування в секундах.', type: 'array{message: string}')]
    public function register(RegisterRequest $request, RegisterUser $action): JsonResponse
    {
        return (new AuthTokenResource($action->handle($request->validated())))
            ->response()->setStatusCode(201)->header('Cache-Control', 'no-store');
    }

    /** @unauthenticated */
    #[Endpoint(title: 'Вхід', description: 'Видає токен на 24 години за замовчуванням. Невідомий email і неправильний пароль мають однакову відповідь 401.')]
    #[ApiResponse(401, description: 'Невірний email або пароль.', type: 'array{message: string}')]
    #[ApiResponse(429, description: '5 запитів за хвилину для email та IP; 30 за хвилину для IP. Retry-After — секунди до наступної спроби.', type: 'array{message: string}')]
    public function login(LoginRequest $request, LoginUser $action): AuthTokenResource
    {
        return new AuthTokenResource($action->handle($request->validated('email'), $request->validated('password')));
    }

    #[Endpoint(title: 'Вихід', description: 'Відкликає лише поточний Bearer-токен.')]
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    #[Endpoint(title: 'Поточний користувач', description: 'Потрібен дійсний Bearer-токен Sanctum; cookie сесії не авторизує API.')]
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** @unauthenticated */
    #[Endpoint(title: 'Запит відновлення пароля', description: 'Доступний без Bearer-токена. Для валідного email повертає нейтральну відповідь 202 незалежно від наявності акаунта. Reset-токен надходить лише в тексті листа разом зі строком дії з конфігурації password broker; відповідь API його не містить. Лист надсилається через SMTP; локально — Mailpit. Повторний лист: не частіше разу на 60 секунд за замовчуванням.')]
    #[ApiResponse(429, description: 'Спільний ліміт відновлення: 5 запитів за хвилину з IP. Retry-After — секунди очікування.', type: 'array{message: string}')]
    public function forgotPassword(EmailRequest $request): JsonResponse
    {
        Password::sendResetLink($request->validated());

        return response()->json(['message' => 'Якщо акаунт існує, ми надішлемо лист із токеном для відновлення пароля.'], 202);
    }

    /** @unauthenticated */
    #[Endpoint(title: 'Скидання пароля', description: 'Доступний без Bearer-токена. Передайте email і скопійований із тексту листа token; строк його дії задає password broker (60 хвилин за замовчуванням). Новий password: щонайменше 12 символів; password_confirmation має збігатися з password. Успіх — 204 без тіла відповіді. Токен одноразовий; усі API-токени та попередні сесії користувача відкликаються.')]
    #[ApiResponse(422, description: 'Помилки валідації або недійсний, прострочений чи вже використаний токен.', type: 'array{message: string, errors: array<string, string[]>}')]
    #[ApiResponse(429, description: 'Спільний ліміт відновлення: 5 запитів за хвилину з IP. Retry-After — секунди очікування.', type: 'array{message: string}')]
    public function resetPassword(ResetPasswordRequest $request, ResetPassword $action): Response
    {
        $action->handle($request->validated());

        return response()->noContent();
    }

    #[Endpoint(title: 'Зміна пароля', description: 'Вимагає правильного current_password і нового пароля щонайменше з 12 символів із підтвердженням. Відкликає всі API-токени, включно з поточним, та попередні сесії. Потрібно ввійти повторно.')]
    #[ApiResponse(422, description: 'Неправильний поточний пароль або помилки валідації.', type: 'array{message: string, errors: array<string, string[]>}')]
    #[ApiResponse(429, description: 'Спільний ліміт відновлення: 5 запитів за хвилину з IP. Retry-After — секунди очікування.', type: 'array{message: string}')]
    public function changePassword(ChangePasswordRequest $request, ChangePassword $action): Response
    {
        $action->handle($request->user(), $request->validated('current_password'), $request->validated('password'));

        return response()->noContent();
    }
}
