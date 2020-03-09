<?php

namespace Bluehost\Maestro;

use Exception;
use WP_User_Query;

/**
 * Class for managing Web Pro connections
 * @todo Check public/private method status
 */
class Web_Pro {

	/**
	 * WP_User object for the web pro
	 *
	 * @since 1.0
	 *
	 * @var WP_User|false
	 */
	public $user = false;

	/**
	 * Key generated by the platform for connecting a Web Pro
	 *
	 * @since 1.0
	 *
	 * @var string
	 */
	public $key = '';

	/**
	 * Unique identifier generated by the platform for the Web Pro
	 *
	 * @since 1.0
	 *
	 * @var int
	 */
	public $reference_id = 0;

	/**
	 * Email address for the Web Pro originally returned from the platform
	 */
	public $email;
	public $first_name;
	public $last_name;
	public $location;
	public $added_by;
	public $added_time;
	private $key_key          = 'bh_maestro_reference_id';
	private $revoke_token_key = 'bh_maestro_revoke_token';
	private $platform           = 'https://api-maestro.webpropanel.com/wp-plugin';

	/**
	 * Constructor
	 *
	 * @since 1.0
	 *
	 * @param int    $user_id The ID of a WordPress user
	 * @param string $key     A Maestro key assigned to a Web Pro by the platform
	 */
	public function __construct( $user_id = 0, $key = '' ) {

		if ( ! $user_id && ! $key ) {
			throw new Exception( 'Must provide a user ID or Maestro key.' );
		}

		// If only one variable is passed and it's not an integer, slide it back to the key.
		if ( ! is_int( $user_id ) && empty( $key ) ) {
			$key     = $user_id;
			$user_id = 0;
		}

		// Attempt to find a user based off supplied ID
		if ( ! empty( $user_id ) ) {
			$this->user = get_userdata( $user_id );
		}

		if ( ! empty( $key ) ) {

			// User ID not supplied or was invalid, so try to use the key to find an existing user
			if ( ! $this->user ) {
				$user_query = new WP_User_Query(
					array(
						'meta_key'   => $this->key_key,
						'meta_value' => $key,
					),
				);
				if ( $user_query->get_total() > 0 ) {
					$users      = $user_query->get_results();
					$this->user = get_userdata( $users[0] );
				}
			} else {
				// We might already have a key for the user
				if ( $this->get_key() ) {
					if ( $this->get_key() !== $key ) {
						throw new Exception( 'Maestro key does not match existing key.' );
					}
				} else {
					// No existing key, so let's verify this one and make sure it matches the user's email
					$data = $this->verify_key( $key );
					if ( ! $data || $this->email !== $data['email'] ) {
						throw new Exception( 'Maestro key does not match user.' );
					}
				}
			}
		}

		if ( $this->user ) {
			$this->init();
		} else {
			// Still did't find a user, so try to populate details from the key
			$this->fetch_details( $key );
		}

		// Bail if we don't have a valid user or a key at this point.
		if ( ! $this->user && ! $this->key ) {
			throw new Exception( 'Must provide valid user ID, email or Maestro key.' );
		}

	}

	/**
	 * Initialize object properties from saved data
	 *
	 * @since 1.0
	 */
	private function init() {
		$this->get_key();
		$this->reference_id = get_user_meta( $this->user->ID, 'bh_maestro_reference_id', true );
		$this->email        = $this->user->user_email;
		$this->first_name   = $this->user->first_name;
		$this->last_name    = $this->user->last_name;
		$this->location     = get_user_meta( $this->user->ID, 'bh_maestro_location', true );
		$this->added_by     = get_user_meta( $this->user->ID, 'bh_maestro_added_by', true );
		$this->added_time   = get_user_meta( $this->user->ID, 'bh_maestro_added_time', true );
	}

	/**
	 * Fetches details about the web pro from the Maestro platform using the provided key
	 *
	 * @since 1.0
	 */
	private function fetch_details( $key ) {
		$data = $this->verify_key( $key );
		if ( $data ) {
			$this->key = $key;
			$this->parse_platform_response( $data );
		}
	}

	/**
	 * Verifies a supplied key against the platform and returns Web Pro information
	 *
	 * @since 1.0
	 *
	 * @return array|false Array of data if valid, false if key is invalid
	 */
	private function verify_key( $key ) {

		$trans_key = 'bh_maestro_' . md5( $key );

		// md5 to make sure the key isn't too long for the option_name column
		$response = get_transient( $trans_key );

		if ( ! $response ) {
		$url = add_query_arg(
			array(
				'sessionToken' => $key,
				'websiteUrl'   => get_option( 'siteurl' ),
			),
			$this->platform . '/verify'
		);

		$response = wp_remote_get( $url );
			set_transient( $trans_key, $response, 300 ); // Cache for 5 minutes
		}

		// If it is valid, the platform will return a 200 status code
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		return json_decode( $response['body'] );
	}

