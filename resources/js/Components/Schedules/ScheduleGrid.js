import React from 'react';

export function ScheduleGrid({ grid, onCellClick }) {
  const assignments = grid.assignments || [];
  const peopleById = new Map((grid.people || []).map((person) => [person.id, person]));

  return React.createElement(
    'section',
    { className: 'schedule-grid', 'data-view-mode': grid.viewMode || 'compact_grid' },
    React.createElement(
      'header',
      { className: 'schedule-grid__header' },
      React.createElement('h1', null, 'Schedule'),
      React.createElement('span', { className: 'schedule-grid__range' }, `${grid.start} to ${grid.end}`),
    ),
    React.createElement(
      'div',
      { className: 'schedule-grid__table', role: 'grid' },
      React.createElement(
        'div',
        { className: 'schedule-grid__row schedule-grid__row--head', role: 'row' },
        React.createElement('div', { role: 'columnheader' }, 'Role'),
        React.createElement('div', { role: 'columnheader' }, 'Assignment'),
        React.createElement('div', { role: 'columnheader' }, 'Date'),
      ),
      assignments.map((assignment) => {
        const person = peopleById.get(assignment.personId);
        return React.createElement(
          'button',
          {
            key: `${assignment.roleId}-${assignment.personId}-${assignment.startsOn}`,
            type: 'button',
            className: 'schedule-grid__row schedule-grid__row--editable',
            role: 'row',
            onClick: () => onCellClick?.(assignment),
          },
          React.createElement('span', { role: 'gridcell' }, assignment.roleId),
          React.createElement('span', { role: 'gridcell' }, person?.displayName || 'Unassigned'),
          React.createElement('span', { role: 'gridcell' }, assignment.startsOn),
        );
      }),
    ),
    (grid.warnings || []).map((warning) =>
      React.createElement('p', { key: warning, className: 'schedule-grid__warning' }, warning),
    ),
  );
}

