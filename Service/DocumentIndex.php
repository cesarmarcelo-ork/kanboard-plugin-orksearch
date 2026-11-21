<?php

namespace Kanboard\Plugin\OrkSearch\Service;

use PDO;
use Throwable;

/**
 * Optional, read-only access to an external, incrementally built FTS5 index.
 * Never extracts a file or writes to Kanboard while serving HTTP requests.
 * Failure to open/read this index must not interrupt ordinary card search.
 */
class DocumentIndex
{
    const MAX_FTS_HITS = 2500;
    const MAX_MATCHES_PER_TASK = 40;

    private $path;
    private $available = false;

    public function __construct($path = null)
    {
        $base = defined('DATA_DIR') ? DATA_DIR : dirname(__DIR__, 3).'/data';
        $this->path = $path ?: rtrim($base, '/').'/orkflow_smartsearch/documents.sqlite';
    }

    /** Checking availability doesn't open databases on the initial UI view. */
    public function isAvailable()
    {
        return is_file($this->path) && class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    /**
     * A safe FTS5 query: no arbitrary MATCH operators/column directives can be
     * injected by users. All terms are quoted and prefix matching is bounded.
     */
    public static function makeMatchExpression($query)
    {
        preg_match_all('/[\p{L}\p{N}_]+/u', (string) $query, $matches);
        $words = array_slice($matches[0], 0, 12);
        $terms = array();
        foreach ($words as $word) {
            if (strlen($word) > 64) $word = substr($word, 0, 64);
            $word = str_replace('"', '""', $word);
            $terms[] = '"'.$word.'"*';
        }
        return implode(' AND ', $terms);
    }

    /**
     * @param int $projectId The currently authorized project.
     * @param string $query User text.
     * @param array $allowedTasks Tasks in current, bounded Kanboard search.
     * @param array $allowedFiles Current task_has_files file_id => task_id map.
     * @return array task_id => match details, never from removed/moved files.
     */
    public function search($projectId, $query, array $allowedTasks, array $allowedFiles)
    {
        if (! $this->isAvailable() || ! $allowedTasks || ! $allowedFiles) return array();
        $expression = self::makeMatchExpression($query);
        if ($expression === '') return array();
        $taskSet = array_fill_keys(array_map('intval', $allowedTasks), true);
        $results = array();
        try {
            // mode=ro: the web app never creates sidecar database or journal.
            $pdo = new PDO('sqlite:file:'.$this->path.'?mode=ro', null, null, array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 1,
            ));
            $pdo->exec('PRAGMA query_only=ON');
            $sql = 'SELECT d.file_id, d.task_id, d.name, '
                 . "snippet(document_fts, 0, '', '', ' … ', 12) AS excerpt "
                 . 'FROM document_fts JOIN documents d ON d.file_id = document_fts.rowid '
                 . "WHERE document_fts MATCH :q AND d.project_id = :project AND d.state = 'indexed' "
                 . 'LIMIT '.self::MAX_FTS_HITS;
            $statement = $pdo->prepare($sql);
            $statement->bindValue(':q', $expression, PDO::PARAM_STR);
            $statement->bindValue(':project', (int) $projectId, PDO::PARAM_INT);
            $statement->execute();
            while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
                $fileId = (int) $row['file_id'];
                $taskId = (int) $row['task_id'];
                // Explicit current-file check: deleted attachments are invisible
                // immediately, even before the next incremental prune cycle.
                if (! isset($taskSet[$taskId]) || ! isset($allowedFiles[$fileId]) ||
                    (int) $allowedFiles[$fileId] !== $taskId) continue;
                $filename = trim((string) $row['name']);
                $excerpt = preg_replace('/\s+/u', ' ', trim((string) $row['excerpt']));
                $snippet = self::truncate($excerpt, 170);
                if (!isset($results[$taskId])) {
                    $results[$taskId] = array(
                        'field' => 'attachment_content',
                        'snippet' => self::truncate($filename.': '.$excerpt, 170),
                        'score' => 6900,
                        'matches' => array(),
                    );
                }
                // Bound the response size. A single task may have many files.
                if (count($results[$taskId]['matches']) < self::MAX_MATCHES_PER_TASK) {
                    $results[$taskId]['matches'][] = array(
                        'field' => 'attachment_content',
                        'file_id' => $fileId,
                        'filename' => $filename,
                        'snippet' => $snippet,
                        'score' => 6900,
                    );
                }
            }
        } catch (Throwable $e) {
            // Fail open for search usability, not for authorization: the normal
            // Kanboard search still runs, but no document excerpts are emitted.
            return array();
        }
        return $results;
    }

    private static function truncate($text, $max)
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8').'…' : $text;
        }
        return strlen($text) > $max ? substr($text, 0, $max).'…' : $text;
    }
}