	/**
	 * Parses data supplied in the response from the platform
	 *
	 * @since 1.0
	 *
	 * @param array $data The array of data returned from verifying the key on the platform
	 *
	 */
	private function parse_platform_response( $data ) {
		// We might have an existing user that matches the email
		// @todo Figure out where to put this email check...this is not the right place
		$this->user = get_user_by( 'email', $data->email );
		if ( $this->user && $this->user->user_email !== $data->email ) {
			throw new Exception( 'User email does not match Maestro platform.' );
		}

		$this->email        = $data->email;
		$this->first_name   = $data->firstName;
		$this->last_name    = $data->lastName;
		$this->reference_id = $data->webproReferenceId;
		$this->location     = $data->city;
		if ( isset( $data->state->name ) ) {
			$this->location .= ', ' . $data->state->name;
		}
		if ( isset( $data->country->name ) ) {
			$this->location .= ', ' . $data->country->name;
		}
	}

	/**
	 * Connect the Web Pro to the platform
	 *
	 * If a user doesn't exist matching the Web Pro's email, then one will be created
	 *
	 * @since 1.0
	 *
	 * @return int|false The user ID of the user who is connected, or false if the connection failed
	 */
	public function connect() {

		if ( ! $this->key ) {
			throw new Exception( 'Web Pro must have a Maestro key' );
		}

		// Restrict who can add Maestro connections
		if ( ! current_user_can( 'edit_users' ) ) {
			return false;
		};

		// User does not exist. If they did, they would have been set during initalization
		if ( ! $this->user ) {
			// A username is required, so create one from the email
			$user_login = substr( $this->email, 0, strrpos( $this->email, '@' ) );

			$userdata = array(
				// We generate a password because the user has to have one,
				// but it is never shown anywhere, so it can't be used.
				'user_pass'    => wp_generate_password( 20, true ),
				'user_login'   => sanitize_user( $user_login ),
				'user_email'   => $this->email,
				'first_name'   => $this->first_name,
				'last_name'    => $this->last_name,
				'display_name' => $this->first_name . ' ' . $this->last_name,
				'role'         => 'administrator',
			);

			$id   = wp_insert_user( $userdata );
			$user = get_userdata( $id );

			$this->user = $user;
		}

		// Make sure they are an administrator
		$this->user->set_role( 'administrator' );

		// Save the supplied Maestro Key
		$this->save_key( $this->key );

		// The platform has a unique ID that we'll save for use in the token and future invalidation notices
		add_user_meta( $this->user->ID, 'bh_maestro_reference_id', $this->reference_id );

		// Extra Information about the Web Pro, who approved their connection and when
		add_user_meta( $this->user->ID, 'bh_maestro_location', $this->location, true );
		add_user_meta( $this->user->ID, 'bh_maestro_added_by', wp_get_current_user()->user_login, true );
		add_user_meta( $this->user->ID, 'bh_maestro_added_date', time(), true );

		$maestro_token = $this->send_access_token();

		// If we didn't get a JWT back from the platform, then the connection failed
		if ( ! $maestro_token ) {
			return false;
		}

		return $this->save_revoke_token( $maestro_token );

	}

	/**
	 * Attempts to set the key for the Web Pro instance
	 *
	 * Verifies it against the platform before saving it
	 *
	 * @since 1.0
	 *
	 * @param string $key The Maestro key to set for the user
	 *
	 * @return string|false The key if successful, false on failure
	 */
	public function set_key( $key ) {

		// Try to check the key against the platform and populate data
		$data = $this->verify_key( $key );

		// Bail if the key is invalid
		if ( ! $data ) {
			return false;
		}

		// Email address returned from platform must match the initialized user
		if ( $this->email !== $data['email'] ) {
			return false;
		}

		// We have a valid key.
		// Set the data from the platform, since we may not have done it at initialization
		$this->save_key( $key );
		$this->parse_platform_response( $data );

		$maestro_token = $this->send_access_token();

		// If we didn't get a JWT back from the platform, then the connection failed
		if ( ! $maestro_token ) {
			return false;
		}

		$this->save_revoke_token( $maestro_token );

		return $this->key;
	}

