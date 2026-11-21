<?php

namespace Kanboard\Plugin\OrkSearch\Controller;

use Kanboard\Controller\BaseController;
use Kanboard\Core\Controller\AccessForbiddenException;
use Kanboard\Plugin\OrkSearch\Service\SearchEngine;

class SmartSearchController extends BaseController
{
    public function search()
    {
        $project = $this->getProject();
        $projectId = (int) $project['id'];
        $userId = (int) $this->userSession->getId();

        // Defense-in-depth, in addition to the projectAccessMap role restriction.
        if (! $this->userSession->isLogged() ||
            (! $this->userSession->isAdmin() &&
             ! $this->projectPermissionModel->isUserAllowed($projectId, $userId))) {
            throw new AccessForbiddenException();
        }

        $q = trim((string) $this->request->getStringParam('q', ''));
        if (strlen($q) > 300) {
            $this->response->json(array('error' => 'Query too long'), 400);
            return;
        }

        $selected = array();
        foreach (array('swimlane', 'column', 'category', 'tag', 'assignee', 'priority', 'complexity', 'status') as $field) {
            $value = $this->request->getStringParam($field, '');
            if (strlen((string) $value) > 80) {
                $this->response->json(array('error' => 'Invalid filter'), 400);
                return;
            }
            $selected[$field] = trim((string) $value);
        }

        $mode = $this->request->getStringParam('mode', 'results');
        if (! in_array($mode, array('summary', 'facets', 'results'), true)) {
            $this->response->json(array('error' => 'Invalid mode'), 400);
            return;
        }
        $offset = $this->request->getIntegerParam('offset', 0);
        $engine = new SearchEngine($this->container);
        $this->response->json($engine->run($projectId, $userId, $q, $selected, $offset, $mode));
    }
}
