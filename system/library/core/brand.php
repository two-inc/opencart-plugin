<?php

declare(strict_types=1);

namespace Opencart\System\Library\Extension\Two\Core;

/**
 * Brand descriptor (Build Guide section 9). Every brand string the plugin shows
 * comes from here, so a white-label edition is a different descriptor (data
 * and assets), never a fork. The keys match the sibling plugins' descriptors.
 */
final class Brand
{
    private const REQUIRED = ['product_name', 'provider_full_name', 'about_url'];

    public function __construct(
        public readonly string $productName,
        public readonly string $providerFullName,
        public readonly string $aboutUrl,
    ) {
    }

    public static function default(): self
    {
        return self::fromArray([
            'product_name' => 'Two',
            'provider_full_name' => 'Two',
            'about_url' => 'https://www.two.inc/what-is-two',
        ]);
    }

    /**
     * @param array<string, mixed> $descriptor
     */
    public static function fromArray(array $descriptor): self
    {
        foreach (self::REQUIRED as $key) {
            if (!isset($descriptor[$key]) || !is_string($descriptor[$key]) || trim($descriptor[$key]) === '') {
                throw new \InvalidArgumentException('Brand descriptor is missing "' . $key . '".');
            }
        }

        return new self($descriptor['product_name'], $descriptor['provider_full_name'], $descriptor['about_url']);
    }
}
