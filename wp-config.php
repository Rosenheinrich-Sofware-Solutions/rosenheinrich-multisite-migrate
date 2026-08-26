<?php
define('DB_NAME', 'wordpress_test');
define('DB_USER', 'test_user');
define('DB_PASSWORD', 'test_pass');
define('DB_HOST', '127.0.0.1:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('AUTH_KEY', '62a171d20f35a771d1dffd5c765419bdef73de2b5a7a7f3817f615d329a5cf45');
define('SECURE_AUTH_KEY', '328c38ed3fb4224a888300fa8a381764f8c786d74d09bf82b0b91e4fbb4ff0f4');
define('LOGGED_IN_KEY', '5dac0d55b4c017f869e5b68c3b5f46f0e65249512c4e101dba77d305facea553');
define('NONCE_KEY', 'd018f221d8b3f49fdbaea0688259518800e57f89205c8c65563c05aa519e52f4');
define('AUTH_SALT', 'c2ea91afffa2a5c399367c7b2fcfea13db3b18a87bd86bff3d17486401a6ce8a');
define('SECURE_AUTH_SALT', 'ff5fda4d1d50a717ecba94b77c13807bb5ff6debbcbefc016fb324158832c88e');
define('LOGGED_IN_SALT', '7f58ead0b77aa21869bc3b1c6fe1f77d7e982b4b6eb353dd7b712af31740d70f');
define('NONCE_SALT', '48b506d576a97caf407956707c032f4039b7899879aec93e8ea372d688236fc2');
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
require_once ABSPATH . 'wp-settings.php';
