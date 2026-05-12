<?php
/**
 * Mock $wpdb implementation for unit tests.
 *
 * Implements just enough of the wpdb surface area to exercise direct meta
 * helpers and other classes that use $wpdb->prepare/get_var/get_results/
 * insert/update without booting WordPress.
 *
 * @package XWP\CustomXmlSitemap
 */

namespace XWP\CustomXmlSitemap\Tests\Unit;

/**
 * A minimal stand-in for the WordPress $wpdb global.
 *
 * Tests configure the result to return via the public properties before
 * calling the system under test, then assert against the captured query
 * and operation metadata.
 */
class Mock_Wpdb {

	/**
	 * Postmeta table name.
	 *
	 * @var string
	 */
	public string $postmeta = 'wp_postmeta';

	/**
	 * Last SQL query string passed to prepare().
	 *
	 * @var string|null
	 */
	public ?string $last_query = null;

	/**
	 * Value returned by the next get_var() call.
	 *
	 * @var mixed
	 */
	public mixed $var_to_return = null;

	/**
	 * Value returned by the next get_results() call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $results_to_return = [];

	/**
	 * Last operation: 'insert' or 'update'.
	 *
	 * @var string|null
	 */
	public ?string $last_op = null;

	/**
	 * Last data array passed to insert()/update().
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $last_data = null;

	/**
	 * Last where array passed to update().
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $last_where = null;

	/**
	 * Mimic wpdb::prepare() with placeholder substitution.
	 *
	 * Supports the placeholders used by Sitemap_CPT: %d, %s, and %i (table name).
	 *
	 * @param string $query Query template.
	 * @param mixed  ...$args Replacement values.
	 * @return string Interpolated query.
	 */
	public function prepare( string $query, mixed ...$args ): string {
		// Allow passing a single array of args.
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}

		$out = '';
		$i   = 0;
		$len = strlen( $query );

		while ( $i < $len ) {
			$char = $query[ $i ];
			if ( '%' === $char && $i + 1 < $len ) {
				$next = $query[ $i + 1 ];
				if ( in_array( $next, [ 'd', 's', 'i' ], true ) ) {
					$value = array_shift( $args );
					switch ( $next ) {
						case 'd':
							$out .= (int) $value;
							break;
						case 's':
							$out .= "'" . str_replace( "'", "''", (string) $value ) . "'";
							break;
						case 'i':
							$out .= '`' . (string) $value . '`';
							break;
					}
					$i += 2;
					continue;
				}
			}
			$out .= $char;
			++$i;
		}

		return $out;
	}

	/**
	 * Mimic wpdb::esc_like() — returns the input unchanged for tests.
	 *
	 * @param string $text Input.
	 * @return string Input verbatim.
	 */
	public function esc_like( string $text ): string {
		return $text;
	}

	/**
	 * Capture the prepared query and return the configured value.
	 *
	 * @param string $query Prepared SQL.
	 * @return mixed Configured return value.
	 */
	public function get_var( string $query ): mixed {
		$this->last_query = $this->normalise_whitespace( $query );

		return $this->var_to_return;
	}

	/**
	 * Capture the prepared query and return the configured rows.
	 *
	 * @param string $query  Prepared SQL.
	 * @param mixed  $output Output type (ignored).
	 * @return array<int, array<string, mixed>> Configured rows.
	 */
	public function get_results( string $query, mixed $output = null ): array {
		$this->last_query = $this->normalise_whitespace( $query );

		return $this->results_to_return;
	}

	/**
	 * Capture insert() arguments.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  Row data.
	 * @return int Always returns 1.
	 */
	public function insert( string $table, array $data ): int {
		$this->last_op   = 'insert';
		$this->last_data = $data;

		return 1;
	}

	/**
	 * Capture update() arguments.
	 *
	 * @param string               $table Table name.
	 * @param array<string, mixed> $data  New values.
	 * @param array<string, mixed> $where WHERE clause.
	 * @return int Always returns 1.
	 */
	public function update( string $table, array $data, array $where ): int {
		$this->last_op    = 'update';
		$this->last_data  = $data;
		$this->last_where = $where;

		return 1;
	}

	/**
	 * Collapse runs of whitespace so test assertions are not formatting-sensitive.
	 *
	 * @param string $sql Raw SQL.
	 * @return string Normalised SQL.
	 */
	private function normalise_whitespace( string $sql ): string {
		return trim( preg_replace( '/\s+/', ' ', $sql ) );
	}
}
