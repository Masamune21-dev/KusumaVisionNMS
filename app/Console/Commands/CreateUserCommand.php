<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Illuminate\Support\Facades\Validator as ValidatorFacade;

class CreateUserCommand extends Command
{
    protected $signature = 'user:create
                            {--name= : Nama lengkap user}
                            {--email= : Alamat email}
                            {--password= : Password}';

    protected $description = 'Buat akun user baru (registrasi publik dinonaktifkan)';

    public function handle(): int
    {
        $name = $this->option('name') ?? $this->ask('Nama lengkap');
        $email = $this->option('email') ?? $this->ask('Email');
        $password = $this->option('password') ?? $this->secret('Password');

        $validator = ValidatorFacade::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
                'password' => ['required', Password::defaults()],
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
        ]);

        $this->info("User berhasil dibuat: {$user->name} <{$user->email}>");

        return self::SUCCESS;
    }
}
