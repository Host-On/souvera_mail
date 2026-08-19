<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Service;

/**
 * Thrown when the provider.tools API is not usable — either because
 * the shared token is missing/misconfigured in Souvera Central, or
 * because the upstream returned a non-2xx status (401/403/429/5xx),
 * or because we could not reach it at all.
 *
 * The MigrationController maps this to HTTP 502 Bad Gateway so the
 * browser sees a friendly "Import-Dienst nicht erreichbar" message
 * instead of a 500 crash-page.
 */
class ProviderToolsUnavailable extends \RuntimeException
{
}
