<?php

namespace Kanboard\Plugin\OrkSearch\Service;

use Kanboard\Core\Base;
use Kanboard\Core\Security\Role;

class SearchEngine extends Base
{
    const MAX_TASKS = 2000;
    const MAX_RESULTS = 20;
    const MAX_MATCH_SOURCES = 40;

    /**
     * Read-only per-project search. Optional attachment index is never built by HTTP.
     * Bulk-load relations to avoid N+1 database queries.
     */
    public function run($projectId, $userId, $query, array $selected, $offset = 0, $mode = 'results')
    {
        // Initial opening is a lightweight count only: no card list or relation scan.
        if ($mode === 'summary') {
            $available = $this->db->table('tasks')->eq('project_id', (int) $projectId)->count();
            return array(
                'mode' => 'summary', 'total' => (int) $available,
                'hits' => array(), 'facets' => array(), 'has_more' => false,
                'limit' => self::MAX_RESULTS, 'indexed_attachment_contents' => false,
            );
        }
        $offset = max(0, min(self::MAX_TASKS, (int) $offset));
        $hasText = trim((string) $query) !== '';
        $rows = $this->db->table('tasks')
            ->columns(
                'tasks.id', 'tasks.reference', 'tasks.title', 'tasks.description',
                'tasks.project_id', 'tasks.category_id', 'tasks.column_id',
                'tasks.swimlane_id', 'tasks.owner_id', 'tasks.is_active',
                'tasks.date_due', 'tasks.date_modification', 'tasks.priority', 'tasks.score',
                'project_has_categories.name AS category_name',
                'columns.title AS column_name',
                'swimlanes.name AS swimlane_name',
                'users.name AS assignee_name', 'users.username AS assignee_username'
            )
            ->join('project_has_categories', 'id', 'category_id', 'tasks')
            ->join('columns', 'id', 'column_id', 'tasks')
            ->join('swimlanes', 'id', 'swimlane_id', 'tasks')
            ->join('users', 'id', 'owner_id', 'tasks')
            ->eq('tasks.project_id', (int) $projectId)
            ->desc('tasks.date_modification')
            ->limit(self::MAX_TASKS + 1)
            ->findAll();

        $truncated = count($rows) > self::MAX_TASKS;
        $rows = array_slice($rows, 0, self::MAX_TASKS);
        $taskIds = array_map(function ($t) { return (int) $t['id']; }, $rows);
        $tags = $this->groupByTask($taskIds);
        $comments = array();
        $filenames = array();
        $currentFileIds = array();
        $subtasks = array();

        // Related prose is required only for text matching. With empty text,
        // do not read comments or filenames merely to build facet dropdowns.
        if ($hasText) foreach (array_chunk($taskIds, 300) as $batch) {
            foreach ($this->db->table('comments')->columns('task_id', 'comment', 'visibility')->in('task_id', $batch)->findAll() as $comment) {
                if (self::canReadComment($comment['visibility'], $this->userSession->getRole())) {
                    $comments[(int) $comment['task_id']][] = (string) $comment['comment'];
                }
            }
            foreach ($this->db->table('task_has_files')->columns('id', 'task_id', 'name')->in('task_id', $batch)->findAll() as $file) {
                $filenames[(int) $file['task_id']][] = (string) $file['name'];
                $currentFileIds[(int) $file['id']] = (int) $file['task_id'];
            }
            foreach ($this->db->table('subtasks')->columns('task_id', 'title')->in('task_id', $batch)->findAll() as $subtask) {
                $subtasks[(int) $subtask['task_id']][] = (string) $subtask['title'];
            }
        }

        // Optional index: read only, and only for a nonempty user query.
        // The indexer is a separate bounded CLI process and may never have run.
        $documentMatches = array();
        $documentIndexAvailable = false;
        if ($hasText && $taskIds && $currentFileIds) {
            $index = new DocumentIndex();
            $documentIndexAvailable = $index->isAvailable();
            if ($documentIndexAvailable) {
                $documentMatches = $index->search($projectId, $query, $taskIds, $currentFileIds);
            }
        }

        $prepared = array();
        foreach ($rows as $task) {
            $id = (int) $task['id'];
            $task['tags'] = isset($tags[$id]) ? $tags[$id] : array();
            $task['comments'] = isset($comments[$id]) ? $comments[$id] : array();
            $task['filenames'] = isset($filenames[$id]) ? $filenames[$id] : array();
            $task['subtasks'] = isset($subtasks[$id]) ? $subtasks[$id] : array();
            $task['assignee'] = trim($task['assignee_name'] ?: $task['assignee_username']);
            $match = self::matchText($task, $query);
            // Keep one result per card but retain every matching origin.
            // This includes multiple attachments linked to the same task.
            if (isset($documentMatches[$id])) {
                $match = self::mergeMatches($match, $documentMatches[$id]);
            }
            if ($match !== null) {
                $task['_match'] = $match;
                $prepared[] = $task;
            }
        }

        $facetKeys = array('swimlane', 'column', 'category', 'tag', 'assignee', 'priority', 'complexity', 'status');
        $facets = array_fill_keys($facetKeys, array());
        $filtered = array();
        foreach ($prepared as $task) {
            foreach ($facetKeys as $field) {
                if (self::matchesFilters($task, $selected, $field)) {
                    foreach (self::facetValues($task, $field) as $value => $label) {
                        if (! isset($facets[$field][$value])) {
                            $facets[$field][$value] = array('value' => (string) $value, 'label' => $label, 'count' => 0);
                        }
                        $facets[$field][$value]['count']++;
                    }
                }
            }
            if (self::matchesFilters($task, $selected)) {
                $filtered[] = $task;
            }
        }

        // Stable pagination: ranking for text, recency + ID for empty text.
        if ($mode !== 'facets') usort($filtered, array(__CLASS__, 'compareRelevance'));
        $total = count($filtered);
        $page = $mode === 'facets' ? array() : array_slice($filtered, $offset, self::MAX_RESULTS);
        $hits = array();
        foreach ($page as $task) {
            $hits[] = array(
                'id' => (int) $task['id'],
                'title' => (string) $task['title'],
                'column' => (string) $task['column_name'],
                'swimlane' => (string) $task['swimlane_name'],
                'category' => (string) $task['category_name'],
                'status' => (int) $task['is_active'],
                'match_field' => $task['_match']['field'],
                'snippet' => $task['_match']['snippet'],
                'matches' => array_slice($task['_match']['matches'], 0, self::MAX_MATCH_SOURCES),
                'match_count' => count($task['_match']['matches']),
                'url' => $this->helper->url->to('TaskViewController', 'show', array('task_id' => (int) $task['id'])),
            );
        }

        foreach ($facetKeys as $field) {
            $facets[$field] = array_values($facets[$field]);
            $facets[$field] = self::sortFacetOptions($facets[$field], $field);
        }

        return array(
            'total' => $total,
            'hits' => $hits,
            'facets' => $facets,
            'truncated' => $truncated,
            'limit' => self::MAX_RESULTS,
            'offset' => $offset,
            'next_offset' => $offset + count($hits),
            'has_more' => $offset + count($hits) < $total,
            'mode' => $mode,
            'scanned' => count($rows),
            'indexed_attachment_contents' => $documentIndexAvailable,
        );
    }

