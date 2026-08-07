<?php

declare(strict_types=1);

final class ClinicalService
{
    public static function notes(array $actor, ?int $customerId = null): array
    {
        Authorization::require($actor, 'clinical.view_all');
        $where = $customerId ? 'WHERE n.customer_id = ?' : '';
        $params = $customerId ? [$customerId] : [];

        return DB::fetchAll(
            'SELECT n.*, c.name AS customer_name, f.name AS consultant_name, creator.name AS created_by_name
             FROM clinical_notes n INNER JOIN users c ON c.id = n.customer_id
             INNER JOIN users f ON f.id = n.consultant_id INNER JOIN users creator ON creator.id = n.created_by
             ' . $where . ' ORDER BY n.id DESC LIMIT 300',
            $params
        );
    }

    public static function createNote(array $actor, array $data): array
    {
        Authorization::require($actor, 'clinical.create');
        $customerId = (int) ($data['customer_id'] ?? 0);
        $consultantId = $actor['role'] === 'consultant' ? (int) $actor['id'] : (int) ($data['consultant_id'] ?? 0);
        self::assertUsers($customerId, $consultantId);
        $type = (string) ($data['note_type'] ?? 'treatment');
        $visibility = (string) ($data['visibility'] ?? 'internal');
        if (!in_array($type, ['assessment', 'treatment', 'progress', 'discharge'], true)
            || !in_array($visibility, ['internal', 'customer_shared'], true)) {
            throw new RuntimeException('Fizyoterapi notu türü veya görünürlüğü geçersiz.');
        }
        $fields = ['subjective', 'objective', 'assessment', 'plan'];
        if (trim(implode('', array_map(static fn ($key): string => (string) ($data[$key] ?? ''), $fields))) === '') {
            throw new RuntimeException('Fizyoterapi notu boş bırakılamaz.');
        }
        $reservationId = !empty($data['reservation_id']) ? (int) $data['reservation_id'] : null;
        if ($reservationId && !DB::fetch('SELECT id FROM reservations WHERE id = ? AND customer_id = ? AND consultant_id = ?', [$reservationId, $customerId, $consultantId])) {
            throw new RuntimeException('Seçilen rezervasyon danışan ve fizyoterapistle eşleşmiyor.');
        }

        $id = DB::insert(
            'INSERT INTO clinical_notes (customer_id, consultant_id, reservation_id, note_type, subjective, objective, assessment, plan, visibility, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [$customerId, $consultantId, $reservationId, $type, trim((string) ($data['subjective'] ?? '')), trim((string) ($data['objective'] ?? '')), trim((string) ($data['assessment'] ?? '')), trim((string) ($data['plan'] ?? '')), $visibility, $actor['id']]
        );
        Audit::record((int) $actor['id'], 'clinical_note.created', 'clinical_note', $id, ['customer_id' => $customerId, 'visibility' => $visibility]);

        return (array) DB::fetch('SELECT * FROM clinical_notes WHERE id = ?', [$id]);
    }

    public static function addHistory(array $actor, array $data): array
    {
        Authorization::require($actor, 'clinical.create');
        $customerId = (int) ($data['customer_id'] ?? 0);
        if (!DB::fetch('SELECT id FROM users WHERE id = ? AND role = "customer"', [$customerId])) {
            throw new RuntimeException('Danışan bulunamadı.');
        }
        $type = (string) ($data['history_type'] ?? 'other');
        $title = trim((string) ($data['title'] ?? ''));
        if (!in_array($type, ['injury', 'condition', 'allergy', 'goal', 'other'], true) || $title === '') {
            throw new RuntimeException('Geçmiş kaydı türü ve başlığı zorunludur.');
        }
        $id = DB::insert(
            'INSERT INTO patient_history (customer_id, history_type, title, details, event_date, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$customerId, $type, $title, trim((string) ($data['details'] ?? '')), ($data['event_date'] ?? '') ?: null, $actor['id']]
        );
        Audit::record((int) $actor['id'], 'patient_history.created', 'patient_history', $id, ['customer_id' => $customerId]);

        return (array) DB::fetch('SELECT * FROM patient_history WHERE id = ?', [$id]);
    }

    public static function createExercise(array $actor, array $data): array
    {
        Authorization::require($actor, 'exercises.manage');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Egzersiz adı zorunludur.');
        }
        $id = DB::insert(
            'INSERT INTO exercise_library (name, description, instructions, active, created_by, created_at) VALUES (?, ?, ?, 1, ?, NOW())',
            [$name, trim((string) ($data['description'] ?? '')), trim((string) ($data['instructions'] ?? '')), $actor['id']]
        );
        Audit::record((int) $actor['id'], 'exercise.created', 'exercise', $id);

