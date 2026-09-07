<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class IssueSocialHubToken extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'social:issue-hub-token
        {email : E-mail of the admin user the token belongs to}
        {--name=social-hub : Token name shown in personal_access_tokens}
        {--expires-days= : Optional expiry in days (default: never)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Issue a Sanctum token with the social:read ability for the social hub feed';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user with e-mail {$email}.");

            return self::FAILURE;
        }

        if (! $user->admin) {
            $this->error("User {$email} is not an admin; refusing to issue a hub token.");

            return self::FAILURE;
        }

        $expiresDays = $this->option('expires-days');
        $expiresAt = filled($expiresDays) ? now()->addDays((int) $expiresDays) : null;

        $token = $user->createToken((string) $this->option('name'), ['social:read'], $expiresAt);

        $this->info('Token issued (shown once, store it in the hub as the source secret):');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
