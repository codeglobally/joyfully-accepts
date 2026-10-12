<?php
// Settings used by the automated tests (tests/run.php). Not for real sites.
const COUPLE_NAMES = 'Alex & Sam';
const WEDDING_DATE = 'Saturday 9 September 2028';
const WEDDING_PLACE = 'The Old Barn, Example Lane, Exampleton, AB1 2CD';
const SITE_URL = 'https://example.com';
const ADMIN_PASSWORD = 'test-admin-password-123';
define('DB_PATH', getenv('WEDDING_TEST_DB') ?: sys_get_temp_dir() . '/wedding-tests-' . getmypid() . '.sqlite');
const MAX_FAILED_ATTEMPTS = 10;
const PHOTO_HOTSPOTS = [['x' => 0.8, 'y' => 0.1, 'w' => 0.1, 'h' => 0.2, 'url' => 'https://example.com/castle', 'title' => 'Castle']];
const MADE_IN = 'Exampleton';
