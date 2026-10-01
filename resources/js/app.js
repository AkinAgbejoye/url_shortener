import './bootstrap';
import { initApiKeys } from './apiKeys';
import { initDashboard } from './dashboard';
import { initShortener } from './shortener';
import { initTheme } from './theme';

initTheme();
initShortener();
initDashboard();
initApiKeys();