	/**
	 * Get the Maestro key for the web pro
	 *
	 * @since 1.0
	 *
	 * @return string|false Either the Maestro key or false if one is not saved
	 */
	private function get_key() {
		if ( empty( $this->key ) ) {
			$this->key = get_user_meta( $this->user->ID, $this->key_key, true );
			// If for some reason the key is null or empty string, go ahead and delete it to clean up
			if ( is_null( $this->key ) || '' === $this->key ) {
				$this->delete_key();
				return false;
			}
		}
		return $this->key;
	}

	/**
	 * Save a provided Maestro key
	 *
	 * @since 1.0
	 *
	 * @param string $key The Maestro key to save
	 *
	 * @return bool Whether the key saved successfully or not
	 */
	private function save_key( $key ) {
		$this->key = $key;
		return (bool) update_user_meta( $this->user->ID, $this->key_key, $key );
	}

	/**
	 * Delete the stored Maestro key for the web pro
	 *
	 * @since 1.0
	 *
	 * @return bool Whether the key was deleted successfully or not
	 */
	private function delete_key() {
		unset( $this->key );
		return delete_user_meta( $this->user->ID, $this->key_key );
	}

	/**
	 * Return the unencrypted revoke JWT from usermeta
	 *
	 * @since 1.0
	 *
	 * @return string The original revoke JWT provided by the platform
	 */
	private function get_revoke_token() {
		$encryption      = new Encryption();
		$encrypted_token = get_user_meta( $this->user->ID, $this->revoke_token_key, true );
		return $encryption->decrypt( $encrypted_token );
	}

	/**
	 * Encrypt and save a JWT provided by the platform for notifying of a local disconnection
	 *
	 * @since 1.0
	 *
	 * @param string $token The JWT supplied by the platform when first connecting
	 *
	 * @return boolean Whether the token was saved or not
	 */
	private function save_revoke_token( $token ) {
		$encryption      = new Encryption();
		$encrypted_token = $encryption->encrypt( $token );
		return (bool) update_user_meta( $this->user->ID, $this->revoke_token_key, $encrypted_token, true );
	}

	/**
	 * Checks whether the Web Pro referenced by the current instance of the class has been connected to the platform
	 *
	 * @since 1.0
	 *
	 * @return bool Connected or not
	 */
	public function is_connected() {
		// To be considered connected, we need:
		//     - A key submitted by a site administrator
		//     - A reference ID returned from the platform
		//     - A revoke token saved in usermeta returned from the platform
		if ( $this->get_key() && $this->reference_id ) {
			if ( $this->user && get_user_meta( $this->user->ID, $this->revoke_token_key, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generates and sends an access token to the Maestro platform
	 *
	 * @since 1.0
	 *
	 */
	private function send_access_token() {

		// Generate the access token
		$jwt          = new Token();
		$access_token = $jwt->generate_token( $this );

		// Send the token to the Maestro Platform
		$body = array(
			'sessionToken'      => $this->key,
			'webproReferenceId' => $this->reference_id,
			'websiteUrl'        => get_option( 'siteurl' ),
			'wpSecret'          => $access_token,
		);

		$args = array(
			'body'        => wp_json_encode( $body ),
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'timeout'     => 10,
			'data_format' => 'body',
		);

		$response = wp_remote_post( $this->platform . '/token', $args );

		// Save the token returned from Maestro
		// This token only allows the site to notify the platform when a Maestro user's access has been revoked

		return json_decode( $response['body'] )->token;

	}

	/**
	 * Severs the connection to the platform for the Web Pro
	 *
	 * Deletes all usermeta set during Maestro connection, which also renders access token invalid
	 *
	 * @since 1.0
	 */
	public function disconnect() {
		$this->delete_key();
		delete_user_meta( $this->user->ID, 'bh_maestro_reference_id' );
		delete_user_meta( $this->user->ID, 'bh_maestro_location' );
		delete_user_meta( $this->user->ID, 'bh_maestro_added_by' );
		delete_user_meta( $this->user->ID, 'bh_maestro_added_date' );

		// Let the platform know that the site is disconnected
		$this->revoke();
	}

	/**
	 * Sends a request to the platform to notify connection is broken
	 *
	 * @since 1.0
	 */
	public function revoke() {

		$body = array(
			'token'             => $this->get_revoke_token(),
			'webproReferenceId' => $this->reference_id,
			'websiteUrl'        => get_option( 'siteurl' ),
		);

		$args = array(
			'body'        => wp_json_encode( $body ),
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'timeout'     => 10,
			'data_format' => 'body',
		);

		wp_remote_post( $this->platform . '/revoke', $args );

		delete_user_meta( $this->user->ID, $this->revoke_token_key );
	}

}
