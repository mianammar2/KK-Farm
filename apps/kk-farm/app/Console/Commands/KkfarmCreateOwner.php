<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates (or resets) the owner account on a server. Run it yourself: the password is typed at the prompt
 * and never appears in shell history or in this repository.
 *   php artisan kkfarm:create-owner
 */
class KkfarmCreateOwner extends Command
{
    protected $signature = 'kkfarm:create-owner {--org=kk-farms : Organization slug}';

    protected $description = 'Create or reset the owner (CEO) account for an organization (D-044).';

    public function handle(): int
    {
        $org = Organization::where('slug', $this->option('org'))->first();
        if (! $org) {
            $this->error('No organization with slug '.$this->option('org').'. Run the database seeder first.');

            return self::FAILURE;
        }

        $name = $this->ask('Owner name', 'Owner');
        $login = strtolower(trim((string) $this->ask('Username or email to sign in with')));
        $password = (string) $this->secret('Password (at least 8 characters)');
        if ($login === '' || strlen($password) < 8 || $password !== $this->secret('Repeat the password')) {
            $this->error('A username is required, and the passwords must match and be at least 8 characters.');

            return self::FAILURE;
        }

        $user = User::where('email', $login)->first() ?? User::where('org_id', $org->id)->where('role', 'ceo')->first() ?? new User;
        $user->forceFill(['name' => $name, 'email' => $login, 'role' => 'ceo', 'org_id' => $org->id, 'active' => true, 'must_change_password' => false, 'password' => Hash::make($password)])->save();
        $this->info("Owner {$login} is ready for {$org->name}.");

        return self::SUCCESS;
    }
}
