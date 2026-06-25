<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Util;

/**
 * Shared SSL-mode helper used by the setup command (and any future command
 * that takes an SSL flag). `tls` is accepted as a synonym for `starttls`.
 */
trait SetupResolvers
{
    private function normalizeSslMode(string $ssl): string
    {
        $ssl = \strtolower(\trim($ssl));
        return $ssl === 'tls' ? 'starttls' : $ssl;
    }
}
