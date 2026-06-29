<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Legacy entry-point for `/index.php/apps/souvera_mail/settings`.
 *
 * Up to Souvera Mail 0.13.2 this controller rendered a full Nextcloud-chrome
 * settings page (templates/settings.php). Per product feedback the user-facing
 * settings now live inside the Snappymail engine as a native Settings tab at
 * the hash route `#/settings/souvera-account` ("Sicherheit & Geräte"), so that
 * the user does not leave the mailbox UI to manage Dashboard widget mode,
 * App Passwords and Connected Devices.
 *
 * This controller is kept as a backward-compatible redirect — operator
 * bookmarks pointing at `/apps/souvera_mail/settings` continue to resolve,
 * they just land in the in-engine tab instead of a separate page.
 */
class SettingsController extends Controller
{
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): RedirectResponse
	{
		// Build the engine URL + Snappymail hash route. The engine is mounted
		// at `souvera_mail.page.index` and uses HASH-based client routing, so
		// we cannot let Symfony build the fragment for us — concatenate it.
		$base = $this->urlGenerator->linkToRoute('souvera_mail.page.index');
		return new RedirectResponse($base . '#/settings/souvera-account');
	}
}
