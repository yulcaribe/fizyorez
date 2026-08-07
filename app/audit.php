<?php

declare(strict_types=1);

final class Audit
{
    public static function record(?int $actorId, string $action, string $entityType, ?int $entityId = null, array $details = []): void
    {
        DB::insert(
            'INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, details_json, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [
                $actorId,
                $action,
                $entityType,
                $entityId,
                $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ]
        );
    }
}
