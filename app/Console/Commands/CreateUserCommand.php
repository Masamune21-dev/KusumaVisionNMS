<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create
                            {--name= : Nama lengkap user}
                            {--email= : Alamat email}
                            {--password= : Password}
                            {--role=operator : Role: admin|operator|partner|demo}';

    protected $description = 'Buat akun user baru (registrasi publik dinonaktifkan)';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Nama lengkap');
        $email = $this->option('email') ?? $this->ask('Email');
        $password = $this->option('password') ?? $this->secret('Password');
        $role = strtolower((string) $this->option('role'));

        $validator = ValidatorFacade::make(
            compact('name', 'email', 'password', 'role'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
                'password' => ['required', Password::defaults()],
                'role' => ['required', Rule::in(UserRole::values())],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => strtolower($email),
            'password' => Hash::make($password),
            'role' => $role,
        ]);

        $this->info("User berhasil dibuat: {$user->name} <{$user->email}> (role: {$user->role->value})");

        return self::SUCCESS;
    }
}