    private function groupByTask(array $taskIds)
    {
        $result = array();
        foreach (array_chunk($taskIds, 300) as $batch) {
            $entries = $this->db->table('tags')
                ->columns('task_has_tags.task_id', 'tags.id', 'tags.name')
                ->join('task_has_tags', 'tag_id', 'id')
                ->in('task_has_tags.task_id', $batch)
                ->findAll();
            foreach ($entries as $entry) {
                $result[(int) $entry['task_id']][(string) $entry['id']] = (string) $entry['name'];
            }
        }
        return $result;
    }

    public static function canReadComment($visibility, $role)
    {
        if ($visibility === Role::APP_USER || $visibility === '' || $visibility === null) {
            return true;
        }
        if ($visibility === Role::APP_MANAGER) {
            return $role === Role::APP_ADMIN || $role === Role::APP_MANAGER;
        }
        if ($visibility === Role::APP_ADMIN) {
            return $role === Role::APP_ADMIN;
        }
        return false;
    }

    private static function normalize($value)
    {
        $value = (string) $value;
        if (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($folded !== false) {
                $value = $folded;
            }
        }
        return strtolower($value);
    }

    /**
     * Require every term across the searchable card fields. The highest-quality
     * matching source determines ranking and the displayed snippet. No document
     * body is read: attachment *filenames* only.
     */
    public static function matchText(array $task, $query)
    {
        $fields = array(
            'id' => array('#'.$task['id'], (string) $task['id'], (string) $task['reference']),
            'title' => array((string) $task['title']),
            'tag' => array_values($task['tags']),
            'description' => array((string) $task['description']),
            'category' => array((string) $task['category_name']),
            'comment' => $task['comments'],
            'subtask' => $task['subtasks'],
            'filename' => $task['filenames'],
            'column' => array((string) $task['column_name']),
            'swimlane' => array((string) $task['swimlane_name']),
            'assignee' => array((string) $task['assignee']),
        );
        $weights = array(
            'id'=>130, 'title'=>120, 'tag'=>110, 'description'=>100,
            'category'=>95, 'comment'=>90, 'subtask'=>85,
            'filename'=>80, 'column'=>75, 'swimlane'=>74, 'assignee'=>73,
        );
        $normalized = trim(self::normalize($query));
        $terms = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        $terms = array_slice($terms ?: array(), 0, 12);
        if (!$terms) {
            return array('field' => '', 'snippet' => '', 'score' => 0, 'matches' => array());
        }
        $joined = '';
        foreach ($fields as $values) {
            foreach ($values as $value) {
                $joined .= ' '.self::normalize($value);
            }
        }
        foreach ($terms as $term) {
            if (strpos($joined, $term) === false) {
                return null;
            }
        }
        $best = array('field' => '', 'snippet' => '', 'score' => 0);
        $allMatches = array();
        $seen = array();
        foreach ($fields as $field => $values) {
            foreach ($values as $value) {
                $folded = self::normalize($value);
                if ($folded === '') continue;
                $matched = 0;
                foreach ($terms as $term) {
                    if (strpos($folded, $term) !== false) $matched++;
                }
                if ($matched === 0) continue;
                // The source hierarchy dominates. Exact/complete phrases receive
                // small tie-breaking bonuses inside their own source tier.
                $score = $weights[$field] * 100 + $matched;
                if ($matched === count($terms)) $score += 4;
                if (strpos($folded, $normalized) !== false) $score += 4;
                $snippet = preg_replace('/\s+/u', ' ', trim((string) $value));
                $snippet = self::truncate($snippet, 170);
                // One source entry for each distinct matched value. Never
                // render user-controlled text as HTML on the client.
                $key = $field."\0".$snippet;
                if (!isset($seen[$key])) {
                    $allMatches[] = array('field' => $field, 'snippet' => $snippet, 'score' => $score);
                    $seen[$key] = true;
                }
                if ($score > $best['score']) {
                    $best = array('field' => $field, 'snippet' => $snippet, 'score' => $score);
                }
            }
        }
        usort($allMatches, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        $best['matches'] = $allMatches;
        return $best;
    }

    /** Combine card and document matches without creating duplicate task hits. */
    public static function mergeMatches($cardMatch, array $documentMatch)
    {
        if ($cardMatch === null) return $documentMatch;
        $all = array_merge(
            isset($cardMatch['matches']) ? $cardMatch['matches'] : array(),
            isset($documentMatch['matches']) ? $documentMatch['matches'] : array()
        );
        usort($all, function ($a, $b) {
            return (int) $b['score'] <=> (int) $a['score'];
        });
        $winner = (int) $documentMatch['score'] > (int) $cardMatch['score'] ? $documentMatch : $cardMatch;
        $winner['matches'] = $all;
        return $winner;
    }

    /** Public comparator, testable without database access. */
    public static function compareRelevance(array $a, array $b)
    {
        $scoreA = isset($a['_match']['score']) ? (int) $a['_match']['score'] : 0;
        $scoreB = isset($b['_match']['score']) ? (int) $b['_match']['score'] : 0;
        if ($scoreA !== $scoreB) return $scoreB <=> $scoreA;
        $dateA = isset($a['date_modification']) ? (int) $a['date_modification'] : 0;
        $dateB = isset($b['date_modification']) ? (int) $b['date_modification'] : 0;
        if ($dateA !== $dateB) return $dateB <=> $dateA;
        return ((int) $b['id']) <=> ((int) $a['id']);
    }

    private static function truncate($text, $max)
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8').'…' : $text;
        }
        return strlen($text) > $max ? substr($text, 0, $max).'…' : $text;
    }

    public static function facetValues(array $task, $field)
    {
        switch ($field) {
            case 'category': return array((string) $task['category_id'] => (string) $task['category_name']);
            case 'column': return array((string) $task['column_id'] => (string) $task['column_name']);
            case 'swimlane': return array((string) $task['swimlane_id'] => (string) $task['swimlane_name']);
            case 'assignee': return array((string) $task['owner_id'] => (string) ($task['assignee'] ?: 'Unassigned'));
            case 'tag': return $task['tags'];
            // Both are native integer columns in Kanboard 1.2.57.
            // Priority 0 is a legitimate project priority; score 0 means
            // complexity was not specified on the task.
            case 'priority':
                $priority = (int) (isset($task['priority']) ? $task['priority'] : 0);
                return array((string) $priority => (string) $priority);
            case 'complexity':
                $score = (int) (isset($task['score']) ? $task['score'] : 0);
                return array((string) $score => $score === 0 ? '0 (Not specified)' : (string) $score);
            case 'status': return array((string) $task['is_active'] => (int) $task['is_active'] === 1 ? 'Open' : 'Closed');
        }
        return array();
    }

    /** Sort numeric refinements numerically (1, 2, 10), others alphabetically. */
    public static function sortFacetOptions(array $items, $field)
    {
        usort($items, function ($a, $b) use ($field) {
            if ($field === 'priority' || $field === 'complexity') {
                return ((int) $a['value']) <=> ((int) $b['value']);
            }
            return strcasecmp($a['label'], $b['label']);
        });
        return $items;
    }

    public static function matchesFilters(array $task, array $filters, $ignore = null)
    {
        foreach ($filters as $field => $value) {
            if ($field === $ignore || $value === '') {
                continue;
            }
            if (! array_key_exists((string) $value, self::facetValues($task, $field))) {
                return false;
            }
        }
        return true;
    }
}
