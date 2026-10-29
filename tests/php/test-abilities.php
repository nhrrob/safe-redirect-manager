<?php
/**
 * Test Abilities API integration
 *
 * @package safe-redirect-manager
 */

class SRMTestAbilities extends WP_UnitTestCase {

	/**
	 * Logs in as an administrator.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Test abilities are registered
	 */
	public function testAbilitiesRegistered() {
		$this->assertInstanceOf( 'WP_Ability', wp_get_ability( 'safe-redirect-manager/list-redirects' ) );
		$this->assertInstanceOf( 'WP_Ability', wp_get_ability( 'safe-redirect-manager/create-redirect' ) );
		$this->assertInstanceOf( 'WP_Ability', wp_get_ability( 'safe-redirect-manager/delete-redirect' ) );
	}

	/**
	 * Test users without the redirect capability are denied
	 */
	public function testPermissionDenied() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'safe-redirect-manager/list-redirects' )->execute();

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Test creating, listing and deleting a redirect
	 */
	public function testCreateListDelete() {
		$created = wp_get_ability( 'safe-redirect-manager/create-redirect' )->execute(
			array(
				'redirect_from' => '/ability-from/',
				'redirect_to'   => '/ability-to/',
				'status_code'   => 301,
				'post_status'   => 'draft',
				'notes'         => 'Created by ability',
			)
		);

		$this->assertIsInt( $created['id'] );

		$redirects = wp_get_ability( 'safe-redirect-manager/list-redirects' )->execute();
		$redirect  = wp_list_filter( $redirects, array( 'ID' => $created['id'] ) );
		$redirect  = reset( $redirect );

		$this->assertSame( '/ability-from/', $redirect['redirect_from'] );
		$this->assertSame( '/ability-to/', $redirect['redirect_to'] );
		$this->assertSame( 301, $redirect['status_code'] );
		$this->assertSame( 'draft', $redirect['post_status'] );
		$this->assertSame( 'Created by ability', $redirect['notes'] );
		$this->assertSame( get_current_user_id(), (int) get_post_field( 'post_author', $created['id'] ) );

		$deleted = wp_get_ability( 'safe-redirect-manager/delete-redirect' )->execute( array( 'id' => $created['id'] ) );

		$this->assertSame( array( 'id' => $created['id'] ), $deleted );
		$this->assertSame( 'trash', get_post_status( $created['id'] ) );
	}

	/**
	 * Test invalid status codes are rejected
	 */
	public function testCreateInvalidStatusCode() {
		$result = wp_get_ability( 'safe-redirect-manager/create-redirect' )->execute(
			array(
				'redirect_from' => '/from/',
				'redirect_to'   => '/to/',
				'status_code'   => 999,
			)
		);

		$this->assertWPError( $result );
	}

	/**
	 * Test a regex redirect in the documented format redirects
	 */
	public function testCreateRegexRedirect() {
		$_SERVER['REQUEST_URI'] = '/blog/hello';
		$actual_redirect        = '';

		wp_get_ability( 'safe-redirect-manager/create-redirect' )->execute(
			array(
				'redirect_from' => '/blog/(.*)$',
				'redirect_to'   => '/news/$1',
				'enable_regex'  => true,
			)
		);

		add_action(
			'srm_do_redirect',
			function ( $requested_path, $redirected_to ) use ( &$actual_redirect ) {
				$actual_redirect = $redirected_to;
			},
			10,
			2
		);

		SRM_Redirect::factory()->maybe_redirect();

		$this->assertSame( '/news/hello', $actual_redirect );
	}

	/**
	 * Test invalid regex patterns are rejected
	 */
	public function testCreateInvalidRegex() {
		$result = wp_get_ability( 'safe-redirect-manager/create-redirect' )->execute(
			array(
				'redirect_from' => '/blog/(.*',
				'redirect_to'   => '/news/',
				'enable_regex'  => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid-regex', $result->get_error_code() );
	}

	/**
	 * Test creating with an existing from path overwrites that rule
	 */
	public function testCreateOverwritesExisting() {
		$ability = wp_get_ability( 'safe-redirect-manager/create-redirect' );
		$first   = $ability->execute(
			array(
				'redirect_from' => '/same/',
				'redirect_to'   => '/first/',
				'status_code'   => 301,
			)
		);
		$second  = $ability->execute(
			array(
				'redirect_from' => '/same/',
				'redirect_to'   => '/second/',
			)
		);

		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( '/second/', get_post_meta( $second['id'], '_redirect_rule_to', true ) );
		$this->assertSame( 302, (int) get_post_meta( $second['id'], '_redirect_rule_status_code', true ) );
	}

	/**
	 * Test creating is refused once the maximum number of redirects is reached
	 */
	public function testCreateMaxRedirectsReached() {
		srm_create_redirect( '/existing/', '/to/' );

		add_filter(
			'srm_max_redirects',
			function () {
				return 1;
			}
		);

		$result = wp_get_ability( 'safe-redirect-manager/create-redirect' )->execute(
			array(
				'redirect_from' => '/new/',
				'redirect_to'   => '/to/',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'max-redirects-reached', $result->get_error_code() );
	}

	/**
	 * Test deleting an already trashed redirect succeeds
	 */
	public function testDeleteIsIdempotent() {
		$redirect_id = srm_create_redirect( '/trash-me/', '/to/' );
		$ability     = wp_get_ability( 'safe-redirect-manager/delete-redirect' );

		$ability->execute( array( 'id' => $redirect_id ) );

		$this->assertSame( array( 'id' => $redirect_id ), $ability->execute( array( 'id' => $redirect_id ) ) );
		$this->assertSame( 'trash', get_post_status( $redirect_id ) );
	}

	/**
	 * Test deleting a post that isn't a redirect is rejected
	 */
	public function testDeleteNonRedirect() {
		$post_id = self::factory()->post->create();

		$result = wp_get_ability( 'safe-redirect-manager/delete-redirect' )->execute( array( 'id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
	}
}
