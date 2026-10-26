<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * The admin save (Build Guide section 3.3): selective, so a field that fails
 * is kept as stored and named, and every other field is written.
 *
 * The API key and environment are one pair (the credentials). They are
 * written together or not at all, because a stored key is only good against
 * the environment it was issued for. A submitted pair replaces the stored one
 * only when it is well formed and:
 * - Two accepts it, or
 * - Two could not judge it (no answer, a 5xx) and the stored pair is not
 *   known good: its last definitive answer was not ok. The heartbeat is not
 *   asked, since an outage overwrites it. A rejected key (401 or 403) is never stored, and a key Two
 *   could not judge never replaces one that last verified.
 *
 * An empty key field means "keep the stored key", so the key never has to be
 * sent back to the browser. A field the form did not post is left alone.
 */
final class SettingsSave
{
    /** Credential outcomes. */
    public const SAVED = 'saved';
    public const SAVED_UNVERIFIED = 'saved_unverified';
    public const UNCHANGED = 'unchanged';
    public const MISSING = 'missing';
    public const KEPT_MALFORMED = 'kept_malformed';
    public const KEPT_REJECTED = 'kept_rejected';
    public const KEPT_UNVERIFIED = 'kept_unverified';
    public const KEPT_ENVIRONMENT = 'kept_environment';

    /** Field error codes. The adapter maps them, and the outcomes, to copy. */
    public const TITLE_MISSING = 'title_missing';
    public const STATUS_MISSING = 'status_missing';
    public const STATUS_UNKNOWN = 'status_unknown';
    /** The shop's void status: OpenCart sets it on orders being edited, so the order lifecycle never acts on it. */
    public const STATUS_VOID = 'status_void';
    public const SWITCH_INVALID = 'switch_invalid';
    public const MINIMUM_INVALID = 'minimum_invalid';
    public const BASIS_INVALID = 'basis_invalid';

    /** The on/off settings of the Checkout fields tab: the placement switch and the optional field toggles. */
    public const SWITCHES = ['company_search_in_address', 'enable_invoice_email', 'enable_po_number', 'enable_project', 'enable_department'];

