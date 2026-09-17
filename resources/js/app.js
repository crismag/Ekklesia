import React from 'react';
import { createRoot } from 'react-dom/client';
import ScheduleIndex from './Pages/Schedules/Index.js';
import '../css/app.css';

const rootElement = document.getElementById('app');

if (rootElement) {
  const initialGrid = JSON.parse(rootElement.dataset.initialGrid || '{}');
  createRoot(rootElement).render(React.createElement(ScheduleIndex, { initialGrid }));
}

