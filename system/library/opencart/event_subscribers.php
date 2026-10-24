<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Opencart;

use Opencart\System\Library\Extension\Two\Core\PostprocessingSubscribers;

/**
 * The order postprocessing hook (Build Guide section 21) as an OpenCart
 * event. A merchant subscribes the way OpenCart extensions subscribe to any
 * event: a row in the event table (Extensions > Events) whose trigger is
 * `catalog/extension/two/order_postprocessing` and whose action takes the
 * payload by reference, chained by the event's sort order:
 *
 *     public function handle(array &$payload, array $context): void
 *
 * The plugin fires the event itself and reads the event table for the
 * subscribers' names, so the default handler knows when to stand down.
 */
final class EventSubscribers implements PostprocessingSubscribers
{
    /** The event name, as fired. Registered triggers carry the `catalog/` area prefix. */
    public const EVENT = 'extension/two/order_postprocessing';
    public const AREA = 'catalog/';

    public function __construct(
        private readonly \Opencart\System\Library\DB $db,
        private readonly \Opencart\System\Engine\Event $event,
    ) {
    }

    public function names(): array
    {
        $query = $this->db->query('SELECT `code`, `trigger`, `action` FROM `' . DB_PREFIX . "event` WHERE `status` = '1' ORDER BY `sort_order` ASC, `event_id` ASC");
        $names = [];
        foreach (is_object($query) ? $query->rows : [] as $row) {
            if (self::matches((string) $row['trigger'])) {
                $names[] = $row['code'] . ' (' . $row['action'] . ')';
            }
        }

        return $names;
    }

    public function dispatch(array $payload, array $context): mixed
    {
        $result = $payload;
        $this->event->trigger(self::EVENT, [&$result, $context]);

        return $result;
    }

    /** OpenCart's own rule (Event::trigger): the trigger, with * and ? as wildcards, matches the start of the event name. */
    public static function matches(string $trigger): bool
    {
        if (!str_starts_with($trigger, self::AREA)) {
            return false;
        }
        $pattern = str_replace(['\*', '\?'], ['.*', '.'], preg_quote(substr($trigger, strlen(self::AREA)), '/'));

        return preg_match('/^' . $pattern . '/', self::EVENT) === 1;
    }
}
