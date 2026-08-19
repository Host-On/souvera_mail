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
     * GET /apps/souvera_mail/api/v2/contacts/list?limit=50&offset=0
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function listAll(): JSONResponse
    {
        $limit = \min(200, \max(1, (int) ($this->request->getParam('limit') ?? 100)));
        $offset = \max(0, (int) ($this->request->getParam('offset') ?? 0));

        // NOTE: IManager has no registerAll() — calling it fataled the
        // endpoint (HTTP 500) and the picker silently showed nothing.
        // limit/offset are OPTIONS (the search() signature has 3 params;
        // positional limit/offset would raise an ArgumentCountError).
        $results = $this->contactsManager->search('', ['FN', 'EMAIL'], [
            'types' => true,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $contacts = [];
        foreach ($results as $contact) {
            $name = $contact['FN'] ?? '';
            $emails = $contact['EMAIL'] ?? [];
            if (!\is_array($emails)) $emails = [$emails];
            $emailList = [];
            foreach ($emails as $email) {
                $addr = \is_array($email) ? ($email['value'] ?? '') : (string) $email;
                if ($addr !== '') {
                    $emailList[] = $addr;
                }
            }
            if ($emailList !== []) {
                $contacts[] = [
                    'name' => $name,
                    'emails' => $emailList,
                    'primaryEmail' => $emailList[0],
                ];
            }
        }

        return new JSONResponse(['contacts' => $contacts, 'total' => \count($contacts)]);
    }
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function search(): JSONResponse
    {
        $query = \trim((string) ($this->request->getParam('q') ?? ''));
        $limit = \min(50, \max(1, (int) ($this->request->getParam('limit') ?? 20)));

        if ($query === '') {
            return new JSONResponse(['contacts' => []]);
        }

        $results = $this->contactsManager->search($query, ['FN', 'EMAIL'], [
            'types' => true,
            'limit' => $limit,
        ]);

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
