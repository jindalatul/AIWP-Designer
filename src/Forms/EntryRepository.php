<?php
declare( strict_types = 1 );

namespace AIWP\Designer\Forms;

/**
 * Stored form submissions.
 *
 * The visitor's IP is hashed, never stored raw, so the log is useful for rate
 * limiting and abuse without keeping personal data it does not need.
 */
final class EntryRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'aiwp_form_entries';
	}

	public static function install_table(): void {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			form_id VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			payload LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			PRIMARY KEY (id),
			KEY page_form (page_id, form_id),
			KEY created_at (created_at),
			KEY ip_hash (ip_hash)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function store( int $page_id, string $form_id, array $payload, string $ip_hash, string $status = 'new' ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert(
			self::table(),
			array(
				'page_id'    => $page_id,
				'form_id'    => substr( $form_id, 0, 64 ),
				'created_at' => current_time( 'mysql', true ),
				'ip_hash'    => $ip_hash,
				'payload'    => (string) wp_json_encode( $payload ),
				'status'     => in_array( $status, array( 'new', 'suspect' ), true ) ? $status : 'new',
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function count(): int {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * How many times this address has submitted in the last hour.
	 */
	public function recent_count_for_ip( string $ip_hash ): int {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE ip_hash = %s AND created_at > %s",
				$ip_hash,
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
			)
		);
	}

	public function delete( int $id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}
}
