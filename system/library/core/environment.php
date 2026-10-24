<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Two environment selection (Build Guide section 3.2): production, sandbox,
 * and staging only for internal Two shops. Explicit host maps, no hostname
 * logic. The adapter reads the TWO_INTERNAL_ENVIRONMENTS flag from the process
 * and passes it in, so this class never touches the environment itself.
 *
 * Behaviour lands in P1, driven by the shared environment-cases.json table.
 */
final class Environment
{
    public const PRODUCTION = 'production';
    public const SANDBOX = 'sandbox';
    public const STAGING = 'staging';
    public const DEFAULT = self::SANDBOX;

    public function __construct(private readonly bool $internalEnvironmentsEnabled)
    {
    }

    /**
     * The environments the admin offers, in display order, given what is stored.
     *
     * @return list<string>
     */
    public function options(string $stored): array
    {
        throw NotImplemented::inPhase('Environment::options', 'P1');
    }

    /** The environment actually used for a stored value. */
    public function resolve(string $stored): string
    {
        throw NotImplemented::inPhase('Environment::resolve', 'P1');
    }

    /** API base URL, without a trailing slash. */
    public function apiBaseUrl(string $environment): string
    {
        throw NotImplemented::inPhase('Environment::apiBaseUrl', 'P1');
    }

    /** Hosted checkout base URL, without a trailing slash. */
    public function checkoutBaseUrl(string $environment): string
    {
        throw NotImplemented::inPhase('Environment::checkoutBaseUrl', 'P1');
    }

    /** False when the stored value cannot be honoured, for the health row. */
    public function isHealthy(string $stored): bool
    {
        throw NotImplemented::inPhase('Environment::isHealthy', 'P1');
    }

    public function internalEnvironmentsEnabled(): bool
    {
        return $this->internalEnvironmentsEnabled;
    }
}
