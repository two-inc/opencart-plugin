<?php

declare(strict_types=1);

use Opencart\System\Library\Extension\Two\Opencart\EventSubscribers;

/**
 * Build Guide section 21.7: no order request bypasses the choke point. Only
 * the listed files call the Client; order requests come from OrderFlow
 * and OrderLifecycle alone, and every request there that changes an order
 * goes through Postprocessing::apply (directly or through
 * OrderFlow::payload) in the same method first. Also pins which event
 * triggers OpenCart would run for the hook.
 */
final class CallSiteAllowlistSpec
{
    /** Files that may call Client::request, and whether they may send order requests. */
    private const CALLERS = [
        'system/library/core/order_flow.php' => true,
        'system/library/core/order_lifecycle.php' => true,
        'system/library/core/record_provider.php' => false,
        'system/library/core/api_key_verdict.php' => false,
    ];

    public static function runAll(): void
    {
        $root = dirname(__DIR__);
        $files = [];
        foreach (['system', 'catalog', 'admin'] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        ksort($files);
        foreach ($files as $path => $code) {
            $calls = preg_match_all('~(?<!this)->request\(~', $code);
            TinyAssert::true($calls === 0 || isset(self::CALLERS[$path]), $path . ' calls the Client but is not on the allowlist');
            if (isset(self::CALLERS[$path]) && !self::CALLERS[$path]) {
                TinyAssert::true(!str_contains($code, '/v1/order'), $path . ' sends no order request');
            }
        }

        $expected = [
            'system/library/core/order_flow.php' => [3, 'OrderFlow sends order create, confirm and cancel'],
            'system/library/core/order_lifecycle.php' => [1, 'OrderLifecycle sends fulfil (its cancel is OrderFlow\'s)'],
        ];
        foreach ($expected as $path => [$count, $description]) {
            $posts = 0;
            foreach (preg_split('~\n    (?:public|private) (?:static )?function ~', $files[$path]) ?: [] as $method) {
                $post = strpos($method, "client->request('POST'");
                if ($post === false) {
                    continue;
                }
                $posts++;
                $hook = preg_match('~postprocessing->apply\(|this->payload\(~', $method, $found, PREG_OFFSET_CAPTURE) === 1 ? $found[0][1] : false;
                TinyAssert::true($hook !== false && $hook < $post, 'every order request in ' . $path . ' passes the hook first: ' . strtok($method, '('));
            }
            TinyAssert::same($count, $posts, $description);
        }
        $flow = $files['system/library/core/order_flow.php'];
        TinyAssert::true(preg_match('~function payload\([^{]*\{[^}]*postprocessing->apply\(~s', $flow) === 1, 'OrderFlow::payload runs the hook');

        self::testTriggers();
        self::testLoadable();
    }

    private static function testLoadable(): void
    {
        eval('namespace Opencart\\Catalog\\Controller\\Extension\\Acme\\Module; final class OrderFix { public function handle(): void {} private function hidden(): void {} }');
        $cases = [
            ['action' => 'extension/acme/module/order_fix.handle', 'loadable' => true, 'description' => 'a public method on a loadable controller'],
            ['action' => 'extension/acme/module/order_fix.hidden', 'loadable' => false, 'description' => 'a private method'],
            ['action' => 'extension/acme/module/order_fix.missing', 'loadable' => false, 'description' => 'a method that does not exist'],
            ['action' => 'extension/acme/module/order_fix.__construct', 'loadable' => false, 'description' => 'a magic method, which OpenCart refuses'],
            ['action' => 'extension/acme/module/gone.handle', 'loadable' => false, 'description' => 'a controller that does not load'],
            ['action' => '', 'loadable' => false, 'description' => 'an empty action'],
        ];
        foreach ($cases as $case) {
            TinyAssert::same($case['loadable'], EventSubscribers::loadable($case['action']), 'event action: ' . $case['description']);
        }
    }

    private static function testTriggers(): void
    {
        $cases = [
            ['trigger' => 'catalog/extension/two/order_postprocessing', 'runs' => true, 'description' => 'the exact event'],
            ['trigger' => 'catalog/extension/two/*', 'runs' => true, 'description' => 'a wildcard over the extension'],
            ['trigger' => 'catalog/extension/two/order_post?rocessing', 'runs' => true, 'description' => 'a single-character wildcard'],
            ['trigger' => 'catalog/extension/two/order', 'runs' => true, 'description' => 'a prefix, as OpenCart matches it'],
            ['trigger' => 'admin/extension/two/order_postprocessing', 'runs' => false, 'description' => 'another area'],
            ['trigger' => 'extension/two/order_postprocessing', 'runs' => false, 'description' => 'no area prefix'],
            ['trigger' => 'catalog/extension/other/order_postprocessing', 'runs' => false, 'description' => 'another extension'],
        ];
        foreach ($cases as $case) {
            TinyAssert::same($case['runs'], EventSubscribers::matches($case['trigger']), 'event trigger: ' . $case['description']);
        }
    }
}
