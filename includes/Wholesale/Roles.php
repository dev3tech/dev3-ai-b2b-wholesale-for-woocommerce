<?php
/**
 * Wholesale roles ("tiers"): option storage, WP role sync and user lookups.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Wholesale;

defined( 'ABSPATH' ) || exit;

/**
 * Roles.
 */
class Roles {

	const OPTION     = 'dev3bq_roles';
	const CAP        = 'dev3bq_wholesale';
	const WP_PREFIX  = 'dev3bq_';
	const QUOTE_MODE = array( 'inherit', 'always', 'never' );

	/**
	 * Memo.
	 *
	 * @var array|null
	 */
	private static $memo = null;

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'init', array( __CLASS__, 'maybe_sync' ), 20 );
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
	}

	/**
	 * Default seed (first install).
	 *
	 * @return array
	 */
	public static function seed() {
		return array(
			'wholesale'   => self::normalize(
				array(
					'key'      => 'wholesale',
					'name'     => 'Wholesale',
					'discount' => 10,
					'signup'   => true,
				)
			),
			'distributor' => self::normalize(
				array(
					'key'       => 'distributor',
					'name'      => 'Distributor',
					'discount'  => 20,
					'min_value' => 250,
					'signup'    => true,
				)
			),
		);
	}

	/**
	 * Clears the memo (tests / after saves).
	 */
	public static function flush() {
		self::$memo = null;
	}

	/**
	 * All roles keyed by key.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$memo ) {
			$stored = get_option( self::OPTION, null );
			if ( ! is_array( $stored ) ) {
				$stored = self::seed();
			}
			$out = array();
			foreach ( $stored as $role ) {
				if ( is_array( $role ) && ! empty( $role['key'] ) ) {
					$role                = self::normalize( $role );
					$out[ $role['key'] ] = $role;
				}
			}
			self::$memo = $out;
		}
		return self::$memo;
	}

	/**
	 * One role.
	 *
	 * @param string $key Key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Normalizes a role definition.
	 *
	 * @param array $r Raw.
	 * @return array
	 */
	public static function normalize( array $r ) {
		$key  = substr( sanitize_key( isset( $r['key'] ) ? $r['key'] : '' ), 0, 30 );
		$mode = isset( $r['quote'] ) && in_array( $r['quote'], self::QUOTE_MODE, true ) ? $r['quote'] : 'inherit';
		return array(
			'key'           => $key,
			'name'          => mb_substr( sanitize_text_field( isset( $r['name'] ) ? (string) $r['name'] : $key ), 0, 60 ),
			'discount'      => min( 100, max( 0, round( (float) ( isset( $r['discount'] ) ? $r['discount'] : 0 ), 2 ) ) ),
			'min_value'     => max( 0, round( (float) ( isset( $r['min_value'] ) ? $r['min_value'] : 0 ), 2 ) ),
			'min_qty'       => max( 0, (int) ( isset( $r['min_qty'] ) ? $r['min_qty'] : 0 ) ),
			'tax_exempt'    => ! empty( $r['tax_exempt'] ),
			'gateways'      => array_values( array_filter( array_map( 'sanitize_key', isset( $r['gateways'] ) ? (array) $r['gateways'] : array() ) ) ),
			'shipping'      => array_values( array_filter( array_map( 'sanitize_key', isset( $r['shipping'] ) ? (array) $r['shipping'] : array() ) ) ),
			'label'         => mb_substr( sanitize_text_field( isset( $r['label'] ) ? (string) $r['label'] : '' ), 0, 40 ),
			'show_retail'   => ! isset( $r['show_retail'] ) || ! empty( $r['show_retail'] ),
			'quote'         => $mode,
			'signup'        => ! empty( $r['signup'] ),
			'auto_approve'  => ! empty( $r['auto_approve'] ),
			'free_shipping' => max( 0, round( (float) ( isset( $r['free_shipping'] ) ? $r['free_shipping'] : 0 ), 2 ) ),
		);
	}

	/**
	 * Saves one role (create or update) and syncs WP roles.
	 *
	 * @param array $role Role.
	 * @return array|\WP_Error Saved role.
	 */
	public static function save( array $role ) {
		$role = self::normalize( $role );
		if ( '' === $role['key'] ) {
			$role['key'] = substr( sanitize_key( str_replace( ' ', '_', strtolower( $role['name'] ) ) ), 0, 30 );
		}
		if ( '' === $role['key'] || in_array( $role['key'], array( 'everyone', 'all_wholesale', 'retail', 'guest' ), true ) ) {
			return new \WP_Error( 'dev3bq_bad_role', __( 'Give the role a name.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		if ( '' === $role['name'] ) {
			$role['name'] = ucwords( str_replace( '_', ' ', $role['key'] ) );
		}
		$all                 = self::all();
		$all[ $role['key'] ] = $role;
		update_option( self::OPTION, $all, false );
		self::flush();
		self::sync_wp_roles();
		return $role;
	}

	/**
	 * Deletes a role; its users become plain customers.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function delete( $key ) {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) ) {
			return false;
		}
		foreach ( get_users(
			array(
				'role'   => self::WP_PREFIX . $key,
				'fields' => 'ID',
			)
		) as $user_id ) {
			$user = get_userdata( (int) $user_id );
			if ( $user ) {
				$user->remove_role( self::WP_PREFIX . $key );
				if ( ! $user->roles ) {
					$user->add_role( 'customer' );
				}
			}
		}
		unset( $all[ $key ] );
		update_option( self::OPTION, $all, false );
		self::flush();
		remove_role( self::WP_PREFIX . $key );
		return true;
	}

	/**
	 * Creates the WP roles once (and when the definitions change).
	 */
	public static function maybe_sync() {
		$hash = md5( (string) wp_json_encode( array_map( static fn( $r ) => $r['name'], self::all() ) ) );
		if ( get_option( 'dev3bq_roles_hash' ) !== $hash ) {
			self::sync_wp_roles();
		}
	}

	/**
	 * Adds / renames WP roles for every wholesale role.
	 */
	public static function sync_wp_roles() {
		$base = get_role( 'customer' );
		$caps = $base ? $base->capabilities : array( 'read' => true );
		foreach ( self::all() as $role ) {
			$slug = self::WP_PREFIX . $role['key'];
			$wp   = get_role( $slug );
			if ( $wp ) {
				$roles = wp_roles();
				if ( isset( $roles->roles[ $slug ] ) && $roles->roles[ $slug ]['name'] !== $role['name'] ) {
					remove_role( $slug );
					$wp = null;
				}
			}
			if ( ! $wp ) {
				add_role( $slug, $role['name'], array_merge( $caps, array( self::CAP => true ) ) );
			}
		}
		update_option( 'dev3bq_roles_hash', md5( (string) wp_json_encode( array_map( static fn( $r ) => $r['name'], self::all() ) ) ), false );
	}

	/**
	 * The user's wholesale role key ('' = retail / guest).
	 *
	 * @param int|null $user_id User (null = current).
	 * @return string
	 */
	public static function user_role( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$key     = '';
		if ( $user_id ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				foreach ( (array) $user->roles as $wp_role ) {
					if ( 0 === strpos( $wp_role, self::WP_PREFIX ) ) {
						$candidate = substr( $wp_role, strlen( self::WP_PREFIX ) );
						if ( self::get( $candidate ) ) {
							$key = $candidate;
							break;
						}
					}
				}
			}
		}
		/**
		 * Filters the wholesale role of a user ('' = retail).
		 *
		 * @param string $key     Role key.
		 * @param int    $user_id User id.
		 */
		return (string) apply_filters( 'dev3bq_user_role', $key, $user_id );
	}

	/**
	 * Gives a user a wholesale role (replaces other wholesale roles, keeps admin roles).
	 *
	 * @param int    $user_id User.
	 * @param string $key     Role key ('' removes wholesale access).
	 * @return bool
	 */
	public static function assign( $user_id, $key ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ( '' !== $key && ! self::get( $key ) ) ) {
			return false;
		}
		foreach ( (array) $user->roles as $wp_role ) {
			if ( 0 === strpos( $wp_role, self::WP_PREFIX ) ) {
				$user->remove_role( $wp_role );
			}
		}
		if ( '' !== $key ) {
			if ( ! get_role( self::WP_PREFIX . $key ) ) {
				self::sync_wp_roles();
			}
			$user->remove_role( 'customer' );
			$user->add_role( self::WP_PREFIX . $key );
		} elseif ( ! $user->roles ) {
			$user->add_role( 'customer' );
		}
		clean_user_cache( $user->ID );
		return true;
	}

	/**
	 * Whether the user is tax exempt (user override beats the role setting).
	 *
	 * @param int|null $user_id User.
	 * @return bool
	 */
	public static function tax_exempt( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		$override = (string) get_user_meta( $user_id, '_dev3bq_tax_exempt', true );
		if ( 'yes' === $override ) {
			return true;
		}
		if ( 'no' === $override ) {
			return false;
		}
		$role = self::get( self::user_role( $user_id ) );
		return $role ? (bool) $role['tax_exempt'] : false;
	}

	/**
	 * Wholesale users (for the customers screen).
	 *
	 * @param array $args search, page, per_page, role.
	 * @return array { rows, total }
	 */
	public static function users( array $args = array() ) {
		$roles = array_map( static fn( $k ) => self::WP_PREFIX . $k, array_keys( self::all() ) );
		if ( ! empty( $args['role'] ) && self::get( $args['role'] ) ) {
			$roles = array( self::WP_PREFIX . $args['role'] );
		}
		if ( ! $roles ) {
			return array(
				'rows'  => array(),
				'total' => 0,
			);
		}
		$per   = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page  = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$query = array(
			'role__in'    => $roles,
			'number'      => $per,
			'paged'       => $page,
			'orderby'     => 'registered',
			'order'       => 'DESC',
			'count_total' => true,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['search']         = '*' . $args['search'] . '*';
			$query['search_columns'] = array( 'user_email', 'user_login', 'display_name' );
		}
		$q    = new \WP_User_Query( $query );
		$rows = array();
		foreach ( $q->get_results() as $user ) {
			$rows[] = self::user_row( $user->ID );
		}
		return array(
			'rows'  => $rows,
			'total' => (int) $q->get_total(),
		);
	}

	/**
	 * One user for the admin.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function user_row( $user_id ) {
		$user = get_userdata( $user_id );
		$role = self::user_role( $user_id );
		$def  = self::get( $role );
		return array(
			'id'         => (int) $user_id,
			'name'       => $user ? $user->display_name : '',
			'email'      => $user ? $user->user_email : '',
			'company'    => (string) get_user_meta( $user_id, 'billing_company', true ),
			'role'       => $role,
			'role_name'  => $def ? $def['name'] : '',
			'tax_exempt' => (string) get_user_meta( $user_id, '_dev3bq_tax_exempt', true ),
			'exempt_now' => self::tax_exempt( $user_id ),
			'note'       => (string) get_user_meta( $user_id, '_dev3bq_note', true ),
			'registered' => $user ? mysql_to_rfc3339( $user->user_registered ) : '',
			'edit_url'   => get_edit_user_link( $user_id ),
		);
	}

	/**
	 * Users list column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function users_column( $cols ) {
		$cols['dev3bq_role'] = __( 'Wholesale role', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		return $cols;
	}

	/**
	 * Users list column value.
	 *
	 * @param string $value   Value.
	 * @param string $column  Column.
	 * @param int    $user_id User.
	 * @return string
	 */
	public static function users_column_value( $value, $column, $user_id ) {
		if ( 'dev3bq_role' !== $column ) {
			return $value;
		}
		$role = self::get( self::user_role( $user_id ) );
		return $role ? esc_html( $role['name'] ) : '&mdash;';
	}
}
