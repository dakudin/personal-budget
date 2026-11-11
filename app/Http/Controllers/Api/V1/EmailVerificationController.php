<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Auth\SendEmailVerificationCode;
use App\Actions\Auth\VerifyEmailCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\VerifyEmailCodeRequest;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Автентифікація')]
class EmailVerificationController extends Controller
{
    #[Endpoint(title: 'Статус підтвердження email', description: 'Доступний із Bearer-токеном незалежно від статусу підтвердження email. Повертає статус та інструкцію для непідтвердженого користувача.')]
    #[ApiResponse(200, type: 'array{verified: bool, message: string}')]
    public function notice(Request $request): JsonResponse
    {
        $verified = $request->user()->hasVerifiedEmail();

        return response()->json([
            'verified' => $verified,
            'message' => $verified
                ? 'Email уже підтверджено.'
                : 'Введіть код із листа у формі підтвердження email. За потреби запросіть новий код.',
        ]);
    }

    /** @unauthenticated */
    #[Endpoint(title: 'Підтвердження email', description: 'Публічний endpoint без Bearer-токена. Передайте email і code — рядок із шести ASCII-цифр, включно з початковими нулями. Email нормалізується. Код одноразовий, діє 10 хвилин від запиту за замовчуванням (auth.verification.expire). Після п’яти неправильних спроб потрібен новий код; повторне надсилання скасовує попередній.')]
    #[ApiResponse(200, type: 'array{verified: bool, message: string}')]
    #[ApiResponse(422, description: 'Помилки формату або однакова помилка code для невідомого email, неправильного, простроченого, використаного чи заблокованого коду.', type: 'array{message: string, errors: array<string, string[]>}')]
    #[ApiResponse(429, description: '5 запитів за хвилину на нормалізований email; 30 за хвилину на IP. Retry-After — час очікування в секундах.', type: 'array{message: string}')]
    public function verify(VerifyEmailCodeRequest $request, VerifyEmailCode $action): JsonResponse
    {
        $action->handle($request->validated('email'), $request->validated('code'));

        return response()->json(['verified' => true, 'message' => 'Email підтверджено.']);
    }

    #[Endpoint(title: 'Повторний лист підтвердження email', description: 'Створює новий шестизначний код на 10 хвилин за замовчуванням і скасовує попередній. Зашифроване завдання надходить у Redis-чергу mail після commit; SMTP виконує воркер. Ліміт — один запит на 60 секунд на користувача незалежно від токена або IP. Підтвердженому користувачу повертає 200 без нового коду та листа.')]
    #[ApiResponse(200, type: 'array{message: string}')]
    #[ApiResponse(202, type: 'array{message: string}')]
    #[ApiResponse(429, description: 'Повторне надсилання доступне раз на 60 секунд. Retry-After — час очікування в секундах.', type: 'array{message: string}')]
    public function send(Request $request, SendEmailVerificationCode $action): JsonResponse
    {
        if (! $action->handle($request->user())) {
            return response()->json(['message' => 'Email уже підтверджено.']);
        }

        return response()->json(['message' => 'Лист підтвердження email поставлено в чергу.'], 202);
    }
}
