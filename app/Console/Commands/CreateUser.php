<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateUser extends Command
{
    protected $signature = 'kikitoons:user {email} {--name=Kikitoons Admin} {--password= : Omit to be prompted securely}';

    protected $description = 'Create (or reset the password of) a user who can sign in to the voice generator';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $password = $this->option('password') ?: $this->secret('Password (min 8 characters)');

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(['email' => $email], ['name' => $this->option('name'), 'password' => $password]);
        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." user {$email}.");

        return self::SUCCESS;
    }
}
