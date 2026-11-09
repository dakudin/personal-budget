<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Email;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--name= : Ім’я адміністратора} {--email= : Email адміністратора}';

    protected $description = 'Створити нового адміністратора з роллю admin (пароль вводиться приховано)';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?? $this->ask('Ім’я'),
            'email' => Email::normalize($this->option('email') ?? $this->ask('Email')),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $data['password'] = $this->secret('Пароль (щонайменше 12 символів)');
        $validator = Validator::make($data, ['password' => ['required', 'string', Password::defaults()]]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        DB::transaction(function () use ($data): void {
            (new RoleSeeder)->run();
            User::create($data)->assignRole('admin');
        });

        $this->info('Адміністратора створено. Увійдіть за адресою /admin.');

        return self::SUCCESS;
    }
}
