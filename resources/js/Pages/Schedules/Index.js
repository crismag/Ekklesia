import React, { useState } from 'react';
import { ScheduleGrid } from '../../Components/Schedules/ScheduleGrid.js';

export default function ScheduleIndex({ initialGrid }) {
  const [selectedAssignment, setSelectedAssignment] = useState(null);

  return React.createElement(
    'main',
    { className: 'portal-shell' },
    React.createElement(ScheduleGrid, {
      grid: initialGrid,
      onCellClick: setSelectedAssignment,
    }),
    selectedAssignment
      ? React.createElement(
          'aside',
          { className: 'assignment-panel' },
          React.createElement('h2', null, 'Assignment'),
          React.createElement('p', null, selectedAssignment.label || 'Ready to edit'),
        )
      : null,
  );
}

