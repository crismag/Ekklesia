export const PUBLIC_HTML_ROUTES: string[] = [
  '/',
  '/login',
  '/events',
  '/ministries',
  '/people',
  '/calendar',
  '/docs',
  '/printables',
  '/printables/events',
  '/printables/birthdays',
  '/printables/schedules',
];

export const SAMPLE_DETAIL_ROUTES: string[] = [
  '/events/1',
  '/ministries/1',
  '/people/1',
  '/docs/navigation',
];

/** GET HTML that currently renders without a session. API writes stay gated. */
export const UNAUTHENTICATED_HTML_RENDERS: string[] = [
  '/admin/event-types',
  '/my-schedule',
  '/availability',
  '/account',
  '/admin',
  '/schedules',
  '/rosters',
  '/password/change',
];

export const PUBLIC_API_GET_ROUTES: string[] = [
  '/api/public/people-directory',
  '/api/public/ministries',
  '/api/public/events',
  '/api/hero',
  '/api/chrome',
  '/api/theme',
];

export const AUTH_REQUIRED_GET_API: string[] = [
  '/api/me',
  '/api/account',
  '/api/rosters',
  '/api/my-schedule',
  '/api/people-directory',
  '/api/schedules/grid',
];

export type ProtectedMutation = {
  method: 'POST' | 'DELETE' | 'PATCH';
  path: string;
  body?: Record<string, unknown>;
};

export const PROTECTED_MUTATIONS: ProtectedMutation[] = [
  { method: 'POST', path: '/api/schedules/assignments', body: { ministryId: 1, assignments: [] } },
  { method: 'POST', path: '/api/events', body: { title: 'regression-should-not-create' } },
  { method: 'POST', path: '/admin/event-types', body: { action: 'delete', event_type_id: 1 } },
  { method: 'POST', path: '/api/hero', body: { slides: [] } },
  { method: 'POST', path: '/api/chrome', body: { header: {} } },
  { method: 'POST', path: '/api/theme/active', body: { active: 'forest' } },
  { method: 'POST', path: '/api/rosters', body: { title: 'regression-should-not-create' } },
  { method: 'DELETE', path: '/api/rosters/1' },
  { method: 'POST', path: '/api/availability', body: { personId: 1, startsOn: '2099-01-01', endsOn: '2099-01-02' } },
  { method: 'DELETE', path: '/api/availability/1' },
  { method: 'POST', path: '/api/auth/password', body: { currentPassword: 'x', newPassword: 'y' } },
  { method: 'POST', path: '/admin/people/delete', body: { id: 1 } },
  { method: 'POST', path: '/admin/people/import/ingest', body: { campus_id: 1 } },
  { method: 'POST', path: '/admin/people/import/apply', body: { confirm: '1', batch_id: 1 } },
  { method: 'POST', path: '/admin/people/import/row', body: { id: 1, field: 'notes', value: 'x' } },
  { method: 'POST', path: '/admin/people/import/discard', body: { batch_id: 1 } },
  { method: 'POST', path: '/admin/maintenance/import/ingest', body: { campus_id: 1 } },
  { method: 'POST', path: '/admin/maintenance/import/apply', body: { confirm: '1', batch_id: 1 } },
  { method: 'POST', path: '/admin/maintenance/backup', body: { kind: 'mysql', target: 'people' } },
  { method: 'POST', path: '/admin/maintenance/export-xlsx', body: { campus_id: 1 } },
  { method: 'POST', path: '/admin/users', body: { action: 'delete', user_id: 1 } },
  { method: 'POST', path: '/admin/campuses', body: { action: 'delete', campus_id: 1 } },
  { method: 'POST', path: '/admin/families/delete', body: { id: 1 } },
  { method: 'POST', path: '/admin/families/merge', body: { keep_id: 1, merge_ids: [2] } },
  { method: 'POST', path: '/admin/church-info', body: { church_name: 'regression' } },
];
