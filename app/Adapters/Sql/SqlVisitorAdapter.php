<?php

declare(strict_types=1);

namespace App\Adapters\Sql;

use App\Contracts\VisitorAdapter;
use PDO;
use Throwable;

/**
 * The visitors database (SQLite). Same tables and the same writes as the
 * standalone sign-up and RSVP modules, so either can pick up where the other
 * left off.
 */
final class SqlVisitorAdapter implements VisitorAdapter
{
    public function __construct(private readonly PDO $db) {}

    public function countRegistrationsByStatus(): array
    {
        $out = ['new' => 0, 'reviewed' => 0, 'duplicate' => 0, 'promoted' => 0, 'rejected' => 0];
        foreach ($this->db->query('SELECT status, COUNT(*) AS n FROM visitor_registrations GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return array{0:string,1:array<string,mixed>} */
    private function where(?string $status, string $search): array
    {
        $conds = [];
        $params = [];
        if ($status !== null) {
            $conds[] = 'status = :status';
            $params[':status'] = $status;
        }
        $search = trim($search);
        if ($search !== '') {
            $like = static fn (string $expr): string => $expr . " LIKE :q ESCAPE '\\'";
            $conds[] = '(' . implode(' OR ', [
                $like("LOWER(first_name || ' ' || last_name)"),
                $like("LOWER(COALESCE(preferred_name, ''))"),
                $like("LOWER(COALESCE(email, ''))"),
                $like("COALESCE(phone, '')"),
                $like("LOWER(COALESCE(city, ''))"),
            ]) . ')';
            $params[':q'] = '%' . strtolower(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search)) . '%';
        }

        return [$conds === [] ? '' : ' WHERE ' . implode(' AND ', $conds), $params];
    }

    public function listRegistrations(?string $status, string $search, int $limit, int $offset): array
    {
        [$where, $params] = $this->where($status, $search);
        $stmt = $this->db->prepare(
            'SELECT * FROM visitor_registrations' . $where . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function countRegistrations(?string $status, string $search): int
    {
        [$where, $params] = $this->where($status, $search);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM visitor_registrations' . $where);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    public function findRegistration(int $registrationId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM visitor_registrations WHERE id = :id');
        $stmt->execute([':id' => $registrationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function registrationCandidates(string $lastName, string $email, int $excludeId): array
    {
        $conds = [];
        $params = [':ex' => $excludeId];
        if ($lastName !== '') {
            $conds[] = 'LOWER(last_name) = :last';
            $params[':last'] = $lastName;
        }
        if ($email !== '') {
            $conds[] = 'LOWER(email) = :email';
            $params[':email'] = $email;
        }
        if ($conds === []) {
            return [];
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM visitor_registrations
              WHERE status IN ('new', 'reviewed', 'duplicate') AND id <> :ex
                AND (" . implode(' OR ', $conds) . ')
              ORDER BY created_at DESC LIMIT 100'
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function setRegistrationStatus(int $registrationId, string $status, string $now): void
    {
        $reviewed = $status !== 'new' ? ', reviewed_at = :now' : '';
        $this->db->prepare("UPDATE visitor_registrations SET status = :status, updated_at = :now{$reviewed} WHERE id = :id")
            ->execute([':status' => $status, ':now' => $now, ':id' => $registrationId]);
    }

    public function setReviewerNotes(int $registrationId, ?string $notes, string $now): void
    {
        $this->db->prepare('UPDATE visitor_registrations SET reviewer_notes = :notes, updated_at = :now WHERE id = :id')
            ->execute([':notes' => $notes, ':now' => $now, ':id' => $registrationId]);
    }

    public function recordPromotion(int $registrationId, int $personId, string $outcome, ?int $accountId, ?string $notes, string $now): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                "UPDATE visitor_registrations
                    SET status = 'promoted', matched_person_id = :person_id, reviewed_at = :now, updated_at = :now
                  WHERE id = :id"
            )->execute([':person_id' => $personId, ':now' => $now, ':id' => $registrationId]);
            $this->db->prepare(
                'INSERT INTO visitor_promotions
                    (visitor_registration_id, person_id, outcome, promoted_by_account_id, promoted_at, notes)
                 VALUES (:id, :person_id, :outcome, :account, :now, :notes)'
            )->execute([
                ':id' => $registrationId, ':person_id' => $personId, ':outcome' => $outcome,
                ':account' => $accountId, ':now' => $now, ':notes' => $notes,
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function promotionsFor(int $registrationId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM visitor_promotions WHERE visitor_registration_id = :id ORDER BY id DESC');
        $stmt->execute([':id' => $registrationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function rsvpsForRegistration(int $registrationId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM visitor_rsvps WHERE visitor_registration_id = :id ORDER BY created_at DESC');
        $stmt->execute([':id' => $registrationId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function rsvpOccasions(): array
    {
        return $this->db->query(
            'SELECT event_id, occurrence_id, COUNT(*) AS responses, MAX(created_at) AS last_response_at
               FROM visitor_rsvps
           GROUP BY event_id, occurrence_id
           ORDER BY last_response_at DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function rsvpsFor(int $eventId, ?int $occurrenceId): array
    {
        $sql = 'SELECT * FROM visitor_rsvps WHERE event_id = :event AND '
            . ($occurrenceId === null ? 'occurrence_id IS NULL' : 'occurrence_id = :occurrence')
            . ' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE, id';
        $stmt = $this->db->prepare($sql);
        $params = [':event' => $eventId];
        if ($occurrenceId !== null) {
            $params[':occurrence'] = $occurrenceId;
        }
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findRsvp(int $rsvpId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM visitor_rsvps WHERE id = :id');
        $stmt->execute([':id' => $rsvpId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function setRsvpAttendance(int $rsvpId, string $attendance, string $now): void
    {
        $this->db->prepare('UPDATE visitor_rsvps SET attendance = :attendance, updated_at = :now WHERE id = :id')
            ->execute([':attendance' => $attendance, ':now' => $now, ':id' => $rsvpId]);
    }

    public function latestAccessCode(string $module): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM visitor_admin_access_codes WHERE module = :module ORDER BY id DESC LIMIT 1');
        $stmt->execute([':module' => $module]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertAccessCode(string $module, string $code, string $issuedAt, string $expiresAt, ?string $note): void
    {
        $this->db->prepare(
            'INSERT INTO visitor_admin_access_codes (module, code, issued_at, expires_at, note)
             VALUES (:module, :code, :issued, :expires, :note)'
        )->execute([':module' => $module, ':code' => $code, ':issued' => $issuedAt, ':expires' => $expiresAt, ':note' => $note]);
    }
}
