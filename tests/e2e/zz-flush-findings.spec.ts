import { test } from '@playwright/test';
import { writeFindingsReport } from './helpers/findings.js';

test('flush findings report', () => {
  writeFindingsReport();
});
