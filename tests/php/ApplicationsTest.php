<?php
/**
 * Wholesale registration and approval flow.
 *
 * @package Dev3\AiB2bWholesale
 */

require_once __DIR__ . '/Base_Case.php';

use Dev3\AiB2bWholesale\Core3\Core\Settings_Store;
use Dev3\AiB2bWholesale\Wholesale\Applications;
use Dev3\AiB2bWholesale\Wholesale\Roles;

class ApplicationsTest extends Dev3bq_Base_Case {

	private function form( array $extra = array() ) {
		return array_merge(
			array(
				'first_name' => 'Lena',
				'last_name'  => 'Berg',
				'email'      => 'lena@berg-cafe.test',
				'company'    => 'Berg Café',
				'phone'      => '+49 30 1234',
				'tax_id'     => 'DE123456789',
				'website'    => 'https://berg-cafe.test',
				'country'    => 'de',
				'role'       => 'distributor',
				'message'    => 'Three cafés in Berlin.',
			),
			$extra
		);
	}

	public function test_guest_applies_admin_approves_as_another_role() {
		wp_set_current_user( 0 );
		$res = Applications::submit( $this->form() );
		$this->assertIsArray( $res );
		$this->assertSame( 'pending', $res['status'] );
		$app = Applications::get( $res['id'] );
		$this->assertSame( 'DE', $app['country'] );
		$this->assertSame( 'distributor', $app['role'] );
		$user = get_user_by( 'email', 'lena@berg-cafe.test' );
		$this->assertInstanceOf( WP_User::class, $user, 'Account created right away (default)' );
		$this->assertSame( '', Roles::user_role( $user->ID ), 'No wholesale prices before approval' );
		$this->assertSame( 'Berg Café', get_user_meta( $user->ID, 'billing_company', true ) );
		$subjects = implode( ' | ', wp_list_pluck( $this->mails(), 'subject' ) );
		$this->assertStringContainsString( 'New wholesale application', $subjects );
		$this->assertStringContainsString( 'received your wholesale application', $subjects );

		$again = Applications::submit( $this->form() );
		$this->assertWPError( $again );

		wp_set_current_user( $this->user( 'administrator' ) );
		reset_phpmailer_instance();
		$done = Applications::approve( $res['id'], 'wholesale', 'yes', 'Welcome!' );
		$this->assertSame( 'approved', $done['status'] );
		$this->assertSame( 'wholesale', Roles::user_role( $user->ID ) );
		$this->assertTrue( Roles::tax_exempt( $user->ID ) );
		$this->assertStringContainsString( 'is active', $this->mails()[0]['subject'] );
		$this->assertStringContainsString( 'Welcome!', $this->mails()[0]['body'] );
	}

	public function test_validation_and_existing_accounts() {
		wp_set_current_user( 0 );
		$bad = Applications::submit( $this->form( array( 'email' => 'nope', 'company' => '' ) ) );
		$this->assertWPError( $bad );
		$this->assertArrayHasKey( 'email', $bad->get_error_data()['fields'] );
		$this->assertArrayHasKey( 'company', $bad->get_error_data()['fields'] );
		$uid = $this->user( 'customer' );
		$this->assertWPError( Applications::submit( $this->form( array( 'email' => get_userdata( $uid )->user_email ) ) ), 'Must log in first' );
		wp_set_current_user( $uid );
		$res = Applications::submit( $this->form( array( 'email' => 'ignored@x.test' ) ) );
		$this->assertSame( $uid, Applications::get( $res['id'] )['user_id'], 'Logged-in customer applies with their own account' );
		wp_set_current_user( $this->user( 'wholesale' ) );
		$this->assertWPError( Applications::submit( $this->form() ), 'Already wholesale' );
	}

	public function test_on_approval_timing_auto_approve_and_reject() {
		Settings_Store::update( array( 'account_timing' => 'on_approval' ) );
		Settings_Store::flush();
		wp_set_current_user( 0 );
		$res = Applications::submit( $this->form( array( 'email' => 'late@x.test' ) ) );
		$this->assertFalse( get_user_by( 'email', 'late@x.test' ) );
		Applications::approve( $res['id'] );
		$this->assertSame( 'distributor', Roles::user_role( get_user_by( 'email', 'late@x.test' )->ID ), 'Account created at approval' );

		Roles::save( array_merge( Roles::get( 'wholesale' ), array( 'auto_approve' => true ) ) );
		$auto = Applications::submit( $this->form( array( 'email' => 'auto@x.test', 'role' => 'wholesale' ) ) );
		$this->assertSame( 'approved', $auto['status'] );
		$this->assertSame( 'wholesale', Roles::user_role( get_user_by( 'email', 'auto@x.test' )->ID ) );

		$rej = Applications::submit( $this->form( array( 'email' => 'no@x.test' ) ) );
		reset_phpmailer_instance();
		$done = Applications::reject( $rej['id'], 'We only sell to cafés.' );
		$this->assertSame( 'rejected', $done['status'] );
		$this->assertStringContainsString( 'We only sell to cafés.', $this->mails()[0]['body'] );
		$q = Applications::query( array( 'status' => 'rejected' ) );
		$this->assertSame( 1, $q['total'] );
		$this->assertSame( 1, $q['counts']['rejected'] );
	}

	public function test_closed_registration() {
		Settings_Store::update( array( 'registration_open' => false ) );
		Settings_Store::flush();
		$this->assertWPError( Applications::submit( $this->form() ) );
	}
}
