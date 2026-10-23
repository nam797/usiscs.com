<?php

declare(strict_types=1);

namespace App\Sitemap;

use Sylius\Component\Core\Model\ChannelInterface;

/**
 * The catalog side of sitemap.xml: which product and category pages exist
 * for a channel/locale. Kept behind an interface so SitemapController can
 * be unit-tested without a database.
 */
interface CatalogUrlSourceInterface
{
    /**
     * Enabled products available in the channel, oldest first.
     *
     * @return list<array{slug: string, updatedAt: ?\DateTimeInterface}>
     */
    public function products(ChannelInterface $channel, string $locale): array;

    /**
     * Slugs of enabled categories that contain at least one enabled product
     * in the channel. Empty categories are left out: they're thin pages that
     * search engines penalise.
     *
     * @return list<string>
     */
    public function taxonSlugs(ChannelInterface $channel, string $locale): array;
}
