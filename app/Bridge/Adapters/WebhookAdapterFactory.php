<?php

namespace App\Bridge\Adapters;

use App\Bridge\Contracts\WebhookAdapter;
use App\Bridge\Exceptions\UnknownProviderException;

/**
 * Resolves a provider URL slug to its adapter. A static match (not a
 * user-influenced dynamic require) so no adapter file is loaded based on
 * request input — opcache parses each class once on first reference.
 */
final class WebhookAdapterFactory
{
    /**
     * Provider slugs with a registered adapter.
     *
     * @var list<string>
     */
    public const SUPPORTED = ['kanban', 'github'];

    public static function supports(string $provider): bool
    {
        return in_array($provider, self::SUPPORTED, true);
    }

    public static function for(string $provider): WebhookAdapter
    {
        return match ($provider) {
            'kanban' => new KanbanAdapter,
            'github' => new GitHubAdapter,
            default => throw new UnknownProviderException($provider),
        };
    }
}