        return (array) DB::fetch('SELECT * FROM exercise_library WHERE id = ?', [$id]);
    }

    public static function createProgram(array $actor, array $data): array
    {
        Authorization::require($actor, 'exercises.manage');
        $customerId = (int) ($data['customer_id'] ?? 0);
        $exerciseId = (int) ($data['exercise_id'] ?? 0);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || !DB::fetch('SELECT id FROM users WHERE id = ? AND role = "customer"', [$customerId])
            || !DB::fetch('SELECT id FROM exercise_library WHERE id = ? AND active = 1', [$exerciseId])) {
            throw new RuntimeException('Program başlığı, danışan ve egzersiz seçimi zorunludur.');
        }
        $startsAt = ($data['starts_at'] ?? '') ?: date('Y-m-d');
        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['draft', 'active'], true)) {
            $status = 'active';
        }

        DB::pdo()->beginTransaction();
        try {
            $id = DB::insert(
                'INSERT INTO exercise_programs (customer_id, title, notes, starts_at, expires_at, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())',
                [$customerId, $title, trim((string) ($data['notes'] ?? '')), $startsAt, ($data['expires_at'] ?? '') ?: null, $status, $actor['id']]
            );
            DB::insert(
                'INSERT INTO exercise_program_items (program_id, exercise_id, sets_count, repetitions, hold_seconds, frequency_text, instructions, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, 10)',
                [$id, $exerciseId, ($data['sets_count'] ?? '') !== '' ? (int) $data['sets_count'] : null, trim((string) ($data['repetitions'] ?? '')), ($data['hold_seconds'] ?? '') !== '' ? (int) $data['hold_seconds'] : null, trim((string) ($data['frequency_text'] ?? '')), trim((string) ($data['item_instructions'] ?? ''))]
            );
            Audit::record((int) $actor['id'], 'exercise_program.created', 'exercise_program', $id, ['customer_id' => $customerId]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        return (array) DB::fetch('SELECT * FROM exercise_programs WHERE id = ?', [$id]);
    }

    public static function addProgramItem(array $actor, array $data): void
    {
        Authorization::require($actor, 'exercises.manage');
        $programId = (int) ($data['program_id'] ?? 0);
        $exerciseId = (int) ($data['exercise_id'] ?? 0);
        if (!DB::fetch('SELECT id FROM exercise_programs WHERE id = ?', [$programId])
            || !DB::fetch('SELECT id FROM exercise_library WHERE id = ? AND active = 1', [$exerciseId])) {
            throw new RuntimeException('Program veya egzersiz bulunamadı.');
        }
        $sort = DB::fetch('SELECT COALESCE(MAX(sort_order), 0) + 10 AS next_sort FROM exercise_program_items WHERE program_id = ?', [$programId]);
        $id = DB::insert(
            'INSERT INTO exercise_program_items (program_id, exercise_id, sets_count, repetitions, hold_seconds, frequency_text, instructions, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$programId, $exerciseId, ($data['sets_count'] ?? '') !== '' ? (int) $data['sets_count'] : null, trim((string) ($data['repetitions'] ?? '')), ($data['hold_seconds'] ?? '') !== '' ? (int) $data['hold_seconds'] : null, trim((string) ($data['frequency_text'] ?? '')), trim((string) ($data['item_instructions'] ?? '')), (int) ($sort['next_sort'] ?? 10)]
        );
        Audit::record((int) $actor['id'], 'exercise_program.item_added', 'exercise_program_item', $id, ['program_id' => $programId]);
    }

    public static function programsForCustomer(int $customerId): array
    {
        return DB::fetchAll(
            'SELECT ep.*, e.name AS exercise_name, e.description, e.instructions AS exercise_instructions,
                    epi.sets_count, epi.repetitions, epi.hold_seconds, epi.frequency_text, epi.instructions AS item_instructions
             FROM exercise_programs ep INNER JOIN exercise_program_items epi ON epi.program_id = ep.id
             INNER JOIN exercise_library e ON e.id = epi.exercise_id
             WHERE ep.customer_id = ? AND ep.status = "active" ORDER BY ep.starts_at DESC, epi.sort_order, epi.id',
            [$customerId]
        );
    }

    public static function sharedNotesForCustomer(int $customerId): array
    {
        return DB::fetchAll(
            'SELECT n.note_type, n.assessment, n.plan, n.created_at, u.name AS consultant_name
             FROM clinical_notes n INNER JOIN users u ON u.id = n.consultant_id
             WHERE n.customer_id = ? AND n.visibility = "customer_shared" ORDER BY n.id DESC',
            [$customerId]
        );
    }

    private static function assertUsers(int $customerId, int $consultantId): void
    {
        $customer = DB::fetch('SELECT id FROM users WHERE id = ? AND role = "customer" AND status = "active"', [$customerId]);
        $consultant = DB::fetch('SELECT id FROM users WHERE id = ? AND role = "consultant" AND status = "active"', [$consultantId]);
        if (!$customer || !$consultant) {
            throw new RuntimeException('Aktif danışan veya fizyoterapist bulunamadı.');
        }
    }
}