    /** Printable ASCII with no spaces: a key is sent as a header value, so nothing else may reach the wire. */
    private const KEY = '/^[\x21-\x7E]+\z/';

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly Environment $environment,
        private readonly ApiKeyVerdict $verdict,
        private readonly RecordProvider $records,
    ) {
    }

    /**
     * Validates and writes the submitted settings in one write, then brings
     * the verdict (and, for new credentials, the merchant record) up to date.
     *
     * @param array{api_key?: string, environment?: string, title?: array<int, string>, fulfil_status_ids?: list<string>, cancel_status_id?: string, refund_status_id?: string, company_search_in_address?: string, enable_invoice_email?: string, enable_po_number?: string, enable_project?: string, enable_department?: string, min_order_amount?: string, min_order_basis?: string} $submitted
     * @param list<int> $orderStatusIds the shop's order statuses, which the maps may name
     * @param int       $voidStatusId   the shop's void status (0 for none), which no map may name
     * @return array{errors: array<string, string>, credentials: string|null, status: string|null, code: int|null}
     */
    public function save(array $submitted, array $orderStatusIds, int $voidStatusId): array
    {
        $write = [];
        $errors = [];

        if (isset($submitted['title'])) {
            $titles = array_map('trim', $submitted['title']);
            if (in_array('', $titles, true)) {
                $errors['title'] = self::TITLE_MISSING;
            } else {
                $write['title'] = (string) json_encode((object) $titles, JSON_UNESCAPED_UNICODE);
            }
        }

        if (isset($submitted['fulfil_status_ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('trim', $submitted['fulfil_status_ids']), static fn (string $id): bool => $id !== '')));
            if (array_diff($ids, array_map('strval', $orderStatusIds)) !== []) {
                $errors['fulfil_status_ids'] = self::STATUS_UNKNOWN;
            } elseif ($voidStatusId > 0 && in_array((string) $voidStatusId, $ids, true)) {
                $errors['fulfil_status_ids'] = self::STATUS_VOID;
            } else {
                $write['fulfil_status_ids'] = (string) json_encode(array_map('intval', $ids));
            }
        }

        foreach (['cancel_status_id', 'refund_status_id'] as $key) {
            if (!isset($submitted[$key])) {
                continue;
            }
            $id = trim($submitted[$key]);
            $error = match (true) {
                $id === '' || $id === '0' => self::STATUS_MISSING,
                !in_array($id, array_map('strval', $orderStatusIds), true) => self::STATUS_UNKNOWN,
                $voidStatusId > 0 && $id === (string) $voidStatusId => self::STATUS_VOID,
                default => null,
            };
            if ($error !== null) {
                $errors[$key] = $error;
            } else {
                $write[$key] = $id;
            }
        }

        foreach (self::SWITCHES as $key) {
            if (isset($submitted[$key])) {
                $value = trim($submitted[$key]);
                if (in_array($value, ['0', '1'], true)) {
                    $write[$key] = $value;
                } else {
                    $errors[$key] = self::SWITCH_INVALID;
                }
            }
        }
        if (isset($submitted['min_order_amount'])) {
            // Empty is no minimum; otherwise a positive amount with at most two decimals.
            $amount = trim($submitted['min_order_amount']);
            if ($amount === '' || (preg_match('~^\d{1,10}(\.\d{1,2})?\z~', $amount) === 1 && (float) $amount > 0)) {
                $write['min_order_amount'] = $amount;
            } else {
                $errors['min_order_amount'] = self::MINIMUM_INVALID;
            }
        }
        if (isset($submitted['min_order_basis'])) {
            $basis = trim($submitted['min_order_basis']);
            if (in_array($basis, ['', SettingsProvider::BASIS_GROSS, SettingsProvider::BASIS_NET], true)) {
                $write['min_order_basis'] = $basis;
            } else {
                $errors['min_order_basis'] = self::BASIS_INVALID;
            }
        }

        $credentials = null;
        $checked = ['status' => null, 'code' => null];
        if (isset($submitted['environment'])) {
            [$credentials, $pair, $checked] = $this->credentials(trim($submitted['api_key'] ?? ''), trim($submitted['environment']));
            $write += $pair;
        }

        if ($write !== []) {
            $this->settings->store()->setMany($write);
        }

        if ($credentials === self::SAVED) {
            // New credentials: the record the shop holds describes the old ones.
            $this->records->refresh();
        } elseif ($credentials === self::UNCHANGED) {
            $checked = $this->verdict->verify();
        }

        return ['errors' => $errors, 'credentials' => $credentials, 'status' => $checked['status'], 'code' => $checked['code']];
    }

    /** Whether a key may be sent to Two at all. The live check applies the same rule. */
    public static function isWellFormedKey(string $key): bool
    {
        return preg_match(self::KEY, $key) === 1;
    }

    /**
     * The outcome for a submitted pair, the settings to write for it, and
     * Two's answer when it was asked.
     *
     * @return array{0: string, 1: array<string, string>, 2: array{status: string|null, code: int|null}}
     */
    private function credentials(string $apiKey, string $environment): array
    {
        $none = ['status' => null, 'code' => null];
        $storedKey = $this->settings->apiKey();
        $storedEnvironment = $this->settings->environment();
        $key = $apiKey === '' ? $storedKey : $apiKey;

        if (!$this->environment->acceptsSave($environment, $storedEnvironment)) {
            return [self::KEPT_ENVIRONMENT, [], $none];
        }
        if ($key === '') {
            // Nothing stored and nothing typed: there is no key to protect, so the environment is kept.
            return [self::MISSING, ['environment' => $environment], $none];
        }
        if (!self::isWellFormedKey($key)) {
            return [self::KEPT_MALFORMED, [], $none];
        }
        if ($key === $storedKey && Environment::recognise($environment) === Environment::recognise($storedEnvironment)) {
            return [self::UNCHANGED, ['environment' => $environment], $none];
        }

        $checked = $this->verdict->check($key, $environment);
        $answer = ['status' => $checked['status'], 'code' => $checked['code']];
        $pair = ['api_key' => $key, 'environment' => $environment];
        if ($checked['status'] === ApiKeyVerdict::OK) {
            return [self::SAVED, $pair, $answer];
        }
        if ($checked['status'] === ApiKeyVerdict::INVALID) {
            return [self::KEPT_REJECTED, [], $answer];
        }
        if ($storedKey !== '' && $this->verdict->lastDefinitiveStatus() === ApiKeyVerdict::OK) {
            return [self::KEPT_UNVERIFIED, [], $answer];
        }

        return [self::SAVED_UNVERIFIED, $pair, $answer];
    }
}
