<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The company a buyer captured with the company search, as the session and a
 * saved address keep it (TWO-26721): the rules, with no OpenCart in them.
 *
 * A capture is `company_name`, `organization_number`, `country_code` (ISO-2,
 * upper case) and `source` (`search`, `manual` or `sole_trader`). A saved
 * address keeps one in its own `custom_field`, under KEY, only while its name
 * is still the address's company.
 */
final class CompanyCapture
{
    /** The key a saved address's `custom_field` keeps the capture under. */
    public const KEY = 'two_company';

    public const SOURCES = ['search', 'manual', 'sole_trader'];

    /**
     * A capture from a `save_company` post, or null when the post is not one.
     * Manual entry is the name only, as on the sibling plugins, so a manual
     * post never carries a number.
     *
     * @param array<string, string> $post trimmed and entity-decoded fields: company_name, organization_number, country, source
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function fromPost(array $post): ?array
    {
        $capture = [
            'company_name' => $post['company_name'] ?? '',
            'organization_number' => ($post['source'] ?? '') === 'manual' ? '' : ($post['organization_number'] ?? ''),
            'country_code' => strtoupper($post['country'] ?? ''),
            'source' => $post['source'] ?? '',
        ];
        if (!in_array($capture['source'], self::SOURCES, true)
            || preg_match('~^[A-Z]{2}\z~', $capture['country_code']) !== 1
            || preg_match('~[\x00-\x1F\x7F]~', $capture['company_name'] . $capture['organization_number']) === 1) {
            return null;
        }
        foreach (OrderRecords::MAX_LENGTHS as $field => $max) {
            if (mb_strlen($capture[$field]) > $max) {
                return null;
            }
        }

        return $capture;
    }

    /**
     * A stored capture in the canonical shape, or null when it holds no name.
     *
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function normalise(mixed $capture): ?array
    {
        if (!is_array($capture) || !is_scalar($capture['company_name'] ?? null) || trim((string) $capture['company_name']) === '') {
            return null;
        }
        $source = (string) ($capture['source'] ?? 'search');

        return [
            'company_name' => trim((string) $capture['company_name']),
            'organization_number' => $source === 'manual' ? '' : trim((string) ($capture['organization_number'] ?? '')),
            'country_code' => strtoupper(trim((string) ($capture['country_code'] ?? ''))),
            'source' => in_array($source, self::SOURCES, true) ? $source : 'search',
        ];
    }

    /**
     * What a saved address keeps, and nothing else: the address edit form is
     * seeded from this alone, never from a capture made on another address.
     *
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function ofAddress(mixed $address): ?array
    {
        return self::normalise(is_array($address) && is_array($address['custom_field'] ?? null) ? ($address['custom_field'][self::KEY] ?? null) : null);
    }

    /**
     * The `custom_field` to save with an address. Whatever arrived under KEY
     * is dropped (the form's own post cannot set it). The capture kept is the
     * first of the session's captures, in the roles' order, whose name is the
     * address's company; else the one the address already holds, on the same
     * terms, and only when that address is the customer's own. A kept capture
     * with no number takes the number of a same-named one that has it.
     *
     * @param array<mixed> $customField what OpenCart is about to store
     * @param array<string, mixed> $captures the session's captures by role
     * @param list<string> $roles the roles to try, in order
     * @param array<string, mixed> $stored the address as stored now ([] for a new one), with its customer_id
     * @return array<mixed>
     */
    public static function keep(array $customField, string $company, array $captures, array $roles, array $stored, int $customerId): array
    {
        unset($customField[self::KEY]);
        $name = trim($company);
        if ($name === '') {
            return $customField;
        }
        $same = static fn (?array $capture): bool => $capture !== null && $capture['company_name'] === $name;
        $held = (int) ($stored['customer_id'] ?? 0) === $customerId && $customerId > 0
            ? self::normalise(is_array($stored['custom_field'] ?? null) ? ($stored['custom_field'][self::KEY] ?? null) : null)
            : null;
        $candidates = [];
        foreach ($roles as $role) {
            $candidates[] = self::normalise($captures[$role] ?? null);
        }
        $candidates[] = $held;
        $kept = null;
        foreach ($candidates as $capture) {
            if ($same($capture)) {
                $kept = $kept ?? $capture;
                if ($kept['organization_number'] === '' && $kept['source'] !== 'manual' && $capture['organization_number'] !== '') {
                    $kept['organization_number'] = $capture['organization_number'];
                }
            }
        }
        if ($kept !== null) {
            $customField[self::KEY] = $kept;
        }

        return $customField;
    }

    /**
     * The company for an address role: on a saved address, what that address
     * keeps, its number topped up from the session's same-named capture where
     * it has none (the number lands a moment after the pick); on a new
     * address, what the buyer captured in its form this session.
     *
     * @param mixed $address the session's address for the role
     * @return array{company_name: string, organization_number: string, country_code: string, source: string}|null
     */
    public static function forAddress(mixed $address, mixed $sessionCapture): ?array
    {
        $session = self::normalise($sessionCapture);
        if (!is_array($address) || (int) ($address['address_id'] ?? 0) <= 0) {
            return $session;
        }
        $kept = self::ofAddress($address);
        if ($kept !== null && $kept['organization_number'] === '' && $kept['source'] !== 'manual'
            && $session !== null && $session['company_name'] === $kept['company_name']) {
            $kept['organization_number'] = $session['organization_number'];
        }

        return $kept;
    }

    /**
     * The address's capture with the number a later save supplied, or null
     * when nothing changes: same name, the address had none, and one arrived.
     *
     * @param array<mixed> $customField the address's stored `custom_field`
     * @param array{company_name: string, organization_number: string, country_code: string, source: string} $capture
     * @return array<mixed>|null
     */
    public static function topUp(array $customField, array $capture): ?array
    {
        $held = self::normalise($customField[self::KEY] ?? null);
        if ($held === null || $held['company_name'] !== $capture['company_name'] || $held['organization_number'] !== ''
            || $held['source'] === 'manual' || $capture['organization_number'] === '') {
            return null;
        }
        $customField[self::KEY] = array_replace($held, ['organization_number' => $capture['organization_number']]);

        return $customField;
    }

    /**
     * Run a relay call and reload the stored session before anything is
     * kept, so this request's write-back at shutdown can never put back a
     * session older than a save that landed while the call was out.
     *
     * @template T
     * @param \Closure(): T $relay
     * @param \Closure(): void $reload
     * @param (\Closure(T): void)|null $keep
     * @return T
     */
    public static function relay(\Closure $relay, \Closure $reload, ?\Closure $keep = null): mixed
    {
        $answer = $relay();
        $reload();
        if ($keep !== null) {
            $keep($answer);
        }

        return $answer;
    }
}
