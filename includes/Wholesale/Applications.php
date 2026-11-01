<?php
/**
 * Wholesale applications: registration, approval and rejection.
 *
 * @package Dev3\AiB2bWholesale
 */

namespace Dev3\AiB2bWholesale\Wholesale;

use Dev3\AiB2bWholesale\Data\Tables;
use Dev3\AiB2bWholesale\Emails\Emails;
use Dev3\AiB2bWholesale\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Applications.
 */
class Applications {

	const STATUSES = array( 'pending', 'approved', 'rejected' );

	/**
	 * Roles open for sign-up.
	 *
	 * @return array
	 */
	public static function signup_roles() {
		return array_values( array_filter( Roles::all(), static fn( $r ) => $r['signup'] ) );
	}

	/**
	 * Submits an application (public form or a logged-in customer).
	 *
	 * @param array $input Fields.
	 * @return array|\WP_Error { id, status, message }
	 */
	public static function submit( array $input ) {
		global $wpdb;
		if ( ! Settings::get( 'registration_open', true ) ) {
			return new \WP_Error( 'dev3bq_closed', __( 'Wholesale registration is closed at the moment.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 403 ) );
		}
		$uid   = get_current_user_id();
		$user  = $uid ? get_userdata( $uid ) : null;
		$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
		if ( $user ) {
			$email = $user->user_email;
		}
		$f      = array(
			'first_name' => mb_substr( sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ), 0, 80 ),
			'last_name'  => mb_substr( sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ), 0, 80 ),
			'company'    => mb_substr( sanitize_text_field( (string) ( $input['company'] ?? '' ) ), 0, 150 ),
			'phone'      => mb_substr( sanitize_text_field( (string) ( $input['phone'] ?? '' ) ), 0, 40 ),
			'tax_id'     => mb_substr( preg_replace( '/[^A-Za-z0-9\-\. ]/', '', (string) ( $input['tax_id'] ?? '' ) ), 0, 40 ),
			'website'    => mb_substr( esc_url_raw( (string) ( $input['website'] ?? '' ) ), 0, 190 ),
			'country'    => strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) ( $input['country'] ?? '' ) ), 0, 2 ) ),
			'message'    => mb_substr( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), 0, 2000 ),
		);
		$errors = array();
		if ( ! is_email( $email ) ) {
			$errors['email'] = __( 'Enter a valid email address.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		if ( '' === $f['company'] ) {
			$errors['company'] = __( 'Enter your company name.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		if ( '' === $f['first_name'] && ! $user ) {
			$errors['first_name'] = __( 'Enter your first name.', 'dev3-ai-b2b-wholesale-for-woocommerce' );
		}
		$roles = self::signup_roles();
		$want  = sanitize_key( (string) ( $input['role'] ?? '' ) );
		$role  = null;
		foreach ( $roles as $r ) {
			if ( $r['key'] === $want ) {
				$role = $r;
			}
		}
		if ( ! $role ) {
			$role = $roles ? $roles[0] : null;
		}
		if ( ! $role ) {
			return new \WP_Error( 'dev3bq_closed', __( 'Wholesale registration is closed at the moment.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 403 ) );
		}
		if ( $errors ) {
			return new \WP_Error(
				'dev3bq_invalid',
				__( 'Please check the highlighted fields.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
				array(
					'status' => 400,
					'fields' => $errors,
				)
			);
		}
		if ( $user && '' !== Roles::user_role( $uid ) ) {
			return new \WP_Error( 'dev3bq_already', __( 'Your account already has wholesale access.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
		}
		$table = Tables::name( 'applications' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s AND status = 'pending' LIMIT 1", $email ) );
		if ( $open ) {
			return new \WP_Error( 'dev3bq_pending', __( 'We already have your application and will reply by email soon.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
		}
		if ( ! $user ) {
			$existing = get_user_by( 'email', $email );
			if ( $existing ) {
				return new \WP_Error( 'dev3bq_login_first', __( 'An account with this email exists. Please log in first, then apply.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 409 ) );
			}
			if ( 'now' === Settings::get( 'account_timing', 'now' ) || $role['auto_approve'] ) {
				$uid = self::create_user( $email, $f );
				if ( is_wp_error( $uid ) ) {
					return $uid;
				}
			}
		}
		if ( $uid ) {
			self::store_profile( $uid, $f );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			array_merge(
				$f,
				array(
					'user_id'    => (int) $uid,
					'email'      => $email,
					'role'       => $role['key'],
					'status'     => 'pending',
					'created_at' => current_time( 'mysql', true ),
				)
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( $role['auto_approve'] ) {
			self::approve( $id, $role['key'] );
			return array(
				'id'      => $id,
				'status'  => 'approved',
				'message' => __( 'Welcome aboard! Your wholesale account is active. Check your email for your login details.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
			);
		}
		Emails::send( 'admin_application', $id );
		Emails::send( 'customer_application', $id, array( 'event' => 'received' ) );
		/**
		 * Fires after a wholesale application is stored.
		 *
		 * @param int $id Application id.
		 */
		do_action( 'dev3bq_application_submitted', $id );
		return array(
			'id'      => $id,
			'status'  => 'pending',
			'message' => __( 'Thanks! We received your application and will email you once it is reviewed.', 'dev3-ai-b2b-wholesale-for-woocommerce' ),
		);
	}

	/**
	 * Creates the WP account (customer role; WordPress emails the password link).
	 *
	 * @param string $email Email.
	 * @param array  $f     Fields.
	 * @return int|\WP_Error
	 */
	private static function create_user( $email, array $f ) {
		$login = sanitize_user( strtolower( strtok( $email, '@' ) ), true );
		$base  = '' !== $login ? $login : 'buyer';
		$n     = 1;
		while ( username_exists( $login ) || '' === $login ) {
			$login = $base . ( ++$n );
		}
		$uid = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'first_name'   => $f['first_name'],
				'last_name'    => $f['last_name'],
				'display_name' => trim( $f['first_name'] . ' ' . $f['last_name'] ) ? trim( $f['first_name'] . ' ' . $f['last_name'] ) : $login,
				'role'         => 'customer',
			)
		);
		if ( is_wp_error( $uid ) ) {
			return new \WP_Error( 'dev3bq_user', $uid->get_error_message(), array( 'status' => 400 ) );
		}
		wp_new_user_notification( $uid, null, 'user' );
		return (int) $uid;
	}

	/**
	 * Copies company details into the WooCommerce billing profile.
	 *
	 * @param int   $uid User.
	 * @param array $f   Fields.
	 */
	private static function store_profile( $uid, array $f ) {
		$map = array(
			'billing_company'    => $f['company'],
			'billing_phone'      => $f['phone'],
			'billing_country'    => $f['country'],
			'billing_first_name' => $f['first_name'],
			'billing_last_name'  => $f['last_name'],
		);
		foreach ( $map as $key => $value ) {
			if ( '' !== $value && '' === (string) get_user_meta( $uid, $key, true ) ) {
				update_user_meta( $uid, $key, $value );
			}
		}
		if ( '' !== $f['tax_id'] ) {
			update_user_meta( $uid, '_dev3bq_tax_id', $f['tax_id'] );
		}
	}

	/**
	 * One application.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = Tables::name( 'applications' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Casts a row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function hydrate( array $row ) {
		$row['id']         = (int) $row['id'];
		$row['user_id']    = (int) $row['user_id'];
		$row['decided_by'] = (int) $row['decided_by'];
		$ai                = json_decode( (string) $row['ai'], true );
		$row['ai']         = is_array( $ai ) ? $ai : null;
		$row['message']    = (string) $row['message'];
		$role              = Roles::get( $row['role'] );
		$row['role_name']  = $role ? $role['name'] : $row['role'];
		$row['created_at'] = mysql_to_rfc3339( $row['created_at'] );
		$row['decided_at'] = $row['decided_at'] ? mysql_to_rfc3339( $row['decided_at'] ) : '';
		return $row;
	}

	/**
	 * List.
	 *
	 * @param array $args status, page, per_page, search.
	 * @return array { rows, total, counts }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table  = Tables::name( 'applications' );
		$status = isset( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ? $args['status'] : 'pending';
		$per    = min( 100, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page   = max( 1, (int) ( $args['page'] ?? 1 ) );
		$where  = $wpdb->prepare( 'status = %s', $status );
		if ( ! empty( $args['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where .= $wpdb->prepare( ' AND (email LIKE %s OR company LIKE %s OR last_name LIKE %s)', $like, $like, $like );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC, id DESC " . $wpdb->prepare( 'LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per ), ARRAY_A );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$raw   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		// phpcs:enable
		$counts = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $raw as $r ) {
			$counts[ $r['status'] ] = (int) $r['n'];
		}
		return array(
			'rows'   => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ),
			'total'  => $total,
			'counts' => $counts,
		);
	}

	/**
	 * Approves: creates the account if needed and assigns the role.
	 *
	 * @param int    $id         Application.
	 * @param string $role       Role key (defaults to the requested one).
	 * @param string $tax_exempt ''|yes|no user override.
	 * @param string $note       Message for the customer email.
	 * @return array|\WP_Error Application.
	 */
	public static function approve( $id, $role = '', $tax_exempt = '', $note = '' ) {
		global $wpdb;
		$app = self::get( $id );
		if ( ! $app ) {
			return new \WP_Error( 'dev3bq_no_app', __( 'Application not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) );
		}
		if ( 'approved' === $app['status'] ) {
			return $app;
		}
		$role = '' !== $role ? $role : $app['role'];
		if ( ! Roles::get( $role ) ) {
			return new \WP_Error( 'dev3bq_bad_role', __( 'Pick a wholesale role.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$uid = $app['user_id'];
		if ( ! $uid || ! get_userdata( $uid ) ) {
			$existing = get_user_by( 'email', $app['email'] );
			$uid      = $existing ? $existing->ID : self::create_user( $app['email'], $app );
			if ( is_wp_error( $uid ) ) {
				return $uid;
			}
			self::store_profile( $uid, $app );
		}
		Roles::assign( $uid, $role );
		if ( in_array( $tax_exempt, array( 'yes', 'no' ), true ) ) {
			update_user_meta( $uid, '_dev3bq_tax_exempt', $tax_exempt );
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'applications' ),
			array(
				'status'     => 'approved',
				'role'       => $role,
				'user_id'    => (int) $uid,
				'reason'     => mb_substr( sanitize_textarea_field( (string) $note ), 0, 500 ),
				'decided_by' => get_current_user_id(),
				'decided_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
		Emails::send(
			'customer_application',
			$id,
			array(
				'event'   => 'approved',
				'message' => (string) $note,
			)
		);
		/**
		 * Fires after an application is approved.
		 *
		 * @param int    $id   Application.
		 * @param int    $uid  User.
		 * @param string $role Role key.
		 */
		do_action( 'dev3bq_application_approved', $id, $uid, $role );
		return self::get( $id );
	}

	/**
	 * Rejects.
	 *
	 * @param int    $id     Application.
	 * @param string $reason Reason (sent to the applicant).
	 * @return array|\WP_Error
	 */
	public static function reject( $id, $reason = '' ) {
		global $wpdb;
		$app = self::get( $id );
		if ( ! $app ) {
			return new \WP_Error( 'dev3bq_no_app', __( 'Application not found.', 'dev3-ai-b2b-wholesale-for-woocommerce' ), array( 'status' => 404 ) );
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			Tables::name( 'applications' ),
			array(
				'status'     => 'rejected',
				'reason'     => mb_substr( sanitize_textarea_field( (string) $reason ), 0, 500 ),
				'decided_by' => get_current_user_id(),
				'decided_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id )
		);
		Emails::send(
			'customer_application',
			$id,
			array(
				'event'   => 'rejected',
				'message' => (string) $reason,
			)
		);
		return self::get( $id );
	}

	/**
	 * Stores the AI screening note.
	 *
	 * @param int   $id Application.
	 * @param array $ai Screening.
	 */
	public static function save_ai( $id, array $ai ) {
		global $wpdb;
		$wpdb->update( Tables::name( 'applications' ), array( 'ai' => wp_json_encode( $ai ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Pending count.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table = Tables::name( 'applications' );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
