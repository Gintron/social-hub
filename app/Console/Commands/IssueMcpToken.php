<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class IssueMcpToken extends Command
{
    /**
     * Scopes an agent can hold, narrowest first.
     */
    private const ABILITIES = [
        'read' => ['mcp'],
        'draft' => ['mcp', 'mcp:draft'],
        'approve' => ['mcp', 'mcp:draft', 'mcp:approve'],
        'publish' => ['mcp', 'mcp:draft', 'mcp:approve', 'mcp:publish'],
    ];

    protected $signature = 'hub:issue-mcp-token
        {email : Hub user the token acts as}
        {--scope=draft : read, draft, approve or publish}
        {--name= : Token name shown in personal_access_tokens}
        {--expires-days= : Optional expiry in days (default: never)}';

    protected $description = 'Issue a Sanctum token for the MCP server, scoped to what the agent may do';

    public function handle(): int
    {
        $scope = (string) $this->option('scope');

        if (! isset(self::ABILITIES[$scope])) {
            $this->error('Unknown scope. Use one of: '.implode(', ', array_keys(self::ABILITIES)).'.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', (string) $this->argument('email'))->first();

        if ($user === null) {
            $this->error("No user with e-mail {$this->argument('email')}.");

            return self::FAILURE;
        }

        if (! $user->isHubAdmin()) {
            $this->error("User {$user->email} is not in HUB_ADMIN_EMAILS; refusing to issue a token that acts as them.");

            return self::FAILURE;
        }

        $expiresDays = $this->option('expires-days');
        $token = $user->createToken(
            (string) ($this->option('name') ?: "mcp-{$scope}"),
            self::ABILITIES[$scope],
            filled($expiresDays) ? now()->addDays((int) $expiresDays) : null,
        );

        $this->info("Token issued for scope '{$scope}' (".implode(', ', self::ABILITIES[$scope]).'):');
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->line('MCP endpoint: '.url('/mcp'));

        return self::SUCCESS;
    }
}
