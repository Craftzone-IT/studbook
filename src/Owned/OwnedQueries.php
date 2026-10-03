<?php

declare(strict_types=1);

namespace Studbook\Owned;

use PDO;

/** Read queries for collections, boxes and lots. */
final class OwnedQueries
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Collections with the numbers shown on the home page cards.
     *
     * @return list<array<string, mixed>>
     */
    public function collections(): array
    {
        return $this->pdo->query(
            'SELECT c.id, c.name, c.can_lend, c.archived_at,
                (SELECT COUNT(*) FROM owned_set s WHERE s.collection_id = c.id) AS sets,
                (SELECT COALESCE(SUM(l.qty), 0) FROM loose_lot l WHERE l.collection_id = c.id) AS loose_parts,
                (SELECT COUNT(*) FROM loose_lot l WHERE l.collection_id = c.id) AS lots,
                (SELECT COUNT(*) FROM storage b WHERE b.collection_id = c.id) AS boxes,
                GREATEST(c.updated_at,
                    COALESCE((SELECT MAX(b.updated_at) FROM storage b WHERE b.collection_id = c.id), c.updated_at),
                    COALESCE((SELECT MAX(l.updated_at) FROM loose_lot l WHERE l.collection_id = c.id), c.updated_at),
                    COALESCE((SELECT MAX(s.updated_at) FROM owned_set s WHERE s.collection_id = c.id), c.updated_at)
                ) AS last_change
             FROM collection c ORDER BY c.archived_at IS NOT NULL, c.name'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function collection(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM collection WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return list<array<string, mixed>> boxes of a collection, Inbox first */
    public function boxes(int $collectionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT b.*,
                (SELECT COUNT(*) FROM loose_lot l WHERE l.storage_id = b.id) AS lots,
                (SELECT COALESCE(SUM(l.qty), 0) FROM loose_lot l WHERE l.storage_id = b.id) AS parts,
                (SELECT COUNT(*) FROM storage_label sl WHERE sl.storage_id = b.id) AS labels
             FROM storage b WHERE b.collection_id = ?
             ORDER BY b.type = 'inbox' DESC, b.name"
        );
        $stmt->execute([$collectionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null box with its collection name */
    public function box(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT b.*, c.name AS collection_name
             FROM storage b JOIN collection c ON c.id = b.collection_id WHERE b.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    public function inboxId(int $collectionId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM storage WHERE collection_id = ? AND type = 'inbox' ORDER BY id LIMIT 1"
        );
        $stmt->execute([$collectionId]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return list<string> labelled part numbers (Rebrickable) in their order */
    public function labels(int $storageId): array
    {
        $stmt = $this->pdo->prepare('SELECT part FROM storage_label WHERE storage_id = ? ORDER BY position, id');
        $stmt->execute([$storageId]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array<string, mixed>> */
    public function lots(int $storageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.*, p.name AS part_name, p.bl_num, COALESCE(c.bl_name, c.name) AS color_name, c.rgb
             FROM loose_lot l
             LEFT JOIN cat_part p ON p.rb_num = l.part
             LEFT JOIN cat_color c ON c.rb_id = l.color_id
             WHERE l.storage_id = ?
             ORDER BY COALESCE(p.bl_num, l.part), color_name'
        );
        $stmt->execute([$storageId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function lot(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM loose_lot WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Runs a read query with positional parameters (for services that need small lookups).
     *
     * @param list<int|string> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** BrickLink number of a part when known, otherwise the Rebrickable number. */
    public function partDisplay(string $rbNum): string
    {
        $stmt = $this->pdo->prepare('SELECT bl_num FROM cat_part WHERE rb_num = ?');
        $stmt->execute([$rbNum]);
        $bl = $stmt->fetchColumn();

        return is_string($bl) && $bl !== '' ? $bl : $rbNum;
    }

    public function isCollectionEmpty(int $collectionId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM loose_lot WHERE collection_id = ?)
                  + (SELECT COUNT(*) FROM owned_set WHERE collection_id = ?)'
        );
        $stmt->execute([$collectionId, $collectionId]);

        return (int) $stmt->fetchColumn() === 0;
    }
}
