<?php

namespace Ikabalzam\LaravelVision\Support;

/**
 * Represents the outcome of a table resolution attempt.
 *
 * During analysis, the auditor needs to determine which database table a column
 * reference belongs to. This can have three outcomes:
 *
 * 1. RESOLVED: We identified the table -> validate the column against it
 * 2. SKIP: The reference is on a Collection, not a Query Builder -> ignore entirely
 * 3. UNRESOLVED: We couldn't determine the table -> track as unresolved reference
 */
enum ResolutionStatus
{
    /** Successfully resolved to a database table. */
    case Resolved;

    /** Explicitly skipped — the reference is on a Collection, not a database query. */
    case Skip;

    /** Could not determine the table — dynamic class name, complex expression, etc. */
    case Unresolved;
}

/**
 * Result of attempting to resolve which database table a column reference targets.
 *
 * Usage:
 *   $result = ResolutionResult::resolved('users');
 *   $result = ResolutionResult::skip();
 *   $result = ResolutionResult::unresolved();
 *
 *   if ($result->isResolved()) {
 *       $table = $result->table; // 'users'
 *   }
 */
final class ResolutionResult
{
    public function __construct(
        public readonly ResolutionStatus $status,
        public readonly ?string $table = null,
    ) {}

    public static function resolved(string $table): self
    {
        return new self(ResolutionStatus::Resolved, $table);
    }

    public static function skip(): self
    {
        return new self(ResolutionStatus::Skip);
    }

    public static function unresolved(): self
    {
        return new self(ResolutionStatus::Unresolved);
    }

    public function isResolved(): bool
    {
        return $this->status === ResolutionStatus::Resolved;
    }

    public function isSkip(): bool
    {
        return $this->status === ResolutionStatus::Skip;
    }

    public function isUnresolved(): bool
    {
        return $this->status === ResolutionStatus::Unresolved;
    }
}
