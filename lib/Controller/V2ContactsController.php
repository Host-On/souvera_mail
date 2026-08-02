<?php

declare(strict_types=1);

namespace OCA\SouveraMail\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Contacts\IManager;
use OCP\IRequest;

class V2ContactsController extends Controller
{
    public function __construct(
        string $appName,
        IRequest $request,
        private IManager $contactsManager,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * GET /apps/souvera_mail/api/v2/contacts/search?q=term&limit=20
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): JSONResponse
    {
        $query = \trim((string) ($this->request->getParam('q') ?? ''));
        $limit = \min(50, \max(1, (int) ($this->request->getParam('limit') ?? 20)));

        if ($query === '') {
            return new JSONResponse(['contacts' => []]);
        }

        $this->contactsManager->registerAll();
        $results = $this->contactsManager->search($query, ['FN', 'EMAIL'], ['types' => true], $limit);

        $contacts = [];
        foreach ($results as $contact) {
            $emails = $contact['EMAIL'] ?? [];
            if (!\is_array($emails)) {
                $emails = [$emails];
            }
            foreach ($emails as $email) {
                $addr = \is_array($email) ? ($email['value'] ?? '') : (string) $email;
                if ($addr !== '') {
                    $contacts[] = [
                        'name' => $contact['FN'] ?? '',
                        'email' => $addr,
                    ];
                }
            }
        }

        return new JSONResponse(['contacts' => $contacts]);
    }
}
