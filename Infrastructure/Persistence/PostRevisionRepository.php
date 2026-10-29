<?php

declare(strict_types=1);

namespace Plugins\Posts\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Posts\Domain\Entities\PostRevision;

/**
 * PostRevisionRepository — DatabasePort ONLY. Owns `post_revisions`.
 *
 * Revision numbers are `MAX(number) + 1` per post, read inside the service's
 * transaction. Two editors saving one post at the same instant can still pick
 * the same number; the unique (post_id, number) index turns that into a failed
 * save the service reports, rather than two revisions claiming one number.
 */
final class PostRevisionRepository
{
    private const string TABLE = 'post_revisions';

    public function __construct(
        private readonly DatabasePort $db,
    ) {
    }

    /** Append a snapshot; returns its number. */
    public function append(string $postId, array $snapshot, ?string $note, ?string $actorId, \DateTimeImmutable $now): int
    {
        try {
            $row    = $this->db->queryOne('SELECT MAX(number) AS latest FROM ' . self::TABLE . ' WHERE post_id = :post_id', ['post_id' => $postId]);
            $number = (int) ($row === null ? 0 : (array_values($row)[0] ?? 0)) + 1;

            $this->db->execute(
                'INSERT INTO ' . self::TABLE . ' (post_id, number, snapshot, note, created_by, created_at)
                 VALUES (:post_id, :number, :snapshot, :note, :created_by, :created_at)',
                [
                    'post_id'    => $postId,
                    'number'     => $number,
                    'snapshot'   => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'note'       => $note,
                    'created_by' => $actorId,
                    'created_at' => PostRepository::formatInstant($now),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to save revision.', layer: 'repository.post_revisions', context: ['postId' => $postId], previous: $e);
        }

        return $number;
    }

    /**
     * Drop all but the newest `$keep` revisions of a post. The cutoff is read
     * first and the delete keyed on it, so no driver-specific
     * DELETE … ORDER BY … LIMIT is needed.
     */
    public function prune(string $postId, int $keep): void
    {
        if ($keep < 1) {
            return;
        }

        try {
            $rows = $this->db->query(
                'SELECT number FROM ' . self::TABLE . ' WHERE post_id = :post_id ORDER BY number DESC LIMIT 1 OFFSET ' . ($keep - 1),
                ['post_id' => $postId],
            );
            if ($rows === []) {
                return;
            }

            $this->db->execute(
                'DELETE FROM ' . self::TABLE . ' WHERE post_id = :post_id AND number < :cutoff',
                ['post_id' => $postId, 'cutoff' => (int) array_values($rows)[0]['number']],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to prune revisions.', layer: 'repository.post_revisions', context: ['postId' => $postId], previous: $e);
        }
    }

    /** @return list<PostRevision> Newest first, without their snapshots' bodies trimmed — callers decide. */
    public function forPost(string $postId, int $limit): array
    {
        try {
            $rows = $this->db->query(
                'SELECT post_id, number, snapshot, note, created_by, created_at FROM ' . self::TABLE . '
                 WHERE post_id = :post_id ORDER BY number DESC LIMIT ' . max(1, min($limit, 500)),
                ['post_id' => $postId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list revisions.', layer: 'repository.post_revisions', context: ['postId' => $postId], previous: $e);
        }

        return array_map(self::hydrate(...), array_values($rows));
    }

    public function find(string $postId, int $number): ?PostRevision
    {
        try {
            $row = $this->db->queryOne(
                'SELECT post_id, number, snapshot, note, created_by, created_at FROM ' . self::TABLE . '
                 WHERE post_id = :post_id AND number = :number',
                ['post_id' => $postId, 'number' => $number],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load revision.', layer: 'repository.post_revisions', context: ['postId' => $postId], previous: $e);
        }

        return $row === null ? null : self::hydrate($row);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): PostRevision
    {
        $snapshot = json_decode((string) ($row['snapshot'] ?? '[]'), true);

        return new PostRevision(
            postId: (string) $row['post_id'],
            number: (int) $row['number'],
            snapshot: is_array($snapshot) ? $snapshot : [],
            note: ($row['note'] ?? '') === '' ? null : (string) $row['note'],
            createdBy: ($row['created_by'] ?? '') === '' ? null : (string) $row['created_by'],
            createdAt: new \DateTimeImmutable((string) $row['created_at'], new \DateTimeZone('UTC')),
        );
    }
}
