<?php

require_once dirname(__DIR__) . '/safe-logger.php';

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses.mysql__PDO -- Topology DDL via PDO.

if (!class_exists('Rmmigrate_Network_Restore_Quarantine', false)) {
    /**
     * Rename live network tables before import so a failed import can be rolled back automatically.
     */
    class Rmmigrate_Network_Restore_Quarantine
    {
        /** @var array<string,mixed>|null */
        private static $interrupted_quarantine_state = null;

        /**
         * @return array<string,mixed>|null
         */
        public static function consume_interrupted_quarantine_state(): ?array
        {
            $state = self::$interrupted_quarantine_state;
            self::$interrupted_quarantine_state = null;

            return $state;
        }

        /**
         * @param string[] $live_tables
         * @return array{token:string,live_to_quarantine:array<string,string>,quarantine_to_live:array<string,string>}
         */
        public static function quarantine_tables(PDO $pdo, array $live_tables, string $token): array
        {
            self::$interrupted_quarantine_state = null;
            $live_to_quarantine = array();
            $index = 0;
            try {
                foreach ($live_tables as $table) {
                    $table = (string) $table;
                    if ($table === '') {
                        continue;
                    }
                    if (!self::table_exists($pdo, $table)) {
                        continue;
                    }
                    $quarantine = self::quarantine_name($token, $index);
                    while (self::table_exists($pdo, $quarantine)) {
                        ++$index;
                        $quarantine = self::quarantine_name($token, $index);
                    }
                    self::rename_table($pdo, $table, $quarantine);
                    $live_to_quarantine[$table] = $quarantine;
                    ++$index;
                }
            } catch (Throwable $e) {
                if ($live_to_quarantine !== array()) {
                    self::$interrupted_quarantine_state = array(
                        'token'              => $token,
                        'live_to_quarantine' => $live_to_quarantine,
                        'quarantine_to_live' => array_flip($live_to_quarantine),
                    );
                }
                throw $e;
            }

            return array(
                'token'               => $token,
                'live_to_quarantine'  => $live_to_quarantine,
                'quarantine_to_live'  => array_flip($live_to_quarantine),
            );
        }

        /**
         * Drop quarantined copies after a successful import.
         *
         * @param array<string,mixed> $state
         */
        public static function commit(PDO $pdo, array $state): void
        {
            $map = is_array($state['live_to_quarantine'] ?? null) ? $state['live_to_quarantine'] : array();
            self::with_fk_checks_off(
                $pdo,
                static function () use ($pdo, $map): void {
                    foreach ($map as $quarantine) {
                        $quarantine = (string) $quarantine;
                        if ($quarantine === '') {
                            continue;
                        }
                        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $quarantine) . '`');
                    }
                }
            );
        }

        /**
         * Restore quarantined tables after a failed import (drops partial import debris first).
         *
         * @param array<string,mixed> $state
         * @param string[]            $import_table_names Tables expected from the archive (live names).
         * @return array{restored:int,dropped_partial:int,errors:string[]}
         */
        public static function rollback(PDO $pdo, array $state, array $import_table_names): array
        {
            $report = array(
                'restored'         => 0,
                'dropped_partial'  => 0,
                'errors'           => array(),
            );
            $live_to_quarantine = is_array($state['live_to_quarantine'] ?? null) ? $state['live_to_quarantine'] : array();
            if ($live_to_quarantine === array()) {
                return $report;
            }

            self::with_fk_checks_off(
                $pdo,
                static function () use ($pdo, $live_to_quarantine, $import_table_names, &$report): void {
                    foreach ($import_table_names as $live) {
                        $live = (string) $live;
                        if ($live === '' || !isset($live_to_quarantine[$live])) {
                            continue;
                        }
                        $quarantine = (string) $live_to_quarantine[$live];
                        if ($quarantine === '' || !self::table_exists($pdo, $quarantine)) {
                            continue;
                        }
                        if (!self::table_exists($pdo, $live)) {
                            continue;
                        }
                        if (self::is_plugin_runtime_table($live)) {
                            continue;
                        }
                        try {
                            $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $live) . '`');
                            $report['dropped_partial']++;
                        } catch (Throwable $e) {
                            $report['errors'][] = $e->getMessage();
                        }
                    }

                    foreach ($live_to_quarantine as $live => $quarantine) {
                        $live = (string) $live;
                        $quarantine = (string) $quarantine;
                        if ($live === '' || $quarantine === '') {
                            continue;
                        }
                        if (!self::table_exists($pdo, $quarantine)) {
                            $report['errors'][] = sprintf(
                                'Quarantine table %s is missing for live table %s.',
                                $quarantine,
                                $live
                            );
                            continue;
                        }
                        if (self::table_exists($pdo, $live)) {
                            try {
                                $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $live) . '`');
                                $report['dropped_partial']++;
                            } catch (Throwable $e) {
                                $report['errors'][] = $e->getMessage();
                                continue;
                            }
                        }
                        try {
                            self::rename_table($pdo, $quarantine, $live);
                            $report['restored']++;
                        } catch (Throwable $e) {
                            $report['errors'][] = $e->getMessage();
                        }
                    }
                }
            );

            return $report;
        }

        /**
         * @param array<string,mixed> $progress
         */
        public static function progress_has_quarantine(array $progress): bool
        {
            $state = $progress['network_quarantine'] ?? null;

            return is_array($state)
                && !empty($state['live_to_quarantine'])
                && empty($state['committed'])
                && empty($state['rolled_back']);
        }

        public static function make_token(int $job_id): string
        {
            return substr(hash('sha256', 'rmmq-' . (string) $job_id . '-' . (string) microtime(true)), 0, 12);
        }

        private static function quarantine_name(string $token, int $index): string
        {
            return '__rmmq_' . preg_replace('/[^a-z0-9]/', '', strtolower($token)) . '_' . sprintf('%04d', $index);
        }

        private static function rename_table(PDO $pdo, string $from, string $to): void
        {
            $pdo->exec(
                'RENAME TABLE `' . str_replace('`', '``', $from) . '` TO `'
                . str_replace('`', '``', $to) . '`'
            );
        }

        private static function table_exists(PDO $pdo, string $table): bool
        {
            if (class_exists('Rmmigrate_Topology_Db_Connection', false)) {
                return Rmmigrate_Topology_Db_Connection::table_exists($pdo, $table);
            }

            return false;
        }

        private static function is_plugin_runtime_table(string $table): bool
        {
            if (class_exists('Rmmigrate_Restore_Topology_Db_Action', false)) {
                return Rmmigrate_Restore_Topology_Db_Action::is_plugin_runtime_table($table);
            }

            return false;
        }

        /**
         * @param callable():void $callback
         */
        private static function with_fk_checks_off(PDO $pdo, callable $callback): void
        {
            $is_mysql = false;
            try {
                $is_mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            } catch (Throwable $e) {
                rmmigrate_shared_log_best_effort_failure($e);
                unset($e);
            }
            if ($is_mysql) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            }
            try {
                $callback();
            } finally {
                if ($is_mysql) {
                    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                }
            }
        }
    }
}
