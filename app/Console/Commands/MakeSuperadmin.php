<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Input;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mint the first superadmin account. Owners are created in /setup and staff
 * in Settings, but nothing in the UI can create the very first superadmin —
 * this command fills that gap. Later ones are added from the dashboard.
 */
class MakeSuperadmin extends Command
{
    protected $signature = 'mrjeff:make-superadmin
        {--name= : Full name}
        {--email= : Email address (optional if --phone is given)}
        {--phone= : Phone number (optional if --email is given)}
        {--password= : Password (at least 8 characters, prompted securely when omitted)}';

    protected $description = 'Create a superadmin account (email or phone + password)';

    public function handle(): int
    {
        $interactive = $this->input->isInteractive();
        $name = trim((string) ($this->option('name') ?: ($interactive ? $this->ask('Full name') : '')));
        $email = trim((string) ($this->option('email') ?: ($interactive ? $this->ask('Email address (blank to use a phone number instead)') : '')));
        $phone = Input::phone($this->option('phone') ?: ($interactive ? $this->ask('Phone number (blank if an email was given)') : ''));
        $password = (string) ($this->option('password') ?: ($interactive ? $this->secret('Password (at least 8 characters)') : ''));

        if ($name === '' || strlen($password) < 8) {
            $this->error('Name required; password at least 8 characters.');

            return self::FAILURE;
        }
        if ($email === '' && $phone === '') {
            $this->error('An email address or phone number is required.');

            return self::FAILURE;
        }
        if ($email !== '' && ! Input::email($email)) {
            $this->error('That email address is not valid.');

            return self::FAILURE;
        }
        if ($email !== '' && User::where('email', $email)->exists()) {
            $this->error('That email address is already in use.');

            return self::FAILURE;
        }
        if ($phone !== '' && User::where('phone', $phone)->exists()) {
            $this->error('That phone number is already in use.');

            return self::FAILURE;
        }

        $user = User::create([
            'id' => (string) Str::uuid(),
            'name' => $name,
            'email' => $email === '' ? null : $email,
            'phone' => $phone === '' ? null : $phone,
            'password' => Hash::make($password),
            'role' => User::ROLE_SUPERADMIN,
            'shop_id' => null,
            'active' => true,
        ]);

        $this->info("Superadmin {$user->name} created — sign in with "
            .($email !== '' ? $email : $phone).'.');

        return self::SUCCESS;
    }
}
