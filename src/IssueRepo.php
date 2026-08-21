<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Public changelog and roadmap. Operators write via the admin
 * dashboard; users read via /api/issues, which is always GET, never
 * auth-gated, and returns only is_public rows.
 * </summary>
 * <remarks>
 * The "Improvements" section on the About page is the user-facing
 * consumer. Status, severity and kind are clamped to fixed enum
 * vocabularies at write time so a malformed payload cannot poison the
 * column. Versions and free-text fields are length-capped.
 * </remarks>
 */
final class IssueRepo
{
    /**
     * <summary>
     * List issues for public consumption, ordered by status (in
     * progress, then planned, then fixed newest first, then rejected)
     * and then by sort_order and id.
     * </summary>
     * <returns>An array of hydrated issue rows suitable for the About page.</returns>
     */
    public static function listPublic(): array
    {
        $stmt = Db::pdo()->query(
            "SELECT id, kind, title, description, status, severity,
                    target_version, fixed_in_version, sort_order,
                    created_at, updated_at
               FROM issues
              WHERE is_public = 1
              ORDER BY
                  /* Status order: in_progress first, then planned, then
                     fixed (most recent version first), then rejected. */
                  CASE status
                      WHEN 'in_progress' THEN 0
                      WHEN 'planned'     THEN 1
                      WHEN 'fixed'       THEN 2
                      WHEN 'rejected'    THEN 3
                      ELSE 4
                  END,
                  fixed_in_version DESC,
                  sort_order ASC,
                  id ASC"
        );
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    /**
     * <summary>
     * List every issue, including ones not marked public. Admin-only
     * consumer.
     * </summary>
     * <returns>An array of hydrated issue rows ordered by status and sort_order.</returns>
     */
    public static function listAll(): array
    {
        $stmt = Db::pdo()->query(
            "SELECT id, kind, title, description, status, severity,
                    target_version, fixed_in_version, is_public, sort_order,
                    created_at, updated_at
               FROM issues
              ORDER BY status, sort_order ASC, id DESC"
        );
        return array_map([self::class, 'hydrate'], $stmt->fetchAll());
    }

    
    /**
     * <summary>
     * Create a new issue row.
     * </summary>
     * <param name="in">Payload with kind, status, optional severity, title, optional description, optional target_version and fixed_in_version, optional is_public flag and sort_order.</param>
     * <returns>The new issue id.</returns>
     * <exception cref="\RuntimeException">If title is empty or any enum value is out of vocabulary.</exception>
     */
    public static function create(array $in): int
    {
        $pdo = Db::pdo();
        $kind     = self::clampEnum($in['kind']     ?? 'feature', ['feature','improvement','bug']);
        $status   = self::clampEnum($in['status']   ?? 'planned', ['planned','in_progress','fixed','rejected']);
        $severity = isset($in['severity']) && $in['severity'] !== ''
                    ? self::clampEnum($in['severity'], ['low','medium','high','critical'])
                    : null;
        $title    = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($title === '') throw new \RuntimeException('Title is required');

        $pdo->prepare(
            "INSERT INTO issues
                 (kind, title, description, status, severity,
                  target_version, fixed_in_version, is_public, sort_order)
             VALUES (?,?,?,?,?,?,?,?,?)"
        )->execute([
            $kind, $title,
            self::nullableText($in['description']      ?? null),
            $status, $severity,
            self::nullableText($in['target_version']   ?? null, 20),
            self::nullableText($in['fixed_in_version'] ?? null, 20),
            !empty($in['is_public']) ? 1 : 0,
            (int) ($in['sort_order'] ?? 0),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * <summary>
     * Patch an existing issue. Only fields present in the payload are
     * touched.
     * </summary>
     * <param name="id">The issue to update.</param>
     * <param name="in">Any subset of kind, status, severity, title, description, target_version, fixed_in_version, is_public, sort_order.</param>
     * <exception cref="\RuntimeException">If an enum value is out of vocabulary or title is cleared to empty.</exception>
     */
    public static function update(int $id, array $in): void
    {
        $sets = []; $params = [];
        foreach (['kind' => ['feature','improvement','bug'],
                  'status' => ['planned','in_progress','fixed','rejected']] as $col => $allowed) {
            if (array_key_exists($col, $in)) {
                $sets[] = "$col = ?"; $params[] = self::clampEnum($in[$col], $allowed);
            }
        }
        if (array_key_exists('severity', $in)) {
            $sets[] = "severity = ?";
            $params[] = $in['severity'] === null || $in['severity'] === ''
                        ? null
                        : self::clampEnum($in['severity'], ['low','medium','high','critical']);
        }
        if (array_key_exists('title', $in)) {
            $title = mb_substr(trim((string) $in['title']), 0, 160);
            if ($title === '') throw new \RuntimeException('Title cannot be empty');
            $sets[] = "title = ?"; $params[] = $title;
        }
        foreach (['description' => null,
                  'target_version' => 20,
                  'fixed_in_version' => 20] as $col => $cap) {
            if (array_key_exists($col, $in)) {
                $sets[] = "$col = ?"; $params[] = self::nullableText($in[$col], $cap);
            }
        }
        if (array_key_exists('is_public', $in)) {
            $sets[] = "is_public = ?"; $params[] = !empty($in['is_public']) ? 1 : 0;
        }
        if (array_key_exists('sort_order', $in)) {
            $sets[] = "sort_order = ?"; $params[] = (int) $in['sort_order'];
        }
        if (!$sets) return;

        $params[] = $id;
        Db::pdo()->prepare("UPDATE issues SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    /**
     * <summary>
     * Delete an issue by id.
     * </summary>
     * <param name="id">The issue id to delete.</param>
     * <returns>True if a row was deleted, false otherwise.</returns>
     */
    public static function delete(int $id): bool
    {
        $stmt = Db::pdo()->prepare("DELETE FROM issues WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------------------

    /**
     * <summary>
     * Normalise a raw DB row into typed, predictable JSON-friendly
     * values. Casts ints, coerces is_public to a bool when present, and
     * defaults missing timestamps to null.
     * </summary>
     * <param name="r">A raw associative row as returned by PDO.</param>
     * <returns>The hydrated row with typed fields.</returns>
     */
    private static function hydrate(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'kind'             => $r['kind'],
            'title'            => $r['title'],
            'description'      => $r['description'],
            'status'           => $r['status'],
            'severity'         => $r['severity'],
            'target_version'   => $r['target_version'],
            'fixed_in_version' => $r['fixed_in_version'],
            'is_public'        => array_key_exists('is_public', $r) ? (bool) $r['is_public'] : null,
            'sort_order'       => isset($r['sort_order']) ? (int) $r['sort_order'] : 0,
            'created_at'       => $r['created_at'] ?? null,
            'updated_at'       => $r['updated_at'] ?? null,
        ];
    }

    /**
     * <summary>
     * Validate that a value is one of the allowed strings, returning it
     * unchanged.
     * </summary>
     * <param name="v">The candidate value.</param>
     * <param name="allowed">The allowed vocabulary.</param>
     * <returns>The original value if it is valid.</returns>
     * <exception cref="\RuntimeException">If the value is not in the allowed list.</exception>
     */
    private static function clampEnum(string $v, array $allowed): string
    {
        if (!in_array($v, $allowed, true)) {
            throw new \RuntimeException("Invalid value '$v'. Allowed: " . implode(', ', $allowed));
        }
        return $v;
    }

    /**
     * <summary>
     * Trim a free-text value, optionally truncate it to a cap, and
     * return null when the result is empty.
     * </summary>
     * <param name="v">The raw value from the input.</param>
     * <param name="cap">Optional maximum character length to retain.</param>
     * <returns>The cleaned string or null when blank.</returns>
     */
    private static function nullableText($v, ?int $cap = null): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        if ($s === '') return null;
        if ($cap !== null) $s = mb_substr($s, 0, $cap);
        return $s;
    }
}
