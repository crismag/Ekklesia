<?php

declare(strict_types=1);

namespace App\DTO\Schedules;

use DateTimeImmutable;

final readonly class ScheduleGrid
{
    /**
     * @param list<ScheduleRole> $roles
     * @param list<SchedulePerson> $people
      * @param list<ScheduleAssignment> $assignments
     * @param list<ScheduleOccurrence> $occurrences
     * @param list<string> $warnings
     * @param list<SchedulingEventOption> $schedulingEvents
     * @param list<int> $selectedEventIds
     */
    public function __construct(
        public int $ministryId,
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public array $roles,
        public array $people,
          public array $specialCandidates,
        public array $assignments,
        public array $warnings,
        public array $occurrences = [],
        public array $conflicts = [],
        public string $viewMode = 'compact_grid',
        public array $schedulingEvents = [],
        public array $selectedEventIds = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ministryId' => $this->ministryId,
            'start' => $this->start->format('Y-m-d'),
            'end' => $this->end->format('Y-m-d'),
            'roles' => array_map(fn (ScheduleRole $role): array => [
                'id' => $role->id,
                'name' => $role->name,
            ], $this->roles),
            'people' => array_map(fn (SchedulePerson $person): array => [
                'id' => $person->id,
                'displayName' => $person->displayName,
            ], $this->people),
            'specialCandidates' => array_map(fn (SchedulePerson $person): array => [
                'id' => $person->id,
                'displayName' => $person->displayName,
            ], $this->specialCandidates),
            'occurrences' => array_map(fn (ScheduleOccurrence $o): array => [
                'id' => $o->id,
                'eventId' => $o->eventId,
                'eventTitle' => $o->eventTitle,
                'startsOn' => $o->startsOn->format(DATE_ATOM),
                'endsOn' => $o->endsOn->format(DATE_ATOM),
            ], $this->occurrences),
            'assignments' => array_map(fn (ScheduleAssignment $assignment): array => [
                'id' => $assignment->id,
                'occurrenceId' => $assignment->occurrenceId,
                'personId' => $assignment->personId,
                'roleId' => $assignment->roleId,
                'startsOn' => $assignment->startsOn->format(DATE_ATOM),
                'label' => $assignment->label,
                'displayName' => $assignment->displayName,
            ], $this->assignments),
            'conflicts' => $this->conflicts,
            'warnings' => $this->warnings,
            'viewMode' => $this->viewMode,
            'schedulingEvents' => array_map(
                fn (SchedulingEventOption $event): array => $event->toArray(),
                $this->schedulingEvents,
            ),
            'selectedEventIds' => array_values(array_map('intval', $this->selectedEventIds)),
        ];
    }
}

